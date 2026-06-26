<?php
/**
 * email_trigger.php
 * Chamado pelo frontend após salvar um lead.
 * Enfileira a sequência de e-mails e WhatsApp no Supabase.
 *
 * Uso: POST /email_trigger.php
 * Body JSON: { "lead_id": "uuid", "event": "new_lead" }
 */

if (file_exists(__DIR__ . '/_env.php')) require_once __DIR__ . '/_env.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── CONFIGURAÇÕES ────────────────────────────────────────────────
define('SUPABASE_URL',         'https://drgrwpmhmrrhxuwxabow.supabase.co');
define('SUPABASE_SERVICE_KEY', getenv('SUPABASE_SERVICE_KEY') ?: '');
define('SITE_URL',             'https://www.oficialemagreser.com');
define('WPP_LINK',             'https://chat.whatsapp.com/GsMAVm3KVncGNR5nHRQ3yQ');
define('ZAPI_INSTANCE',        getenv('ZAPI_INSTANCE')        ?: '');
define('ZAPI_TOKEN',           getenv('ZAPI_TOKEN')           ?: '');
define('HOTMART_LINK',         getenv('HOTMART_LINK')         ?: 'https://pay.hotmart.com/emagreser');
// ────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['error' => 'POST only']); exit; }

$body = json_decode(file_get_contents('php://input'), true);

// Suporte ao formato do Supabase Database Webhook (INSERT em leads)
if (isset($body['type']) && $body['type'] === 'INSERT' && isset($body['record'])) {
    $lead_id = $body['record']['id'] ?? null;
} else {
    // Formato legado: { lead_id, event }
    $lead_id = $body['lead_id'] ?? null;
}
$event = 'new_lead';

if (!$lead_id) { echo json_encode(['error' => 'lead_id required']); exit; }

// Busca dados do lead
$lead = sb_get("leads?id=eq.{$lead_id}&select=*&limit=1");
if (empty($lead)) { echo json_encode(['error' => 'lead not found']); exit; }
$lead = $lead[0];

// ── Variáveis do lead definidas PRIMEIRO (necessário para todos os checks abaixo) ──
$now    = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
$email  = $lead['email'] ?? '';
$name   = $lead['name']  ?? 'você';
$phone  = preg_replace('/\D/', '', $lead['phone'] ?? '');
$perfil = strtoupper($lead['sabotador'] ?? '');

$perfis = [
    'A' => ['emoji'=>'🍫','tipo'=>'Perfil A — A Recompensadora','titulo'=>'Você usa a comida como prêmio e alívio emocional'],
    'B' => ['emoji'=>'🤖','tipo'=>'Perfil B — Piloto Automático','titulo'=>'Você come sem perceber — o automático assumiu o controle'],
    'C' => ['emoji'=>'⚡','tipo'=>'Perfil C — Prisioneira do Esforço','titulo'=>'Você esgota seu autocontrole e desiste por exaustão mental'],
    'D' => ['emoji'=>'🌙','tipo'=>'Perfil D — Compensadora Noturna','titulo'=>'Você restringe o dia todo e perde o controle à noite'],
];
$p = $perfis[$perfil] ?? ['emoji'=>'🎯','tipo'=>'Perfil identificado','titulo'=>'Seu mapeamento está completo'];

$vars = [
    '{{nome}}'             => htmlspecialchars($name),
    '{{nome_lead}}'        => htmlspecialchars($name),
    '{{nome_perfil}}'      => htmlspecialchars($p['tipo']),
    '{{email}}'            => $email,
    '{{emoji}}'            => $p['emoji'],
    '{{tipo}}'             => $p['tipo'],
    '{{titulo}}'           => $p['titulo'],
    '{{descricao}}'        => '',
    '{{link_vip}}'         => WPP_LINK,
    '{{link_wpp}}'         => WPP_LINK,
    '{{link_hotmart}}'     => HOTMART_LINK,
    '{{link_site}}'        => SITE_URL,
    '{{link_descadastro}}' => SITE_URL . '/descadastro.php?email=' . urlencode($email),
];

// ── Evita enfileirar duas vezes pelo mesmo lead_id ────────────────
if (!empty($lead['sequence_queued_at'])) {
    echo json_encode(['ok' => true, 'msg' => 'already queued']); exit;
}

