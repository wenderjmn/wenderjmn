<?php
/**
 * cron.php — Automação de sequência para leads importados
 *
 * Executa via cron a cada hora (ou conforme configurado no Hostinger).
 * Para cada lead em imported_leads com automation_enrolled=false:
 *   - Se sabotador = 'IG — Sem mapeamento' ou null → sequência WPP de 3 mensagens (D+0, D+1, D+3)
 *   - Marca automation_enrolled=true e enrolled_at=now()
 *
 * Acesso via HTTP: https://www.oficialemagreser.com/cron.php?token=CRON_SECRET
 * Ou diretamente via CLI: php cron.php
 */

// ── CONFIGURAÇÃO ────────────────────────────────────────────────────────────
foreach ([__DIR__ . '/_env.php', __DIR__ . '/../_env.php'] as $_p) {
    if (file_exists($_p)) { require_once $_p; break; }
}

define('SUPABASE_URL',         'https://drgrwpmhmrrhxuwxabow.supabase.co');
define('SUPABASE_SERVICE_KEY', getenv('SUPABASE_SERVICE_KEY') ?: '');
define('CRON_SECRET',          getenv('CRON_SECRET')          ?: '');
define('SITE_URL',             'https://www.oficialemagreser.com');
define('BATCH_SIZE',           50); // leads por execução

// ── AUTENTICAÇÃO HTTP ────────────────────────────────────────────────────────
$is_http = php_sapi_name() !== 'cli';
if ($is_http) {
    $token = $_GET['token'] ?? '';
    if (CRON_SECRET && $token !== CRON_SECRET) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
}

// ── HELPERS ─────────────────────────────────────────────────────────────────
function sb_get(string $path): array {
    $r = sb_request('GET', $path);
    return is_array($r['body']) ? $r['body'] : [];
}
function sb_post(string $path, array $body): array {
    return sb_request('POST', $path, $body);
}
function sb_patch(string $path, array $body): array {
    return sb_request('PATCH', $path, $body);
}
function sb_request(string $method, string $path, ?array $body = null): array {
    $url = SUPABASE_URL . '/rest/v1/' . $path;
    $headers = [
        'apikey: '        . SUPABASE_SERVICE_KEY,
        'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
        'Content-Type: application/json',
        'Prefer: return=representation',
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $resp   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode($resp ?: '[]', true);
    return ['status' => $status, 'body' => $decoded];
}

function log_msg(string $msg): void {
    $ts = date('[Y-m-d H:i:s]');
    if (php_sapi_name() === 'cli') echo "$ts $msg\n";
}

// ── VALIDAÇÃO ────────────────────────────────────────────────────────────────
if (!SUPABASE_SERVICE_KEY) {
    $err = ['ok' => false, 'error' => 'SUPABASE_SERVICE_KEY não configurada'];
    if ($is_http) echo json_encode($err); else print_r($err);
    exit;
}

// ── PROCESSAR LEADS IG SEM AUTOMAÇÃO ─────────────────────────────────────────
$leads = sb_get(
    'imported_leads' .
    '?automation_enrolled=eq.false' .
    '&wpp_optout=eq.false' .
    '&select=id,name,phone,sabotador,source_campaign' .
    '&order=created_at.asc' .
    '&limit=' . BATCH_SIZE
);

$enrolled = 0;
$wpp_queued = 0;
$skipped = 0;

foreach ($leads as $lead) {
    $id    = $lead['id']     ?? '';
    $nome  = $lead['name']   ?? 'você';
    $phone = $lead['phone']  ?? '';
    $sab   = $lead['sabotador'] ?? '';

    // Só processa leads IG (sabotador nulo ou 'IG — Sem mapeamento')
    $is_ig = empty($sab) || trim($sab) === 'IG — Sem mapeamento';
    if (!$is_ig) {
        $skipped++;
        // Marca como enrollado mesmo assim para não reprocessar
        sb_patch('imported_leads?id=eq.' . rawurlencode($id), [
            'automation_enrolled' => true,
            'enrolled_at'         => gmdate('c'),
            'updated_at'          => gmdate('c'),
        ]);
        continue;
    }

    // Sem telefone: marca como enrollado para não reprocessar
    if (empty($phone)) {
        sb_patch('imported_leads?id=eq.' . rawurlencode($id), [
            'automation_enrolled' => true,
            'enrolled_at'         => gmdate('c'),
            'updated_at'          => gmdate('c'),
        ]);
        $skipped++;
        log_msg("SKIP (sem telefone): $nome");
        continue;
    }

    $now  = gmdate('c');
    $d1   = gmdate('c', strtotime('+1 day'));
    $d3   = gmdate('c', strtotime('+3 days'));

    // ── SEQUÊNCIA WPP IG ─────────────────────────────────────────────────────
    // WPP IG-1 (D+0)
    $msg1 = "Olá, *{$nome}*! 🌱 Aqui é a Daniely.\n\nVocê entrou no grupo VIP do Programa EmagreSer — ótima decisão.\n\nTenho uma pergunta: você já descobriu qual perfil sabotador está travando o seu emagrecimento?\n\nÉ gratuito, leva 3 minutos e muda tudo.\n\n👉 " . SITE_URL;

    // WPP IG-2 (D+1)
    $msg2 = "Oi, *{$nome}*! 👋\n\nOntem te mandei o link do mapeamento.\n\nSabe por que eu insisto nisso?\n\nPorque 94% das mulheres que fazem o quiz se reconhecem imediatamente no resultado. E entendem pela primeira vez por que nada funcionou antes.\n\nSão só 10 perguntas. Resultado imediato.\n\n👉 " . SITE_URL;

    // WPP IG-3 (D+3)
    $msg3 = "*{$nome}*, uma coisa rápida 👇\n\nNa Masterclass do dia 11/06 às 20h, a Daniely e a Ira vão analisar ao vivo os 4 perfis sabotadores.\n\nQuem fizer o mapeamento antes vai aproveitar muito mais.\n\nAinda dá tempo: " . SITE_URL . "\n\nMasterclass gratuita — vagas limitadas.";

    $messages = [
        ['message' => $msg1, 'scheduled_at' => $now],
        ['message' => $msg2, 'scheduled_at' => $d1],
        ['message' => $msg3, 'scheduled_at' => $d3],
    ];

    $rows = array_map(function($m) use ($lead, $nome, $phone) {
        return [
            'lead_id'      => $lead['id'],
            'to_phone'     => $phone,
            'to_name'      => $nome,
            'message'      => $m['message'],
            'scheduled_at' => $m['scheduled_at'],
            'status'       => 'pending',
        ];
    }, $messages);

    $r = sb_post('whatsapp_queue', $rows);

    if ($r['status'] < 300) {
        $wpp_queued += 3;
        log_msg("WPP x3 enfileirado: $nome ($phone)");
    } else {
        log_msg("ERRO WPP: $nome — status " . $r['status']);
    }

    // Marca como enrollado
    sb_patch('imported_leads?id=eq.' . rawurlencode($id), [
        'automation_enrolled' => true,
        'enrolled_at'         => gmdate('c'),
        'sequence_queued_at'  => $now,
        'updated_at'          => gmdate('c'),
    ]);
    $enrolled++;
}

$result = [
    'ok'         => true,
    'processed'  => count($leads),
    'enrolled'   => $enrolled,
    'wpp_queued' => $wpp_queued,
    'skipped'    => $skipped,
    'ts'         => date('Y-m-d H:i:s'),
];

log_msg("Resultado: " . json_encode($result));
if ($is_http) echo json_encode($result);
