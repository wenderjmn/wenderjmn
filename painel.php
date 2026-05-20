<?php
/**
 * painel.php — Painel de Automação EmagreSer
 * Acesso: /painel.php?token=es2026admin
 */

define('PANEL_TOKEN',          'es2026admin');
define('SUPABASE_URL',         'https://drgrwpmhmrrhxuwxabow.supabase.co');
define('SUPABASE_SERVICE_KEY', '***REMOVED_SUPABASE_KEY***');
// Resend.com — API de e-mail transacional
define('RESEND_API_KEY',       '***REMOVED_RESEND_KEY***');
define('SMTP_FROM',            'emagreser@danielydealbuquerque.com.br');
define('SMTP_FROM_NAME',       'Programa EmagreSer');
define('ZAPI_INSTANCE',        '***REMOVED_ZAPI_INSTANCE***');
define('ZAPI_TOKEN',           '***REMOVED_ZAPI_TOKEN***');
define('ZAPI_CLIENT_TOKEN',    '***REMOVED_ZAPI_CLIENT_TOKEN***');
define('ZAPI_URL',             'https://api.z-api.io/instances/' . ZAPI_INSTANCE . '/token/' . ZAPI_TOKEN);

if (($_GET['token'] ?? '') !== PANEL_TOKEN) {
    http_response_code(403); exit('Acesso negado. Use ?token=es2026admin');
}

$msg = ''; $msg_type = 'ok';

