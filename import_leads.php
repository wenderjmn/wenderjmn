<?php
/**
 * import_leads.php — Importação de leads com seleção de canal
 *
 * Acesso web: https://www.oficialemagreser.com/import_leads.php
 * Login: qualquer usuário · Senha = IMPORT_PASS (definida em _env.php)
 * Padrão se não configurada: import2026
 *
 * Canais disponíveis:
 *   email — enfileira sequência de reengajamento no email_queue
 *   wpp   — enfileira sequência de reengajamento no whatsapp_queue
 *   ambos — enfileira nos dois canais
 *
 * Formato CSV: nome, email, telefone (ou name, email, phone)
 * Colunas extras aceitas: cidade, estado, sabotador
 */

if (file_exists(__DIR__ . '/_env.php')) require_once __DIR__ . '/_env.php';

define('IMPORT_PASS',          getenv('IMPORT_PASS')          ?: 'import2026');
define('SUPABASE_URL',         'https://drgrwpmhmrrhxuwxabow.supabase.co');
define('SUPABASE_SERVICE_KEY', getenv('SUPABASE_SERVICE_KEY') ?: '***REMOVED_SUPABASE_KEY***');
define('ZAPI_INSTANCE',        getenv('ZAPI_INSTANCE')        ?: '***REMOVED_ZAPI_INSTANCE***');
define('ZAPI_TOKEN',           getenv('ZAPI_TOKEN')           ?: '***REMOVED_ZAPI_TOKEN***');
define('ZAPI_CLIENT_TOKEN',    getenv('ZAPI_CLIENT_TOKEN')    ?: '***REMOVED_ZAPI_CLIENT_TOKEN***');
define('SITE_URL',             'https://www.oficialemagreser.com');
define('WPP_LINK',             'https://chat.whatsapp.com/GsMAVm3KVncGNR5nHRQ3yQ');

$is_cli = php_sapi_name() === 'cli';

// ── Autenticação web ─────────────────────────────────────────────
if (!$is_cli) {
    header('Content-Type: text/html; charset=UTF-8');
    if (!isset($_SERVER['PHP_AUTH_PW']) || $_SERVER['PHP_AUTH_PW'] !== IMPORT_PASS) {
        header('WWW-Authenticate: Basic realm="EmagreSer Import"');
        header('HTTP/1.0 401 Unauthorized');
        echo 'Senha incorreta.'; exit;
    }
}

// ── Sequência de e-mail ───────────────────────────────────────────
// [template_slug, delay_days (null=data fixa), data_fixa|null]
$email_schedule = [
    ['reengajamento_1',           0,    null],
    ['nutricao_d1',               2,    null],
    ['prova_social_d2',           4,    null],
    ['reengajamento_masterclass', 8,    null],
    ['reengajamento_masterclass', null, '2026-06-08 09:00:00'],
    ['masterclass_hoje',          null, '2026-06-11 08:00:00'],
    ['masterclass_1h',            null, '2026-06-11 19:00:00'],
];

// ── Sequência de WPP ─────────────────────────────────────────────
// [delay_hours (null=data fixa), data_fixa|null, mensagem_template]
$tz   = new DateTimeZone('America/Sao_Paulo');
$base = new DateTime('now', $tz);

