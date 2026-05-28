<?php
// email_worker.php — Disparado via cron externo (cron-job.org) ou CLI.
// URL externa: https://www.oficialemagreser.com/email_worker.php?secret=***REMOVED_WORKER_SECRET***
// CLI Hostinger (backup): php public_html/email_worker.php

if (file_exists(__DIR__ . '/_env.php')) require_once __DIR__ . '/_env.php';

// ── CONFIGURAÇÕES ────────────────────────────────────────────────
// Fallbacks hardcoded garantem que o worker funciona mesmo sem _env.php ou sem env vars no cron
define('WORKER_SECRET',        getenv('WORKER_SECRET')        ?: '');
define('SUPABASE_URL',         'https://drgrwpmhmrrhxuwxabow.supabase.co');
define('SUPABASE_SERVICE_KEY', getenv('SUPABASE_SERVICE_KEY') ?: '');

// Resend.com — API de e-mail transacional
define('RESEND_API_KEY',  getenv('RESEND_API_KEY')  ?: '');
define('SMTP_FROM',       'emagreser@oficialemagreser.com');
define('SMTP_FROM_NAME',  'Programa EmagreSer');

// Z-API WhatsApp
define('ZAPI_INSTANCE',     getenv('ZAPI_INSTANCE')     ?: '');
define('ZAPI_TOKEN',        getenv('ZAPI_TOKEN')        ?: '');
define('ZAPI_CLIENT_TOKEN', getenv('ZAPI_CLIENT_TOKEN') ?: '');
define('ZAPI_URL',          'https://api.z-api.io/instances/' . ZAPI_INSTANCE . '/token/' . ZAPI_TOKEN);

define('BATCH_SIZE', 20);

// Janela de envio permitida — horário de Brasília (UTC-3)
define('TZ_BRT',          'America/Sao_Paulo');
define('SEND_HOUR_START',  8);   // a partir das 08:00
define('SEND_HOUR_END',   21);   // até as 20:59 (não envia às 21:00 em diante)
// ────────────────────────────────────────────────────────────────

// ── AUTENTICAÇÃO (quando chamado via HTTP) ───────────────────────
$via_http = (php_sapi_name() !== 'cli');
if ($via_http) {
    $secret = $_GET['secret'] ?? $_SERVER['HTTP_X_WORKER_SECRET'] ?? '';
    if (!WORKER_SECRET || $secret !== WORKER_SECRET) {
        http_response_code(403);
        exit(json_encode(['error' => 'Forbidden']));
    }
    header('Content-Type: application/json');
}

// ── FILE LOCK — impede runs paralelos ───────────────────────────
$lock_file = sys_get_temp_dir() . '/emagreser_worker.lock';
$lock = fopen($lock_file, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    $msg = 'Worker já está rodando — ignorando esta execução.';
    if ($via_http) { echo json_encode(['ok' => false, 'msg' => $msg]); } else { echo $msg . "\n"; }
    exit;
}

$log = [];

// ── VALIDAÇÃO DE CREDENCIAIS ─────────────────────────────────────
if (!SUPABASE_SERVICE_KEY) {
    $ts  = date('Y-m-d H:i:s');
    $err = "[{$ts}] ERRO FATAL: SUPABASE_SERVICE_KEY não configurada. Worker abortado.\n";
    file_put_contents(__DIR__ . '/email_worker.log', $err, FILE_APPEND);
    flock($lock, LOCK_UN); fclose($lock);
    if ($via_http) echo json_encode(['ok'=>false,'error'=>'missing SUPABASE_SERVICE_KEY']);
    exit;
}

// ── JANELA DE ENVIO: 08h–21h horário de Brasília ────────────────
// Primeiro e-mail e primeiro WPP de cada lead são sempre enviados imediatamente.
// A partir do segundo, respeita a janela 08:00–20:59 BRT.
$now_brt      = new DateTime('now', new DateTimeZone(TZ_BRT));
$hora_brt     = (int)$now_brt->format('G');
$within_window = ($hora_brt >= SEND_HOUR_START && $hora_brt < SEND_HOUR_END);

// ── PROCESSAR FILA DE E-MAILS ────────────────────────────────────
$now_iso = gmdate('Y-m-d\TH:i:s\Z'); // sem '+' no timezone, evita quebra de URL
$pending = sb_get("email_queue?status=eq.pending&scheduled_at=lte.{$now_iso}&attempts=lt.3&order=scheduled_at.asc&limit=" . BATCH_SIZE);

