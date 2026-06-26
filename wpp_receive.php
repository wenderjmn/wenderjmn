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
define('SUPABASE_SERVICE_KEY', getenv('SUPABASE_SERVICE_KEY') ?: '');
define('ZAPI_CLIENT_TOKEN',    getenv('ZAPI_CLIENT_TOKEN')    ?: '');
define('ZAPI_INSTANCE',        getenv('ZAPI_INSTANCE')        ?: '');
define('ZAPI_TOKEN',           getenv('ZAPI_TOKEN')           ?: '');

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

// ── Callback de status de entrega (algumas configs Z-API usam um único webhook) ──
// Detecta pelo campo "status" OU pelo type ReceivedCallback/ReadCallback
$status_raw = strtoupper($data['status'] ?? '');
$cb_type    = strtoupper($data['type'] ?? '');
$is_status_cb = in_array($status_raw, ['RECEIVED','DELIVERED','READ','PLAYED'], true)
             || in_array($cb_type, ['RECEIVEDCALLBACK','READCALLBACK','DELIVERYCALLBACK'], true);

if ($is_status_cb && !empty($data['messageId'] ?? $data['zaapId'] ?? '')) {
    // Delega ao wpp_status.php via include para reaproveitar a lógica
    if (file_exists(__DIR__ . '/wpp_status.php')) {
        // Reprocessa internamente sem nova requisição HTTP
        $message_id = $data['messageId'] ?? $data['zaapId'] ?? null;
        $status_map = ['RECEIVED'=>'received','DELIVERED'=>'received','READ'=>'read','PLAYED'=>'read',
                       'RECEIVEDCALLBACK'=>'received','READCALLBACK'=>'read','DELIVERYCALLBACK'=>'received'];
        $new_status = $status_map[$status_raw] ?: ($status_map[$cb_type] ?? null);
        $now        = gmdate('Y-m-d\TH:i:s\Z');
        if ($message_id && $new_status) {
            $ch = curl_init(SUPABASE_URL . '/rest/v1/whatsapp_queue?zapi_message_id=eq.' . urlencode($message_id) . '&select=id,delivery_status&limit=1');
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['apikey: '.SUPABASE_SERVICE_KEY,'Authorization: Bearer '.SUPABASE_SERVICE_KEY]]);
            $sb_rows = json_decode(curl_exec($ch),true) ?: []; curl_close($ch);
            if (!empty($sb_rows)) {
                $item = $sb_rows[0];
                $order = ['sent'=>0,'received'=>1,'read'=>2];
                if (($order[$new_status]??0) > ($order[$item['delivery_status']??'sent']??0)) {
                    $patch = ['delivery_status'=>$new_status];
                    if ($new_status==='received') $patch['delivered_at']=$now;
                    if ($new_status==='read')     $patch['read_at']=$now;
                    $ch2 = curl_init(SUPABASE_URL.'/rest/v1/whatsapp_queue?id=eq.'.$item['id']);
                    curl_setopt_array($ch2,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>'PATCH',CURLOPT_POSTFIELDS=>json_encode($patch),CURLOPT_HTTPHEADER=>['apikey: '.SUPABASE_SERVICE_KEY,'Authorization: Bearer '.SUPABASE_SERVICE_KEY,'Content-Type: application/json','Prefer: return=minimal']]);
                    curl_exec($ch2); curl_close($ch2);
                }
            }
        }
    }
    echo json_encode(['ok' => true, 'handled' => 'status_callback', 'status' => $new_status ?? $status_raw]);
    exit;
}

// Ignora mensagens que NÓS enviamos (mas não são callbacks de status)
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

// ── OPT-IN / OPT-OUT ──────────────────────────────────────────────────
// Resposta SIM ao pedido de consentimento de WPP importados
if ($text === 'SIM') {
    if ($lead !== null) {
        $lead_id = $lead['id'];
        sb_patch("leads?id=eq.{$lead_id}", ['wpp_optout' => false, 'optin_status' => 'confirmed']);
        $lead_full = sb_get("leads?id=eq.{$lead_id}&select=id,name,phone,email,sabotador,source,sequence_queued_at&limit=1");
        $lf = $lead_full[0] ?? null;
        if ($lf && ($lf['source'] === 'import') && empty($lf['sequence_queued_at'])) {
            wpp_enqueue_followup_sequence($lf, $phone);
        }
    }
    $msg_sim = "Que bom, {$nome}! 🎉 Confirmamos sua participação.\n\nVocê vai receber conteúdos exclusivos da *Daniely* e da *Ira* sobre como superar os padrões que sabotam seu emagrecimento.\n\nFique ligada! 💚\n\n*Daniely e Ira*\nPrograma EmagreSer";
    zapi_send($phone, $msg_sim);
    echo json_encode(['ok' => true, 'replied' => true, 'action' => 'optin_confirmed']);
    exit;
}