$wpp_messages = [
    [0, null,
        "Oi, {{nome}}! 👋\n\nSou do *Programa EmagreSer*. Você se inscreveu em nossa lista e quero te enviar dicas e conteúdos exclusivos sobre emagrecimento de verdade — sem dietas restritivas.\n\nPosso continuar te enviando mensagens aqui no WhatsApp?\n\nResponda *SIM* para continuar ou *NÃO* para não receber mais. 🙏"],

    [26, null,
        "{{nome}}, uma coisa rápida 💡\n\nA maioria das pessoas que não consegue manter o peso não tem problema de falta de força de vontade.\n\nTem um *padrão neurológico* que repete em loop sem que a pessoa perceba.\n\nNa Masterclass do dia 11/06 a Daniely vai mostrar como identificar e quebrar esse padrão.\n\nEntra no grupo VIP para receber o link 👇\n" . WPP_LINK],

    [74, null,
        "{{nome}}, deixa eu te contar algo 🙌\n\nA Carol me escreveu essa semana:\n\n_\"Tentei tudo. Dieta, academia, aplicativo. Nada funcionava porque eu não entendia por que eu sabotava. Depois que entendi meu padrão, tudo mudou.\"_\n\nÉ exatamente sobre isso que a Masterclass vai falar.\n\nGrupo VIP gratuito 👇\n" . WPP_LINK],

    [122, null,
        "{{nome}}, preciso te contar uma coisa importante 💚\n\nO que você chama de *falta de força de vontade* tem nome científico. E tem solução.\n\nA Masterclass *\"O Código dos Sabotadores\"* é dia 11/06 às 20h — ao vivo, gratuita.\n\nJá garantiu seu lugar? 👇\n" . WPP_LINK],

    [null, '2026-06-08 09:00:00',
        "⚠️ {{nome}}, faltam *3 dias* para a Masterclass!\n\n📅 *11 de junho, 20h*\n\nSó recebe o link quem está no grupo VIP 👇\n" . WPP_LINK],

    [null, '2026-06-10 09:00:00',
        "{{nome}}! 🔥 *Amanhã é o grande dia!*\n\nMasterclass *\"O Código dos Sabotadores\"*\n📅 11/06 às 20h — ao vivo\n\nO link sai no grupo VIP amanhã 👇\n" . WPP_LINK],

    [null, '2026-06-11 08:00:00',
        "🔴 *HOJE É O DIA*, {{nome}}!\n\nMasterclass *\"O Código dos Sabotadores\"*\n⏰ Hoje às 20h — ao vivo\n\nO link vai sair no grupo VIP antes do início 👇\n" . WPP_LINK],

    [null, '2026-06-11 19:00:00',
        "⏰ *Em 1 hora começa!*\n\n{{nome}}, corre que falta pouco!\n\nO link da live está no grupo VIP agora 👇\n" . WPP_LINK],
];

// ── Interface web ─────────────────────────────────────────────────
$csv_file = $is_cli ? ($argv[1] ?? null) : ($_FILES['csv']['tmp_name'] ?? null);
$canal    = $is_cli ? ($argv[2] ?? 'ambos') : ($_POST['canal'] ?? 'ambos');

