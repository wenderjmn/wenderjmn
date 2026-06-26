<?php
/**
 * cron.php — Automação de opt-in silencioso para leads importados
 *
 * Executa via cron a cada hora (cron-job.org).
 * Para cada lead importado com optin_status='pending', sem opt-out,
 * ainda não enrollado, e sem sequência enviada há mais de 24h:
 *   → enrola na sequência configurada em site_config.optin_followup_sequence_id
 *   → equivale ao fluxo do SIM automático em wpp_receive.php
 *
 * Acesso via HTTP: https://www.oficialemagreser.com/cron.php?token=CRON_SECRET
 * Ou diretamente via CLI: php cron.php
 */

foreach ([__DIR__ . '/_env.php', __DIR__ . '/../_env.php'] as $_p) {
    if (file_exists($_p)) { require_once $_p; break; }
}

define('SUPABASE_URL',         'https://drgrwpmhmrrhxuwxabow.supabase.co');
define('SUPABASE_SERVICE_KEY', getenv('SUPABASE_SERVICE_KEY') ?: '');
define('CRON_SECRET',          getenv('CRON_SECRET')          ?: '');
define('SITE_URL',             'https://www.oficialemagreser.com');
define('BATCH_SIZE',           50);
define('SILENT_OPTIN_HOURS',   24); // horas sem resposta → auto-enrolar

$is_http = php_sapi_name() !== 'cli';
if ($is_http) {
    $token = $_GET['token'] ?? '';
    if (!CRON_SECRET || $token !== CRON_SECRET) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
}

