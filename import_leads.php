<?php
/**
 * import_leads.php
 * Importa as 2.500 leads antigas e enfileira campanha de reengajamento.
 * Acesse via browser (protegido por senha) ou rode via CLI.
 *
 * Uso CLI: php import_leads.php leads.csv
 * Uso Web: faça upload do CSV e acesse /import_leads.php
 */

define('IMPORT_PASS',          getenv('IMPORT_PASS')          ?: '***REMOVED_IMPORT_PASS***');
define('SUPABASE_URL',         getenv('SUPABASE_URL')         ?: 'https://drgrwpmhmrrhxuwxabow.supabase.co');
define('SUPABASE_SERVICE_KEY', getenv('SUPABASE_SERVICE_KEY') ?: '***REMOVED_SUPABASE_KEY***');
define('SITE_URL',             getenv('SITE_URL')             ?: 'https://emagreser.danielydealbuquerque.com.br');
define('WPP_LINK',             getenv('WPP_LINK')             ?: 'https://chat.whatsapp.com/GsMAVm3KVncGNR5nHRQ3yQ');

$is_cli = php_sapi_name() === 'cli';

// ── Autenticação web ─────────────────────────────────────────────
if (!$is_cli) {
    header('Content-Type: text/html; charset=UTF-8');
    if (!isset($_SERVER['PHP_AUTH_PW']) || $_SERVER['PHP_AUTH_PW'] !== IMPORT_PASS) {
        header('WWW-Authenticate: Basic realm="Import"');
        header('HTTP/1.0 401 Unauthorized');
        echo 'Senha incorreta.'; exit;
    }
}

// ── Datas da campanha de reengajamento ───────────────────────────
// Hoje + N dias (baseado em 18/05/2026, masterclass 11/06/2026)
$tz   = new DateTimeZone('America/Sao_Paulo');
$base = new DateTime('now', $tz);

$campaign_schedule = [
    // [template_slug, delay_days]
    ['reengajamento_1',          0],   // Hoje
    ['nutricao_d1',              2],   // D+2
    ['prova_social_d2',          4],   // D+4
    ['reengajamento_masterclass', 8],  // D+8
    // Fixos próximos à masterclass
    ['reengajamento_masterclass', null, '2026-06-08 09:00:00'], // 3 dias antes
    ['masterclass_hoje',         null, '2026-06-11 08:00:00'],  // manhã do dia
    ['masterclass_1h',           null, '2026-06-11 19:00:00'],  // 1h antes
];

// ── Processa CSV ─────────────────────────────────────────────────
$csv_file = $is_cli ? ($argv[1] ?? null) : ($_FILES['csv']['tmp_name'] ?? null);