if (!$is_cli && (!$csv_file || !file_exists($csv_file))): ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Importar Leads — EmagreSer</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Arial,sans-serif;background:#f0f4f8;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{background:#fff;border-radius:16px;padding:36px;box-shadow:0 8px 32px rgba(0,0,0,.10);width:100%;max-width:560px}
.logo{display:flex;align-items:center;gap:12px;margin-bottom:28px}
.logo-icon{width:44px;height:44px;background:linear-gradient(135deg,#0d9488,#6366f1);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:22px}
.logo h1{font-size:18px;font-weight:700;color:#1e293b}
.logo p{font-size:12px;color:#64748b;margin-top:2px}
.info{background:#f0fdfa;border:1px solid #99f6e4;border-radius:10px;padding:14px 16px;font-size:13px;color:#0f766e;margin-bottom:22px;line-height:1.7}
.info strong{display:block;margin-bottom:6px;font-size:14px}
code{background:#ccfbf1;padding:1px 6px;border-radius:4px;font-family:monospace;font-size:12px}
label.field-label{display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:8px}
.file-wrap{border:2px dashed #0d9488;border-radius:10px;background:#f0fdfa;margin-bottom:22px;padding:14px;text-align:center;font-size:13px;color:#0f766e}
.file-wrap input[type=file]{width:100%;cursor:pointer}
.canal-group{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:26px}
.canal-opt{position:relative}
.canal-opt input{position:absolute;opacity:0;width:0;height:0}
.canal-opt label{border:2px solid #e2e8f0;border-radius:10px;padding:14px 8px;text-align:center;cursor:pointer;transition:.15s;font-size:13px;font-weight:600;color:#64748b;display:block}
.canal-opt label em{display:block;font-size:24px;font-style:normal;margin-bottom:5px}
.canal-opt input:checked + label{border-color:#0d9488;background:#f0fdfa;color:#0d9488}
.canal-opt label:hover{border-color:#0d9488}
.btn{width:100%;background:linear-gradient(135deg,#0d9488,#0891b2);color:#fff;border:none;padding:16px;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer}
.btn:hover{opacity:.9}
.cred{background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:12px 14px;font-size:12px;color:#78350f;margin-top:20px;line-height:1.7}
.cred strong{display:block;margin-bottom:4px}
</style>
</head>
<body>
<div class="card">
  <div class="logo">
    <div class="logo-icon">📤</div>
    <div>
      <h1>Importar Leads</h1>
      <p>EmagreSer 2.0 — Campanha de Reengajamento</p>
    </div>
  </div>

  <div class="info">
    <strong>📋 Formato CSV aceito</strong>
    Obrigatório: <code>nome</code> + <code>email</code> (para e-mail) ou <code>telefone</code> (para WPP)<br>
    Opcionais: <code>cidade</code> · <code>estado</code> · <code>sabotador</code> (A/B/C/D)<br>
    Telefone sem +55 e sem espaços, ex: <code>85998765432</code>
  </div>

  <form method="post" enctype="multipart/form-data">
    <label class="field-label">Arquivo CSV:</label>
    <div class="file-wrap">
      <input type="file" name="csv" accept=".csv,.txt" required>
    </div>

    <label class="field-label">Canal de envio da campanha:</label>
    <div class="canal-group">
      <div class="canal-opt">
        <input type="radio" name="canal" id="c-email" value="email">
        <label for="c-email"><em>📧</em>Só E-mail</label>
      </div>
      <div class="canal-opt">
        <input type="radio" name="canal" id="c-wpp" value="wpp">
        <label for="c-wpp"><em>💬</em>Só WPP</label>
      </div>
      <div class="canal-opt">
        <input type="radio" name="canal" id="c-ambos" value="ambos" checked>
        <label for="c-ambos"><em>🚀</em>Ambos</label>
      </div>
    </div>

    <button type="submit" class="btn">📤 IMPORTAR E ENFILEIRAR CAMPANHA</button>
  </form>

  <div class="cred">
    <strong>🔐 Credenciais de acesso</strong>
    URL: <code>oficialemagreser.com/import_leads.php</code><br>
    Usuário: qualquer valor · Senha: valor de <code>IMPORT_PASS</code> no <code>_env.php</code><br>
    Senha padrão (sem _env.php): <code>import2026</code>
  </div>
</div>
</body>
</html>
<?php
    exit;
endif;

// ── Processamento ─────────────────────────────────────────────────
if (!$csv_file || !file_exists($csv_file)) die("Arquivo CSV não encontrado.\n");

$rows    = [];
$handle  = fopen($csv_file, 'r');
$headers = null;
while (($line = fgetcsv($handle, 2000, ',')) !== false) {
    if (!$headers) { $headers = array_map(fn($h) => strtolower(trim($h)), $line); continue; }
    if (count($line) < 1) continue;
    $rows[] = array_combine(array_slice($headers, 0, count($line)), $line);
}
fclose($handle);

$total   = count($rows);
$ok      = 0;
$skipped = 0;
$no_wpp  = 0;
$errors  = [];

output("<h2 style='font-family:Arial;color:#0d9488'>Importando {$total} leads — Canal: <strong>{$canal}</strong></h2>");

foreach ($rows as $i => $row) {
    $name   = trim($row['nome']     ?? $row['name']      ?? '');
    $email  = strtolower(trim($row['email'] ?? $row['e-mail'] ?? ''));
    $phone  = preg_replace('/\D/', '', $row['telefone']   ?? $row['phone'] ?? $row['whatsapp'] ?? '');
    $cidade = trim($row['cidade']   ?? '');
    $estado = strtoupper(trim($row['estado'] ?? ''));
    $sab    = strtoupper(trim($row['sabotador'] ?? $row['perfil'] ?? ''));

    // Validações por canal
    if (in_array($canal, ['email', 'ambos']) && (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
        if ($canal === 'email') { $skipped++; continue; }
    }
    if (in_array($canal, ['wpp', 'ambos']) && (!$phone || strlen($phone) < 10)) {
        if ($canal === 'wpp') { $no_wpp++; $skipped++; continue; }
        $no_wpp++; // ambos: segue mas só enfileira e-mail
    }
    if (!$name) $name = $email ? explode('@', $email)[0] : "Lead " . ($i+2);

    // ── Upsert lead ───────────────────────────────────────────────
    $existing = $email
        ? sb_get("leads?email=eq." . urlencode($email) . "&select=id,sequence_queued_at&limit=1")
        : [];

    if (!empty($existing)) {
        if (!empty($existing[0]['sequence_queued_at'])) { $skipped++; continue; }
        $lead_id = $existing[0]['id'];
    } else {
        $created = sb_post_return('leads', [
            'name'            => $name,
            'email'           => $email  ?: null,
            'phone'           => $phone  ?: null,
            'cidade'          => $cidade ?: null,
            'estado'          => $estado ?: null,
            'sabotador'       => $sab    ?: null,
            'source'          => 'import',
            'source_campaign' => 'reengajamento_2026',
        ]);
        if (empty($created[0]['id'])) {
            $errors[] = "Linha " . ($i+2) . ": falha ao criar lead {$email}";
            continue;
        }
        $lead_id = $created[0]['id'];
    }

    // ── Fila de E-mail ────────────────────────────────────────────
    if ($email && filter_var($email, FILTER_VALIDATE_EMAIL) && in_array($canal, ['email', 'ambos'])) {
        $vars = [
            '{{nome}}'             => htmlspecialchars($name),
            '{{link_site}}'        => SITE_URL,
            '{{link_wpp}}'         => WPP_LINK,
            '{{link_descadastro}}' => SITE_URL . '/descadastro.php?email=' . urlencode($email),
            '{{emoji}}'            => '🎯',
            '{{tipo}}'             => 'Perfil Sabotador',
            '{{titulo}}'           => 'Descubra seu padrão comportamental',
            '{{descricao}}'        => '',
        ];
        foreach ($email_schedule as [$slug, $delay, $fixed_at]) {
            $send_at = $fixed_at
                ? (new DateTime($fixed_at, $tz))->format(DateTime::ATOM)
                : (clone $base)->modify("+{$delay} days")->setTime(9, 0, 0)->format(DateTime::ATOM);
            if (strtotime($send_at) < time() - 60) continue;
            sb_post('email_queue', [[
                'lead_id'       => $lead_id,
                'template_slug' => $slug,
                'to_email'      => $email,
                'to_name'       => $name,
                'extra_vars'    => $vars,
                'scheduled_at'  => $send_at,
                'status'        => 'pending',
            ]]);
        }
    }

    // ── Fila de WPP ──────────────────────────────────────────────
    if ($phone && strlen($phone) >= 10 && in_array($canal, ['wpp', 'ambos'])) {
        $tel = '55' . ltrim($phone, '0');
        foreach ($wpp_messages as [$delay, $fixed_at, $msg_tpl]) {
            $send_at = $fixed_at
                ? (new DateTime($fixed_at, $tz))->format(DateTime::ATOM)
                : (clone $base)->modify("+{$delay} hours")->format(DateTime::ATOM);
            if (strtotime($send_at) < time() - 60) continue;
            sb_post('whatsapp_queue', [[
                'lead_id'      => $lead_id,
                'to_phone'     => $tel,
                'to_name'      => $name,
                'message'      => str_replace('{{nome}}', $name, $msg_tpl),
                'scheduled_at' => $send_at,
                'status'       => 'pending',
            ]]);
        }
    }

    // Marca como enfileirado
    sb_patch("leads?id=eq.{$lead_id}", [
        'sequence_queued_at' => $base->format(DateTime::ATOM),
        'source_campaign'    => 'reengajamento_2026',
    ]);

    $ok++;
    if ($ok % 50 === 0) output("✅ {$ok}/{$total} processadas...");
    usleep(50000);
}

$wpp_note = $no_wpp > 0 ? " | Sem telefone (WPP ignorado): {$no_wpp}" : '';
output("<br><strong>✅ Importação concluída!</strong>");
output("Total: {$total} | Importadas: {$ok} | Ignoradas: {$skipped}{$wpp_note} | Erros: " . count($errors));
if ($errors) output("<br>Erros:<br>" . implode('<br>', array_map('htmlspecialchars', $errors)));

// ── Helpers ───────────────────────────────────────────────────────
function output(string $msg): void {
    echo (php_sapi_name() === 'cli' ? strip_tags($msg) : $msg) . "<br>\n";
    if (ob_get_level()) { ob_flush(); flush(); }
}

function sb_get(string $path): array {
    $ch = curl_init(SUPABASE_URL . '/rest/v1/' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => [
        'apikey: ' . SUPABASE_SERVICE_KEY, 'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
    ]]);
    $res = curl_exec($ch); curl_close($ch);
    return json_decode($res, true) ?: [];
}

function sb_post(string $table, array $data): void {
    $ch = curl_init(SUPABASE_URL . '/rest/v1/' . $table);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'apikey: ' . SUPABASE_SERVICE_KEY, 'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
            'Content-Type: application/json', 'Prefer: return=minimal',
        ],
    ]);
    curl_exec($ch); curl_close($ch);
}

function sb_post_return(string $table, array $data): array {
    $ch = curl_init(SUPABASE_URL . '/rest/v1/' . $table);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'apikey: ' . SUPABASE_SERVICE_KEY, 'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
            'Content-Type: application/json', 'Prefer: return=representation',
        ],
    ]);
    $res = curl_exec($ch); curl_close($ch);
    return json_decode($res, true) ?: [];
}

function sb_patch(string $path, array $data): void {
    $ch = curl_init(SUPABASE_URL . '/rest/v1/' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'apikey: ' . SUPABASE_SERVICE_KEY, 'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
            'Content-Type: application/json', 'Prefer: return=minimal',
        ],
    ]);
    curl_exec($ch); curl_close($ch);
}
