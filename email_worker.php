<?php
// email_worker.php — Roda via cron a cada 10 minutos.
// Cron Hostinger: */10 * * * * php /home/u426299340/public_html/email_worker.php
// Envia e-mails pendentes via SMTP Hostinger e WhatsApp via Z-API.

// ── CONFIGURAÇÕES ────────────────────────────────────────────────
define('SUPABASE_URL',         getenv('SUPABASE_URL')         ?: 'https://drgrwpmhmrrhxuwxabow.supabase.co');
define('SUPABASE_SERVICE_KEY', getenv('SUPABASE_SERVICE_KEY') ?: '***REMOVED_SUPABASE_KEY***');

// SMTP Hostinger
define('SMTP_HOST',     'mail.hostinger.com');
define('SMTP_PORT',     587);
define('SMTP_USER',     'contato@emagreser.danielydealbuquerque.com.br');
define('SMTP_PASS',     'EmagreSer@2025!');
define('SMTP_FROM',     'contato@emagreser.danielydealbuquerque.com.br');
define('SMTP_FROM_NAME','Programa EmagreSer');

// Z-API WhatsApp
define('ZAPI_INSTANCE',      '***REMOVED_ZAPI_INSTANCE***');
define('ZAPI_TOKEN',         '***REMOVED_ZAPI_TOKEN***');
define('ZAPI_CLIENT_TOKEN',  '***REMOVED_ZAPI_CLIENT_TOKEN***');
define('ZAPI_URL',           'https://api.z-api.io/instances/' . ZAPI_INSTANCE . '/token/' . ZAPI_TOKEN);

define('BATCH_SIZE', 20); // e-mails por rodada
// ────────────────────────────────────────────────────────────────

$log = [];

// ── PROCESSAR FILA DE E-MAILS ────────────────────────────────────
$now_iso = gmdate('Y-m-d\TH:i:s\Z'); // sem '+' no timezone, evita quebra de URL
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

    // Garante wrapper <html> para evitar penalidade HTML_MIME_NO_HTML_TAG
    if (stripos($body, '<html') === false) {
        $body = '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body>' . $body . '</body></html>';
    }

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

// ── ENVIO DE E-MAIL via SMTP autenticado (Hostinger port 587 + STARTTLS) ───
function send_smtp(string $to, string $toName, string $subject, string $body): array {
    $host     = SMTP_HOST;
    $port     = SMTP_PORT;
    $user     = SMTP_USER;
    $pass     = SMTP_PASS;
    $from     = SMTP_FROM;
    $fromName = SMTP_FROM_NAME;

    // Monta a mensagem RFC 2822
    $boundary = md5(uniqid());
    $domain   = substr(strrchr($from, '@'), 1);
    $fromEnc  = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $toEnc    = $toName ? ('=?UTF-8?B?' . base64_encode($toName) . '?= <' . $to . '>') : $to;
    $subjEnc  = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    $rawMsg  = "From: {$fromEnc} <{$from}>\r\n";
    $rawMsg .= "To: {$toEnc}\r\n";
    $rawMsg .= "Reply-To: {$from}\r\n";
    $rawMsg .= "Message-ID: <" . uniqid('es', true) . "@{$domain}>\r\n";
    $rawMsg .= "Date: " . date('r') . "\r\n";
    $rawMsg .= "Subject: {$subjEnc}\r\n";
    $rawMsg .= "MIME-Version: 1.0\r\n";
    $rawMsg .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    $rawMsg .= "List-Unsubscribe: <mailto:{$from}?subject=descadastro>\r\n";
    $rawMsg .= "X-Mailer: EmagreSer-Automacao/1.0\r\n\r\n";
    $rawMsg .= "--{$boundary}\r\n";
    $rawMsg .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n";
    $rawMsg .= quoted_printable_encode(strip_tags(str_replace(['<br>','<br/>','<br />'],"\n",$body))) . "\r\n\r\n";
    $rawMsg .= "--{$boundary}\r\n";
    $rawMsg .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n";
    $rawMsg .= quoted_printable_encode($body) . "\r\n\r\n--{$boundary}--";

    // Conecta ao servidor SMTP
    $sock = @fsockopen($host, $port, $errno, $errstr, 15);
    if (!$sock) return ['ok'=>false, 'msg'=>"Conexão SMTP falhou ({$host}:{$port}): {$errstr} [{$errno}]"];
    stream_set_timeout($sock, 15);

    $read = function() use ($sock) {
        $r = '';
        while (($line = fgets($sock, 512)) !== false) {
            $r .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        return trim($r);
    };
    $write = function(string $cmd) use ($sock) { fwrite($sock, $cmd . "\r\n"); };

    $greeting = $read();
    if (substr($greeting, 0, 3) !== '220') { fclose($sock); return ['ok'=>false,'msg'=>"Greeting: {$greeting}"]; }

    $write("EHLO " . gethostname());
    $read(); // consumir resposta EHLO

    $write("STARTTLS");
    $tls = $read();
    if (substr($tls, 0, 3) !== '220') { fclose($sock); return ['ok'=>false,'msg'=>"STARTTLS: {$tls}"]; }

    if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
        // Fallback para TLS genérico
        stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    }

    $write("EHLO " . gethostname());
    $read();

    $write("AUTH LOGIN");
    $read();
    $write(base64_encode($user));
    $read();
    $write(base64_encode($pass));
    $auth = $read();
    if (substr($auth, 0, 3) !== '235') { fclose($sock); return ['ok'=>false,'msg'=>"Auth falhou: {$auth}"]; }

    $write("MAIL FROM:<{$from}>");
    $mf = $read();
    if (substr($mf, 0, 3) !== '250') { fclose($sock); return ['ok'=>false,'msg'=>"MAIL FROM: {$mf}"]; }

    $write("RCPT TO:<{$to}>");
    $rt = $read();
    if (substr($rt, 0, 3) !== '250') { fclose($sock); return ['ok'=>false,'msg'=>"RCPT TO: {$rt}"]; }

    $write("DATA");
    $dt = $read();
    if (substr($dt, 0, 3) !== '354') { fclose($sock); return ['ok'=>false,'msg'=>"DATA: {$dt}"]; }

    // Envia a mensagem — escapa linhas que começam com ponto
    fwrite($sock, preg_replace('/^\.$/m', '..', $rawMsg) . "\r\n.\r\n");
    $sent = $read();

    $write("QUIT");
    fclose($sock);

    if (substr($sent, 0, 3) === '250') return ['ok'=>true, 'msg'=>trim($sent)];
    return ['ok'=>false, 'msg'=>"Envio recusado: {$sent}"];
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
