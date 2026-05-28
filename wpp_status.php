<?php
/**
 * wpp_status.php — Callback de status de entrega da Z-API
 *
 * Configure no painel Z-API:
 *   Webhooks → "Na entrega" (ou "Status") → URL: https://www.oficialemagreser.com/wpp_status.php
 *
 * Atualiza delivery_status em whatsapp_queue:
 *   sent → received (duas marcações cinzas / azuis)
 *   received → read  (duas marcações azuis / confirmação de leitura)
 *
 * Payload recebido da Z-API:
 *   { "instanceId":"...", "messageId":"3EB0xxx", "phone":"5511...", "status":"RECEIVED"|"READ"|"PLAYED"|"ERROR" }
 */

if (file_exists(__DIR__ . '/_env.php')) require_once __DIR__ . '/_env.php';

define('SUPABASE_URL',         'https://drgrwpmhmrrhxuwxabow.supabase.co');
define('SUPABASE_SERVICE_KEY', getenv('SUPABASE_SERVICE_KEY') ?: '');
define('ZAPI_CLIENT_TOKEN',    getenv('ZAPI_CLIENT_TOKEN')    ?: '');

header('Content-Type: application/json');

// Valida Client-Token da Z-API
$token = $_SERVER['HTTP_CLIENT_TOKEN'] ?? $_SERVER['HTTP_X_CLIENT_TOKEN'] ?? $_GET['token'] ?? '';
if (ZAPI_CLIENT_TOKEN && $token !== ZAPI_CLIENT_TOKEN) {
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

// Log para debug
$log_line = date('Y-m-d H:i:s') . ' STATUS ' . substr($raw, 0, 400) . "\n";
file_put_contents(__DIR__ . '/wpp_status.log', $log_line, FILE_APPEND);

// Extrai campos — Z-API pode enviar em diferentes formatos
$message_id = $data['messageId'] ?? $data['zaapId'] ?? $data['id'] ?? null;
$status_raw = strtoupper($data['status'] ?? $data['type'] ?? '');

if (!$message_id || !$status_raw) {
    echo json_encode(['ok' => true, 'skipped' => 'no_message_id_or_status']);
    exit;
}

// Mapeia status Z-API → delivery_status interno
$status_map = [
    'RECEIVED'         => 'received',
    'DELIVERED'        => 'received',
    'READ'             => 'read',
    'PLAYED'           => 'read',
    'RECEIVEDCALLBACK' => 'received',
    'READCALLBACK'     => 'read',
    'DELIVERYCALLBACK' => 'received',
];

if (!isset($status_map[$status_raw])) {
    echo json_encode(['ok' => true, 'skipped' => 'irrelevant_status', 'status' => $status_raw]);
    exit;
}

$new_status = $status_map[$status_raw];
$now        = gmdate('Y-m-d\TH:i:s\Z');

// Busca o item na fila pelo zapi_message_id
$ch = curl_init(SUPABASE_URL . '/rest/v1/whatsapp_queue?zapi_message_id=eq.' . urlencode($message_id) . '&select=id,delivery_status&limit=1');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'apikey: '               . SUPABASE_SERVICE_KEY,
        'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
    ],
]);
$body  = curl_exec($ch);
$code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$rows = ($code === 200) ? (json_decode($body, true) ?: []) : [];

if (empty($rows)) {
    echo json_encode(['ok' => true, 'skipped' => 'message_not_found', 'messageId' => $message_id]);
    exit;
}

$item           = $rows[0];
$current_status = $item['delivery_status'] ?? 'sent';

// Não regride status (read > received > sent)
$order = ['sent' => 0, 'received' => 1, 'read' => 2];
if (($order[$new_status] ?? 0) <= ($order[$current_status] ?? 0)) {
    echo json_encode(['ok' => true, 'skipped' => 'status_not_upgraded', 'current' => $current_status, 'new' => $new_status]);
    exit;
}

// Monta patch
$patch = ['delivery_status' => $new_status];
if ($new_status === 'received') $patch['delivered_at'] = $now;
if ($new_status === 'read')     $patch['read_at']      = $now;

$ch = curl_init(SUPABASE_URL . '/rest/v1/whatsapp_queue?id=eq.' . $item['id']);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'PATCH',
    CURLOPT_POSTFIELDS     => json_encode($patch),
    CURLOPT_HTTPHEADER     => [
        'apikey: '               . SUPABASE_SERVICE_KEY,
        'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
        'Content-Type: application/json',
        'Prefer: return=minimal',
    ],
]);
curl_exec($ch);
$patch_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo json_encode([
    'ok'         => $patch_code >= 200 && $patch_code < 300,
    'messageId'  => $message_id,
    'old_status' => $current_status,
    'new_status' => $new_status,
]);