// ── AÇÕES ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'test_email') {
        $to   = trim($_POST['test_email_to'] ?? '');
        $name = trim($_POST['test_email_name'] ?? 'Teste');
        if ($to && filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $subject = 'Teste de envio — Programa EmagreSer';
            $body    = '<html><body style="font-family:Arial;padding:24px;background:#f4f3ef"><div style="max-width:500px;margin:0 auto;background:#fff;padding:28px;border-radius:10px"><h2 style="color:#0d9488">✅ E-mail funcionando!</h2><p>Olá, <strong>' . htmlspecialchars($name) . '</strong>!</p><p>Este é um teste da automação do <strong>Programa EmagreSer</strong>.</p><p style="color:#6b7c67;font-size:13px">Enviado em ' . date('d/m/Y H:i:s') . '</p></div></body></html>';
            $result  = send_smtp($to, $name, $subject, $body);
            $msg = $result['ok'] ? "✅ E-mail de teste enviado para {$to}" : "❌ Falha: " . $result['msg'];
            $msg_type = $result['ok'] ? 'ok' : 'fail';
        } else { $msg = '❌ E-mail inválido'; $msg_type = 'fail'; }
    }

    if ($action === 'test_wpp') {
        $phone = preg_replace('/\D/', '', $_POST['test_wpp_phone'] ?? '');
        $name  = trim($_POST['test_wpp_name'] ?? 'Teste');
        if (strlen($phone) >= 10) {
            $result = send_whatsapp('55' . $phone, "✅ *Teste EmagreSer*\n\nOlá, {$name}! A automação de WhatsApp está funcionando.\n\n_" . date('d/m/Y H:i') . "_");
            $msg = $result['ok'] ? "✅ WhatsApp enviado para {$phone}" : "❌ Falha: " . $result['msg'];
            $msg_type = $result['ok'] ? 'ok' : 'fail';
        } else { $msg = '❌ Telefone inválido'; $msg_type = 'fail'; }
    }

    if ($action === 'retry_failed') {
        sb_patch_raw("email_queue?status=eq.failed", ['status'=>'pending','attempts'=>0,'error_msg'=>null]);
        $msg = '✅ E-mails com falha recolocados na fila'; $msg_type = 'ok';
    }
    if ($action === 'retry_failed_wpp') {
        sb_patch_raw("whatsapp_queue?status=eq.failed", ['status'=>'pending','attempts'=>0,'error_msg'=>null]);
        $msg = '✅ WhatsApp com falha recolocados na fila'; $msg_type = 'ok';
    }
    if ($action === 'reset_stuck_wpp') {
        sb_patch_raw("whatsapp_queue?status=eq.processing", ['status'=>'pending','attempts'=>0]);
        $msg = '✅ WhatsApp travados resetados para fila'; $msg_type = 'ok';
    }
    if ($action === 'reset_stuck_email') {
        sb_patch_raw("email_queue?status=eq.processing", ['status'=>'pending','attempts'=>0]);
        $msg = '✅ E-mails travados resetados para fila'; $msg_type = 'ok';
    }

    if ($action === 'delete_email') {
        $id = sanitize_uuid($_POST['item_id'] ?? '');
        if ($id) { sb_delete("email_queue?id=eq.{$id}"); $msg = '🗑️ E-mail removido da fila'; }
    }
    if ($action === 'delete_wpp') {
        $id = sanitize_uuid($_POST['item_id'] ?? '');
        if ($id) { sb_delete("whatsapp_queue?id=eq.{$id}"); $msg = '🗑️ WhatsApp removido da fila'; }
    }
    if ($action === 'delete_all_failed_email') {
        sb_delete("email_queue?status=eq.failed"); $msg = '🗑️ E-mails com falha excluídos';
    }
    if ($action === 'delete_all_failed_wpp') {
        sb_delete("whatsapp_queue?status=eq.failed"); $msg = '🗑️ WhatsApp com falha excluídos';
    }
    if ($action === 'delete_all_pending_email') {
        sb_delete("email_queue?status=eq.pending"); $msg = '🗑️ Fila de e-mails limpa';
    }
    if ($action === 'delete_all_pending_wpp') {
        sb_delete("whatsapp_queue?status=eq.pending"); $msg = '🗑️ Fila de WhatsApp limpa';
    }

    if ($action === 'activate_leads') {
        $unqueued = sb_get("leads?sequence_queued_at=is.null&select=id,name,email,phone,sabotador&limit=100");
        $count = 0;
        foreach ($unqueued as $lead) {
            if (empty($lead['email'])) continue;
            $ch = curl_init((isset($_SERVER['HTTPS'])?'https':'http').'://'.$_SERVER['HTTP_HOST'].dirname($_SERVER['REQUEST_URI']).'/email_trigger.php');
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['lead_id'=>$lead['id'],'event'=>'new_lead']),CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_TIMEOUT=>10]);
            curl_exec($ch); curl_close($ch);
            $count++; usleep(100000);
        }
        $msg = "✅ Automação ativada para {$count} leads"; $msg_type = 'ok';
    }

    // ── FORÇAR ENVIO INDIVIDUAL ──────────────────────────────────────
    if ($action === 'force_send_email') {
        $id = sanitize_uuid($_POST['item_id'] ?? '');
        if ($id) {
            $items = sb_get("email_queue?id=eq.{$id}&limit=1");
            if (!empty($items)) {
                $item = $items[0];
                $tpls = sb_get("email_templates?slug=eq." . rawurlencode($item['template_slug']) . "&limit=1");
                if (!empty($tpls)) {
                    $tpl  = $tpls[0];
                    $vars = [
                        '{{nome}}'             => htmlspecialchars($item['to_name'] ?? 'você'),
                        '{{link_descadastro}}' => 'https://emagreser.danielydealbuquerque.com.br/descadastro.php?email='.urlencode($item['to_email']),
                    ];
                    $subject = str_replace(array_keys($vars), array_values($vars), $tpl['subject']);
                    $body    = str_replace(array_keys($vars), array_values($vars), $tpl['body_html']);
                    if (stripos($body,'<html')===false) $body='<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"></head><body>'.$body.'</body></html>';
                    $result = send_smtp($item['to_email'], $item['to_name'] ?? '', $subject, $body);
                    if ($result['ok']) {
                        $now = gmdate('Y-m-d\TH:i:s\Z');
                        sb_patch("email_queue?id=eq.{$id}", ['status'=>'sent','sent_at'=>$now]);
                        sb_post('email_log',[['queue_id'=>$id,'lead_id'=>$item['lead_id'],'to_email'=>$item['to_email'],'template_slug'=>$item['template_slug'],'smtp_response'=>'forced']]);
                        $msg = "✅ E-mail forçado: {$item['to_email']} [{$item['template_slug']}]"; $msg_type = 'ok';
                    } else {
                        sb_patch("email_queue?id=eq.{$id}", ['status'=>'failed','error_msg'=>$result['msg'],'attempts'=>($item['attempts']+1)]);
                        $msg = "❌ Falha: " . $result['msg']; $msg_type = 'fail';
                    }
                } else { $msg = '❌ Template não encontrado'; $msg_type = 'fail'; }
            }
        }
    }

    if ($action === 'force_send_wpp') {
        $id = sanitize_uuid($_POST['item_id'] ?? '');
        if ($id) {
            $items = sb_get("whatsapp_queue?id=eq.{$id}&limit=1");
            if (!empty($items)) {
                $item   = $items[0];
                $result = send_whatsapp($item['to_phone'], $item['message']);
                if ($result['ok']) {
                    $now = gmdate('Y-m-d\TH:i:s\Z');
                    sb_patch("whatsapp_queue?id=eq.{$id}", ['status'=>'sent','sent_at'=>$now]);
                    $msg = "✅ WhatsApp forçado para {$item['to_phone']}"; $msg_type = 'ok';
                } else {
                    sb_patch("whatsapp_queue?id=eq.{$id}", ['status'=>'failed','error_msg'=>$result['msg'],'attempts'=>($item['attempts']+1)]);
                    $msg = "❌ Falha: " . $result['msg']; $msg_type = 'fail';
                }
            }
        }
    }

    // ── PROCESSAR FILA AGORA (roda o worker inline) ──────────────────
    if ($action === 'process_now') {
        // gmdate evita o '+' do timezone no URL que quebra a query Supabase
        $now_iso  = gmdate('Y-m-d\TH:i:s\Z');
        $pending  = sb_get("email_queue?status=eq.pending&scheduled_at=lte.{$now_iso}&attempts=lt.3&order=scheduled_at.asc&limit=20");
        $sent_c = 0; $fail_c = 0;
        if (is_list($pending) || empty($pending)) {
            foreach ($pending as $item) {
                if (!is_array($item) || !isset($item['id'])) continue;
                sb_patch("email_queue?id=eq.{$item['id']}", ['attempts'=>($item['attempts']+1)]);
                $tpls = sb_get("email_templates?slug=eq.".rawurlencode($item['template_slug'])."&limit=1");
                if (!is_list($tpls) || empty($tpls)) { sb_patch("email_queue?id=eq.{$item['id']}", ['status'=>'failed','error_msg'=>'template not found']); $fail_c++; continue; }
                $tpl  = $tpls[0];
                $vars = ['{{nome}}'=>htmlspecialchars($item['to_name']??'você'),'{{link_descadastro}}'=>'https://emagreser.danielydealbuquerque.com.br/descadastro.php?email='.urlencode($item['to_email'])];
                $subject = str_replace(array_keys($vars),array_values($vars),$tpl['subject']);
                $body    = str_replace(array_keys($vars),array_values($vars),$tpl['body_html']);
                if (stripos($body,'<html')===false) $body='<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"></head><body>'.$body.'</body></html>';
                $result = send_smtp($item['to_email'], $item['to_name']??'', $subject, $body);
                if ($result['ok']) {
                    sb_patch("email_queue?id=eq.{$item['id']}", ['status'=>'sent','sent_at'=>$now_iso]);
                    sb_post('email_log',[['queue_id'=>$item['id'],'lead_id'=>$item['lead_id'],'to_email'=>$item['to_email'],'template_slug'=>$item['template_slug'],'smtp_response'=>'process_now']]);
                    $sent_c++;
                } else {
                    $status = $item['attempts'] >= 2 ? 'failed' : 'pending';
                    sb_patch("email_queue?id=eq.{$item['id']}", ['status'=>$status,'error_msg'=>$result['msg']]);
                    $fail_c++;
                }
                usleep(300000);
            }
        }
        $wpp_pending = sb_get("whatsapp_queue?status=eq.pending&scheduled_at=lte.{$now_iso}&attempts=lt.3&order=scheduled_at.asc&limit=10");
        $wpp_sent_c = 0;
        if (is_list($wpp_pending) || empty($wpp_pending)) {
            foreach ($wpp_pending as $item) {
                if (!is_array($item) || !isset($item['id'])) continue;
                sb_patch("whatsapp_queue?id=eq.{$item['id']}", ['attempts'=>($item['attempts']+1)]);
                $result = send_whatsapp($item['to_phone'], $item['message']);
                if ($result['ok']) {
                    sb_patch("whatsapp_queue?id=eq.{$item['id']}", ['status'=>'sent','sent_at'=>$now_iso]);
                    $wpp_sent_c++;
                } else {
                    $status = $item['attempts'] >= 2 ? 'failed' : 'pending';
                    sb_patch("whatsapp_queue?id=eq.{$item['id']}", ['status'=>$status,'error_msg'=>$result['msg']]);
                }
                usleep(300000);
            }
        }
        // Confirmação real: conta quantos foram gravados no email_log agora
        $confirmed_c = count(sb_get("email_log?created_at=gte.{$now_iso}&select=id"));
        if ($sent_c === 0 && $fail_c === 0 && $wpp_sent_c === 0) {
            $msg = "ℹ️ Nenhum item na fila estava vencido agora. Use ⚡ Forçar em itens individuais se precisar enviar antes do horário.";
            $msg_type = 'ok';
        } else {
            $msg = "✅ Enviados via Resend: {$sent_c} e-mail(s) | Confirmado no log: {$confirmed_c} | Falhas: {$fail_c} | WPP: {$wpp_sent_c}";
            if ($fail_c > 0 && $sent_c === 0) $msg_type = 'fail';
            elseif ($confirmed_c < $sent_c) { $msg .= " ⚠️ Diferença entre aceito e confirmado — verifique spam ou logs do servidor."; $msg_type = 'warn'; }
            else $msg_type = 'ok';
        }
    }
}