foreach ($pending as $item) {
    // Verifica se o email do lead está bloqueado
    if (!empty($item['lead_id'])) {
        $lcheck = sb_get("leads?id=eq.{$item['lead_id']}&select=email_blocked&limit=1");
        if (!empty($lcheck[0]['email_blocked'])) {
            sb_patch("email_queue?id=eq.{$item['id']}", ['status'=>'failed','error_msg'=>'email_blocked']);
            continue;
        }
    }

    // Janela de envio: se fora do horário, só envia se for o primeiro e-mail deste lead
    if (!$within_window && !empty($item['lead_id'])) {
        $ja_enviou = sb_get("email_queue?lead_id=eq.{$item['lead_id']}&status=eq.sent&select=id&limit=1");
        if (!empty($ja_enviou)) {
            $log[] = "⏰ Email adiado ({$hora_brt}h BRT): {$item['to_email']}";
            continue; // deixa pending, o cron pega quando a janela abrir
        }
    }

    // Trava atômica: muda para 'processing' — cron paralelo não pega este item
    sb_patch("email_queue?id=eq.{$item['id']}", ['status'=>'processing', 'attempts' => ($item['attempts'] + 1)]);

    // Busca template
    $templates = sb_get("email_templates?slug=eq.{$item['template_slug']}&limit=1");
    if (empty($templates)) {
        sb_patch("email_queue?id=eq.{$item['id']}", ['status'=>'failed','error_msg'=>'template not found']);
        continue;
    }
    $tpl = $templates[0];

    // Substitui variáveis
    $extra = $item['extra_vars'] ?? [];
    if (is_string($extra)) $extra = json_decode($extra, true) ?: [];
    $vars = array_merge([
        '{{nome}}'             => htmlspecialchars($item['to_name'] ?? 'você'),
        '{{nome_lead}}'        => htmlspecialchars($item['to_name'] ?? 'você'),
        '{{nome_perfil}}'      => '',
        '{{link_descadastro}}' => 'https://www.oficialemagreser.com/descadastro.php?email=' . urlencode($item['to_email']),
        '{{link_vip}}'         => 'https://chat.whatsapp.com/GsMAVm3KVncGNR5nHRQ3yQ',
        '{{link_wpp}}'         => 'https://chat.whatsapp.com/GsMAVm3KVncGNR5nHRQ3yQ',
        '{{link_hotmart}}'     => 'https://pay.hotmart.com/emagreser',
        '{{link_site}}'        => 'https://www.oficialemagreser.com',
        '{{emoji}}'            => '',
        '{{tipo}}'             => '',
        '{{titulo}}'           => '',
        '{{descricao}}'        => '',
    ], $extra);

    $subject = str_replace(array_keys($vars), array_values($vars), $tpl['subject']);
    $body    = str_replace(array_keys($vars), array_values($vars), $tpl['body_html']);

    // Garante wrapper <html> para evitar penalidade HTML_MIME_NO_HTML_TAG
    if (stripos($body, '<html') === false) {
        $body = '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body>' . $body . '</body></html>';
    }

    // Envia via Resend.com API
    $result = send_smtp($item['to_email'], $item['to_name'] ?? '', $subject, $body);

    if ($result['ok']) {
        sb_patch("email_queue?id=eq.{$item['id']}", ['status'=>'sent','sent_at'=>$now_iso]);
        sb_post('email_log', [[
            'queue_id'      => $item['id'],
            'lead_id'       => $item['lead_id'],
            'to_email'      => $item['to_email'],
            'template_slug' => $item['template_slug'],
            'smtp_response' => $result['msg'],
        ]]);
        $log[] = "✅ Email enviado para {$item['to_email']} [{$item['template_slug']}]";
    } else {
        $status = ($item['attempts'] + 1) >= 3 ? 'failed' : 'pending';
        sb_patch("email_queue?id=eq.{$item['id']}", ['status'=>$status,'error_msg'=>$result['msg']]);
        $log[] = "❌ Falha {$item['to_email']}: {$result['msg']}";
    }

    usleep(300000); // 0.3s entre envios para não ser bloqueado
}

