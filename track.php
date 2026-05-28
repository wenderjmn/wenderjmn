<?php
/**
 * track.php — Endpoint público de rastreamento de eventos de funil
 *
 * Chamado via fetch() nas landing pages (index.html, ig.html).
 * Sem autenticação — usa ANON key ou service key via env.
 * Rate-limit por IP: 120 eventos por minuto.
 */

if (file_exists(__DIR__ . '/_env.php')) require_once __DIR__ . '/_env.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { http_response_code(405); echo json_encode(['ok'=>false]); exit; }

define('SUPABASE_URL',         'https://drgrwpmhmrrhxuwxabow.supabase.co');
define('SUPABASE_SERVICE_KEY', getenv('SUPABASE_SERVICE_KEY') ?: '');

// ── Rate limit por IP (120 req/min via APCu se disponível, senão skip) ──
$ip  = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$ip  = preg_replace('/[^0-9a-fA-F:.]/', '', explode(',', $ip)[0]);
if (function_exists('apcu_fetch')) {
    $key = 'track_rl_' . md5($ip);
    $cnt = (int)apcu_fetch($key);
    if ($cnt > 120) { http_response_code(429); echo json_encode(['ok'=>false,'error'=>'rate_limit']); exit; }
    apcu_store($key, $cnt + 1, 60);
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data || empty($data['event']) || empty($data['session_id'])) {
    http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing fields']); exit;
}

// Eventos permitidos
$allowed = [
    'page_view','quiz_opened','quiz_q1','quiz_q2','quiz_q3','quiz_q4',
    'quiz_completed','form_opened','form_submitted','vip_click',
];
$event = $data['event'];
if (!in_array($event, $allowed, true)) {
    http_response_code(400); echo json_encode(['ok'=>false,'error'=>'invalid event']); exit;
}

$row = [
    'session_id'      => substr(preg_replace('/[^a-zA-Z0-9_-]/', '', $data['session_id']), 0, 64),
    'event'           => $event,
    'page'            => in_array($data['page'] ?? '', ['index','ig'], true) ? $data['page'] : 'index',
    'step'            => isset($data['step']) ? (int)$data['step'] : null,
    'sabotador'       => isset($data['sabotador']) ? substr(strip_tags($data['sabotador']), 0, 10) : null,
    'source'          => isset($data['source']) ? substr(strip_tags($data['source']), 0, 60) : null,
    'source_campaign' => isset($data['source_campaign']) ? substr(strip_tags($data['source_campaign']), 0, 60) : null,
    'referrer'        => isset($data['referrer']) ? substr($data['referrer'], 0, 200) : null,
    'created_at'      => gmdate('Y-m-d\TH:i:s\Z'),
];

$ch = curl_init(SUPABASE_URL . '/rest/v1/page_events');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($row),
    CURLOPT_TIMEOUT        => 5,
    CURLOPT_HTTPHEADER     => [
        'apikey: '           . SUPABASE_SERVICE_KEY,
        'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
        'Content-Type: application/json',
        'Prefer: return=minimal',
    ],
]);
curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo json_encode(['ok' => $code >= 200 && $code < 300]);