// ── Busca slugs já pendentes/processando para este e-mail ─────────
// Necessário para evitar violação do UNIQUE INDEX email_queue_unique_pending
// (que bloqueia todo o batch se QUALQUER linha conflitar)
$already_pending_slugs = [];
if ($email) {
    $existing_pending = sb_get("email_queue?to_email=eq." . rawurlencode($email) . "&status=in.(pending,processing)&select=template_slug&limit=20");
    if (is_array($existing_pending) && !isset($existing_pending['code'])) {
        $already_pending_slugs = array_column($existing_pending, 'template_slug');
    }
}

// ── SEQUÊNCIA DE E-MAILS (nurturing evergreen) ──────────────────
$email_sequence = [
    ['slug' => 'mc_boas_vindas',     'delay_hours' => 0],    // D+0
    ['slug' => 'mc_conteudo_perfil', 'delay_hours' => 24],   // D+1
    ['slug' => 'mc_prova_social',    'delay_hours' => 72],   // D+3
];

$email_rows = [];
foreach ($email_sequence as $step) {
    // Pula se já existe entrada pending/processing para este slug+email (evita unique constraint)
    if (in_array($step['slug'], $already_pending_slugs)) continue;

    if (isset($step['scheduled_at'])) {
        $send_at = $step['scheduled_at'];
    } else {
        $dt = clone $now;
        $dt->modify("+{$step['delay_hours']} hours");
        $send_at = $dt->format(DateTime::ATOM);
    }
    // Não enfileira e-mails já passados (janela de 60s de tolerância)
    if (strtotime($send_at) < time() - 60) continue;

    $email_rows[] = [
        'lead_id'       => $lead_id,
        'template_slug' => $step['slug'],
        'to_email'      => $email,
        'to_name'       => $name,
        'extra_vars'    => $vars,
        'scheduled_at'  => $send_at,
        'status'        => 'pending',
    ];
}

// Insere e-mails um por um para evitar que uma falha cancele todo o batch
$emails_queued = 0;
foreach ($email_rows as $row) {
    $ok = sb_post_check('email_queue', [$row]);
    if ($ok) $emails_queued++;
}

