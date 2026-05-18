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
define('WPP_LINK',             getenv('WPP_LINK')             ?: 'https://chat.whatsapp.com/SEU_LINK_AQUI');
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

// ── DATA DA MASTERCLASS ──────────────────────────────────────────
$masterclass = new DateTime('2026-06-06 08:00:00', new DateTimeZone('America/Sao_Paulo'));
$masterclass_1h = new DateTime('2026-06-06 19:00:00', new DateTimeZone('America/Sao_Paulo'));

// ── SEQUÊNCIA DE E-MAILS ─────────────────────────────────────────
$email_sequence = [
    ['slug' => 'boas_vindas',    'delay_hours' => 0],
    ['slug' => 'nutricao_d1',    'delay_hours' => 24],
    ['slug' => 'prova_social_d2','delay_hours' => 48],
    ['slug' => 'urgencia_d3',    'delay_hours' => 72],
    ['slug' => 'masterclass_hoje','scheduled_at' => $masterclass->format(DateTime::ATOM)],
    ['slug' => 'masterclass_1h', 'scheduled_at' => $masterclass_1h->format(DateTime::ATOM)],
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

// ── SEQUÊNCIA DE WHATSAPP ────────────────────────────────────────
if ($phone && strlen($phone) >= 10) {
    $wpp_rows = [];

    // WA 1 — imediato: resultado do perfil
    $wpp_rows[] = [
        'lead_id'      => $lead_id,
        'to_phone'     => '55' . $phone,
        'message'      => "Oi, {$name}! 🎉\n\nSeu mapeamento revelou que você é: *{$p['emoji']} {$p['tipo']}*\n\n_{$p['titulo']}_\n\nIsso não é fraqueza — é o seu cérebro operando de um jeito que você nunca aprendeu a gerenciar.\n\nA Masterclass *\"O Código dos Sabotadores\"* é dia 06/06 às 20h e vai mostrar exatamente o que fazer para quebrar esse ciclo.\n\nEntre no grupo VIP para receber o link da transmissão 👇\n" . WPP_LINK,
        'scheduled_at' => $now->format(DateTime::ATOM),
        'status'       => 'pending',
    ];

    // WA 2 — dia da masterclass (manhã)
    $wpp_rows[] = [
        'lead_id'      => $lead_id,
        'to_phone'     => '55' . $phone,
        'message'      => "Oi, {$name}! 🔴 *Hoje é o dia!*\n\nA Masterclass começa às 20h. O link da transmissão está no grupo VIP.\n\nSe ainda não entrou:\n" . WPP_LINK,
        'scheduled_at' => $masterclass->format(DateTime::ATOM),
        'status'       => 'pending',
    ];

    // WA 3 — 1h antes
    $wpp_rows[] = [
        'lead_id'      => $lead_id,
        'to_phone'     => '55' . $phone,
        'message'      => "⏰ *Em 1 hora começa*, {$name}!\n\nO link da live está no grupo VIP do WhatsApp 👇\n" . WPP_LINK,
        'scheduled_at' => $masterclass_1h->format(DateTime::ATOM),
        'status'       => 'pending',
    ];

    // Remove WA passados
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
