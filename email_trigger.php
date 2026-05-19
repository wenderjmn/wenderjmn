<?php
/**
 * email_trigger.php
 * Chamado pelo frontend após salvar um lead.
 * Enfileira a sequência de e-mails e WhatsApp no Supabase.
 *
 * Uso: POST /email_trigger.php
 * Body JSON: { "lead_id": "uuid", "event": "new_lead" }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── CONFIGURAÇÕES ────────────────────────────────────────────────
define('SUPABASE_URL',         getenv('SUPABASE_URL')         ?: 'https://drgrwpmhmrrhxuwxabow.supabase.co');
define('SUPABASE_SERVICE_KEY', getenv('SUPABASE_SERVICE_KEY') ?: '***REMOVED_SUPABASE_KEY***');
define('SITE_URL',             getenv('SITE_URL')             ?: 'https://emagreser.danielydealbuquerque.com.br');
define('WPP_LINK',             getenv('WPP_LINK')             ?: 'https://chat.whatsapp.com/GsMAVm3KVncGNR5nHRQ3yQ');
define('ZAPI_INSTANCE',        '***REMOVED_ZAPI_INSTANCE***');
define('ZAPI_TOKEN',           '***REMOVED_ZAPI_TOKEN***');
// ────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['error' => 'POST only']); exit; }

$body = json_decode(file_get_contents('php://input'), true);
$lead_id = $body['lead_id'] ?? null;
$event   = $body['event']   ?? 'new_lead';

if (!$lead_id) { echo json_encode(['error' => 'lead_id required']); exit; }

// Busca dados do lead
$lead = sb_get("leads?id=eq.{$lead_id}&select=*&limit=1");
if (empty($lead)) { echo json_encode(['error' => 'lead not found']); exit; }
$lead = $lead[0];

// Evita enfileirar duas vezes
if (!empty($lead['sequence_queued_at'])) {
    echo json_encode(['ok' => true, 'msg' => 'already queued']); exit;
}

$now   = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
$email = $lead['email'] ?? '';
$name  = $lead['name']  ?? 'você';
$phone = preg_replace('/\D/', '', $lead['phone'] ?? '');
$perfil = strtoupper($lead['sabotador'] ?? '');

$perfis = [
    'A' => ['emoji'=>'🍫','tipo'=>'Perfil A — A Recompensadora','titulo'=>'Você usa a comida como prêmio e alívio emocional'],
    'B' => ['emoji'=>'🤖','tipo'=>'Perfil B — Piloto Automático','titulo'=>'Você come sem perceber — o automático assumiu o controle'],
    'C' => ['emoji'=>'⚡','tipo'=>'Perfil C — Prisioneira do Esforço','titulo'=>'Você esgota seu autocontrole e desiste por exaustão mental'],
    'D' => ['emoji'=>'🌙','tipo'=>'Perfil D — Compensadora Noturna','titulo'=>'Você restringe o dia todo e perde o controle à noite'],
];
$p = $perfis[$perfil] ?? ['emoji'=>'🎯','tipo'=>'Perfil identificado','titulo'=>'Seu mapeamento está completo'];

$vars = [
    '{{nome}}'          => htmlspecialchars($name),
    '{{email}}'         => $email,
    '{{emoji}}'         => $p['emoji'],
    '{{tipo}}'          => $p['tipo'],
    '{{titulo}}'        => $p['titulo'],
    '{{descricao}}'     => '',
    '{{link_wpp}}'      => WPP_LINK,
    '{{link_site}}'     => SITE_URL,
    '{{link_descadastro}}' => SITE_URL . '/descadastro.php?email=' . urlencode($email),
];

// ── DATAS FIXAS ───────────────────────────────────────────────────
$masterclass      = new DateTime('2026-06-11 08:00:00', new DateTimeZone('America/Sao_Paulo'));
$masterclass_1h   = new DateTime('2026-06-11 19:00:00', new DateTimeZone('America/Sao_Paulo'));
$masterclass_3d   = new DateTime('2026-06-08 09:00:00', new DateTimeZone('America/Sao_Paulo'));
$masterclass_eve  = new DateTime('2026-06-10 09:00:00', new DateTimeZone('America/Sao_Paulo'));

// ── SEQUÊNCIA DE E-MAILS (11 e-mails) ───────────────────────────
$email_sequence = [
    ['slug' => 'boas_vindas',       'delay_hours' => 0],
    ['slug' => 'nutricao_d1',       'delay_hours' => 24],
    ['slug' => 'prova_social_d2',   'delay_hours' => 48],
    ['slug' => 'urgencia_d3',       'delay_hours' => 72],
    ['slug' => 'conteudo_d5',       'delay_hours' => 120],
    ['slug' => 'depoimento_d7',     'delay_hours' => 168],
    ['slug' => 'antecipacao_d10',   'delay_hours' => 240],
    ['slug' => 'lembrete_3dias',    'scheduled_at' => $masterclass_3d->format(DateTime::ATOM)],
    ['slug' => 'amanha_masterclass','scheduled_at' => $masterclass_eve->format(DateTime::ATOM)],
    ['slug' => 'masterclass_hoje',  'scheduled_at' => $masterclass->format(DateTime::ATOM)],
    ['slug' => 'masterclass_1h',    'scheduled_at' => $masterclass_1h->format(DateTime::ATOM)],
];

$email_rows = [];
foreach ($email_sequence as $step) {
    if (isset($step['scheduled_at'])) {
        $send_at = $step['scheduled_at'];
    } else {
        $dt = clone $now;
        $dt->modify("+{$step['delay_hours']} hours");
        $send_at = $dt->format(DateTime::ATOM);
    }
    // Não enfileira e-mails passados da masterclass
    if (strtotime($send_at) < time() - 60) continue;

    $email_rows[] = [
        'lead_id'      => $lead_id,
        'template_slug'=> $step['slug'],
        'to_email'     => $email,
        'to_name'      => $name,
        'extra_vars'   => $vars,
        'scheduled_at' => $send_at,
        'status'       => 'pending',
    ];
}
if (!empty($email_rows)) sb_post('email_queue', $email_rows);

// ── SEQUÊNCIA DE WHATSAPP (7 mensagens) ──────────────────────────
if ($phone && strlen($phone) >= 10) {
    $wpp_rows = [];
    $tel = '55' . $phone;

    // WA 1 — D+0 imediato: resultado do perfil
    $wpp_rows[] = [
        'lead_id'      => $lead_id,
        'to_phone'     => $tel,
        'message'      => "Oi, {$name}! 🎉\n\nSeu mapeamento revelou que você é:\n\n*{$p['emoji']} {$p['tipo']}*\n\n_{$p['titulo']}_\n\nIsso não é fraqueza — é o seu cérebro operando de um jeito que você nunca aprendeu a gerenciar.\n\nA Masterclass *\"O Código dos Sabotadores\"* é dia 11/06 às 20h e vai mostrar exatamente o que fazer para quebrar esse ciclo. 🔓\n\nEntre no grupo VIP para receber o link da transmissão 👇\n" . WPP_LINK,
        'scheduled_at' => $now->format(DateTime::ATOM),
        'status'       => 'pending',
    ];

    // WA 2 — D+1: dica rápida do perfil
    $d1 = clone $now; $d1->modify('+26 hours');
    $wpp_rows[] = [
        'lead_id'      => $lead_id,
        'to_phone'     => $tel,
        'message'      => "{$name}, uma coisa rápida 💡\n\nQuem tem o *{$p['tipo']}* geralmente sente que \"já sabe o que precisa fazer\" mas trava na hora de executar.\n\nO problema não é falta de informação. É o *padrão neurológico* por trás das suas escolhas.\n\nNa Masterclass vamos falar exatamente sobre como quebrar isso. Já garantiu seu lugar no grupo? 👇\n" . WPP_LINK,
        'scheduled_at' => $d1->format(DateTime::ATOM),
        'status'       => 'pending',
    ];

    // WA 3 — D+3: prova social / depoimento
    $d3 = clone $now; $d3->modify('+74 hours');
    $wpp_rows[] = [
        'lead_id'      => $lead_id,
        'to_phone'     => $tel,
        'message'      => "{$name}, deixa eu te contar algo 🙌\n\nA Carla, que também tem o *{$p['tipo']}*, me escreveu essa semana:\n\n_\"Eu tentei tudo. Dieta, academia, aplicativos. Nada funcionava porque eu não entendia por que eu sabotava. Depois que entendi meu padrão, tudo mudou.\"_\n\nÉ exatamente sobre isso que a Dra. Daniely vai falar na Masterclass dia 11/06. 🎯\n\nEstá no grupo VIP? O link da live vai sair lá 👇\n" . WPP_LINK,
        'scheduled_at' => $d3->format(DateTime::ATOM),
        'status'       => 'pending',
    ];

    // WA 4 — D+5: pergunta de engajamento
    $d5 = clone $now; $d5->modify('+122 hours');
    $wpp_rows[] = [
        'lead_id'      => $lead_id,
        'to_phone'     => $tel,
        'message'      => "{$name}, me conta uma coisa 👇\n\nQual dessas situações te identifica mais?\n\n🍫 *A* — Você come bem o dia todo e à noite desanda\n⚡ *B* — Você começa a semana firme e na quarta já desistiu\n🤖 *C* — Você come sem nem perceber, no automático\n🌙 *D* — Você restringe muito e compensa depois\n\nResponde aqui com a letra! Vou te mandar uma dica personalizada 😊",
        'scheduled_at' => $d5->format(DateTime::ATOM),
        'status'       => 'pending',
    ];

    // WA 5 — 3 dias antes da masterclass
    $wpp_rows[] = [
        'lead_id'      => $lead_id,
        'to_phone'     => $tel,
        'message'      => "⚠️ {$name}, faltam *3 dias* para a Masterclass!\n\n📅 *11 de junho, 20h*\n\n\"O Código dos Sabotadores\" com a Dra. Daniely de Albuquerque e a Nutri Ira Soraya\n\nSerá online, ao vivo e *gratuito*. Mas o link só vai para quem está no grupo VIP 👇\n" . WPP_LINK,
        'scheduled_at' => $masterclass_3d->format(DateTime::ATOM),
        'status'       => 'pending',
    ];

    // WA 6 — véspera da masterclass
    $wpp_rows[] = [
        'lead_id'      => $lead_id,
        'to_phone'     => $tel,
        'message'      => "{$name}! 🔥 *Amanhã é o grande dia!*\n\nMasterclass *\"O Código dos Sabotadores\"*\n📅 11/06 às 20h — ao vivo\n\nSepara um cantinho tranquilo, bloqueia sua agenda e venha aprender o que nenhuma dieta te ensinou.\n\nO link da transmissão vai sair amanhã no grupo VIP 👇\n" . WPP_LINK,
        'scheduled_at' => $masterclass_eve->format(DateTime::ATOM),
        'status'       => 'pending',
    ];

    // WA 7 — dia da masterclass (manhã)
    $wpp_rows[] = [
        'lead_id'      => $lead_id,
        'to_phone'     => $tel,
        'message'      => "🔴 *HOJE É O DIA*, {$name}!\n\nMasterclass *\"O Código dos Sabotadores\"*\n⏰ Hoje às 20h — ao vivo\n\nO link da transmissão vai sair no grupo VIP antes do início.\n\nNão esquece: anota as dúvidas que você quer tirar ao vivo! ✏️\n\n👇\n" . WPP_LINK,
        'scheduled_at' => $masterclass->format(DateTime::ATOM),
        'status'       => 'pending',
    ];

    // WA 8 — 1h antes
    $wpp_rows[] = [
        'lead_id'      => $lead_id,
        'to_phone'     => $tel,
        'message'      => "⏰ *Em 1 hora começa!*\n\n{$name}, corre que falta pouco!\n\nO link da live está no grupo VIP agora 👇\n" . WPP_LINK,
        'scheduled_at' => $masterclass_1h->format(DateTime::ATOM),
        'status'       => 'pending',
    ];

    // Remove mensagens com data já passada
    $wpp_rows = array_filter($wpp_rows, fn($r) => strtotime($r['scheduled_at']) >= time() - 60);
    if (!empty($wpp_rows)) sb_post('whatsapp_queue', array_values($wpp_rows));
}

// Marca lead como enfileirado
sb_patch("leads?id=eq.{$lead_id}", ['sequence_queued_at' => $now->format(DateTime::ATOM)]);

echo json_encode(['ok' => true, 'emails' => count($email_rows)]);

// ── FUNÇÕES SUPABASE ─────────────────────────────────────────────
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