// ── SEQUÊNCIA WPP (6 mensagens — nurturing evergreen) ────────────
if ($phone && strlen($phone) >= 10) {
    $wpp_rows = [];
    $tel = '55' . $phone;
    $perfil_nome = $p['tipo'];

    // WPP 1 — D+0 · Boas-vindas + perfil
    $wpp_rows[] = ['lead_id'=>$lead_id,'to_phone'=>$tel,'to_name'=>$name,'scheduled_at'=>$now->format(DateTime::ATOM),'status'=>'pending',
        'message' => "Olá, *{$name}*! 🌱\n\nAqui é a Daniely do Programa EmagreSer.\n\nVocê acabou de descobrir algo que a maioria das mulheres leva anos para entender — ou nunca entende.\n\nVocê é o *{$perfil_nome}*.\n\nIsso significa que o seu cérebro tem um padrão específico que comanda suas escolhas alimentares. Não é falta de força de vontade. É neurobiologia.\n\nGuarda esse nome: *{$perfil_nome}*. Vamos trabalhar em cima dele juntas.\n\nEntre no nosso Grupo VIP e acompanhe de perto o que preparamos para setembro 👇\n" . WPP_LINK,
    ];

    // WPP 2 — D+1 · Engajamento emocional
    $d1 = clone $now; $d1->modify('+32 hours');
    $wpp_rows[] = ['lead_id'=>$lead_id,'to_phone'=>$tel,'to_name'=>$name,'scheduled_at'=>$d1->format(DateTime::ATOM),'status'=>'pending',
        'message' => "{$name}, uma pergunta rápida 👇\n\nQuando você tenta manter a alimentação em dia e escorrega... o que você sente primeiro?\n\n😔 Culpa — \"eu estraguei tudo de novo\"\n😤 Raiva — \"não tenho força de vontade\"\n😶 Nada — \"já esperava isso de mim\"\n\nResponde com o emoji que mais te representa.\n\nPergunto porque a resposta diz muito sobre como o seu *{$perfil_nome}* opera — e é exatamente isso que trabalhamos no EmagreSer.",
    ];

    // WPP 3 — D+2 · Apresentação Ira Soraya
    $d2 = clone $now; $d2->modify('+48 hours');
    $wpp_rows[] = ['lead_id'=>$lead_id,'to_phone'=>$tel,'to_name'=>$name,'scheduled_at'=>$d2->format(DateTime::ATOM),'status'=>'pending',
        'message' => "Oi *{$name}*! Aqui é a *Ira Soraya*, nutricionista do EmagreSer 🥗\n\nQueria me apresentar melhor, porque a gente vai passar 12 semanas juntas e faz toda diferença você me conhecer de verdade.\n\nMinha missão é mostrar que nutrição não precisa ser sinônimo de restrição, culpa ou cardápio chato — especialmente para o *{$perfil_nome}*.\n\nSe quiser me acompanhar:\n📲 @irasorayanutri no Instagram\n🌐 www.irasorayanutri.com.br\n\n*Ira* 🌿",
    ];

    // WPP 4 — D+3 · Quebra objeção "já tentei de tudo"
    $d3 = clone $now; $d3->modify('+80 hours');
    $wpp_rows[] = ['lead_id'=>$lead_id,'to_phone'=>$tel,'to_name'=>$name,'scheduled_at'=>$d3->format(DateTime::ATOM),'status'=>'pending',
        'message' => "{$name}, deixa eu ser direta com você 👇\n\nToda mulher que chega até o EmagreSer já tentou de tudo.\n\nLow carb ✅  Jejum ✅  Reeducação ✅  Personal ✅  App de calorias ✅\n\nE sabe o que todas essas tentativas têm em comum?\n\nIgnoram completamente o *{$perfil_nome}*.\n\nNenhuma foi feita para você. Foram feitas para um modelo genérico de pessoa que não existe.\n\nO EmagreSer foi criado para o seu perfil específico. Quer saber mais?\n" . WPP_LINK,
    ];

    // WPP 5 — D+4 · Apresentação Daniely Albuquerque
    $d4 = clone $now; $d4->modify('+96 hours');
    $wpp_rows[] = ['lead_id'=>$lead_id,'to_phone'=>$tel,'to_name'=>$name,'scheduled_at'=>$d4->format(DateTime::ATOM),'status'=>'pending',
        'message' => "Oi *{$name}* 💚 Aqui é a *Daniely Albuquerque*, psicóloga do EmagreSer.\n\nVocê já parou para pensar por que é tão difícil manter a consistência na alimentação — mesmo quando você quer muito, sabe o que fazer e tenta de verdade?\n\nNão é falta de disciplina. É o seu *{$perfil_nome}* operando no piloto automático.\n\nA abordagem que aplico no EmagreSer é prática: como o cérebro toma decisões alimentares, e como reprogramar esses padrões de dentro para fora.\n\nMe acompanha:\n📲 @psidanielyalbuquerque\n🌐 www.danielydealbuquerque.com.br\n\n*Daniely* 🙏",
    ];

    // WPP 6 — D+5 · Conheça o programa
    $d5 = clone $now; $d5->modify('+120 hours');
    $wpp_rows[] = ['lead_id'=>$lead_id,'to_phone'=>$tel,'to_name'=>$name,'scheduled_at'=>$d5->format(DateTime::ATOM),'status'=>'pending',
        'message' => "*{$name}*, criamos uma página com tudo que você precisa saber sobre o Programa EmagreSer 💡\n\n✅ Como funcionam as 12 semanas\n✅ O que você recebe dentro do programa\n✅ A metodologia da Daniely e da Ira juntas\n✅ O cronograma semana a semana\n✅ Quem é para você (e quem não é)\n\n👉 www.oficialemagreser.com/programa\n\nDá uma lida — e se fizer sentido, entre no Grupo VIP para saber em primeira mão quando abrirmos as vagas de setembro 👇\n" . WPP_LINK . "\n\n*Daniely e Ira* 🌿",
    ];
    $wpp_queued = 0;
    foreach (array_values($wpp_rows) as $wrow) {
        if (sb_post_check('whatsapp_queue', [$wrow])) $wpp_queued++;
    }
}

// Marca lead como enfileirado (só aqui, depois de tudo inserido)
sb_patch("leads?id=eq.{$lead_id}", ['sequence_queued_at' => $now->format(DateTime::ATOM)]);

echo json_encode(['ok' => true, 'emails' => $emails_queued, 'wpp' => $wpp_queued ?? 0]);

// ── FUNÇÕES SUPABASE ─────────────────────────────────────────────

// Retorna true se inseriu com sucesso (2xx), false se falhou
function sb_post_check(string $table, array $data): bool {
    $ch = curl_init(SUPABASE_URL . '/rest/v1/' . $table);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'apikey: ' . SUPABASE_SERVICE_KEY,
            'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
            'Content-Type: application/json',
            'Prefer: return=minimal',
        ],
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

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