// ── DADOS ─────────────────────────────────────────────────────────────
$leads_total   = count(sb_get("leads?select=id"));
$leads_queued  = count(sb_get("leads?sequence_queued_at=not.is.null&select=id"));
$leads_pending = $leads_total - $leads_queued;
$email_sent    = count(sb_get("email_queue?status=eq.sent&select=id"));
$email_pend    = count(sb_get("email_queue?status=eq.pending&select=id"));
$email_fail    = count(sb_get("email_queue?status=eq.failed&select=id"));
$wpp_sent      = count(sb_get("whatsapp_queue?status=eq.sent&select=id"));
$wpp_pend      = count(sb_get("whatsapp_queue?status=eq.pending&select=id"));
$wpp_fail      = count(sb_get("whatsapp_queue?status=eq.failed&select=id"));

$email_stuck   = count(sb_get("email_queue?status=eq.processing&select=id"));
$wpp_stuck     = count(sb_get("whatsapp_queue?status=eq.processing&select=id"));

$recent_sent   = sb_get("email_log?select=to_email,template_slug,sent_at,smtp_response&order=sent_at.desc&limit=20");
$recent_fail   = sb_get("email_queue?status=eq.failed&select=id,to_email,to_name,template_slug,error_msg,attempts,created_at&order=created_at.desc&limit=20");
$wpp_fail_list = sb_get("whatsapp_queue?status=eq.failed&select=id,to_phone,message,error_msg,attempts,created_at&order=created_at.desc&limit=20");

// Fila completa para agrupar por lead
$all_email_q   = sb_get("email_queue?status=eq.pending&select=id,to_email,to_name,lead_id,template_slug,scheduled_at,attempts&order=scheduled_at.asc&limit=200");
$all_wpp_q     = sb_get("whatsapp_queue?status=eq.pending&select=id,to_phone,lead_id,message,scheduled_at,attempts&order=scheduled_at.asc&limit=200");

// Agrupa por lead (garante que é lista válida — descarta erros do Supabase)
function is_list(array $a): bool { return !empty($a) && array_keys($a) === range(0, count($a)-1); }
$email_by_lead = [];
if (is_list($all_email_q) || empty($all_email_q)) {
    foreach ($all_email_q as $r) {
        if (!is_array($r) || !isset($r['to_email'])) continue;
        $k = $r['to_email'];
        if (!isset($email_by_lead[$k])) $email_by_lead[$k] = ['name'=>$r['to_name']??$k,'email'=>$k,'items'=>[]];
        $email_by_lead[$k]['items'][] = $r;
    }
}
$wpp_by_lead = [];
if (is_list($all_wpp_q) || empty($all_wpp_q)) {
    foreach ($all_wpp_q as $r) {
        if (!is_array($r) || !isset($r['to_phone'])) continue;
        $k = $r['to_phone'];
        if (!isset($wpp_by_lead[$k])) $wpp_by_lead[$k] = ['name'=>$k,'phone'=>$k,'items'=>[]];
        $wpp_by_lead[$k]['items'][] = $r;
    }
}

