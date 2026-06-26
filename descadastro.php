<?php
/**
 * descadastro.php — Página de descadastro de e-mail
 *
 * Chamado pelo link {{link_descadastro}} nos e-mails:
 *   https://www.oficialemagreser.com/descadastro.php?email=xxx@yyy.com
 *
 * Ações:
 *   1. Seta email_optout=true no lead
 *   2. Cancela todos os e-mails pendentes (email_queue status=pending)
 */

if (file_exists(__DIR__ . '/_env.php')) require_once __DIR__ . '/_env.php';

define('SUPABASE_URL',         'https://drgrwpmhmrrhxuwxabow.supabase.co');
define('SUPABASE_SERVICE_KEY', getenv('SUPABASE_SERVICE_KEY') ?: '');

$email   = trim($_GET['email']   ?? '');
$lead_id = trim($_GET['lead_id'] ?? '');

$status  = 'error'; // success | already | notfound | error
$message = '';
$name    = '';

if (!$email && !$lead_id) {
    $status  = 'error';
    $message = 'Link inválido. Parâmetro de identificação ausente.';
} else {
    // Busca o lead
    if ($lead_id) {
        $leads = sb_get('leads?id=eq.' . rawurlencode($lead_id) . '&select=id,name,email,email_optout&limit=1');
    } else {
        $leads = sb_get('leads?email=eq.' . rawurlencode($email) . '&select=id,name,email,email_optout&limit=1');
    }

    if (empty($leads) || !isset($leads[0]['id'])) {
        $status  = 'notfound';
        $message = 'E-mail não encontrado em nossa lista.';
    } else {
        $lead  = $leads[0];
        $name  = $lead['name'] ?? '';
        $first = explode(' ', $name)[0];

        if (!empty($lead['email_optout'])) {
            $status  = 'already';
            $message = 'Você já estava descadastrado(a) da nossa lista.';
        } else {
            // 1. Seta email_optout = true
            sb_patch('leads?id=eq.' . rawurlencode($lead['id']), ['email_optout' => true]);

            // 2. Cancela todos os e-mails pendentes
            sb_patch(
                'email_queue?lead_id=eq.' . rawurlencode($lead['id']) . '&status=eq.pending',
                ['status' => 'cancelled', 'error_msg' => 'descadastro pelo link do e-mail']
            );

            $status  = 'success';
            $message = "Pronto, {$first}! Seu e-mail foi removido da nossa lista.";
        }
    }
}

// ── HELPERS ──────────────────────────────────────────────────────────────────

function sb_get(string $path): array {
    $ch = curl_init(SUPABASE_URL . '/rest/v1/' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => [
        'apikey: '               . SUPABASE_SERVICE_KEY,
        'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
    ]]);
    $res = curl_exec($ch); curl_close($ch);
    return json_decode($res, true) ?: [];
}

function sb_patch(string $path, array $data): void {
    $ch = curl_init(SUPABASE_URL . '/rest/v1/' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PATCH',
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => [
            'apikey: '               . SUPABASE_SERVICE_KEY,
            'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
            'Content-Type: application/json',
            'Prefer: return=minimal',
        ],
    ]);
    curl_exec($ch); curl_close($ch);
}

?><!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Descadastro — EmagreSer</title>
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#fafaf8;color:#1c1917;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px}
    .card{background:#fff;border:1px solid #e7e5e0;border-radius:14px;padding:40px 32px;max-width:480px;width:100%;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,.06)}
    .icon{font-size:2.8rem;margin-bottom:16px}
    h1{font-size:1.35rem;font-weight:800;margin-bottom:10px;line-height:1.3}
    p{font-size:.93rem;color:#57534e;line-height:1.65;margin-bottom:20px}
    .btn{display:inline-block;background:#0d9488;color:#fff;font-weight:700;font-size:.88rem;padding:13px 28px;border-radius:9px;text-decoration:none;letter-spacing:.04em;text-transform:uppercase;transition:background .18s}
    .btn:hover{background:#0f766e}
    .btn-ghost{background:transparent;border:1px solid #e7e5e0;color:#57534e;margin-top:10px}
    .btn-ghost:hover{background:#f4f3ef}
    .note{font-size:.78rem;color:#a8a29e;margin-top:20px}
    .logo{margin-bottom:28px;opacity:.7}
  </style>
</head>
<body>
  <div class="card">
    <div class="logo">
      <img src="/logo.png" alt="EmagreSer" height="32" onerror="this.style.display='none'">
    </div>

    <?php if ($status === 'success'): ?>
      <div class="icon">✅</div>
      <h1>Descadastro confirmado</h1>
      <p><?= htmlspecialchars($message) ?><br>Você não receberá mais e-mails nossos.</p>
      <a href="https://www.oficialemagreser.com" class="btn">Voltar ao site</a>
      <p class="note">Se mudar de ideia, você sempre pode se cadastrar novamente em nosso site.</p>

    <?php elseif ($status === 'already'): ?>
      <div class="icon">ℹ️</div>
      <h1>Já descadastrado</h1>
      <p><?= htmlspecialchars($message) ?><br>Você não está recebendo nenhum e-mail nosso.</p>
      <a href="https://www.oficialemagreser.com" class="btn btn-ghost">Voltar ao site</a>

    <?php elseif ($status === 'notfound'): ?>
      <div class="icon">🔍</div>
      <h1>E-mail não encontrado</h1>
      <p><?= htmlspecialchars($message) ?><br>Se acredita que é um erro, entre em contato conosco.</p>
      <a href="https://www.oficialemagreser.com" class="btn btn-ghost">Voltar ao site</a>

    <?php else: ?>
      <div class="icon">⚠️</div>
      <h1>Link inválido</h1>
      <p><?= htmlspecialchars($message) ?></p>
      <a href="https://www.oficialemagreser.com" class="btn btn-ghost">Voltar ao site</a>
    <?php endif; ?>
  </div>
</body>
</html>
