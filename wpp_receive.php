<?php
/**
 * wpp_receive.php — Recebe mensagens de resposta dos leads via Z-API
 *
 * Configure no painel Z-API:
 *   Webhooks → Ao receber → URL: https://www.oficialemagreser.com/wpp_receive.php
 *   Ative "Notificar as enviadas por mim também": NÃO (só queremos respostas dos leads)
 *
 * Fluxo:
 *   Lead recebe mensagem com pergunta → responde (ex: "B") → este webhook processa
 *   → verifica a conversa ativa → envia resposta personalizada via Z-API
 */

if (file_exists(__DIR__ . '/_env.php')) require_once __DIR__ . '/_env.php';

define('SUPABASE_URL',         'https://drgrwpmhmrrhxuwxabow.supabase.co');
define('SUPABASE_SERVICE_KEY', getenv('SUPABASE_SERVICE_KEY') ?: '***REMOVED_SUPABASE_KEY***');
define('ZAPI_CLIENT_TOKEN',    getenv('ZAPI_CLIENT_TOKEN')    ?: '***REMOVED_ZAPI_CLIENT_TOKEN***');
define('ZAPI_INSTANCE',        getenv('ZAPI_INSTANCE')        ?: '***REMOVED_ZAPI_INSTANCE***');
define('ZAPI_TOKEN',           getenv('ZAPI_TOKEN')           ?: '***REMOVED_ZAPI_TOKEN***');

header('Content-Type: application/json');

// Valida Client-Token da Z-API
$token = $_SERVER['HTTP_CLIENT_TOKEN'] ?? $_GET['token'] ?? '';
if ($token !== ZAPI_CLIENT_TOKEN) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Log para debug
$log_line = date('Y-m-d H:i:s') . ' RECEIVE ' . substr($raw, 0, 400) . "\n";
file_put_contents(__DIR__ . '/wpp_receive.log', $log_line, FILE_APPEND);

// Ignora mensagens que NÓS enviamos
$from_me = $data['fromMe'] ?? false;
if ($from_me) {
    echo json_encode(['ok' => true, 'skipped' => 'own_message']);
    exit;
}

// Extrai dados da mensagem recebida
$phone = preg_replace('/\D/', '', $data['phone'] ?? $data['from'] ?? '');
$text  = trim(strtoupper($data['text']['message'] ?? $data['body'] ?? $data['message']['conversation'] ?? ''));

if (!$phone || !$text) {
    echo json_encode(['ok' => true, 'skipped' => 'no_phone_or_text']);
    exit;
}

// Remove código de país duplicado (5555...)
if (strlen($phone) > 13) $phone = substr($phone, -13);

// Busca lead pelo telefone
$leads = sb_get("leads?phone=eq." . urlencode($phone) . "&select=id,name&limit=1");
$lead  = is_array($leads) && !empty($leads[0]) ? $leads[0] : null;
$nome  = $lead ? (explode(' ', $lead['name'])[0]) : 'você';

// ── RESPOSTAS INTERATIVAS ──────────────────────────────────────────────
// Detecta se é resposta ao quiz de perfil sabotador (A/B/C/D)
$respostas_quiz = [
    'A' => [
        'perfil'    => '🍕 Perfil Noturno',
        'resposta'  => "Entendi, {$nome}! Você é do perfil *Noturno* 🍕\n\nVocê mantém o controle durante o dia, mas à noite o cansaço e a ansiedade falam mais alto.\n\n*A dica personalizada para você:* Crie um ritual noturno leve — uma xícara de chá, uma fruta. Isso sinaliza pro seu cérebro que o dia terminou SEM precisar de comida.\n\nNo Programa EmagreSer trabalhamos exatamente isso. Quer saber como? 👇",
    ],
    'B' => [
        'perfil'    => '⚡ Perfil Semanal',
        'resposta'  => "Faz todo sentido, {$nome}! Você é do perfil *Semanal* ⚡\n\nVocê começa com tudo na segunda, mas na quarta a motivação vai embora — e a culpa aparece.\n\n*A dica personalizada para você:* Para de esperar a segunda-feira. Pequenas ações hoje valem mais que planos perfeitos amanhã.\n\nNo Programa EmagreSer você aprende a quebrar esse ciclo de uma vez. Quer conhecer? 👇",
    ],
    'C' => [
        'perfil'    => '🤖 Perfil Automático',
        'resposta'  => "Isso é muito comum, {$nome}! Você é do perfil *Automático* 🤖\n\nVocê come sem perceber — no piloto automático, por tédio, hábito ou distração.\n\n*A dica personalizada para você:* Antes de comer qualquer coisa, para 10 segundos e pergunta: estou com fome de verdade ou é outra coisa?\n\nNo Programa EmagreSer a gente treina exatamente essa consciência. Quer saber mais? 👇",
    ],
    'D' => [
        'perfil'    => '🌙 Perfil Restritivo',
        'resposta'  => "Entendi perfeitamente, {$nome}! Você é do perfil *Restritivo* 🌙\n\nVocê se priva muito, aguenta, aguenta… e quando chega o limite, compensa tudo de uma vez.\n\n*A dica personalizada para você:* Restrição gera compensação. Comer bem não é sofrer — é se permitir sem culpa.\n\nNo Programa EmagreSer você aprende a equilibrar sem se privar. Quer conhecer? 👇",
    ],
];

