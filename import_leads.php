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

define('IMPORT_PASS',          getenv('IMPORT_PASS')          ?: '');
define('SUPABASE_URL',         'https://drgrwpmhmrrhxuwxabow.supabase.co');
define('SUPABASE_SERVICE_KEY', getenv('SUPABASE_SERVICE_KEY') ?: '');
define('ZAPI_INSTANCE',        getenv('ZAPI_INSTANCE')        ?: '');
define('ZAPI_TOKEN',           getenv('ZAPI_TOKEN')           ?: '');
define('ZAPI_CLIENT_TOKEN',    getenv('ZAPI_CLIENT_TOKEN')    ?: '');
define('SITE_URL',             'https://www.oficialemagreser.com');
define('WPP_LINK',             'https://chat.whatsapp.com/GsMAVm3KVncGNR5nHRQ3yQ');
define('HOTMART_LINK',         getenv('HOTMART_LINK')         ?: 'https://pay.hotmart.com/emagreser');
define('IRA_VIDEO_URL',        getenv('IRA_VIDEO_URL')        ?: '');

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
    // ── D+0h: Opt-in consent ─────────────────────────────────────────
    [0, null,
        "Oi, {{nome}}! 👋\n\nSou do *Programa EmagreSer*. Você se inscreveu em nossa lista e quero te enviar dicas e conteúdos exclusivos sobre emagrecimento de verdade — sem dietas restritivas.\n\nPosso continuar te enviando mensagens aqui no WhatsApp?\n\nResponda *SIM* para continuar ou *NÃO* para não receber mais. 🙏"],

    // ── D+1: manhã + tarde ───────────────────────────────────────────
    [26, null,
        "{{nome}}, uma coisa rápida 💡\n\nA maioria das pessoas que não consegue manter o peso não tem problema de falta de força de vontade.\n\nTem um *padrão neurológico* que repete em loop sem que a pessoa perceba.\n\nNa Masterclass do dia 11/06 a Daniely vai mostrar como identificar e quebrar esse padrão.\n\nEntra no grupo VIP para receber o link 👇\n" . WPP_LINK],

    [34, null,
        "{{nome}}, boa tarde! ☀️\n\nAinda dá tempo de garantir seu lugar na Masterclass *\"O Código dos Sabotadores\"*.\n\n📅 *11 de junho, 20h* — ao vivo e gratuita\n\nO link de acesso à transmissão vai sair exclusivamente no grupo VIP.\n\n👇\n" . WPP_LINK],

    // ── D+2: manhã + tarde ───────────────────────────────────────────
    [50, null,
        "Bom dia, {{nome}}! ☀️\n\nVocê sabia que 8 em cada 10 mulheres que tentam emagrecer falham — *não por falta de força de vontade*, mas porque nunca identificaram o padrão que as faz sabotar?\n\nExistem 4 perfis de sabotagem, e cada um tem uma estratégia específica. A Dra. Daniely vai revelar tudo na Masterclass do dia 11/06.\n\nEstá no grupo VIP? 👇\n" . WPP_LINK],

    [58, null,
        "{{nome}}, boa tarde! 🌸\n\nA Fernanda tentou 7 dietas em 3 anos. Sempre começava bem, depois desandava. Ela achava que o problema era ela.\n\nDepois que entendeu seu *perfil sabotador*, as coisas mudaram de vez.\n\n_\"Pela primeira vez, eu entendia meu próprio comportamento. Aí ficou mais fácil.\"_ — Fernanda, 41 anos\n\nQuer entender o seu? Grupo VIP gratuito 👇\n" . WPP_LINK],

    // ── D+3: manhã + tarde ───────────────────────────────────────────
    [74, null,
        "{{nome}}, deixa eu te contar algo 🙌\n\nA Carol me escreveu essa semana:\n\n_\"Tentei tudo. Dieta, academia, aplicativo. Nada funcionava porque eu não entendia por que eu sabotava. Depois que entendi meu padrão, tudo mudou.\"_\n\nÉ exatamente sobre isso que a Masterclass vai falar.\n\nGrupo VIP gratuito 👇\n" . WPP_LINK],

    [82, null,
        "{{nome}}, boa tarde! ☀️\n\nUma dica que você pode aplicar *hoje*:\n\nAntes de comer qualquer coisa fora do planejado, pare *10 segundos* e pergunte: estou com fome de verdade ou é outra coisa?\n\nEssa é uma das técnicas do Programa EmagreSer. Na Masterclass você aprende o método completo.\n\n📅 11/06 às 20h — gratuita 👇\n" . WPP_LINK],

    // ── D+4: manhã + tarde ───────────────────────────────────────────
    [98, null,
        "Bom dia, {{nome}}! 🌞\n\nFaltam poucos dias para a Masterclass *\"O Código dos Sabotadores\"*.\n\nSe você ainda não está no grupo VIP, é hora de entrar — o link da transmissão ao vivo vai sair apenas lá, antes do início.\n\n📅 11/06 às 20h | Gratuita | Ao vivo\n\n👇\n" . WPP_LINK],

    [106, null,
        "{{nome}}, boa tarde! 👋\n\nUma pergunta rápida:\n\nQual é o momento do dia que você mais perde o controle com a alimentação?\n\n⏰ À noite depois do jantar?\n💼 No trabalho?\n📱 Assistindo séries?\n😰 Quando está estressada?\n\nCada resposta tem uma estratégia específica que a Dra. Daniely vai apresentar na Masterclass 😊"],

    // ── D+5: manhã + tarde ───────────────────────────────────────────
    [122, null,
        "{{nome}}, preciso te contar uma coisa importante 💚\n\nO que você chama de *falta de força de vontade* tem nome científico. E tem solução.\n\nA Masterclass *\"O Código dos Sabotadores\"* é dia 11/06 às 20h — ao vivo, gratuita.\n\nJá garantiu seu lugar? 👇\n" . WPP_LINK],

    [130, null,
        "{{nome}}, boa tarde! ⏳\n\nA Masterclass está chegando — e o grupo VIP está quase cheio.\n\nSão centenas de mulheres que já garantiram presença. Não fica de fora!\n\n💬 Grupo VIP gratuito 👇\n" . WPP_LINK],

    // ── Jun 7 (D-4): 3 mensagens — manhã / tarde / noite ────────────
    [null, '2026-06-07 09:00:00',
        "{{nome}} 🗓️\n\n*Faltam 4 dias* para a Masterclass \"O Código dos Sabotadores\"!\n\nJá separou no calendário? 📅 11/06 às 20h — ao vivo\n\nO conteúdo é ao vivo e não há garantia de gravação. Quem não estiver na transmissão, perde.\n\nEstá no grupo VIP? 👇\n" . WPP_LINK],

    [null, '2026-06-07 14:00:00',
        "{{nome}}, boa tarde! ☀️\n\nO que te impediu de emagrecer nas últimas tentativas?\n\n🔴 Começa bem mas não consegue manter\n🔴 Fica restringindo demais e depois compensa\n🔴 Sabe o que fazer mas não faz\n🔴 Emagrece e depois recupera tudo\n\nSe a resposta foi qualquer uma dessas — a Masterclass é pra você. 11/06 às 20h 👇\n" . WPP_LINK],

    [null, '2026-06-07 19:00:00',
        "{{nome}}, boa noite! 🌙\n\nÀ noite é quando mais sabotamos — e não é por acaso.\n\nO cérebro cansado busca recompensa. É neurológico. É previsível. *E tem solução.*\n\nNa Masterclass você vai entender exatamente esse mecanismo — e como quebrar o ciclo de vez.\n\n📅 11/06 às 20h | Gratuita | Ao vivo\n\n👇\n" . WPP_LINK],

    // ── Jun 8 (D-3): 3 mensagens — manhã / tarde / noite ────────────
    [null, '2026-06-08 09:00:00',
        "⚠️ {{nome}}, faltam *3 dias* para a Masterclass!\n\n📅 *11 de junho, 20h*\n\nSó recebe o link quem está no grupo VIP 👇\n" . WPP_LINK],

    [null, '2026-06-08 14:00:00',
        "{{nome}}, boa tarde! ☀️\n\nA Psicóloga Daniely identificou que todos os perfis de sabotagem têm uma coisa em comum:\n\n*O cérebro usa a comida como estratégia de regulação emocional.*\n\nNão é falta de vontade. É neurologia.\n\nE o que é neurológico, pode ser *reprogramado*. A Masterclass mostra como.\n\n📅 11/06 às 20h 👇\n" . WPP_LINK],

    [null, '2026-06-08 19:00:00',
        "{{nome}} 🌙\n\nUma pergunta honesta:\n\nHá quanto tempo você tenta emagrecer do mesmo jeito e obtém o mesmo resultado?\n\nEinstein dizia que loucura é repetir a mesma ação esperando resultados diferentes.\n\nA Masterclass *\"O Código dos Sabotadores\"* oferece um caminho diferente.\n\n📅 11/06 às 20h — ao vivo e gratuita 👇\n" . WPP_LINK],

    // ── Jun 9 (D-2): 3 mensagens — manhã / tarde / noite ────────────
    [null, '2026-06-09 09:00:00',
        "Bom dia, {{nome}}! 🌅\n\n*Faltam apenas 2 dias* para a Masterclass!\n\nO que vai acontecer na transmissão:\n\n✅ Os 4 perfis de sabotagem revelados\n✅ A técnica do *Confronto Gentil*\n✅ Por que força de vontade não funciona — e o que funciona\n✅ Estratégias práticas para os primeiros 7 dias\n\nPrepara o caderninho! 📓 11/06 às 20h 👇\n" . WPP_LINK],

    [null, '2026-06-09 14:00:00',
        "{{nome}}, boa tarde! ☀️\n\nSabe o que é o *Confronto Gentil*?\n\nÉ uma técnica desenvolvida pela Psicóloga Daniely que ensina como dialogar com o sabotador interno — *sem culpa e sem repressão*.\n\nÉ uma das partes mais transformadoras da Masterclass.\n\nDois dias! 📅 11/06 às 20h 👇\n" . WPP_LINK],

    [null, '2026-06-09 19:00:00',
        "{{nome}} 🌙\n\nComo está a sua noite?\n\nA gente passa o dia inteiro controlando tudo — e à noite quer uma recompensa. Isso é humano.\n\nO problema não é querer a recompensa. O problema é quando a recompensa é *sempre comida*.\n\nNa Masterclass de domingo, a Daniely vai ensinar como trocar esse padrão. Devagar, sem sofrimento.\n\nAté domingo! 👇\n" . WPP_LINK],

    // ── Jun 10 (D-1): 3 mensagens — manhã / tarde / noite ───────────
    [null, '2026-06-10 09:00:00',
        "{{nome}}! 🔥 *Amanhã é o grande dia!*\n\nMasterclass *\"O Código dos Sabotadores\"*\n📅 11/06 às 20h — ao vivo\n\nSepara um cantinho tranquilo, bloqueia sua agenda e venha aprender o que nenhuma dieta te ensinou.\n\nO link vai sair no grupo VIP amanhã 👇\n" . WPP_LINK],

    [null, '2026-06-10 14:00:00',
        "{{nome}}, boa tarde! ☀️\n\n🔴 Amanhã, 11/06 às 20h!\n\nEsta pode ser a noite que muda o seu relacionamento com a comida para sempre.\n\nO link sai no grupo VIP antes do início. Se ainda não está lá, corre! ⬇️\n\n👇\n" . WPP_LINK],

    [null, '2026-06-10 19:00:00',
        "{{nome}} 🌟\n\nA véspera de uma coisa importante tem um sabor especial.\n\nAmanhã às 20h, você vai entender de uma vez por todas por que se sabota — e o que fazer para parar.\n\n*Anota no celular: 11/06 às 20h*\n\nO link estará no grupo VIP amanhã 👇\n" . WPP_LINK],

    // ── Jun 11 (D-day): manhã + noite ────────────────────────────────
    [null, '2026-06-11 08:00:00',
        "🔴 *HOJE É O DIA*, {{nome}}!\n\nMasterclass *\"O Código dos Sabotadores\"*\n⏰ Hoje às 20h — ao vivo\n\nO link vai sair no grupo VIP antes do início 👇\n" . WPP_LINK],

    [null, '2026-06-11 19:00:00',
        "⏰ *Em 1 hora começa!*\n\n{{nome}}, corre que falta pouco!\n\nO link da live está no grupo VIP agora 👇\n" . WPP_LINK],
];