$site_url = 'https://www.oficialemagreser.com';

// Descadastro / opt-out — detecta qualquer intenção de sair da lista
$optout_words = [
    'NÃO', 'NAO', 'N',
    'PARAR', 'PARA',
    'STOP',
    'CANCELAR', 'CANCELA', 'CANCEL',
    'SAIR', 'SAIO',
    'REMOVER', 'REMOVE',
    'DESCADASTRAR', 'DESCADASTRE', 'DESCADASTRO',
    'CHEGA',
    'NAO QUERO', 'NÃO QUERO',
    'NAO QUERO MAIS', 'NÃO QUERO MAIS',
    'PODE PARAR', 'PODE CANCELAR',
    'ME REMOVE', 'ME REMOVA',
    'SAIR DA LISTA', 'REMOVER DA LISTA',
    'EXCLUIR', 'EXCLUI',
    'DESINSCREVER', 'DESINSCRITO',
    'UNSUBSCRIBE',
];

if (in_array($text, $optout_words, true)) {
    if ($lead !== null) {
        sb_patch("leads?id=eq.{$lead['id']}", ['wpp_optout' => true]);
        // Cancela todos os WPP pendentes deste lead
        sb_patch(
            "whatsapp_queue?lead_id=eq.{$lead['id']}&status=eq.pending",
            ['status' => 'cancelled', 'error_msg' => 'opt-out via WPP: ' . $text]
        );
    }
    $msg_nao = "Entendido, {$nome}. 🙏 Removemos seu número da nossa lista.\n\nNão receberá mais mensagens nossas.\n\nSe mudar de ideia, acesse: 👉 " . $site_url;
    zapi_send($phone, $msg_nao);
    echo json_encode(['ok' => true, 'replied' => true, 'action' => 'optout_confirmed', 'word' => $text]);
    exit;
}

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

// Enfileira a sequência de follow-up quando lead importado responde SIM
function wpp_enqueue_followup_sequence(array $lead, string $phone_normalized): void {
    $config    = sb_get('site_config?key=eq.optin_followup_sequence_id&select=value&limit=1');
    $seq_id    = $config[0]['value'] ?? null;
    if (!$seq_id) return;

    $seq_data  = sb_get("sequences?id=eq.{$seq_id}&is_active=eq.true&select=name,items&limit=1");
    if (empty($seq_data[0]['items'])) return;
    $seq_items = $seq_data[0]['items'];

    $cfg_ira        = sb_get('site_config?key=eq.link_video_ira&select=value&limit=1');
    $link_video_ira = $cfg_ira[0]['value'] ?? '';

    $perfis_nomes = ['A'=>'Recompensadora','B'=>'Piloto Automático','C'=>'Prisioneira do Esforço','D'=>'Compensadora Noturna'];
    $sab         = strtoupper($lead['sabotador'] ?? '');
    $nome_perfil = $sab ? ($perfis_nomes[$sab] ?? 'Perfil Sabotador') : 'Perfil Sabotador';
    $name        = $lead['name'] ?? 'você';
    $email       = $lead['email'] ?? '';
    $lead_id     = $lead['id'];

    $tz   = new DateTimeZone('America/Sao_Paulo');
    $base = new DateTime('now', $tz);
    $now  = $base->format(DateTime::ATOM);

    foreach ($seq_items as $item) {
        $type    = $item['type'] ?? '';
        $send_at = wpp_seq_schedule($item, $base, $tz);
        if (!$send_at || strtotime($send_at) < time() - 60) continue;

        if ($type === 'wpp' && strlen($phone_normalized) >= 10) {
            $msg = wpp_sub_vars($item['message'] ?? '', $name, $nome_perfil, $link_video_ira);
            if (!$msg) continue;
            $tel = '55' . ltrim(preg_replace('/\D/', '', $phone_normalized), '0');
            sb_post('whatsapp_queue', [['lead_id'=>$lead_id,'to_phone'=>$tel,'to_name'=>$name,'message'=>$msg,'scheduled_at'=>$send_at,'status'=>'pending']]);
        } elseif ($type === 'email' && $email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $slug = trim($item['template_slug'] ?? '');
            if (!$slug) continue;
            $extra_vars = [
                '{{nome}}'             => htmlspecialchars($name),
                '{{nome_lead}}'        => htmlspecialchars($name),
                '{{nome_perfil}}'      => htmlspecialchars($nome_perfil),
                '{{link_site}}'        => 'https://www.oficialemagreser.com',
                '{{link_wpp}}'         => 'https://chat.whatsapp.com/GsMAVm3KVncGNR5nHRQ3yQ',
                '{{link_vip}}'         => 'https://chat.whatsapp.com/GsMAVm3KVncGNR5nHRQ3yQ',
                '{{link_hotmart}}'     => getenv('HOTMART_LINK') ?: 'https://pay.hotmart.com/emagreser',
                '{{link_descadastro}}' => 'https://www.oficialemagreser.com/descadastro.php?email='.urlencode($email),
                '{{link_video_ira}}'   => $link_video_ira,
            ];
            sb_post('email_queue', [['lead_id'=>$lead_id,'template_slug'=>$slug,'to_email'=>$email,'to_name'=>$name,'extra_vars'=>json_encode($extra_vars),'scheduled_at'=>$send_at,'status'=>'pending']]);
        }
    }

    sb_patch("leads?id=eq.{$lead_id}", ['sequence_queued_at' => $now, 'automation_enrolled' => true]);
}