// ── HELPERS ─────────────────────────────────────────────────────────────────
function sb_get(string $path): array {
    $r = sb_request('GET', $path);
    return is_array($r['body']) ? $r['body'] : [];
}
function sb_post(string $path, array $body): array {
    return sb_request('POST', $path, $body);
}
function sb_patch(string $path, array $body): array {
    return sb_request('PATCH', $path, $body);
}
function sb_request(string $method, string $path, ?array $body = null): array {
    $url = SUPABASE_URL . '/rest/v1/' . $path;
    $headers = [
        'apikey: '               . SUPABASE_SERVICE_KEY,
        'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
        'Content-Type: application/json',
        'Prefer: return=representation',
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $resp   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode($resp ?: '[]', true);
    return ['status' => $status, 'body' => $decoded];
}

function log_msg(string $msg): void {
    $ts = date('[Y-m-d H:i:s]');
    if (php_sapi_name() === 'cli') echo "$ts $msg\n";
}

// ── VALIDAÇÃO ────────────────────────────────────────────────────────────────
if (!SUPABASE_SERVICE_KEY) {
    $err = ['ok' => false, 'error' => 'SUPABASE_SERVICE_KEY não configurada'];
    if ($is_http) echo json_encode($err); else print_r($err);
    exit;
}

// ── BUSCAR ID DA SEQUÊNCIA DE FOLLOW-UP ─────────────────────────────────────
$cfg = sb_get('site_config?key=eq.optin_followup_sequence_id&select=value&limit=1');
$followup_seq_id = trim($cfg[0]['value'] ?? '');

if (!$followup_seq_id) {
    $err = ['ok' => false, 'error' => 'optin_followup_sequence_id não configurado em site_config'];
    log_msg('ERRO: ' . $err['error']);
    if ($is_http) echo json_encode($err); else print_r($err);
    exit;
}

// ── BUSCAR SEQUÊNCIA ─────────────────────────────────────────────────────────
$seq_rows = sb_get('sequences?id=eq.' . rawurlencode($followup_seq_id) . '&select=id,items&limit=1');
$sequence = $seq_rows[0] ?? null;

if (!$sequence || empty($sequence['items'])) {
    $err = ['ok' => false, 'error' => 'Sequência de follow-up não encontrada ou vazia'];
    log_msg('ERRO: ' . $err['error']);
    if ($is_http) echo json_encode($err); else print_r($err);
    exit;
}

$seq_items = $sequence['items'];

// ── BUSCAR LINK_VIDEO_IRA (para substituição de variáveis) ───────────────────
$cfg_ira  = sb_get('site_config?key=eq.link_video_ira&select=value&limit=1');
$link_video_ira = trim($cfg_ira[0]['value'] ?? '');

// ── BUSCAR LEADS PENDENTES (silêncio ≥ 24h) ──────────────────────────────────
// Leads importados que:
//   - optin_status = 'pending' (não responderam SIM nem NÃO)
//   - wpp_optout = false (não pediram opt-out)
//   - email_optout = false
//   - automation_enrolled = false
//   - sequence_queued_at IS NOT NULL (receberam a sequência inicial)
//   - sequence_queued_at < agora - 24h (já passaram as 24h)

$cutoff = gmdate('c', strtotime('-' . SILENT_OPTIN_HOURS . ' hours'));

$leads = sb_get(
    'leads' .
    '?source=eq.import' .
    '&optin_status=eq.pending' .
    '&wpp_optout=eq.false' .
    '&email_optout=eq.false' .
    '&automation_enrolled=eq.false' .
    '&sequence_queued_at=not.is.null' .
    '&sequence_queued_at=lt.' . rawurlencode($cutoff) .
    '&select=id,name,phone,email,sabotador' .
    '&order=sequence_queued_at.asc' .
    '&limit=' . BATCH_SIZE
);

log_msg('Leads pendentes encontrados: ' . count($leads) . ' (cutoff: ' . $cutoff . ')');

$enrolled  = 0;
$skipped   = 0;
$errors    = 0;

foreach ($leads as $lead) {
    $id    = $lead['id']    ?? '';
    $nome  = $lead['name']  ?? 'você';
    $phone = $lead['phone'] ?? '';
    $email = $lead['email'] ?? '';

    if (!$id) { $skipped++; continue; }

    // Sem telefone e sem e-mail: marcar como enrollado para não reprocessar
    if (empty($phone) && empty($email)) {
        sb_patch('leads?id=eq.' . rawurlencode($id), [
            'automation_enrolled' => true,
            'optin_status'        => 'confirmed',
            'updated_at'          => gmdate('c'),
        ]);
        $skipped++;
        log_msg("SKIP (sem contato): $nome ($id)");
        continue;
    }

    $now = gmdate('c');

    // ── ENFILEIRAR ITENS DA SEQUÊNCIA ─────────────────────────────────────
    $wpp_rows   = [];
    $email_rows = [];

    foreach ($seq_items as $item) {
        $type = $item['type'] ?? '';

        // Calcular scheduled_at
        if (!empty($item['fixed_date'])) {
            $fixed_time = $item['fixed_time'] ?? '08:00';
            $dt = new DateTime($item['fixed_date'] . ' ' . $fixed_time, new DateTimeZone('America/Sao_Paulo'));
            $dt->setTimezone(new DateTimeZone('UTC'));
            $scheduled_at = $dt->format('c');
        } else {
            $delay_hours  = floatval($item['delay_hours'] ?? 0);
            $delay_secs   = (int) round($delay_hours * 3600);
            $scheduled_at = gmdate('c', time() + $delay_secs);
        }

        if ($type === 'wpp' && !empty($phone)) {
            $msg = sub_vars($item['message'] ?? '', $nome, $lead);
            $wpp_rows[] = [
                'lead_id'      => $id,
                'to_phone'     => $phone,
                'to_name'      => $nome,
                'message'      => $msg,
                'scheduled_at' => $scheduled_at,
                'status'       => 'pending',
            ];
        } elseif ($type === 'email' && !empty($email)) {
            $email_rows[] = [
                'lead_id'       => $id,
                'to_email'      => $email,
                'to_name'       => $nome,
                'template_slug' => $item['template_slug'] ?? '',
                'scheduled_at'  => $scheduled_at,
                'status'        => 'pending',
            ];
        }
    }

    $ok = true;

    if (!empty($wpp_rows)) {
        $r = sb_post('whatsapp_queue', $wpp_rows);
        if ($r['status'] >= 300) {
            log_msg("ERRO WPP queue: $nome — status " . $r['status']);
            $ok = false;
        }
    }

    if (!empty($email_rows)) {
        $r = sb_post('email_queue', $email_rows);
        if ($r['status'] >= 300) {
            log_msg("ERRO email queue: $nome — status " . $r['status']);
            $ok = false;
        }
    }

    if ($ok) {
        sb_patch('leads?id=eq.' . rawurlencode($id), [
            'automation_enrolled' => true,
            'optin_status'        => 'confirmed',
            'sequence_queued_at'  => $now,
            'updated_at'          => gmdate('c'),
        ]);
        $enrolled++;
        log_msg("Enrollado (silêncio 24h): $nome ($phone)");
    } else {
        $errors++;
    }
}

// ── SUBSTITUIÇÃO DE VARIÁVEIS ────────────────────────────────────────────────
function sub_vars(string $msg, string $nome, array $lead): string {
    global $link_video_ira;

    $link_vip     = 'https://chat.whatsapp.com/FXsqaYzrB3p3ZEq7VGP9oZ';
    $link_hotmart = getenv('HOTMART_LINK') ?: 'https://pay.hotmart.com/emagreser';
    $link_site    = SITE_URL;

    $sab_map = [
        'A' => 'Perfil Emocional',
        'B' => 'Perfil Social',
        'C' => 'Perfil Analítico',
        'D' => 'Perfil Impulsivo',
    ];
    $sab         = strtoupper(trim($lead['sabotador'] ?? ''));
    $nome_perfil = $sab_map[$sab] ?? 'Perfil Sabotador';

    $msg = str_replace('{{nome_lead}}',    $nome,          $msg);
    $msg = str_replace('{{nome}}',         $nome,          $msg);
    $msg = str_replace('{{nome_perfil}}',  $nome_perfil,   $msg);
    $msg = str_replace('{{link_vip}}',     $link_vip,      $msg);
    $msg = str_replace('{{link_hotmart}}', $link_hotmart,  $msg);
    $msg = str_replace('{{link_site}}',    $link_site,     $msg);
    $msg = str_replace('{{link_video_ira}}', $link_video_ira, $msg);

    return $msg;
}

$result = [
    'ok'       => true,
    'checked'  => count($leads),
    'enrolled' => $enrolled,
    'skipped'  => $skipped,
    'errors'   => $errors,
    'ts'       => date('Y-m-d H:i:s'),
];

log_msg("Resultado: " . json_encode($result));
if ($is_http) echo json_encode($result);