if (!$is_cli) {
    // Interface web simples
    if (!$csv_file || !file_exists($csv_file)):
?>
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><title>Importar Leads</title>
<style>body{font-family:Arial;max-width:600px;margin:60px auto;padding:20px;background:#f4f3ef}
.card{background:#fff;border-radius:12px;padding:32px;box-shadow:0 4px 16px rgba(0,0,0,.08)}
h2{color:#0d9488;margin-top:0}label{display:block;margin-bottom:8px;font-weight:600;font-size:14px}
input[type=file]{width:100%;padding:10px;border:2px dashed #0d9488;border-radius:8px;margin-bottom:16px;background:#f0fdfa}
button{background:#0d9488;color:#fff;border:none;padding:14px 28px;border-radius:8px;font-size:16px;font-weight:700;cursor:pointer;width:100%}
.info{background:#f0fdfa;border:1px solid #99f6e4;border-radius:8px;padding:16px;font-size:13px;margin-bottom:20px;color:#0f766e}
</style></head><body><div class="card">
<h2>🚀 Importar Leads Antigas</h2>
<div class="info">
<strong>Formato aceito:</strong> CSV com colunas: <code>nome, email, telefone</code> (ou <code>name, email, phone</code>)<br>
Colunas extras como <code>cidade, estado</code> também são aceitas.<br><br>
<strong>Campanha:</strong> 7 e-mails ao longo de ~19 dias até a masterclass (11/06).
</div>
<form method="post" enctype="multipart/form-data">
<label>Arquivo CSV das leads antigas:</label>
<input type="file" name="csv" accept=".csv" required>
<button type="submit">📤 IMPORTAR E ENFILEIRAR CAMPANHA</button>
</form></div></body></html>
<?php
        exit;
    endif;
}

if (!$csv_file || !file_exists($csv_file)) {
    die("Arquivo CSV não encontrado.\n");
}

// ── Lê o CSV ─────────────────────────────────────────────────────
$rows    = [];
$handle  = fopen($csv_file, 'r');
$headers = null;

while (($line = fgetcsv($handle, 1000, ',')) !== false) {
    if (!$headers) {
        $headers = array_map(fn($h) => strtolower(trim($h)), $line);
        continue;
    }
    if (count($line) < count($headers)) continue;
    $rows[] = array_combine($headers, $line);
}
fclose($handle);

$total  = count($rows);
$ok     = 0;
$skipped = 0;
$errors = [];

output("<h2>Importando {$total} leads...</h2>");

foreach ($rows as $i => $row) {
    // Normaliza campos
    $name  = trim($row['nome'] ?? $row['name'] ?? '');
    $email = strtolower(trim($row['email'] ?? $row['e-mail'] ?? ''));
    $phone = preg_replace('/\D/', '', $row['telefone'] ?? $row['phone'] ?? $row['whatsapp'] ?? '');
    $cidade = trim($row['cidade'] ?? '');
    $estado = strtoupper(trim($row['estado'] ?? ''));

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $skipped++;
        continue;
    }
    if (!$name) $name = explode('@', $email)[0];

    // Verifica duplicidade por e-mail
    $existing = sb_get("leads?email=eq." . urlencode($email) . "&select=id,sequence_queued_at&limit=1");

    if (!empty($existing)) {
        // Lead já existe — verifica se já tem campanha
        if (!empty($existing[0]['sequence_queued_at'])) {
            $skipped++;
            continue;
        }
        $lead_id = $existing[0]['id'];
    } else {
        // Cria novo lead
        $new_lead = [
            'name'            => $name,
            'email'           => $email,
            'phone'           => $phone ?: null,
            'cidade'          => $cidade ?: null,
            'estado'          => $estado ?: null,
            'source'          => 'import',
            'source_campaign' => 'reengajamento_2026',
        ];
        $created = sb_post_return('leads', $new_lead);
        if (empty($created[0]['id'])) {
            $errors[] = "Linha " . ($i+2) . ": falha ao criar lead {$email}";
            continue;
        }
        $lead_id = $created[0]['id'];
    }

    // Enfileira campanha de reengajamento
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

    $email_rows = [];
    foreach ($campaign_schedule as $step) {
        [$slug] = $step;
        if (isset($step[2])) {
            $send_at = (new DateTime($step[2], $tz))->format(DateTime::ATOM);
        } else {
            $dt = clone $base;
            $dt->modify("+{$step[1]} days");
            $dt->setTime(9, 0, 0);
            $send_at = $dt->format(DateTime::ATOM);
        }
        if (strtotime($send_at) < time() - 60) continue;

        $email_rows[] = [
            'lead_id'       => $lead_id,
            'template_slug' => $slug,
            'to_email'      => $email,
            'to_name'       => $name,
            'extra_vars'    => $vars,
            'scheduled_at'  => $send_at,
            'status'        => 'pending',
        ];
    }

    if (!empty($email_rows)) {
        sb_post('email_queue', $email_rows);
    }

    // Marca como enfileirado
    sb_patch("leads?id=eq.{$lead_id}", [
        'sequence_queued_at' => $base->format(DateTime::ATOM),
        'source_campaign'    => 'reengajamento_2026',
    ]);

    $ok++;
    if ($ok % 50 === 0) output("✅ {$ok}/{$total} leads processadas...");
    usleep(50000); // 50ms entre leads
}

output("<br><strong>✅ Importação concluída!</strong>");
output("Total: {$total} | Importadas: {$ok} | Ignoradas: {$skipped} | Erros: " . count($errors));
if ($errors) output("<br>Erros:<br>" . implode('<br>', array_map('htmlspecialchars', $errors)));

function output(string $msg): void {
    if (php_sapi_name() === 'cli') {
        echo strip_tags($msg) . "\n";
    } else {
        echo $msg . "<br>\n";
        if (ob_get_level()) ob_flush();
        flush();
    }
}

// ── FUNÇÕES SUPABASE ──────────────────────────────────────────────
function sb_get(string $path): array {
    $ch = curl_init(SUPABASE_URL . '/rest/v1/' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>[
        'apikey: '.SUPABASE_SERVICE_KEY, 'Authorization: Bearer '.SUPABASE_SERVICE_KEY,
    ]]);
    $res = curl_exec($ch); curl_close($ch);
    return json_decode($res, true) ?: [];
}

function sb_post(string $table, array $data): void {
    $ch = curl_init(SUPABASE_URL . '/rest/v1/' . $table);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode($data), CURLOPT_HTTPHEADER=>[
            'apikey: '.SUPABASE_SERVICE_KEY, 'Authorization: Bearer '.SUPABASE_SERVICE_KEY,
            'Content-Type: application/json', 'Prefer: return=minimal',
        ],
    ]);
    curl_exec($ch); curl_close($ch);
}

function sb_post_return(string $table, array $data): array {
    $ch = curl_init(SUPABASE_URL . '/rest/v1/' . $table);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode($data), CURLOPT_HTTPHEADER=>[
            'apikey: '.SUPABASE_SERVICE_KEY, 'Authorization: Bearer '.SUPABASE_SERVICE_KEY,
            'Content-Type: application/json', 'Prefer: return=representation',
        ],
    ]);
    $res = curl_exec($ch); curl_close($ch);
    return json_decode($res, true) ?: [];
}

function sb_patch(string $path, array $data): void {
    $ch = curl_init(SUPABASE_URL . '/rest/v1/' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CUSTOMREQUEST=>'PATCH',
        CURLOPT_POSTFIELDS=>json_encode($data), CURLOPT_HTTPHEADER=>[
            'apikey: '.SUPABASE_SERVICE_KEY, 'Authorization: Bearer '.SUPABASE_SERVICE_KEY,
            'Content-Type: application/json', 'Prefer: return=minimal',
        ],
    ]);
    curl_exec($ch); curl_close($ch);
}
