<?php
/**
 * email_worker.php
 * Roda via cron a cada 10 minutos.
 * Cron Hostinger: */10 * * * * php /home/usuario/public_html/email_worker.php
 *
 * Envia e-mails pendentes via SMTP Hostinger e WhatsApp via Z-API.
 */

// ── CONFIGURAÇÕES ────────────────────────────────────────────────
define('SUPABASE_URL',         getenv('SUPABASE_URL')         ?: 'https://drgrwpmhmrrhxuwxabow.supabase.co');
define('SUPABASE_SERVICE_KEY', getenv('SUPABASE_SERVICE_KEY') ?: 'COLE_AQUI_SUA_SERVICE_ROLE_KEY');

// SMTP Hostinger
define('SMTP_HOST',     'mail.hostinger.com');
define('SMTP_PORT',     465);
define('SMTP_USER',     'contato@emagreser.danielydealbuquerque.com.br');
define('SMTP_PASS',     'EmagreSer@2025!');
define('SMTP_FROM',     'contato@emagreser.danielydealbuquerque.com.br');
define('SMTP_FROM_NAME','Programa EmagreSer');

// Z-API WhatsApp
define('ZAPI_INSTANCE', '***REMOVED_ZAPI_INSTANCE***');
define('ZAPI_TOKEN',    '***REMOVED_ZAPI_TOKEN***');
define('ZAPI_URL',      'https://api.z-api.io/instances/' . ZAPI_INSTANCE . '/token/' . ZAPI_TOKEN);

define('BATCH_SIZE', 20); // e-mails por rodada
// ────────────────────────────────────────────────────────────────

$log = [];

// ── PROCESSAR FILA DE E-MAILS ────────────────────────────────────
$now_iso = (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM);
$pending = sb_get("email_queue?status=eq.pending&scheduled_at=lte.{$now_iso}&attempts=lt.3&order=scheduled_at.asc&limit=" . BATCH_SIZE);

foreach ($pending as $item) {
    // Marca como processando (evita race condition)
    sb_patch("email_queue?id=eq.{$item['id']}", ['attempts' => ($item['attempts'] + 1)]);

    // Busca template
    $templates = sb_get("email_templates?slug=eq.{$item['template_slug']}&limit=1");
    if (empty($templates)) {
        sb_patch("email_queue?id=eq.{$item['id']}", ['status'=>'failed','error_msg'=>'template not found']);
        continue;
    }
    $tpl = $templates[0];

    // Substitui variáveis
    $vars = array_merge([
        '{{nome}}'             => htmlspecialchars($item['to_name'] ?? 'você'),
        '{{link_descadastro}}' => 'https://emagreser.danielydealbuquerque.com.br/descadastro.php?email=' . urlencode($item['to_email']),
    ], $item['extra_vars'] ?? []);

    $subject = str_replace(array_keys($vars), array_values($vars), $tpl['subject']);
    $body    = str_replace(array_keys($vars), array_values($vars), $tpl['body_html']);

    // Envia via SMTP
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
        $status = $item['attempts'] >= 2 ? 'failed' : 'pending';
        sb_patch("email_queue?id=eq.{$item['id']}", ['status'=>$status,'error_msg'=>$result['msg']]);
        $log[] = "❌ Falha {$item['to_email']}: {$result['msg']}";
    }

    usleep(300000); // 0.3s entre envios para não ser bloqueado
}

// ── PROCESSAR FILA DE WHATSAPP ────────────────────────────────────
if (ZAPI_INSTANCE && ZAPI_TOKEN) {
    $wpp_pending = sb_get("whatsapp_queue?status=eq.pending&scheduled_at=lte.{$now_iso}&attempts=lt.3&order=scheduled_at.asc&limit=10");

    foreach ($wpp_pending as $item) {
        sb_patch("whatsapp_queue?id=eq.{$item['id']}", ['attempts' => ($item['attempts'] + 1)]);

        $result = send_whatsapp($item['to_phone'], $item['message']);

        if ($result['ok']) {
            sb_patch("whatsapp_queue?id=eq.{$item['id']}", ['status'=>'sent','sent_at'=>$now_iso]);
            $log[] = "✅ WA enviado para {$item['to_phone']}";
        } else {
            $status = $item['attempts'] >= 2 ? 'failed' : 'pending';
            sb_patch("whatsapp_queue?id=eq.{$item['id']}", ['status'=>$status,'error_msg'=>$result['msg']]);
            $log[] = "❌ WA falhou {$item['to_phone']}: {$result['msg']}";
        }
        usleep(500000); // 0.5s entre mensagens WA
    }
}

