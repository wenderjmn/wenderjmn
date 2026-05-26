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

// ── DATAS FIXAS (Masterclass 11/06/2026) ─────────────────────────
$tz = new DateTimeZone('America/Sao_Paulo');
$dt_07_09h = new DateTime('2026-06-07 09:00:00', $tz);
$dt_08_09h = new DateTime('2026-06-08 09:00:00', $tz);
$dt_08_17h = new DateTime('2026-06-08 17:00:00', $tz);
$dt_09_09h = new DateTime('2026-06-09 09:00:00', $tz);
$dt_09_19h = new DateTime('2026-06-09 19:00:00', $tz);
$dt_10_09h = new DateTime('2026-06-10 09:00:00', $tz);
$dt_10_17h = new DateTime('2026-06-10 17:00:00', $tz);
$dt_11_08h = new DateTime('2026-06-11 08:00:00', $tz);
$dt_11_14h = new DateTime('2026-06-11 14:00:00', $tz);
$dt_11_19h = new DateTime('2026-06-11 19:00:00', $tz);

// ── SEQUÊNCIA DE E-MAILS (7 e-mails) ────────────────────────────
$email_sequence = [
    ['slug' => 'mc_boas_vindas',     'delay_hours' => 0],    // D+0 09h
    ['slug' => 'mc_conteudo_perfil', 'delay_hours' => 24],   // D+1 09h
    ['slug' => 'mc_prova_social',    'delay_hours' => 72],   // D+3 09h
    ['slug' => 'mc_vespera',         'delay_hours' => 143],  // D+6 08h
    ['slug' => 'mc_objecao_tempo',   'scheduled_at' => $dt_08_09h->format(DateTime::ATOM)],
    ['slug' => 'mc_carta_daniely',   'scheduled_at' => $dt_10_09h->format(DateTime::ATOM)],
    ['slug' => 'mc_ultimo_dia',      'scheduled_at' => $dt_11_08h->format(DateTime::ATOM)],
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

// ── SEQUÊNCIA WPP (13 mensagens — Masterclass 2026) ──────────────
if ($phone && strlen($phone) >= 10) {
    $wpp_rows = [];
    $tel = '55' . $phone;
    $perfil_nome = $p['tipo']; // ex: "Perfil A — A Recompensadora"

    // WPP 1 — D+0 09h · Boas-vindas + perfil
    $wpp_rows[] = ['lead_id'=>$lead_id,'to_phone'=>$tel,'to_name'=>$name,'scheduled_at'=>$now->format(DateTime::ATOM),'status'=>'pending',
        'message' => "Olá, *{$name}*! 🌱\n\nAqui é a Daniely do Programa EmagreSer.\n\nVocê acabou de descobrir algo que a maioria das mulheres leva anos para entender — ou nunca entende.\n\nVocê é o *{$perfil_nome}*.\n\nIsso significa que o seu cérebro tem um padrão específico que comanda suas escolhas alimentares. Não é falta de força de vontade. É neurobiologia.\n\nGuarda esse nome: *{$perfil_nome}*.\n\nNa Masterclass do dia *11/06 às 20h*, a Daniely vai explicar ao vivo o que esse padrão faz com você — e o que muda quando você finalmente entende ele.\n\nGarante sua vaga no Grupo VIP agora 👇\n" . WPP_LINK,
    ];

    // WPP 2 — D+1 17h · Engajamento
    $d1_17 = clone $now; $d1_17->modify('+32 hours');
    $wpp_rows[] = ['lead_id'=>$lead_id,'to_phone'=>$tel,'to_name'=>$name,'scheduled_at'=>$d1_17->format(DateTime::ATOM),'status'=>'pending',
        'message' => "{$name}, uma pergunta rápida 👇\n\nQuando você tenta manter a alimentação em dia e escorrega... o que você sente primeiro?\n\n😔 Culpa — \"eu estraguei tudo de novo\"\n😤 Raiva — \"não tenho força de vontade\"\n😶 Nada — \"já esperava isso de mim\"\n\nResponde com o emoji que mais te representa.\n\nPergunto porque a resposta diz muito sobre como o seu perfil opera — e o que a Daniely vai mostrar na Masterclass do dia *11/06* vai fazer muito sentido pra você.",
    ];

    // WPP 3 — D+3 17h · Quebra objeção "já tentei de tudo"
    $d3_17 = clone $now; $d3_17->modify('+80 hours');
    $wpp_rows[] = ['lead_id'=>$lead_id,'to_phone'=>$tel,'to_name'=>$name,'scheduled_at'=>$d3_17->format(DateTime::ATOM),'status'=>'pending',
        'message' => "{$name}, deixa eu ser direta com você 👇\n\nToda mulher que chega até o EmagreSer já tentou de tudo.\n\nLow carb ✅\nJejum intermitente ✅\nReeducação alimentar ✅\nPersonal trainer ✅\nAplicativo de calorias ✅\n\nE sabe o que todas essas tentativas têm em comum?\n\nIgnoram completamente o *{$perfil_nome}*.\n\nNenhuma delas foi feita pra você. Foram feitas para um modelo genérico de pessoa que não existe.\n\nÉ por isso que funcionaram por um tempo e pararam.\n\nNa Masterclass do dia *11/06 às 20h*, a Daniely vai mostrar exatamente o que precisa ser diferente desta vez.\n\nJá está no grupo VIP? " . WPP_LINK,
    ];

    // WPP 4 — D+6 08h · Véspera intensidade
    $d6_08 = clone $now; $d6_08->modify('+143 hours');
    $wpp_rows[] = ['lead_id'=>$lead_id,'to_phone'=>$tel,'to_name'=>$name,'scheduled_at'=>$d6_08->format(DateTime::ATOM),'status'=>'pending',
        'message' => "*{$name}*, AMANHÃ é o dia. 🔥\n\n11/06 às 20h — Masterclass \"O Código dos Sabotadores: Fim do Efeito Sanfona\"\n\nEu sei que você está ocupada. Eu sei que você já viu muita promessa.\n\nMas me responde uma coisa:\n\nQuantos anos você já está nesse ciclo de tentar, conseguir por um tempo e voltar para o começo?\n\nA Masterclass de amanhã não é mais uma aula de dieta.\n\nÉ a primeira vez que alguém vai olhar diretamente para o *{$perfil_nome}* e te mostrar o que está acontecendo no seu cérebro.\n\nO link de acesso está no Grupo VIP 👇\n" . WPP_LINK . "\n\nAmanhã às 20h. Não deixa pra última hora.",
    ];

    // WPP 5 — D+6 19h · Lembrete noturno
    $d6_19 = clone $now; $d6_19->modify('+154 hours');
    $wpp_rows[] = ['lead_id'=>$lead_id,'to_phone'=>$tel,'to_name'=>$name,'scheduled_at'=>$d6_19->format(DateTime::ATOM),'status'=>'pending',
        'message' => "Uma coisa antes de dormir, *{$name}* 🌙\n\nAmanhã às 20h é a Masterclass.\n\nJá separou o horário? Já avisou que vai estar online?\n\nEsse é o tipo de coisa que a gente sempre deixa pra depois — e depois não vem.\n\nO link está no grupo VIP 👇\n" . WPP_LINK . "\n\nAté amanhã. 🌿\n*Daniely e Ira*",
    ];

    // WPP 6 — 08/06 09h · Pós-masterclass + oferta
    $wpp_rows[] = ['lead_id'=>$lead_id,'to_phone'=>$tel,'to_name'=>$name,'scheduled_at'=>$dt_08_09h->format(DateTime::ATOM),'status'=>'pending',
        'message' => "*{$name}*, a Masterclass foi ontem.\n\nSe você assistiu: você sabe o que fazer agora.\n\nSe você não conseguiu assistir: as vagas do Programa ainda estão abertas por poucos dias — e quem veio pelo mapeamento do perfil entra com condições que não aparecem em nenhum outro canal.\n\nMas essas condições têm data para acabar.\n\nEnquanto você lê essa mensagem, outras mulheres com o *{$perfil_nome}* estão tomando a decisão.\n\nA pergunta não é se você quer mudar.\nA pergunta é: você vai agir agora ou vai esperar mais um recomeço?\n\n👉 " . HOTMART_LINK,
    ];

    // WPP 7 — 08/06 17h · Prova social 2
    $wpp_rows[] = ['lead_id'=>$lead_id,'to_phone'=>$tel,'to_name'=>$name,'scheduled_at'=>$dt_08_17h->format(DateTime::ATOM),'status'=>'pending',
        'message' => "*{$name}*, deixa eu te contar o que aconteceu na Masterclass 👇\n\nTinham mulheres de todo o Brasil. Professoras, médicas, mães, empreendedoras.\n\nTodas com histórias diferentes. Todas com o mesmo cansaço: de tentar, conseguir por um tempo, e voltar ao começo.\n\nSabe o que mais apareceu nos comentários ao vivo?\n\n*\"Finalmente alguém explicou o que acontece comigo de verdade.\"*\n\nIsso é o EmagreSer.\n\nAs vagas com condições especiais ainda estão abertas — por pouco tempo.\n\n👉 " . HOTMART_LINK,
    ];

    // WPP 8 — 09/06 09h · Escassez
    $wpp_rows[] = ['lead_id'=>$lead_id,'to_phone'=>$tel,'to_name'=>$name,'scheduled_at'=>$dt_09_09h->format(DateTime::ATOM),'status'=>'pending',
        'message' => "*{$name}*, atualização importante 👇\n\nAs vagas com as condições especiais para quem fez o mapeamento estão acabando.\n\nNão é pressão. É a realidade do programa — o acompanhamento é próximo e o número de participantes por turma é limitado.\n\nQuando fechar, fecha.\n\nA próxima oportunidade de entrar no EmagreSer será com valor cheio e sem os bônus de quem veio pelo perfil sabotador.\n\nVocê tem o mapa. Falta só dar o próximo passo.\n\n👉 " . HOTMART_LINK,
    ];

    // WPP 9 — 09/06 19h · Medo de ficar de fora
    $wpp_rows[] = ['lead_id'=>$lead_id,'to_phone'=>$tel,'to_name'=>$name,'scheduled_at'=>$dt_09_19h->format(DateTime::ATOM),'status'=>'pending',
        'message' => "*{$name}*, uma última coisa hoje 🌙\n\nPensa comigo:\n\nDaqui a 12 semanas, onde você quer estar?\n\nNo mesmo ciclo — tentando, conseguindo por um tempo, recomeçando?\n\nOu com um mapa real do que acontece no seu cérebro, e uma estratégia feita especificamente para o *{$perfil_nome}*?\n\nA decisão de hoje determina qual das duas respostas vai ser verdade.\n\nAs vagas fecham em breve.\n\n👉 " . HOTMART_LINK,
    ];

    // WPP 10 — 10/06 17h · Penúltimo dia
    $wpp_rows[] = ['lead_id'=>$lead_id,'to_phone'=>$tel,'to_name'=>$name,'scheduled_at'=>$dt_10_17h->format(DateTime::ATOM),'status'=>'pending',
        'message' => "*{$name}*, amanhã as vagas fecham. 🔴\n\nNão vou encher de texto.\n\nVocê fez o mapeamento. Você sabe qual é o seu perfil. Você esteve na Masterclass (ou viu o conteúdo).\n\nA única pergunta que fica é:\n\n*Você vai agir ou vai esperar mais um recomeço?*\n\n👉 " . HOTMART_LINK . "\n\nDaniely e Ira",
    ];

    // WPP 11 — 11/06 08h · Último dia
    $wpp_rows[] = ['lead_id'=>$lead_id,'to_phone'=>$tel,'to_name'=>$name,'scheduled_at'=>$dt_11_08h->format(DateTime::ATOM),'status'=>'pending',
        'message' => "*{$name}* — hoje é o último dia. 🚨\n\nAs vagas com condições especiais para quem fez o mapeamento encerram hoje à meia-noite.\n\nDepois disso: valor cheio, sem os bônus exclusivos, sem a condição de quem veio pelo perfil sabotador.\n\nVocê tem o mapa. Você sabe o que está travando você.\n\nA decisão é só sua — e ela é agora.\n\n👉 " . HOTMART_LINK,
    ];

    // WPP 12 — 11/06 14h · Últimas horas
    $wpp_rows[] = ['lead_id'=>$lead_id,'to_phone'=>$tel,'to_name'=>$name,'scheduled_at'=>$dt_11_14h->format(DateTime::ATOM),'status'=>'pending',
        'message' => "*{$name}*, últimas horas ⏳\n\nFaltam menos de 10h para as vagas fecharem.\n\nSe você ainda está em dúvida, me responde uma coisa:\n\nO que te impede de dar esse passo agora?\n\nResponde aqui — leio todas as mensagens.\n\n👉 " . HOTMART_LINK,
    ];

    // WPP 13 — 11/06 19h · Encerramento
    $wpp_rows[] = ['lead_id'=>$lead_id,'to_phone'=>$tel,'to_name'=>$name,'scheduled_at'=>$dt_11_19h->format(DateTime::ATOM),'status'=>'pending',
        'message' => "*{$name}*, em breve as vagas fecham definitivamente. 🔴\n\n5 horas.\n\nNão existe momento perfeito para começar. Existe o momento em que você decide que chega.\n\nSe esse é o momento — estamos esperando por você.\n\n👉 " . HOTMART_LINK . "\n\nCom carinho,\n*Daniely e Ira* 🌿",
    ];

    // Remove mensagens com data já passada (janela de 60s)
    $wpp_rows = array_filter($wpp_rows, fn($r) => strtotime($r['scheduled_at']) >= time() - 60);
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
