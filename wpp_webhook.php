<?php
/**
 * wpp_webhook.php — Recebe callbacks de entrega da Z-API
 *
 * Configure no painel Z-API:
 *   Webhook → Delivery → URL: https://www.oficialemagreser.com/wpp_webhook.php
 *
 * Eventos recebidos:
 *   DeliveryCallbackDto → status: SENT | RECEIVED | READ | PLAYED
 */

if (file_exists(__DIR__ . '/_env.php')) require_once __DIR__ . '/_env.php';

define('SUPABASE_URL',         'https://drgrwpmhmrrhxuwxabow.supabase.co');
define('SUPABASE_SERVICE_KEY', getenv('SUPABASE_SERVICE_KEY') ?: '***REMOVED_SUPABASE_KEY***');
define('ZAPI_CLIENT_TOKEN',    getenv('ZAPI_CLIENT_TOKEN')    ?: '***REMOVED_ZAPI_CLIENT_TOKEN***');

header('Content-Type: application/json');

// Valida Client-Token da Z-API no header
$token = $_SERVER['HTTP_CLIENT_TOKEN'] ?? $_GET['token'] ?? '';
if ($token !== ZAPI_CLIENT_TOKEN) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Log para debug (últimas 500 linhas)
$log_line = date('Y-m-d H:i:s') . ' ' . substr($raw, 0, 300) . "\n";
file_put_contents(__DIR__ . '/wpp_webhook.log', $log_line, FILE_APPEND);

// DeliveryCallbackDto: sempre sobre mensagens que enviamos — fromMe não existe neste evento
$type       = $data['type']       ?? $data['event']  ?? '';
$message_id = $data['messageId']  ?? $data['zaapId'] ?? '';
$status     = strtoupper($data['status'] ?? '');

// Rejeita apenas se não tiver messageId ou status (campos obrigatórios)
if (!$message_id || !$status) {
    echo json_encode(['ok' => true, 'skipped' => true]);
    exit;
}

// Ignora status que não são de entrega
if (!in_array($status, ['SENT','RECEIVED','READ','PLAYED','PENDING','FAILED'])) {
    echo json_encode(['ok' => true, 'skipped' => true, 'reason' => 'not_delivery_status']);
    exit;
}

// Mapeia status Z-API → campos do banco
$now = gmdate('Y-m-d\TH:i:s\Z');
$patch = ['delivery_status' => strtolower($status)];

if ($status === 'RECEIVED') {
    $patch['delivered_at'] = $now;
} elseif ($status === 'READ' || $status === 'PLAYED') {
    $patch['delivered_at'] = $patch['delivered_at'] ?? $now;
    $patch['read_at']      = $now;
}

// Atualiza pelo zapi_message_id
$path = 'whatsapp_queue?zapi_message_id=eq.' . rawurlencode($message_id);
$ch = curl_init(SUPABASE_URL . '/rest/v1/' . $path);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'PATCH',
    CURLOPT_POSTFIELDS     => json_encode($patch),
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_HTTPHEADER     => [
        'apikey: '               . SUPABASE_SERVICE_KEY,
        'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
        'Content-Type: application/json',
        'Prefer: return=minimal',
    ],
]);
$res  = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo json_encode(['ok' => $code < 300, 'status' => $status, 'messageId' => $message_id]);
