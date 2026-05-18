<?php
/**
 * painel.php — Painel de Automação EmagreSer
 * Acesso: /painel.php?token=es2026admin
 */

define('PANEL_TOKEN',          'es2026admin');
define('SUPABASE_URL',         'https://drgrwpmhmrrhxuwxabow.supabase.co');
define('SUPABASE_SERVICE_KEY', '***REMOVED_SUPABASE_KEY***');
define('SMTP_HOST',            'mail.hostinger.com');
define('SMTP_PORT',            587);
define('SMTP_USER',            'contato@emagreser.danielydealbuquerque.com.br');
define('SMTP_PASS',            'EmagreSer@2025!');
define('SMTP_FROM',            'contato@emagreser.danielydealbuquerque.com.br');
define('SMTP_FROM_NAME',       'Programa EmagreSer');
define('ZAPI_INSTANCE',        '***REMOVED_ZAPI_INSTANCE***');
define('ZAPI_TOKEN',           '***REMOVED_ZAPI_TOKEN***');
define('ZAPI_CLIENT_TOKEN',    '***REMOVED_ZAPI_CLIENT_TOKEN***');

if (($_GET['token'] ?? '') !== PANEL_TOKEN) {
    http_response_code(403);
    exit('Acesso negado. Use ?token=es2026admin');
}

$msg = '';
$msg_type = 'ok';