// Log simples
$ts = date('Y-m-d H:i:s');
$output = "[{$ts}] Worker rodou. " . count($pending) . " email(s) processados.\n" . implode("\n", $log) . "\n";
file_put_contents(__DIR__ . '/email_worker.log', $output, FILE_APPEND);
echo $output;

// ── SMTP VIA SOCKET (sem dependência de extensão) ─────────────────
function send_smtp(string $to, string $toName, string $subject, string $body): array {
    $host = SMTP_HOST;
    $port = SMTP_PORT;

    $ctx = stream_context_create(['ssl' => [
        'verify_peer'       => false,
        'verify_peer_name'  => false,
        'allow_self_signed' => true,
    ]]);

    $sock = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) return ['ok'=>false,'msg'=>"Conexão falhou: {$errstr}"];

    $read = fgets($sock, 512);
    if (strpos($read, '220') === false) { fclose($sock); return ['ok'=>false,'msg'=>"SMTP: {$read}"]; }

    $steps = [
        "EHLO " . gethostname() . "\r\n"          => '250',
        "AUTH LOGIN\r\n"                            => '334',
        base64_encode(SMTP_USER) . "\r\n"          => '334',
        base64_encode(SMTP_PASS) . "\r\n"          => '235',
        "MAIL FROM:<" . SMTP_FROM . ">\r\n"        => '250',
        "RCPT TO:<{$to}>\r\n"                      => '250',
        "DATA\r\n"                                  => '354',
    ];

    foreach ($steps as $cmd => $expected) {
        fwrite($sock, $cmd);
        $res = fgets($sock, 512);
        if (strpos($res, $expected) === false) {
            fclose($sock);
            return ['ok'=>false,'msg'=>"SMTP step failed ({$expected}): {$res}"];
        }
    }

    // Monta o e-mail
    $fromEncoded = '=?UTF-8?B?' . base64_encode(SMTP_FROM_NAME) . '?=';
    $toEncoded   = $toName ? ('=?UTF-8?B?' . base64_encode($toName) . '?= <' . $to . '>') : $to;
    $subjectEnc  = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $boundary    = md5(uniqid());
    $date        = date('r');

    $msg  = "Date: {$date}\r\n";
    $msg .= "From: {$fromEncoded} <" . SMTP_FROM . ">\r\n";
    $msg .= "To: {$toEncoded}\r\n";
    $msg .= "Subject: {$subjectEnc}\r\n";
    $msg .= "MIME-Version: 1.0\r\n";
    $msg .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n";
    $msg .= "--{$boundary}\r\n";
    $msg .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
    $msg .= strip_tags($body) . "\r\n\r\n";
    $msg .= "--{$boundary}\r\n";
    $msg .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
    $msg .= $body . "\r\n\r\n";
    $msg .= "--{$boundary}--\r\n";
    $msg .= ".\r\n";

    fwrite($sock, $msg);
    $res = fgets($sock, 512);
    fwrite($sock, "QUIT\r\n");
    fclose($sock);

    if (strpos($res, '250') !== false) return ['ok'=>true,'msg'=>trim($res)];
    return ['ok'=>false,'msg'=>trim($res)];
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
            'Client-Token: ' . ZAPI_TOKEN,
        ],
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($res, true);
    if ($code === 200 && !empty($data['zaapId'])) return ['ok'=>true,'msg'=>'sent'];
    return ['ok'=>false,'msg'=>$res];
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