define('HOTMART_LINK', getenv('HOTMART_LINK') ?: 'https://pay.hotmart.com/emagreser');

// ── Busca sequências ativas ───────────────────────────────────────
$sequences_for_form = sb_get("sequences?is_active=eq.true&select=id,name,description,items&order=created_at.asc");

// ── Interface web ─────────────────────────────────────────────────
$csv_file    = $is_cli ? ($argv[1] ?? null) : ($_FILES['csv']['tmp_name'] ?? null);
$canal       = $is_cli ? ($argv[2] ?? 'ambos') : ($_POST['canal'] ?? 'ambos');
$sequence_id = $is_cli ? ($argv[3] ?? null)    : ($_POST['sequence_id'] ?? null);

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
.card{background:#fff;border-radius:16px;padding:36px;box-shadow:0 8px 32px rgba(0,0,0,.10);width:100%;max-width:640px}
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
.seq-select{width:100%;border:2px solid #0d9488;border-radius:10px;padding:12px;font-size:14px;background:#fff;margin-bottom:8px;color:#1e293b}
.seq-preview{background:#f0fdfa;border:1px solid #99f6e4;border-radius:8px;padding:10px 14px;font-size:12px;color:#0f766e;margin-bottom:22px;display:none}
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
.no-seq{background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:14px 16px;font-size:13px;color:#92400e;margin-bottom:22px}
</style>
</head>
<body>
<div class="card">
  <div class="logo">
    <div class="logo-icon">📤</div>
    <div>
      <h1>Importar Leads</h1>
      <p>EmagreSer 2.0 — Importação com sequência</p>
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

    <label class="field-label">Sequência de mensagens:</label>
    <?php if (empty($sequences_for_form)): ?>
    <div class="no-seq">
      ⚠️ Nenhuma sequência ativa encontrada. Acesse o <strong>Painel Admin → Sequências</strong> para criar sequências antes de importar.
    </div>
    <input type="hidden" name="sequence_id" value="">
    <?php else: ?>
    <select name="sequence_id" id="seq-sel" class="seq-select" required onchange="showSeqPreview(this)">
      <option value="">Selecione uma sequência…</option>
      <?php foreach ($sequences_for_form as $s):
        $cnt      = is_array($s['items']) ? count($s['items']) : 0;
        $wpp_cnt  = is_array($s['items']) ? count(array_filter($s['items'], fn($i) => ($i['type']??'') === 'wpp')) : 0;
        $email_cnt= $cnt - $wpp_cnt;
      ?>
      <option value="<?= htmlspecialchars($s['id']) ?>"
              data-desc="<?= htmlspecialchars($s['description']??'') ?>"
              data-wpp="<?= $wpp_cnt ?>"
              data-email="<?= $email_cnt ?>"
              data-total="<?= $cnt ?>">
        <?= htmlspecialchars($s['name']) ?> (<?= $cnt ?> msgs: <?= $wpp_cnt ?> WPP + <?= $email_cnt ?> email)
      </option>
      <?php endforeach; ?>
    </select>
    <div id="seq-preview" class="seq-preview"></div>
    <?php endif; ?>

    <label class="field-label">Canal de envio (filtra mensagens da sequência):</label>
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

    <button type="submit" class="btn" <?= empty($sequences_for_form) ? 'disabled style="opacity:.5;cursor:not-allowed"' : '' ?>>📤 IMPORTAR E ENFILEIRAR CAMPANHA</button>
  </form>

  <div class="cred">
    <strong>🔐 Credenciais de acesso</strong>
    URL: <code>oficialemagreser.com/import_leads.php</code><br>
    Usuário: qualquer valor · Senha: valor de <code>IMPORT_PASS</code> no <code>_env.php</code><br>
    Senha padrão (sem _env.php): <code>import2026</code>
  </div>
</div>
<script>
function showSeqPreview(sel) {
  const opt = sel.options[sel.selectedIndex];
  const box = document.getElementById('seq-preview');
  if (!sel.value) { box.style.display='none'; return; }
  box.style.display = '';
  box.innerHTML = `<strong>${opt.dataset.wpp} WPP</strong> + <strong>${opt.dataset.email} e-mails</strong> enfileirados por lead.`
    + (opt.dataset.desc ? `<br>${opt.dataset.desc}` : '');
}
</script>
</body>
</html>
<?php
    exit;
endif;

// ── Processamento ─────────────────────────────────────────────────
if (!$csv_file || !file_exists($csv_file)) die("Arquivo CSV não encontrado.\n");

// Carrega sequência selecionada
$seq_items = [];
if ($sequence_id) {
    $seq_data = sb_get("sequences?id=eq." . urlencode($sequence_id) . "&is_active=eq.true&select=name,items&limit=1");
    if (!empty($seq_data[0]['items'])) {
        $seq_items = $seq_data[0]['items'];
        output("<p style='font-family:Arial;color:#0f766e;font-size:14px'>📋 Sequência: <strong>" . htmlspecialchars($seq_data[0]['name']) . "</strong> — " . count($seq_items) . " mensagens</p>");
    }
}
if (empty($seq_items)) {
    output("<p style='color:#dc2626;font-family:Arial'>❌ Nenhuma sequência selecionada ou sequência inativa. Volte e selecione uma sequência.</p>");
    exit;
}

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
    $tipo   = strtolower(trim($row['tipo'] ?? $row['origem'] ?? ''));

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

    // ── Fila a partir da sequência ───────────────────────────────
    $perfis_nomes = [
        'A' => 'Recompensadora', 'B' => 'Piloto Automático',
        'C' => 'Prisioneira do Esforço', 'D' => 'Compensadora Noturna',
    ];
    $nome_perfil = $sab ? ($perfis_nomes[$sab] ?? 'Perfil Sabotador') : 'Perfil Sabotador';

    $vars_email = [
        '{{nome}}'             => htmlspecialchars($name),
        '{{nome_lead}}'        => htmlspecialchars($name),
        '{{nome_perfil}}'      => htmlspecialchars($nome_perfil),
        '{{link_site}}'        => SITE_URL,
        '{{link_wpp}}'         => WPP_LINK,
        '{{link_vip}}'         => WPP_LINK,
        '{{link_hotmart}}'     => HOTMART_LINK,
        '{{link_video_ira}}'   => IRA_VIDEO_URL,
        '{{link_descadastro}}' => SITE_URL . '/descadastro.php?email=' . urlencode($email),
        '{{emoji}}'            => '🎯',
        '{{tipo}}'             => $nome_perfil,
        '{{titulo}}'           => 'Descubra seu padrão comportamental',
        '{{descricao}}'        => '',
    ];

    $tel = $phone ? '55' . ltrim($phone, '0') : '';

    // ── Mensagem especial da Ira (ex-pacientes) ───────────────────
    // Ativado pela coluna "tipo=ira" (ou "origem=ira") no CSV
    if ($tipo === 'ira' && IRA_VIDEO_URL) {
        $send_now = $base->format(DateTime::ATOM);

        if ($tel && strlen($phone) >= 10 && in_array($canal, ['wpp', 'ambos'])) {
            sb_post('whatsapp_queue', [[
                'lead_id'      => $lead_id,
                'to_phone'     => $tel,
                'to_name'      => $name,
                'message'      => "Olá, *{$name}*! 🌿\n\nA Ira gravou um recado especial para você — para quem esteve junto nessa jornada.\n\nAssiste com atenção 👇\n\n" . IRA_VIDEO_URL,
                'scheduled_at' => $send_now,
                'status'       => 'pending',
            ]]);
        }

        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL) && in_array($canal, ['email', 'ambos'])) {
            sb_post('email_queue', [[
                'lead_id'       => $lead_id,
                'template_slug' => 'ira_video_boas_vindas',
                'to_email'      => $email,
                'to_name'       => $name,
                'extra_vars'    => $vars_email,
                'scheduled_at'  => $send_now,
                'status'        => 'pending',
            ]]);
        }
    }

    foreach ($seq_items as $item) {
        $type = $item['type'] ?? '';
        if (!in_array($type, ['wpp', 'email'])) continue;

        // Filtra por canal
        if ($canal === 'email' && $type !== 'email') continue;
        if ($canal === 'wpp'   && $type !== 'wpp')   continue;

        $send_at = seq_schedule($item, $base, $tz);
        if (!$send_at || strtotime($send_at) < time() - 60) continue;

        if ($type === 'email' && $email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $slug = trim($item['template_slug'] ?? '');
            if (!$slug) continue;
            sb_post('email_queue', [[
                'lead_id'       => $lead_id,
                'template_slug' => $slug,
                'to_email'      => $email,
                'to_name'       => $name,
                'extra_vars'    => $vars_email,
                'scheduled_at'  => $send_at,
                'status'        => 'pending',
            ]]);
        } elseif ($type === 'wpp' && $tel && strlen($phone) >= 10) {
            $msg = seq_sub_vars($item['message'] ?? '', $name, $nome_perfil);
            if (!$msg) continue;
            sb_post('whatsapp_queue', [[
                'lead_id'      => $lead_id,
                'to_phone'     => $tel,
                'to_name'      => $name,
                'message'      => $msg,
                'scheduled_at' => $send_at,
                'status'       => 'pending',
            ]]);
        }
    }

    // Marca como enfileirado
    sb_patch("leads?id=eq.{$lead_id}", [
        'sequence_queued_at' => $base->format(DateTime::ATOM),
        'source_campaign'    => 'reengajamento_2026',
        'source'             => 'import',
    ]);

    $ok++;
    if ($ok % 50 === 0) output("✅ {$ok}/{$total} processadas...");
    usleep(50000);
}

$wpp_note = $no_wpp > 0 ? " | Sem telefone (WPP ignorado): {$no_wpp}" : '';
output("<br><strong>✅ Importação concluída!</strong>");
output("Total: {$total} | Importadas: {$ok} | Ignoradas: {$skipped}{$wpp_note} | Erros: " . count($errors));
if ($errors) output("<br>Erros:<br>" . implode('<br>', array_map('htmlspecialchars', $errors)));

// ── Helpers de sequência ─────────────────────────────────────────
function seq_schedule(array $item, DateTime $base, DateTimeZone $tz): ?string {
    if (!empty($item['fixed_date'])) {
        $time = $item['fixed_time'] ?? '09:00';
        $dt = DateTime::createFromFormat('Y-m-d H:i', $item['fixed_date'] . ' ' . $time, $tz);
        return $dt ? $dt->format(DateTime::ATOM) : null;
    }
    $hours = (int)($item['delay_hours'] ?? 0);
    $dt = clone $base;
    $dt->modify("+{$hours} hours");
    return $dt->format(DateTime::ATOM);
}

function seq_sub_vars(string $msg, string $name, string $perfil): string {
    return str_replace(
        ['{{nome_lead}}', '{{nome}}', '{{nome_perfil}}', '{{link_vip}}', '{{link_hotmart}}', '{{link_site}}'],
        [$name, $name, $perfil, WPP_LINK, HOTMART_LINK, SITE_URL],
        $msg
    );
}

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