// ── PROCESSAR FILA DE WHATSAPP ────────────────────────────────────
if (!ZAPI_INSTANCE || !ZAPI_TOKEN) {
    $log[] = "⚠️ WPP pulado: ZAPI_INSTANCE ou ZAPI_TOKEN não configurados";
} else {
    $wpp_pending = sb_get("whatsapp_queue?status=eq.pending&scheduled_at=lte.{$now_iso}&attempts=lt.3&order=scheduled_at.asc&limit=10");

    foreach ($wpp_pending as $item) {
        // Verifica opt-out do lead antes de enviar
        if (!empty($item['lead_id'])) {
            $lcheck = sb_get("leads?id=eq.{$item['lead_id']}&select=wpp_optout&limit=1");
            if (!empty($lcheck[0]['wpp_optout'])) {
                sb_patch("whatsapp_queue?id=eq.{$item['id']}", ['status'=>'cancelled','error_msg'=>'wpp_optout']);
                $log[] = "⛔ WA cancelado (opt-out) para {$item['to_phone']}";
                continue;
            }
        }

        // Janela de envio: se fora do horário, só envia se for o primeiro WPP deste lead
        if (!$within_window && !empty($item['lead_id'])) {
            $ja_enviou = sb_get("whatsapp_queue?lead_id=eq.{$item['lead_id']}&status=eq.sent&select=id&limit=1");
            if (!empty($ja_enviou)) {
                $log[] = "⏰ WPP adiado ({$hora_brt}h BRT): {$item['to_phone']}";
                continue; // deixa pending, o cron pega quando a janela abrir
            }
        }

        // Trava atômica: impede reenvio duplicado se status=sent falhar
        sb_patch("whatsapp_queue?id=eq.{$item['id']}", ['status'=>'processing', 'attempts' => ($item['attempts'] + 1)]);

        $result = send_whatsapp($item['to_phone'], $item['message']);

        if ($result['ok']) {
            sb_patch("whatsapp_queue?id=eq.{$item['id']}", [
                'status'          => 'sent',
                'sent_at'         => $now_iso,
                'zapi_message_id' => $result['zaapId'] ?? null,
                'delivery_status' => 'sent',
            ]);
            $log[] = "✅ WA enviado para {$item['to_phone']}";
        } else {
            $status = ($item['attempts'] + 1) >= 3 ? 'failed' : 'pending';
            sb_patch("whatsapp_queue?id=eq.{$item['id']}", ['status'=>$status,'error_msg'=>$result['msg']]);
            $log[] = "❌ WA falhou {$item['to_phone']}: {$result['msg']}";
        }
        usleep(500000); // 0.5s entre mensagens WA
    }
}

// ── LOG + RESPOSTA ───────────────────────────────────────────────
flock($lock, LOCK_UN);
fclose($lock);

$ts = date('Y-m-d H:i:s');
$wpp_count = isset($wpp_pending) ? count($wpp_pending) : 0;
$output = "[{$ts}] Worker rodou. " . count($pending) . " email(s), {$wpp_count} WPP processados.\n" . implode("\n", $log) . "\n";
file_put_contents(__DIR__ . '/email_worker.log', $output, FILE_APPEND);

if ($via_http) {
    echo json_encode(['ok' => true, 'ts' => $ts, 'emails' => count($pending), 'wpp' => $wpp_count, 'log' => $log]);
} else {
    echo $output;
}

// ── ENVIO DE E-MAIL via Resend.com API ───────────────────────────────
function send_smtp(string $to, string $toName, string $subject, string $body): array {
    $from = SMTP_FROM_NAME . ' <' . SMTP_FROM . '>';
    $text = strip_tags(str_replace(['<br>','<br/>','<br />'], "\n", $body));

    $payload = json_encode([
        'from'     => $from,
        'to'       => [$toName ? "{$toName} <{$to}>" : $to],
        'reply_to' => SMTP_FROM,
        'subject'  => $subject,
        'html'     => $body,
        'text'     => $text,
    ]);

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . RESEND_API_KEY,
            'Content-Type: application/json',
        ],
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return ['ok'=>false, 'msg'=>"cURL: {$err}"];
    $data = json_decode($res, true);
    if ($code === 200 || $code === 201) return ['ok'=>true, 'msg'=>'resend_id:' . ($data['id'] ?? 'ok')];
    return ['ok'=>false, 'msg'=>"Resend [{$code}]: " . ($data['message'] ?? $res)];
}

// ── Z-API WHATSAPP ────────────────────────────────────────────────
function send_whatsapp(string $phone, string $message): array {
    $url = ZAPI_URL . '/send-text';
    $payload = json_encode(['phone' => $phone, 'message' => $message]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Client-Token: ' . ZAPI_CLIENT_TOKEN,
        ],
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($res, true);
    if ($code === 200 && !empty($data['zaapId'])) return ['ok'=>true,'msg'=>'sent','zaapId'=>$data['zaapId']];
    return ['ok'=>false,'msg'=>$res,'zaapId'=>null];
}

// ── FUNÇÕES SUPABASE ──────────────────────────────────────────────
function sb_get(string $path): array {
    $ch = curl_init(SUPABASE_URL . '/rest/v1/' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'apikey: ' . SUPABASE_SERVICE_KEY,
            'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
        ],
    ]);
    $res = curl_exec($ch); curl_close($ch);
    return json_decode($res, true) ?: [];
}

function sb_post(string $table, array $data): void {
    $ch = curl_init(SUPABASE_URL . '/rest/v1/' . $table);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => [
            'apikey: ' . SUPABASE_SERVICE_KEY,
            'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
            'Content-Type: application/json',
            'Prefer: return=minimal',
        ],
    ]);
    curl_exec($ch); curl_close($ch);
}

function sb_patch(string $path, array $data): void {
    $ch = curl_init(SUPABASE_URL . '/rest/v1/' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PATCH',
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => [
            'apikey: ' . SUPABASE_SERVICE_KEY,
            'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
            'Content-Type: application/json',
            'Prefer: return=minimal',
        ],
    ]);
    curl_exec($ch); curl_close($ch);
}