$site_url = 'https://www.oficialemagreser.com';
$wpp_link = 'https://chat.whatsapp.com/GsMAVm3KVncGNR5nHRQ3yQ';

// Verifica se é resposta ao quiz (letra A, B, C ou D)
if (isset($respostas_quiz[$text])) {
    $info = $respostas_quiz[$text];
    $msg_resposta = $info['resposta'] . "\n\n👉 " . $site_url . "\n💬 Grupo VIP: " . $wpp_link;

    // Evita responder a mesma letra duas vezes (anti-spam) — verifica últimas 6h
    $recente = sb_get("wpp_interactions?phone=eq." . urlencode($phone) .
        "&reply_text=eq." . urlencode($text) .
        "&created_at=gte." . urlencode(gmdate('Y-m-d\TH:i:s\Z', strtotime('-6 hours'))) .
        "&select=id&limit=1");
    if (!empty($recente)) {
        echo json_encode(['ok' => true, 'skipped' => 'already_replied']);
        exit;
    }

    // Envia resposta via Z-API
    $sent = zapi_send($phone, $msg_resposta);

    // Registra a interação
    sb_post('wpp_interactions', [
        'phone'        => $phone,
        'lead_id'      => $lead['id'] ?? null,
        'received_text'=> $text,
        'reply_text'   => $text,
        'perfil'       => $info['perfil'],
        'sent_ok'      => $sent,
        'created_at'   => gmdate('Y-m-d\TH:i:s\Z'),
    ]);

    echo json_encode(['ok' => true, 'replied' => true, 'perfil' => $info['perfil'], 'sent' => $sent]);
    exit;
}

// Resposta padrão para mensagens não reconhecidas (opcional — descomente se quiser)
// $default = "Oi, {$nome}! 😊\n\nSe você quiser fazer o mapeamento do seu Perfil Sabotador, acesse:\n👉 {$site_url}";
// zapi_send($phone, $default);

echo json_encode(['ok' => true, 'skipped' => 'no_match', 'text' => $text]);

// ── FUNÇÕES ───────────────────────────────────────────────────────────

function zapi_send(string $phone, string $message): bool {
    if (!ZAPI_INSTANCE || !ZAPI_TOKEN) return false;
    $url = "https://api.z-api.io/instances/" . ZAPI_INSTANCE . "/token/" . ZAPI_TOKEN . "/send-text";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['phone' => $phone, 'message' => $message]),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Client-Token: ' . ZAPI_CLIENT_TOKEN,
        ],
    ]);
    $res  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

function sb_get(string $path): array {
    $ch = curl_init(SUPABASE_URL . '/rest/v1/' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => [
        'apikey: ' . SUPABASE_SERVICE_KEY,
        'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
    ]]);
    $res = curl_exec($ch); curl_close($ch);
    return json_decode($res, true) ?: [];
}

function sb_post(string $table, array $data): void {
    $ch = curl_init(SUPABASE_URL . '/rest/v1/' . $table);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => [
            'apikey: ' . SUPABASE_SERVICE_KEY,
            'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
            'Content-Type: application/json', 'Prefer: return=minimal',
        ],
    ]);
    curl_exec($ch); curl_close($ch);
}