function wpp_seq_schedule(array $item, DateTime $base, DateTimeZone $tz): ?string {
    if (!empty($item['fixed_date'])) {
        $time = $item['fixed_time'] ?? '09:00';
        $dt   = DateTime::createFromFormat('Y-m-d H:i', $item['fixed_date'] . ' ' . $time, $tz);
        return $dt ? $dt->format(DateTime::ATOM) : null;
    }
    $hours = (int)($item['delay_hours'] ?? 0);
    $dt    = clone $base;
    $dt->modify("+{$hours} hours");
    return $dt->format(DateTime::ATOM);
}

function wpp_sub_vars(string $msg, string $name, string $perfil, string $link_video_ira = ''): string {
    return str_replace(
        ['{{nome_lead}}', '{{nome}}', '{{nome_perfil}}', '{{link_vip}}', '{{link_hotmart}}', '{{link_site}}', '{{link_video_ira}}'],
        [$name, $name, $perfil, 'https://chat.whatsapp.com/GsMAVm3KVncGNR5nHRQ3yQ', getenv('HOTMART_LINK') ?: 'https://pay.hotmart.com/emagreser', 'https://www.oficialemagreser.com', $link_video_ira],
        $msg
    );
}

function zapi_send(string $phone, string $message): bool {
    if (!ZAPI_INSTANCE || !ZAPI_TOKEN) return false;
    $base = "https://api.z-api.io/instances/" . ZAPI_INSTANCE . "/token/" . ZAPI_TOKEN;

    // Suporte a [VIDEO:url]caption e [IMAGE:url]caption
    if (preg_match('/^\[(VIDEO|IMAGE):([^\]]+)\](.*)/si', $message, $m)) {
        $type    = strtolower($m[1]);
        $url_m   = trim($m[2]);
        $caption = trim($m[3]);
        $endpoint = $base . '/send-' . $type;
        $field    = $type; // 'video' ou 'image'
        $body = array_filter(['phone' => $phone, $field => $url_m, 'caption' => $caption ?: null], fn($v) => $v !== null);
        $timeout = 30;
    } else {
        $has_url  = (bool) preg_match('/https?:\/\/\S+/', $message);
        $endpoint = $base . '/send-text';
        $body     = ['phone' => $phone, 'message' => $message, 'linkPreview' => $has_url];
        $timeout  = 15;
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Client-Token: ' . ZAPI_CLIENT_TOKEN],
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

function sb_patch(string $path, array $data): void {
    $ch = curl_init(SUPABASE_URL . '/rest/v1/' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PATCH',
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => [
            'apikey: '               . SUPABASE_SERVICE_KEY,
            'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
            'Content-Type: application/json', 'Prefer: return=minimal',
        ],
    ]);
    curl_exec($ch); curl_close($ch);
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