// Diagnóstico do worker
$log_file   = __DIR__ . '/email_worker.log';
$log_exists = file_exists($log_file);
$log_last   = $log_exists ? file_get_contents($log_file) : '';
$log_lines  = $log_last ? array_slice(array_filter(explode("\n", trim($log_last))), -5) : [];
$now_ts = time();
$log_age_ok = false;
if ($log_exists) {
    $log_age_ok = ($now_ts - filemtime($log_file)) < 1800; // rodou nos últimos 30min
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Painel Automação — EmagreSer</title>
<style>
*{box-sizing:border-box}
body{margin:0;padding:20px;background:#f4f3ef;font-family:Arial,sans-serif;color:#1a2318;font-size:14px}
.wrap{max-width:980px;margin:0 auto}
.header{background:#0f172a;color:#fff;border-radius:12px;padding:18px 24px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between}
.header h1{margin:0;font-size:17px;color:#5eead4}
.header span{font-size:12px;color:#94a3b8}
.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px}
@media(max-width:640px){.cards{grid-template-columns:repeat(2,1fr)}}
.card{background:#fff;border-radius:10px;padding:16px;border:1px solid #ede9e2;text-align:center}
.card .n{font-size:28px;font-weight:700;color:#2d6a4f}
.card .n.red{color:#dc2626}.card .n.amber{color:#d97706}
.card .l{font-size:11px;color:#6b7c67;margin-top:4px}
.section{background:#fff;border-radius:10px;border:1px solid #ede9e2;margin-bottom:14px;overflow:hidden}
.section-hdr{background:#f8fafc;padding:11px 16px;font-weight:700;font-size:13px;border-bottom:1px solid #ede9e2;display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap}
table{width:100%;border-collapse:collapse}
th{background:#2d6a4f;color:#fff;padding:8px 10px;font-size:11px;text-align:left;font-weight:600}
td{padding:7px 10px;font-size:13px;border-bottom:1px solid #f4f3ef;vertical-align:middle}
tr:last-child td{border-bottom:none}
.ok{color:#2d6a4f;font-weight:700}.fail{color:#dc2626;font-weight:700}
.tag{display:inline-block;background:#e2f4f1;color:#0d6e63;padding:2px 7px;border-radius:10px;font-size:11px;font-weight:600}
.alert{padding:11px 16px;margin-bottom:14px;border-radius:8px;font-weight:600;font-size:13px}
.alert.ok{background:#d1fae5;color:#065f46;border:1px solid #6ee7b7}
.alert.fail{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}
.alert.warn{background:#fef9c3;color:#854d0e;border:1px solid #fde047}
.forms-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px}
@media(max-width:600px){.forms-grid{grid-template-columns:1fr}}
.form-card{background:#fff;border-radius:10px;border:1px solid #ede9e2;padding:16px}
.form-card h3{margin:0 0 12px;font-size:13px;color:#0f172a}
.form-card input{width:100%;padding:7px 9px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;margin-bottom:7px}
.btn{padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer;width:100%}
.btn-green{background:#0d9488;color:#fff}.btn-green:hover{background:#0b7a6f}
.btn-amber{background:#d97706;color:#fff}.btn-amber:hover{background:#b45309}
.btn-blue{background:#1d4ed8;color:#fff}.btn-blue:hover{background:#1e3a8a}
.btn-red{background:#dc2626;color:#fff;font-size:11px;padding:4px 9px;border-radius:5px;border:none;cursor:pointer}
.btn-red:hover{background:#b91c1c}
.btn-force{background:#7c3aed;color:#fff;font-size:11px;padding:4px 9px;border-radius:5px;border:none;cursor:pointer}
.btn-force:hover{background:#6d28d9}
.btn-sm{font-size:11px;padding:4px 10px;width:auto}

/* ACORDEÃO DE LEADS */
.lead-row{cursor:pointer;background:#f0fdf4;transition:background .15s}
.lead-row:hover{background:#dcfce7}
.lead-row td{font-weight:700;font-size:13px;padding:9px 10px}
.lead-row .toggle-icon{font-size:11px;color:#6b7c67;margin-left:6px}
.detail-rows{display:none}
.detail-rows.open{display:table-row-group}
.detail-row td{background:#fafafa;padding:6px 10px 6px 24px;font-size:12px;border-bottom:1px solid #f0f0f0}
.detail-actions{display:flex;gap:6px;align-items:center}

/* DIAGNÓSTICO */
.diag{background:#fff;border-radius:10px;border:1px solid #ede9e2;padding:14px 18px;margin-bottom:14px;font-size:12px}
.diag h4{margin:0 0 8px;font-size:13px;font-weight:700;color:#0f172a}
.diag-row{display:flex;align-items:center;gap:8px;margin-bottom:5px}
.diag-ok{color:#059669;font-weight:700}.diag-warn{color:#d97706;font-weight:700}.diag-fail{color:#dc2626;font-weight:700}
.log-pre{background:#1e293b;color:#a3e635;font-family:monospace;font-size:11px;padding:10px;border-radius:6px;margin-top:8px;max-height:120px;overflow-y:auto;white-space:pre-wrap;word-break:break-all}

.refresh-bar{background:#1e293b;color:#64748b;text-align:center;font-size:11px;padding:5px;position:sticky;bottom:0}
</style>
</head>
<body>
<div class="wrap">
  <div class="header">
    <h1>📊 Painel de Automação — EmagreSer</h1>
    <span><?= date('d/m/Y H:i') ?></span>
  </div>

  <?php if ($msg): ?>
  <div class="alert <?= $msg_type ?>"><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>

  <!-- DIAGNÓSTICO DO WORKER -->
  <div class="diag">
    <h4>🔧 Diagnóstico do Worker</h4>
    <div class="diag-row">
      <?php if ($log_exists && $log_age_ok): ?>
        <span class="diag-ok">✅</span> Cron rodou nos últimos 30 minutos
      <?php elseif ($log_exists): ?>
        <span class="diag-warn">⚠️</span> Log encontrado mas desatualizado — cron pode estar parado
      <?php else: ?>
        <span class="diag-fail">❌</span> Nenhum log encontrado — cron provavelmente não está configurado
      <?php endif; ?>
    </div>
    <div class="diag-row">
      <?php $resend_ok = defined('RESEND_API_KEY') && RESEND_API_KEY !== 'COLOQUE_SUA_RESEND_API_KEY_AQUI' && strlen(RESEND_API_KEY) > 10; ?>
      <?= $resend_ok ? '<span class="diag-ok">✅</span> Resend API Key configurada' : '<span class="diag-fail">❌</span> Resend API Key não configurada — coloque em painel.php e email_worker.php' ?>
    </div>
    <div style="display:flex;gap:10px;align-items:center;margin-top:10px;flex-wrap:wrap">
      <form method="post" style="margin:0">
        <input type="hidden" name="action" value="process_now">
        <button type="submit" class="btn btn-blue" style="width:auto;padding:7px 18px;font-size:13px">⚡ Processar fila agora</button>
      </form>
      <span style="font-size:11px;color:#6b7c67">Dispara envios pendentes imediatamente, sem esperar o cron.</span>
    </div>
    <?php if ($log_lines): ?>
    <div class="log-pre"><?= htmlspecialchars(implode("\n", $log_lines)) ?></div>
    <?php endif; ?>
    <div style="margin-top:10px;font-size:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px 14px">
      <strong>Como configurar o cron no Hostinger:</strong><br><br>
      1. Painel Hostinger → <strong>Sites → Avançado → Cron Jobs</strong><br>
      2. Selecione o tipo <strong style="color:#7c3aed">PHP</strong> (não "Personalizado")<br>
      3. No campo comando coloque apenas: <code style="background:#e2e8f0;padding:2px 5px;border-radius:3px">public_html/email_worker.php</code><br>
      4. Frequência: selecione <strong>"A cada 10 minutos"</strong> nas Opções Comuns<br>
      5. Salvar<br><br>
      <span style="color:#dc2626">⚠️ Não use o tipo "Personalizado" — ele tenta executar o arquivo diretamente sem o PHP e gera erro "No such file or directory".</span>
    </div>
  </div>

  <!-- MANUTENÇÃO DA FILA — sempre visível -->
  <div class="section">
    <div class="section-hdr">🔧 Manutenção da Fila</div>
    <div style="padding:14px;display:grid;grid-template-columns:1fr 1fr;gap:14px">

      <!-- E-MAIL -->
      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px">
        <div style="font-weight:700;font-size:13px;margin-bottom:10px">📧 E-mail</div>
        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px">
          <span style="font-size:12px">Travados: <strong style="color:<?= $email_stuck>0?'#d97706':'#059669' ?>"><?= $email_stuck ?></strong></span>
          <span style="font-size:12px">Com falha: <strong style="color:<?= $email_fail>0?'#dc2626':'#059669' ?>"><?= $email_fail ?></strong></span>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:6px">
          <form method="post" style="margin:0"><input type="hidden" name="action" value="reset_stuck_email"><button type="submit" class="btn btn-amber btn-sm" <?= $email_stuck==0?'disabled style="opacity:.45"':'' ?>>↺ Resetar travados</button></form>
          <form method="post" style="margin:0"><input type="hidden" name="action" value="retry_failed"><button type="submit" class="btn btn-amber btn-sm" <?= $email_fail==0?'disabled style="opacity:.45"':'' ?>>↺ Retentar falhas</button></form>
          <form method="post" style="margin:0" onsubmit="return confirm('Excluir todos com falha?')"><input type="hidden" name="action" value="delete_all_failed_email"><button type="submit" class="btn btn-red" <?= $email_fail==0?'disabled style="opacity:.45"':'' ?>>🗑️ Excluir falhas</button></form>
        </div>
      </div>

      <!-- WHATSAPP -->
      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px">
        <div style="font-weight:700;font-size:13px;margin-bottom:10px">💬 WhatsApp</div>
        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px">
          <span style="font-size:12px">Travados: <strong style="color:<?= $wpp_stuck>0?'#d97706':'#059669' ?>"><?= $wpp_stuck ?></strong></span>
          <span style="font-size:12px">Com falha: <strong style="color:<?= $wpp_fail>0?'#dc2626':'#059669' ?>"><?= $wpp_fail ?></strong></span>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:6px">
          <form method="post" style="margin:0"><input type="hidden" name="action" value="reset_stuck_wpp"><button type="submit" class="btn btn-amber btn-sm" <?= $wpp_stuck==0?'disabled style="opacity:.45"':'' ?>>↺ Resetar travados</button></form>
          <form method="post" style="margin:0"><input type="hidden" name="action" value="retry_failed_wpp"><button type="submit" class="btn btn-amber btn-sm" <?= $wpp_fail==0?'disabled style="opacity:.45"':'' ?>>↺ Retentar falhas</button></form>
          <form method="post" style="margin:0" onsubmit="return confirm('Excluir todos com falha?')"><input type="hidden" name="action" value="delete_all_failed_wpp"><button type="submit" class="btn btn-red" <?= $wpp_fail==0?'disabled style="opacity:.45"':'' ?>>🗑️ Excluir falhas</button></form>
        </div>
      </div>

    </div>
  </div>

  <!-- CARDS STATS -->
  <div class="cards">
    <div class="card"><div class="n"><?= $leads_total ?></div><div class="l">Leads cadastradas</div></div>
    <div class="card"><div class="n"><?= $leads_queued ?></div><div class="l">Na automação</div></div>
    <?php if($leads_pending > 0): ?>
    <div class="card" style="grid-column:span 2;background:#fef9c3;border-color:#fde047">
      <div class="n amber"><?= $leads_pending ?></div>
      <div class="l" style="color:#854d0e"><?= $leads_pending ?> lead(s) sem automação</div>
      <form method="post" style="margin-top:8px">
        <input type="hidden" name="action" value="activate_leads">
        <button type="submit" class="btn btn-blue" style="width:auto;padding:6px 14px;font-size:12px">⚡ Ativar automação para todas</button>
      </form>
    </div>
    <?php endif; ?>
    <div class="card"><div class="n"><?= $email_sent ?></div><div class="l">E-mails enviados</div></div>
    <div class="card"><div class="n amber"><?= $email_pend ?></div><div class="l">E-mails pendentes</div></div>
    <div class="card"><div class="n <?= $email_fail>0?'red':'' ?>"><?= $email_fail ?></div><div class="l">E-mails com falha</div></div>
    <div class="card"><div class="n"><?= $wpp_sent ?></div><div class="l">WPP enviados</div></div>
    <div class="card"><div class="n amber"><?= $wpp_pend ?></div><div class="l">WPP pendentes</div></div>
    <div class="card"><div class="n <?= $wpp_fail>0?'red':'' ?>"><?= $wpp_fail ?></div><div class="l">WPP com falha</div></div>
  </div>

  <!-- FORMULÁRIOS DE TESTE -->
  <div class="forms-grid">
    <div class="form-card">
      <h3>📧 Enviar E-mail de Teste</h3>
      <form method="post">
        <input type="hidden" name="action" value="test_email">
        <input type="email" name="test_email_to" placeholder="E-mail destinatário *" required>
        <input type="text" name="test_email_name" placeholder="Nome" value="Daniely">
        <button type="submit" class="btn btn-green">Enviar agora</button>
      </form>
    </div>
    <div class="form-card">
      <h3>💬 Enviar WhatsApp de Teste</h3>
      <form method="post">
        <input type="hidden" name="action" value="test_wpp">
        <input type="tel" name="test_wpp_phone" placeholder="Telefone sem +55 (ex: 85998765432) *" required>
        <input type="text" name="test_wpp_name" placeholder="Nome" value="Daniely">
        <button type="submit" class="btn btn-green">Enviar agora</button>
      </form>
    </div>
  </div>

  <!-- E-MAILS COM FALHA — sempre visível -->
  <div class="section">
    <div class="section-hdr">
      <?php if ($email_fail > 0): ?>⚠️ <?= $email_fail ?> e-mail(s) com falha<?php else: ?>✅ E-mails com falha (nenhum)<?php endif; ?>
      <?php if ($email_fail > 0): ?>
      <div style="display:flex;gap:8px">
        <form method="post" style="margin:0"><input type="hidden" name="action" value="retry_failed"><button type="submit" class="btn btn-amber btn-sm">↺ Retentar todos</button></form>
        <form method="post" style="margin:0" onsubmit="return confirm('Excluir todos com falha?')"><input type="hidden" name="action" value="delete_all_failed_email"><button type="submit" class="btn btn-red">🗑️ Excluir todos</button></form>
      </div>
      <?php endif; ?>
    </div>
    <?php if (empty($recent_fail)): ?>
    <div style="padding:14px;color:#059669;text-align:center;font-size:13px">Nenhum e-mail com falha</div>
    <?php else: ?>
    <table>
      <tr><th>E-mail</th><th>Template</th><th>Tentativas</th><th>Erro</th><th>Ações</th></tr>
      <?php foreach ($recent_fail as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['to_email']) ?></td>
        <td><span class="tag"><?= htmlspecialchars($r['template_slug']) ?></span></td>
        <td><?= intval($r['attempts']) ?></td>
        <td style="color:#dc2626;font-size:12px;max-width:180px"><?= htmlspecialchars(substr($r['error_msg']??'',0,70)) ?></td>
        <td>
          <div class="detail-actions">
            <form method="post" style="margin:0"><input type="hidden" name="action" value="force_send_email"><input type="hidden" name="item_id" value="<?= htmlspecialchars($r['id']) ?>"><button type="submit" class="btn-force" onclick="return confirm('Forçar envio deste e-mail agora?')">⚡ Forçar</button></form>
            <form method="post" style="margin:0" onsubmit="return confirm('Excluir?')"><input type="hidden" name="action" value="delete_email"><input type="hidden" name="item_id" value="<?= htmlspecialchars($r['id']) ?>"><button type="submit" class="btn-red">🗑️</button></form>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>

  <!-- WHATSAPP COM FALHA — sempre visível -->
  <div class="section">
    <div class="section-hdr">
      <?php if ($wpp_fail > 0): ?>⚠️ <?= $wpp_fail ?> WhatsApp(s) com falha<?php else: ?>✅ WhatsApp com falha (nenhum)<?php endif; ?>
      <?php if ($wpp_fail > 0): ?>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <form method="post" style="margin:0"><input type="hidden" name="action" value="retry_failed_wpp"><button type="submit" class="btn btn-amber btn-sm">↺ Retentar todos</button></form>
        <form method="post" style="margin:0" onsubmit="return confirm('Excluir todos com falha?')"><input type="hidden" name="action" value="delete_all_failed_wpp"><button type="submit" class="btn btn-red">🗑️ Excluir todos</button></form>
      </div>
      <?php endif; ?>
    </div>
    <?php if (empty($wpp_fail_list)): ?>
    <div style="padding:14px;color:#059669;text-align:center;font-size:13px">Nenhum WhatsApp com falha</div>
    <?php else: ?>
    <table>
      <tr><th>Telefone</th><th>Mensagem</th><th>Tentativas</th><th>Erro</th><th>Ações</th></tr>
      <?php foreach ($wpp_fail_list as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['to_phone']) ?></td>
        <td style="font-size:12px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars(substr($r['message']??'',0,50)) ?>…</td>
        <td><?= intval($r['attempts']) ?></td>
        <td style="color:#dc2626;font-size:12px"><?= htmlspecialchars(substr($r['error_msg']??'',0,70)) ?></td>
        <td>
          <div class="detail-actions">
            <form method="post" style="margin:0"><input type="hidden" name="action" value="force_send_wpp"><input type="hidden" name="item_id" value="<?= htmlspecialchars($r['id']) ?>"><button type="submit" class="btn-force" onclick="return confirm('Forçar envio deste WPP agora?')">⚡ Forçar</button></form>
            <form method="post" style="margin:0" onsubmit="return confirm('Excluir?')"><input type="hidden" name="action" value="delete_wpp"><input type="hidden" name="item_id" value="<?= htmlspecialchars($r['id']) ?>"><button type="submit" class="btn-red">🗑️</button></form>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>

  <!-- FILA DE E-MAILS AGRUPADA POR LEAD -->
  <div class="section">
    <div class="section-hdr">⏳ Fila de E-mails — <?= count($email_by_lead) ?> lead(s), <?= $email_pend ?> mensagem(ns)
      <?php if (!empty($email_by_lead)): ?>
      <div style="display:flex;gap:8px">
        <button type="button" onclick="toggleAll('email')" class="btn btn-amber btn-sm" style="width:auto">± Expandir todos</button>
        <form method="post" style="margin:0" onsubmit="return confirm('Limpar toda a fila de e-mails?')">
          <input type="hidden" name="action" value="delete_all_pending_email">
          <button type="submit" class="btn btn-red">🗑️ Limpar fila</button>
        </form>
      </div>
      <?php endif; ?>
    </div>
    <?php if (empty($email_by_lead)): ?>
    <div style="padding:14px;color:#6b7c67;text-align:center">Nenhum e-mail pendente</div>
    <?php else: ?>
    <table>
      <tr><th>Lead</th><th>Mensagens</th><th>Próximo envio</th><th>Ações</th></tr>
      <?php foreach ($email_by_lead as $key => $group):
        $gid = 'eg-' . md5($key);
        $next = $group['items'][0]['scheduled_at'] ?? '';
        $next_dt = $next ? substr($next,0,16) : '—';
        $overdue = $next && strtotime($next) < time();
      ?>
      <tr class="lead-row" onclick="toggleGroup('<?= $gid ?>')">
        <td>
          <?= htmlspecialchars($group['name']) ?>
          <span style="font-size:11px;font-weight:400;color:#6b7c67;display:block"><?= htmlspecialchars($key) ?></span>
        </td>
        <td><?= count($group['items']) ?> e-mail(s) <span class="toggle-icon" id="icon-<?= $gid ?>">▶</span></td>
        <td style="<?= $overdue?'color:#dc2626;font-weight:700':'' ?>"><?= $next_dt ?><?= $overdue?' ⚠️ atrasado':'' ?></td>
        <td></td>
      </tr>
      <tbody id="<?= $gid ?>" class="detail-rows">
        <?php foreach ($group['items'] as $r): ?>
        <tr class="detail-row">
          <td colspan="2"><span class="tag"><?= htmlspecialchars($r['template_slug']) ?></span></td>
          <td><?= substr($r['scheduled_at']??'',0,16) ?></td>
          <td>
            <div class="detail-actions">
              <form method="post" style="margin:0"><input type="hidden" name="action" value="force_send_email"><input type="hidden" name="item_id" value="<?= htmlspecialchars($r['id']) ?>"><button type="submit" class="btn-force" onclick="return confirm('Forçar envio agora?')">⚡ Forçar</button></form>
              <form method="post" style="margin:0" onsubmit="return confirm('Remover da fila?')"><input type="hidden" name="action" value="delete_email"><input type="hidden" name="item_id" value="<?= htmlspecialchars($r['id']) ?>"><button type="submit" class="btn-red">🗑️</button></form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>

  <!-- LOG DE ENVIADOS -->
  <div class="section">
    <div class="section-hdr">✅ Últimos E-mails Enviados</div>
    <?php if (empty($recent_sent)): ?>
    <div style="padding:14px;color:#6b7c67;text-align:center">Nenhum e-mail enviado ainda</div>
    <?php else: ?>
    <table>
      <tr><th>E-mail</th><th>Template</th><th>Enviado em</th></tr>
      <?php foreach ($recent_sent as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['to_email']) ?></td>
        <td><span class="tag"><?= htmlspecialchars($r['template_slug']) ?></span></td>
        <td style="font-size:12px;color:#6b7c67"><?= substr($r['sent_at']??'',0,16) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>

  <!-- FILA DE WPP AGRUPADA POR LEAD -->
  <div class="section">
    <div class="section-hdr">💬 Fila de WhatsApp — <?= count($wpp_by_lead) ?> lead(s), <?= $wpp_pend ?> mensagem(ns)
      <?php if (!empty($wpp_by_lead)): ?>
      <div style="display:flex;gap:8px">
        <button type="button" onclick="toggleAll('wpp')" class="btn btn-amber btn-sm" style="width:auto">± Expandir todos</button>
        <form method="post" style="margin:0" onsubmit="return confirm('Limpar toda a fila de WhatsApp?')">
          <input type="hidden" name="action" value="delete_all_pending_wpp">
          <button type="submit" class="btn btn-red">🗑️ Limpar fila</button>
        </form>
      </div>
      <?php endif; ?>
    </div>
    <?php if (empty($wpp_by_lead)): ?>
    <div style="padding:14px;color:#6b7c67;text-align:center">Nenhum WhatsApp pendente</div>
    <?php else: ?>
    <table>
      <tr><th>Lead</th><th>Mensagens</th><th>Próximo envio</th><th>Ações</th></tr>
      <?php foreach ($wpp_by_lead as $key => $group):
        $gid = 'wg-' . md5($key);
        $next = $group['items'][0]['scheduled_at'] ?? '';
        $next_dt = $next ? substr($next,0,16) : '—';
        $overdue = $next && strtotime($next) < time();
      ?>
      <tr class="lead-row" onclick="toggleGroup('<?= $gid ?>')">
        <td>
          <?= htmlspecialchars($group['name']) ?>
          <span style="font-size:11px;font-weight:400;color:#6b7c67;display:block"><?= htmlspecialchars($key) ?></span>
        </td>
        <td><?= count($group['items']) ?> msg(s) <span class="toggle-icon" id="icon-<?= $gid ?>">▶</span></td>
        <td style="<?= $overdue?'color:#dc2626;font-weight:700':'' ?>"><?= $next_dt ?><?= $overdue?' ⚠️ atrasado':'' ?></td>
        <td></td>
      </tr>
      <tbody id="<?= $gid ?>" class="detail-rows">
        <?php foreach ($group['items'] as $r): ?>
        <tr class="detail-row">
          <td colspan="2" style="color:#374151;max-width:300px"><?= htmlspecialchars(substr($r['message']??'',0,80)) ?>…</td>
          <td><?= substr($r['scheduled_at']??'',0,16) ?></td>
          <td>
            <div class="detail-actions">
              <form method="post" style="margin:0"><input type="hidden" name="action" value="force_send_wpp"><input type="hidden" name="item_id" value="<?= htmlspecialchars($r['id']) ?>"><button type="submit" class="btn-force" onclick="return confirm('Forçar envio deste WPP agora?')">⚡ Forçar</button></form>
              <form method="post" style="margin:0" onsubmit="return confirm('Remover da fila?')"><input type="hidden" name="action" value="delete_wpp"><input type="hidden" name="item_id" value="<?= htmlspecialchars($r['id']) ?>"><button type="submit" class="btn-red">🗑️</button></form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>

</div>
<div class="refresh-bar">Atualização automática em <span id="countdown-bar">30</span>s &nbsp;·&nbsp; <?= date('H:i:s') ?></div>

<script>
// Acordeão
function toggleGroup(id) {
  const el = document.getElementById(id);
  const icon = document.getElementById('icon-' + id);
  if (!el) return;
  const open = el.classList.toggle('open');
  if (icon) icon.textContent = open ? '▼' : '▶';
}

function toggleAll(type) {
  const prefix = type === 'email' ? 'eg-' : 'wg-';
  const groups = document.querySelectorAll('[id^="' + prefix + '"]');
  const anyOpen = Array.from(groups).some(g => g.classList.contains('open'));
  groups.forEach(g => {
    const icon = document.getElementById('icon-' + g.id);
    if (anyOpen) { g.classList.remove('open'); if(icon) icon.textContent='▶'; }
    else         { g.classList.add('open');    if(icon) icon.textContent='▼'; }
  });
}

// Contagem regressiva
let cnt = 30;
function tick() {
  cnt--;
  const el = document.getElementById('countdown-bar');
  if (el) el.textContent = cnt + 's';
  if (cnt <= 0) location.reload();
  else setTimeout(tick, 1000);
}
setTimeout(tick, 1000);
</script>
</body>
</html>
<?php

// ── FUNÇÕES ───────────────────────────────────────────────────────────
function sanitize_uuid(string $s): string {
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s) ? $s : '';
}

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

function send_whatsapp(string $phone, string $message): array {
    $ch = curl_init(ZAPI_URL . '/send-text');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['phone'=>$phone,'message'=>$message]),CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Client-Token: '.ZAPI_CLIENT_TOKEN]]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $data = json_decode($res, true);
    return ($code===200 && !empty($data['zaapId'])) ? ['ok'=>true,'msg'=>'sent'] : ['ok'=>false,'msg'=>$res];
}

function sb_get(string $path): array {
    $ch = curl_init(SUPABASE_URL . '/rest/v1/' . $path);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['apikey: '.SUPABASE_SERVICE_KEY,'Authorization: Bearer '.SUPABASE_SERVICE_KEY,'Prefer: count=none']]);
    $res = curl_exec($ch); curl_close($ch);
    return json_decode($res, true) ?: [];
}

function sb_post(string $table, array $data): void {
    $ch = curl_init(SUPABASE_URL . '/rest/v1/' . $table);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($data),CURLOPT_HTTPHEADER=>['apikey: '.SUPABASE_SERVICE_KEY,'Authorization: Bearer '.SUPABASE_SERVICE_KEY,'Content-Type: application/json','Prefer: return=minimal']]);
    curl_exec($ch); curl_close($ch);
}

function sb_patch(string $path, array $data): void {
    $ch = curl_init(SUPABASE_URL . '/rest/v1/' . $path);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>'PATCH',CURLOPT_POSTFIELDS=>json_encode($data),CURLOPT_HTTPHEADER=>['apikey: '.SUPABASE_SERVICE_KEY,'Authorization: Bearer '.SUPABASE_SERVICE_KEY,'Content-Type: application/json','Prefer: return=minimal']]);
    curl_exec($ch); curl_close($ch);
}

function sb_patch_raw(string $path, array $data): void { sb_patch($path, $data); }

function sb_delete(string $path): void {
    $ch = curl_init(SUPABASE_URL . '/rest/v1/' . $path);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>'DELETE',CURLOPT_HTTPHEADER=>['apikey: '.SUPABASE_SERVICE_KEY,'Authorization: Bearer '.SUPABASE_SERVICE_KEY,'Prefer: return=minimal']]);
    curl_exec($ch); curl_close($ch);
}