// ── AÇÕES DE TESTE ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'test_email') {
        $to   = trim($_POST['test_email_to'] ?? '');
        $name = trim($_POST['test_email_name'] ?? 'Teste');
        if ($to && filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $result = send_smtp_test($to, $name);
            $msg = $result['ok'] ? "✅ E-mail de teste enviado para {$to}" : "❌ Falha: " . $result['msg'];
            $msg_type = $result['ok'] ? 'ok' : 'fail';
        } else {
            $msg = '❌ E-mail inválido'; $msg_type = 'fail';
        }
    }

    if ($action === 'test_wpp') {
        $phone = preg_replace('/\D/', '', $_POST['test_wpp_phone'] ?? '');
        $name  = trim($_POST['test_wpp_name'] ?? 'Teste');
        if (strlen($phone) >= 10) {
            $result = send_zapi_test('55' . $phone, $name);
            $msg = $result['ok'] ? "✅ WhatsApp enviado para {$phone}" : "❌ Falha: " . $result['msg'];
            $msg_type = $result['ok'] ? 'ok' : 'fail';
        } else {
            $msg = '❌ Telefone inválido (mínimo 10 dígitos)'; $msg_type = 'fail';
        }
    }

    if ($action === 'retry_failed') {
        sb_patch_raw("email_queue?status=eq.failed", ['status' => 'pending', 'attempts' => 0, 'error_msg' => null]);
        $msg = '✅ E-mails com falha recolocados na fila'; $msg_type = 'ok';
    }

    if ($action === 'retry_failed_wpp') {
        sb_patch_raw("whatsapp_queue?status=eq.failed", ['status' => 'pending', 'attempts' => 0, 'error_msg' => null]);
        $msg = '✅ WhatsApp com falha recolocados na fila'; $msg_type = 'ok';
    }

    // ── EXCLUSÕES INDIVIDUAIS ─────────────────────────────────────
    if ($action === 'delete_email') {
        $id = preg_replace('/[^a-f0-9\-]/', '', $_POST['item_id'] ?? '');
        if ($id) { sb_delete("email_queue?id=eq.{$id}"); $msg = '🗑️ E-mail removido da fila'; $msg_type = 'ok'; }
    }

    if ($action === 'delete_wpp') {
        $id = preg_replace('/[^a-f0-9\-]/', '', $_POST['item_id'] ?? '');
        if ($id) { sb_delete("whatsapp_queue?id=eq.{$id}"); $msg = '🗑️ WhatsApp removido da fila'; $msg_type = 'ok'; }
    }

    // ── EXCLUSÕES EM LOTE ─────────────────────────────────────────
    if ($action === 'delete_all_failed_email') {
        sb_delete("email_queue?status=eq.failed");
        $msg = '🗑️ Todos os e-mails com falha foram excluídos'; $msg_type = 'ok';
    }

    if ($action === 'delete_all_failed_wpp') {
        sb_delete("whatsapp_queue?status=eq.failed");
        $msg = '🗑️ Todos os WhatsApp com falha foram excluídos'; $msg_type = 'ok';
    }

    if ($action === 'delete_all_pending_email') {
        sb_delete("email_queue?status=eq.pending");
        $msg = '🗑️ Todos os e-mails pendentes foram excluídos'; $msg_type = 'ok';
    }

    if ($action === 'delete_all_pending_wpp') {
        sb_delete("whatsapp_queue?status=eq.pending");
        $msg = '🗑️ Todos os WhatsApp pendentes foram excluídos'; $msg_type = 'ok';
    }

    if ($action === 'activate_leads') {
        $unqueued = sb_get("leads?sequence_queued_at=is.null&select=id,name,email,phone,sabotador&limit=100");
        $count = 0;
        foreach ($unqueued as $lead) {
            if (empty($lead['email'])) continue;
            $ch = curl_init((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/email_trigger.php');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode(['lead_id'=>$lead['id'],'event'=>'new_lead']), CURLOPT_HTTPHEADER=>['Content-Type: application/json'], CURLOPT_TIMEOUT=>10]);
            curl_exec($ch); curl_close($ch);
            $count++;
            usleep(100000);
        }
        $msg = "✅ Automação ativada para {$count} leads"; $msg_type = 'ok';
    }
}

// ── ESTATÍSTICAS ──────────────────────────────────────────────────
$leads_total    = count(sb_get("leads?select=id"));
$leads_queued   = count(sb_get("leads?sequence_queued_at=not.is.null&select=id"));
$leads_pending  = $leads_total - $leads_queued;
$email_sent    = count(sb_get("email_queue?status=eq.sent&select=id"));
$email_pend    = count(sb_get("email_queue?status=eq.pending&select=id"));
$email_fail    = count(sb_get("email_queue?status=eq.failed&select=id"));
$wpp_sent      = count(sb_get("whatsapp_queue?status=eq.sent&select=id"));
$wpp_pend      = count(sb_get("whatsapp_queue?status=eq.pending&select=id"));
$wpp_fail      = count(sb_get("whatsapp_queue?status=eq.failed&select=id"));

$recent_sent   = sb_get("email_log?select=to_email,template_slug,sent_at,smtp_response&order=sent_at.desc&limit=15");
$recent_fail   = sb_get("email_queue?status=eq.failed&select=id,to_email,template_slug,error_msg,attempts,created_at&order=created_at.desc&limit=10");
$next_emails   = sb_get("email_queue?status=eq.pending&select=id,to_email,template_slug,scheduled_at&order=scheduled_at.asc&limit=10");
$wpp_fail_list = sb_get("whatsapp_queue?status=eq.failed&select=id,to_phone,message,error_msg,attempts,created_at&order=created_at.desc&limit=10");
$wpp_next      = sb_get("whatsapp_queue?status=eq.pending&select=id,to_phone,message,scheduled_at&order=scheduled_at.asc&limit=10");
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
.wrap{max-width:960px;margin:0 auto}
.header{background:#0f172a;color:#fff;border-radius:12px;padding:20px 24px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between}
.header h1{margin:0;font-size:18px;color:#5eead4}
.header span{font-size:12px;color:#94a3b8}
.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
@media(max-width:640px){.cards{grid-template-columns:repeat(2,1fr)}}
.card{background:#fff;border-radius:10px;padding:18px;border:1px solid #ede9e2;text-align:center}
.card .n{font-size:30px;font-weight:700;color:#2d6a4f}
.card .n.red{color:#dc2626}
.card .n.amber{color:#d97706}
.card .l{font-size:12px;color:#6b7c67;margin-top:4px}
.section{background:#fff;border-radius:10px;border:1px solid #ede9e2;margin-bottom:16px;overflow:hidden}
.section-hdr{background:#f8fafc;padding:12px 16px;font-weight:700;font-size:13px;border-bottom:1px solid #ede9e2;display:flex;justify-content:space-between;align-items:center}
table{width:100%;border-collapse:collapse}
th{background:#2d6a4f;color:#fff;padding:9px 12px;font-size:12px;text-align:left;font-weight:600}
td{padding:8px 12px;font-size:13px;border-bottom:1px solid #f4f3ef;vertical-align:middle}
tr:last-child td{border-bottom:none}
.ok{color:#2d6a4f;font-weight:700}
.fail{color:#dc2626;font-weight:700}
.tag{display:inline-block;background:#e2f4f1;color:#0d6e63;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600}
.alert{padding:12px 16px;margin-bottom:16px;border-radius:8px;font-weight:600;font-size:14px}
.alert.ok{background:#d1fae5;color:#065f46;border:1px solid #6ee7b7}
.alert.fail{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}
.forms-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px}
@media(max-width:600px){.forms-grid{grid-template-columns:1fr}}
.form-card{background:#fff;border-radius:10px;border:1px solid #ede9e2;padding:18px}
.form-card h3{margin:0 0 14px;font-size:14px;color:#0f172a}
.form-card input{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;margin-bottom:8px}
.btn{padding:9px 18px;border:none;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer;width:100%}
.btn-green{background:#0d9488;color:#fff}
.btn-green:hover{background:#0b7a6f}
.btn-amber{background:#d97706;color:#fff}
.btn-amber:hover{background:#b45309}
.btn-blue{background:#1d4ed8;color:#fff}
.btn-blue:hover{background:#1e3a8a}
.btn-red{background:#dc2626;color:#fff;font-size:11px;padding:4px 10px;border-radius:5px;border:none;cursor:pointer;white-space:nowrap}
.btn-red:hover{background:#b91c1c}
.refresh-bar{background:#1e293b;color:#64748b;text-align:center;font-size:11px;padding:6px;position:sticky;bottom:0}
</style>
<script>
let countdown = 30;
function tick(){
  countdown--;
  const el = document.getElementById('countdown');
  if(el) el.textContent = countdown + 's';
  if(countdown <= 0) location.reload();
  else setTimeout(tick, 1000);
}
setTimeout(tick, 1000);
</script>
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

  <!-- CARDS -->
  <div class="cards">
    <div class="card"><div class="n"><?= $leads_total ?></div><div class="l">Leads cadastradas</div></div>
    <div class="card"><div class="n"><?= $leads_queued ?></div><div class="l">Na automação</div></div>
    <?php if($leads_pending > 0): ?>
    <div class="card" style="grid-column:span 2;background:#fef9c3;border-color:#fde047">
      <div class="n amber"><?= $leads_pending ?></div>
      <div class="l" style="color:#854d0e"><?= $leads_pending ?> lead(s) sem automação ativada</div>
      <form method="post" style="margin-top:10px">
        <input type="hidden" name="action" value="activate_leads">
        <button type="submit" class="btn btn-blue" style="width:auto;padding:7px 16px;font-size:12px">⚡ Ativar automação para todas</button>
      </form>
    </div>
    <?php endif; ?>
    <div class="card"><div class="n"><?= $email_sent ?></div><div class="l">E-mails enviados</div></div>
    <div class="card"><div class="n amber"><?= $email_pend ?></div><div class="l">E-mails pendentes</div></div>
    <div class="card"><div class="n <?= $email_fail > 0 ? 'red' : '' ?>"><?= $email_fail ?></div><div class="l">E-mails com falha</div></div>
    <div class="card"><div class="n"><?= $wpp_sent ?></div><div class="l">WPP enviados</div></div>
    <div class="card"><div class="n amber"><?= $wpp_pend ?></div><div class="l">WPP pendentes</div></div>
    <div class="card"><div class="n <?= $wpp_fail > 0 ? 'red' : '' ?>"><?= $wpp_fail ?></div><div class="l">WPP com falha</div></div>
  </div>

  <!-- TESTE DE ENVIO -->
  <div class="forms-grid">
    <div class="form-card">
      <h3>📧 Enviar E-mail de Teste</h3>
      <form method="post">
        <input type="hidden" name="action" value="test_email">
        <input type="email" name="test_email_to" placeholder="E-mail destinatário *" required>
        <input type="text" name="test_email_name" placeholder="Nome (ex: Daniely)" value="Daniely">
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

  <?php if ($email_fail > 0): ?>
  <div class="section" style="margin-bottom:16px">
    <div class="section-hdr">⚠️ Existem <?= $email_fail ?> e-mail(s) com falha
      <div style="display:flex;gap:8px">
        <form method="post" style="margin:0">
          <input type="hidden" name="action" value="retry_failed">
          <button type="submit" class="btn btn-amber" style="width:auto;padding:5px 14px">↺ Retentar todos</button>
        </form>
        <form method="post" style="margin:0" onsubmit="return confirm('Excluir todos os e-mails com falha?')">
          <input type="hidden" name="action" value="delete_all_failed_email">
          <button type="submit" class="btn btn-red">🗑️ Excluir todos</button>
        </form>
      </div>
    </div>
    <table>
      <tr><th>E-mail</th><th>Template</th><th>Tentativas</th><th>Erro</th><th></th></tr>
      <?php foreach ($recent_fail as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['to_email']) ?></td>
        <td><span class="tag"><?= htmlspecialchars($r['template_slug']) ?></span></td>
        <td><?= intval($r['attempts']) ?></td>
        <td style="color:#dc2626;font-size:12px"><?= htmlspecialchars(substr($r['error_msg'] ?? '', 0, 70)) ?></td>
        <td>
          <form method="post" style="margin:0" onsubmit="return confirm('Excluir este e-mail da fila?')">
            <input type="hidden" name="action" value="delete_email">
            <input type="hidden" name="item_id" value="<?= htmlspecialchars($r['id']) ?>">
            <button type="submit" class="btn-red">🗑️</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>

  <?php if ($wpp_fail > 0): ?>
  <div class="section" style="margin-bottom:16px">
    <div class="section-hdr">⚠️ <?= $wpp_fail ?> WhatsApp(s) com falha
      <div style="display:flex;gap:8px">
        <form method="post" style="margin:0">
          <input type="hidden" name="action" value="retry_failed_wpp">
          <button type="submit" class="btn btn-amber" style="width:auto;padding:5px 14px">↺ Retentar todos</button>
        </form>
        <form method="post" style="margin:0" onsubmit="return confirm('Excluir todos os WhatsApp com falha?')">
          <input type="hidden" name="action" value="delete_all_failed_wpp">
          <button type="submit" class="btn btn-red">🗑️ Excluir todos</button>
        </form>
      </div>
    </div>
    <table>
      <tr><th>Telefone</th><th>Mensagem</th><th>Tentativas</th><th>Erro</th><th></th></tr>
      <?php foreach ($wpp_fail_list as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['to_phone']) ?></td>
        <td style="font-size:12px;color:#374151;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars(substr($r['message'] ?? '', 0, 55)) ?>…</td>
        <td><?= intval($r['attempts']) ?></td>
        <td style="color:#dc2626;font-size:12px"><?= htmlspecialchars(substr($r['error_msg'] ?? '', 0, 70)) ?></td>
        <td>
          <form method="post" style="margin:0" onsubmit="return confirm('Excluir este WhatsApp da fila?')">
            <input type="hidden" name="action" value="delete_wpp">
            <input type="hidden" name="item_id" value="<?= htmlspecialchars($r['id']) ?>">
            <button type="submit" class="btn-red">🗑️</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>

  <!-- PRÓXIMOS ENVIOS -->
  <div class="section">
    <div class="section-hdr">⏳ Próximos E-mails na Fila
      <?php if (!empty($next_emails)): ?>
      <form method="post" style="margin:0" onsubmit="return confirm('Excluir TODOS os e-mails pendentes da fila?')">
        <input type="hidden" name="action" value="delete_all_pending_email">
        <button type="submit" class="btn btn-red">🗑️ Limpar fila</button>
      </form>
      <?php endif; ?>
    </div>
    <?php if (empty($next_emails)): ?>
    <div style="padding:16px;color:#6b7c67;text-align:center">Nenhum e-mail pendente</div>
    <?php else: ?>
    <table>
      <tr><th>E-mail</th><th>Template</th><th>Agendado para</th><th></th></tr>
      <?php foreach ($next_emails as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['to_email']) ?></td>
        <td><span class="tag"><?= htmlspecialchars($r['template_slug']) ?></span></td>
        <td><?= substr($r['scheduled_at'] ?? '', 0, 16) ?></td>
        <td>
          <form method="post" style="margin:0" onsubmit="return confirm('Excluir este e-mail da fila?')">
            <input type="hidden" name="action" value="delete_email">
            <input type="hidden" name="item_id" value="<?= htmlspecialchars($r['id']) ?>">
            <button type="submit" class="btn-red">🗑️</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>

  <!-- LOG DE ENVIOS -->
  <div class="section">
    <div class="section-hdr">✅ Últimos E-mails Enviados</div>
    <?php if (empty($recent_sent)): ?>
    <div style="padding:16px;color:#6b7c67;text-align:center">Nenhum e-mail enviado ainda</div>
    <?php else: ?>
    <table>
      <tr><th>E-mail</th><th>Template</th><th>Enviado em</th><th>Resposta SMTP</th></tr>
      <?php foreach ($recent_sent as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['to_email']) ?></td>
        <td><span class="tag"><?= htmlspecialchars($r['template_slug']) ?></span></td>
        <td><?= substr($r['sent_at'] ?? '', 0, 16) ?></td>
        <td style="font-size:11px;color:#6b7c67"><?= htmlspecialchars(substr($r['smtp_response'] ?? '', 0, 60)) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>

  <!-- PRÓXIMOS WPP -->
  <div class="section">
    <div class="section-hdr">💬 Próximos WhatsApp na Fila
      <?php if (!empty($wpp_next)): ?>
      <form method="post" style="margin:0" onsubmit="return confirm('Excluir TODOS os WhatsApp pendentes da fila?')">
        <input type="hidden" name="action" value="delete_all_pending_wpp">
        <button type="submit" class="btn btn-red">🗑️ Limpar fila</button>
      </form>
      <?php endif; ?>
    </div>
    <?php if (empty($wpp_next)): ?>
    <div style="padding:16px;color:#6b7c67;text-align:center">Nenhum WhatsApp pendente</div>
    <?php else: ?>
    <table>
      <tr><th>Telefone</th><th>Mensagem (prévia)</th><th>Agendado para</th><th></th></tr>
      <?php foreach ($wpp_next as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['to_phone']) ?></td>
        <td style="font-size:12px;color:#374151"><?= htmlspecialchars(substr($r['message'] ?? '', 0, 65)) ?>…</td>
        <td><?= substr($r['scheduled_at'] ?? '', 0, 16) ?></td>
        <td>
          <form method="post" style="margin:0" onsubmit="return confirm('Excluir este WhatsApp da fila?')">
            <input type="hidden" name="action" value="delete_wpp">
            <input type="hidden" name="item_id" value="<?= htmlspecialchars($r['id']) ?>">
            <button type="submit" class="btn-red">🗑️</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>

</div>
<div class="refresh-bar">Atualização automática em <span id="countdown">30</span>s &nbsp;·&nbsp; <?= date('H:i:s') ?></div>
</body>
</html>
<?php

// ── FUNÇÕES ───────────────────────────────────────────────────────
function send_smtp_test(string $to, string $name): array {
    $subject  = 'Teste de envio — Programa EmagreSer';
    $body     = '<html><body style="font-family:Arial;padding:24px;background:#f4f3ef"><div style="max-width:500px;margin:0 auto;background:#fff;padding:28px;border-radius:10px"><h2 style="color:#0d9488">✅ E-mail funcionando!</h2><p>Olá, <strong>' . htmlspecialchars($name) . '</strong>!</p><p>Este é um e-mail de teste da automação do <strong>Programa EmagreSer</strong>.</p><p style="color:#6b7c67;font-size:13px">Enviado em ' . date('d/m/Y H:i:s') . '</p></div></body></html>';
    $fromEnc  = '=?UTF-8?B?' . base64_encode(SMTP_FROM_NAME) . '?=';
    $toEnc    = '=?UTF-8?B?' . base64_encode($name) . '?= <' . $to . '>';
    $subjEnc  = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $bnd      = md5(uniqid());
    $domain   = substr(strrchr(SMTP_FROM, '@'), 1);

    $headers  = "From: {$fromEnc} <" . SMTP_FROM . ">\r\n";
    $headers .= "To: {$toEnc}\r\n";
    $headers .= "Reply-To: " . SMTP_FROM . "\r\n";
    $headers .= "Message-ID: <" . uniqid('es', true) . "@{$domain}>\r\n";
    $headers .= "Date: " . date('r') . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$bnd}\"\r\n";
    $headers .= "List-Unsubscribe: <mailto:" . SMTP_FROM . "?subject=descadastro>\r\n";
    $headers .= "X-Mailer: EmagreSer-Automacao/1.0";

    $msg  = "--{$bnd}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n";
    $msg .= quoted_printable_encode("Teste EmagreSer OK. Enviado em " . date('d/m/Y H:i:s')) . "\r\n\r\n";
    $msg .= "--{$bnd}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n";
    $msg .= quoted_printable_encode($body) . "\r\n\r\n--{$bnd}--";

    $ok = mail($to, $subjEnc, $msg, $headers, '-f' . SMTP_FROM);
    return $ok ? ['ok' => true, 'msg' => 'Enviado com sucesso!'] : ['ok' => false, 'msg' => 'mail() retornou false'];
}

function send_zapi_test(string $phone, string $name): array {
    $url = 'https://api.z-api.io/instances/' . ZAPI_INSTANCE . '/token/' . ZAPI_TOKEN . '/send-text';
    $payload = json_encode(['phone' => $phone, 'message' => "✅ *Teste EmagreSer*\n\nOlá, {$name}! A automação de WhatsApp está funcionando corretamente.\n\n_Programa EmagreSer — " . date('d/m/Y H:i') . "_"]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Client-Token: ' . ZAPI_CLIENT_TOKEN]]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $data = json_decode($res, true);
    return ($code === 200 && !empty($data['zaapId'])) ? ['ok' => true, 'msg' => 'sent'] : ['ok' => false, 'msg' => $res];
}

function sb_get(string $path): array {
    $ch = curl_init(SUPABASE_URL . '/rest/v1/' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['apikey: ' . SUPABASE_SERVICE_KEY, 'Authorization: Bearer ' . SUPABASE_SERVICE_KEY, 'Prefer: count=none']]);
    $res = curl_exec($ch); curl_close($ch);
    return json_decode($res, true) ?: [];
}

function sb_patch_raw(string $path, array $data): void {
    $ch = curl_init(SUPABASE_URL . '/rest/v1/' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'PATCH', CURLOPT_POSTFIELDS => json_encode($data), CURLOPT_HTTPHEADER => ['apikey: ' . SUPABASE_SERVICE_KEY, 'Authorization: Bearer ' . SUPABASE_SERVICE_KEY, 'Content-Type: application/json', 'Prefer: return=minimal']]);
    curl_exec($ch); curl_close($ch);
}

function sb_delete(string $path): void {
    $ch = curl_init(SUPABASE_URL . '/rest/v1/' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_HTTPHEADER => ['apikey: ' . SUPABASE_SERVICE_KEY, 'Authorization: Bearer ' . SUPABASE_SERVICE_KEY, 'Prefer: return=minimal']]);
    curl_exec($ch); curl_close($ch);
}
