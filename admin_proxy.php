<?php
/**
 * EmagreSer — Admin Proxy
 * Todas as escritas no Supabase passam por aqui, com autenticação via sessão PHP.
 *
 * CONFIGURAÇÃO OBRIGATÓRIA:
 * Substitua 'COLE_AQUI_SUA_SERVICE_ROLE_KEY' pela chave de serviço do Supabase.
 * Acesse: Supabase Dashboard → Settings → API → service_role (secret)
 *
 * SEGURANÇA:
 * - Nunca exponha esta chave no front-end.
 * - Este arquivo deve ficar em servidor PHP, não em hospedagem estática.
 * - Coloque um .htaccess impedindo acesso direto a este arquivo de fora.
 */

if (file_exists(__DIR__ . '/_env.php')) require_once __DIR__ . '/_env.php';

// ── CONFIGURAÇÃO ──────────────────────────────────────────────────────────────
define('SUPABASE_URL',         'https://drgrwpmhmrrhxuwxabow.supabase.co');
define('SUPABASE_SERVICE_KEY', getenv('SUPABASE_SERVICE_KEY') ?: '***REMOVED_SUPABASE_KEY***');

// Tempo de expiração da sessão em segundos (4 horas)
define('SESSION_LIFETIME', 14400);

// ── HEADERS ──────────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');

// Só aceitar de origens do próprio domínio em produção
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'Method Not Allowed']); exit; }

// ── SESSÃO ────────────────────────────────────────────────────────────────────
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
session_start();

// Verificar expiração da sessão por inatividade
if (isset($_SESSION['admin'])) {
    if (isset($_SESSION['_last_activity']) && (time() - $_SESSION['_last_activity']) > SESSION_LIFETIME) {
        session_unset();
        session_destroy();
    } else {
        $_SESSION['_last_activity'] = time();
    }
}

// ── ROTEAMENTO ───────────────────────────────────────────────────────────────
$action = trim($_POST['action'] ?? '');

switch ($action) {
    case 'login':    handle_login();    break;
    case 'logout':   handle_logout();   break;
    case 'check':    handle_check();    break;

    // Escritas — exigem sessão ativa
    case 'update_config':         require_auth(); update_config();         break;
    case 'update_quiz_question':  require_auth(); update_quiz_question();  break;
    case 'update_testimonial':    require_auth(); update_testimonial();    break;
    case 'update_mentor':         require_auth(); update_mentor();         break;

    // WhatsApp fila
    case 'wpp_stats':       require_auth(); wpp_stats();       break;
    case 'wpp_reset_stuck': require_auth(); wpp_reset_stuck(); break;
    case 'wpp_retry_failed':require_auth(); wpp_retry_failed();break;
    case 'wpp_cancel_msg':  require_auth(); wpp_cancel_msg();  break;

    // Templates de e-mail
    case 'list_email_templates':   require_auth(); list_email_templates();   break;
    case 'get_email_template':     require_auth(); get_email_template();     break;
    case 'save_email_template':    require_auth(); save_email_template();    break;
    case 'delete_email_template':  require_auth(); delete_email_template();  break;
    case 'toggle_email_template':  require_auth(); toggle_email_template();  break;

    // Templates de WhatsApp
    case 'list_wpp_templates':     require_auth(); list_wpp_templates();     break;
    case 'get_wpp_template':       require_auth(); get_wpp_template();       break;
    case 'save_wpp_template':      require_auth(); save_wpp_template();      break;
    case 'delete_wpp_template':    require_auth(); delete_wpp_template();    break;
    case 'toggle_wpp_template':    require_auth(); toggle_wpp_template();    break;

    // Usuários do painel
    case 'list_users':    require_auth(); list_users();    break;
    case 'create_user':   require_auth(); create_user();   break;
    case 'update_user':   require_auth(); update_user();   break;
    case 'reset_password':require_auth(); reset_password();break;
    case 'toggle_user':   require_auth(); toggle_user();   break;
    case 'delete_user':   require_auth(); delete_user();   break;

    // Upload de arquivo para Supabase Storage
    case 'upload_file':   require_auth(); upload_file();   break;

    default:
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Ação desconhecida']);
}

// ── AUTH HANDLERS ─────────────────────────────────────────────────────────────
function handle_login() {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$username || !$password) {
        echo json_encode(['ok'=>false,'error'=>'Usuário e senha são obrigatórios']);
        return;
    }

    $res = sb_request('GET', 'admin_users', null,
        'username=eq.' . rawurlencode($username)
        . '&active=eq.true'
        . '&select=id,username,password_hash,role,perm_dashboard,perm_leads,perm_videos,perm_textos,perm_quiz,perm_mentoras,perm_config,perm_usuarios'
        . '&limit=1'
    );

    if ($res['status'] !== 200 || empty($res['body'])) {
        echo json_encode(['ok'=>false,'error'=>'Usuário não encontrado ou inativo']);
        return;
    }

    $user = $res['body'][0];

    if (!password_verify($password, $user['password_hash'])) {
        echo json_encode(['ok'=>false,'error'=>'Senha incorreta']);
        return;
    }

    // Registrar último login (non-blocking — ignorar falha)
    sb_request('PATCH', 'admin_users?id=eq.' . rawurlencode($user['id']),
        ['last_login' => date('c'), 'updated_at' => date('c')]
    );

    $_SESSION['admin'] = [
        'id'       => $user['id'],
        'username' => $user['username'],
        'role'     => $user['role'],
        'perms'    => [
            'dashboard' => (bool)$user['perm_dashboard'],
            'leads'     => (bool)$user['perm_leads'],
            'videos'    => (bool)$user['perm_videos'],
            'textos'    => (bool)$user['perm_textos'],
            'quiz'      => (bool)$user['perm_quiz'],
            'mentoras'  => (bool)$user['perm_mentoras'],
            'config'    => (bool)$user['perm_config'],
            'usuarios'  => (bool)$user['perm_usuarios'],
        ],
    ];
    $_SESSION['_last_activity'] = time();

    echo json_encode(['ok'=>true,'user'=>$_SESSION['admin']]);
}

function handle_logout() {
    session_unset();
    session_destroy();
    echo json_encode(['ok'=>true]);
}

function handle_check() {
    if (isset($_SESSION['admin'])) {
        echo json_encode(['ok'=>true,'user'=>$_SESSION['admin']]);
    } else {
        echo json_encode(['ok'=>false]);
    }
}

// ── WRITE HANDLERS ────────────────────────────────────────────────────────────
function update_config() {
    $key   = trim($_POST['key']   ?? '');
    $value = $_POST['value'] ?? '';

    if (!$key) { echo json_encode(['ok'=>false,'error'=>'key obrigatória']); return; }

    // Validação básica: não permitir chaves com SQL injection
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
        echo json_encode(['ok'=>false,'error'=>'key inválida']); return;
    }

    // Verificar permissão granular
    $user = $_SESSION['admin'];
    $is_config_key = in_array($key, [
        'live_data','masterclass_data','carrinho_abertura','carrinho_forcar',
        'carrinho_link','carrinho_preco','carrinho_parcelado','whatsapp_link',
        'hero_video_url','video_promessa_daniely','video_promessa_ira',
        'video_perfil_a','video_perfil_b','video_perfil_c','video_perfil_d',
        'anuncio_bar','carrinho_titulo',
    ]);
    $is_video_key = strpos($key, 'video_') === 0;

    if (!($user['perms']['textos'] || $user['perms']['config'] || $user['role'] === 'super_admin')) {
        if ($is_config_key && !$user['perms']['config']) {
            http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Sem permissão para editar configurações']); return;
        }
        if ($is_video_key && !$user['perms']['videos']) {
            http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Sem permissão para editar vídeos']); return;
        }
    }

    $res = sb_request('PATCH', 'site_config?key=eq.' . rawurlencode($key),
        ['value' => $value, 'updated_at' => date('c')]
    );

    if ($res['status'] === 503) {
        http_response_code(503);
        echo json_encode(['ok'=>false,'error'=>'Chave de serviço não configurada no servidor (SUPABASE_SERVICE_KEY). Edite admin_proxy.php e substitua COLE_AQUI_SUA_SERVICE_ROLE_KEY pela sua chave.']);
        return;
    }
    if ($res['status'] >= 200 && $res['status'] < 300) {
        echo json_encode(['ok'=>true]);
    } else {
        echo json_encode(['ok'=>false,'error'=>'Supabase retornou HTTP '.$res['status'].($res['body']?' — '.json_encode($res['body']):'')]);
    }
}

function update_quiz_question() {
    $id = trim($_POST['id'] ?? '');
    if (!is_valid_uuid($id)) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); return; }

    $user = $_SESSION['admin'];
    if (!$user['perms']['quiz'] && $user['role'] !== 'super_admin') {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Sem permissão para editar o quiz']); return;
    }

    $data = [
        'question_text' => trim($_POST['question_text'] ?? ''),
        'option_a'      => trim($_POST['option_a'] ?? ''),
        'option_b'      => trim($_POST['option_b'] ?? ''),
        'option_c'      => trim($_POST['option_c'] ?? ''),
        'option_d'      => trim($_POST['option_d'] ?? ''),
        'weight_a'      => trim($_POST['weight_a'] ?? 'A'),
        'weight_b'      => trim($_POST['weight_b'] ?? 'B'),
        'weight_c'      => trim($_POST['weight_c'] ?? 'C'),
        'weight_d'      => trim($_POST['weight_d'] ?? 'D'),
    ];

    foreach (['question_text','option_a','option_b','option_c','option_d'] as $k) {
        if (!$data[$k]) { echo json_encode(['ok'=>false,'error'=>"Campo {$k} não pode ser vazio"]); return; }
    }

    $res = sb_request('PATCH', 'quiz_questions?id=eq.' . rawurlencode($id), $data);
    if ($res['status'] === 503) {
        http_response_code(503);
        echo json_encode(['ok'=>false,'error'=>'Chave de serviço não configurada no servidor.']); return;
    }
    echo json_encode(['ok' => ($res['status'] >= 200 && $res['status'] < 300), 'status' => $res['status']]);
}

function update_testimonial() {
    $id = trim($_POST['id'] ?? '');
    $user = $_SESSION['admin'];
    if (!$user['perms']['videos'] && !$user['perms']['textos'] && $user['role'] !== 'super_admin') {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Sem permissão para editar depoimentos']); return;
    }

    $data = [
        'name'        => trim($_POST['name']      ?? ''),
        'result'      => trim($_POST['result']    ?? ''),
        'quote'       => trim($_POST['quote']     ?? ''),
        'profession'  => trim($_POST['profession']?? ''),
        'video_url'   => trim($_POST['video_url'] ?? ''),
        'order_index' => (int)($_POST['order_index'] ?? 0),
        'active'      => ($_POST['active'] ?? '1') === '1',
        'updated_at'  => date('c'),
        'type'        => 'video',
    ];

    if ($id === 'new') {
        $res = sb_request('POST', 'testimonials', $data);
        echo json_encode(['ok' => ($res['status'] === 201 || $res['status'] === 200), 'data' => $res['body']]);
    } else {
        if (!is_valid_uuid($id)) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); return; }
        $res = sb_request('PATCH', 'testimonials?id=eq.' . rawurlencode($id), $data);
        echo json_encode(['ok' => ($res['status'] >= 200 && $res['status'] < 300)]);
    }
}

function update_mentor() {
    $id = trim($_POST['id'] ?? '');
    if (!is_valid_uuid($id)) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); return; }

    $user = $_SESSION['admin'];
    if (!$user['perms']['mentoras'] && $user['role'] !== 'super_admin') {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Sem permissão para editar mentoras']); return;
    }

    $data = [
        'name'          => trim($_POST['name']          ?? ''),
        'role'          => trim($_POST['role']          ?? ''),
        'bio'           => trim($_POST['bio']           ?? ''),
        'photo_url'     => trim($_POST['photo_url']     ?? ''),
        'video_url'     => trim($_POST['video_url']     ?? ''),
        'instagram_url' => trim($_POST['instagram_url'] ?? ''),
        'facebook_url'  => trim($_POST['facebook_url']  ?? '') ?: null,
    ];

    $res = sb_request('PATCH', 'mentors?id=eq.' . rawurlencode($id), $data);
    echo json_encode(['ok' => ($res['status'] >= 200 && $res['status'] < 300)]);
}

// ── WPP HANDLERS ─────────────────────────────────────────────────────────────
function wpp_stats() {
    // Contagens por status
    $statuses = ['pending', 'processing', 'sent', 'failed'];
    $counts   = ['pending'=>0,'processing'=>0,'sent'=>0,'failed'=>0,'read'=>0,'delivered'=>0];

    foreach ($statuses as $s) {
        $res = sb_request('GET', 'whatsapp_queue', null, 'status=eq.' . rawurlencode($s) . '&select=id&limit=1');
        // Supabase não retorna count sem Prefer:count=exact — usamos query separada
    }

    // Abordagem mais simples: buscar todos com limit alto e contar no PHP
    $all = sb_request('GET', 'whatsapp_queue', null, 'select=id,status,delivery_status,delivered_at,read_at&limit=5000');
    if (isset($all['body']) && is_array($all['body'])) {
        foreach ($all['body'] as $row) {
            $s = $row['status'] ?? '';
            if (isset($counts[$s])) $counts[$s]++;
            if ($s === 'sent') {
                $ds = strtolower($row['delivery_status'] ?? '');
                if ($row['read_at'] || in_array($ds, ['read','played'])) $counts['read']++;
                elseif ($row['delivered_at'] || $ds === 'received') $counts['delivered']++;
            }
        }
    }

    // Fila ativa (pending, processing, failed) — até 200 registros
    $qRes = sb_request('GET', 'whatsapp_queue', null,
        'status=in.(pending,processing,failed)&select=id,to_name,to_phone,message,status,error_msg,scheduled_at,attempts&order=scheduled_at.asc&limit=200');

    // Enviadas recentes com confirmação de entrega — 100 últimas
    $sRes = sb_request('GET', 'whatsapp_queue', null,
        'status=eq.sent&select=id,to_name,to_phone,message,sent_at,delivery_status,delivered_at,read_at,zapi_message_id&order=sent_at.desc&limit=100');

    echo json_encode([
        'ok'     => true,
        'counts' => $counts,
        'queue'  => $qRes['body'] ?? [],
        'sent'   => $sRes['body'] ?? [],
    ]);
}

function wpp_reset_stuck() {
    // Mensagens em "processing" há mais de 5 minutos → volta para "pending"
    $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - 300);
    $res = sb_request('PATCH',
        'whatsapp_queue?status=eq.processing&updated_at=lt.' . rawurlencode($cutoff),
        ['status' => 'pending', 'updated_at' => gmdate('Y-m-d\TH:i:s\Z')]
    );
    $affected = is_array($res['body']) ? count($res['body']) : 0;
    echo json_encode(['ok' => true, 'affected' => $affected]);
}

function wpp_retry_failed() {
    $res = sb_request('PATCH',
        'whatsapp_queue?status=eq.failed',
        ['status' => 'pending', 'attempts' => 0, 'error_msg' => null, 'updated_at' => gmdate('Y-m-d\TH:i:s\Z')]
    );
    $affected = is_array($res['body']) ? count($res['body']) : 0;
    echo json_encode(['ok' => true, 'affected' => $affected]);
}

function wpp_cancel_msg() {
    $id = trim($_POST['id'] ?? '');
    if (!is_valid_uuid($id)) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); return; }
    $res = sb_request('DELETE', 'whatsapp_queue?id=eq.' . rawurlencode($id));
    echo json_encode(['ok' => ($res['status'] >= 200 && $res['status'] < 300)]);
}

// ── EMAIL TEMPLATES HANDLERS ──────────────────────────────────────────────────
function list_email_templates() {
    $res = sb_request('GET', 'email_templates', null, 'select=slug,subject,active,updated_at&order=slug.asc&limit=200');
    echo json_encode(['ok' => true, 'data' => $res['body'] ?? []]);
}

function get_email_template() {
    $slug = trim($_POST['slug'] ?? '');
    if (!$slug || !preg_match('/^[a-z0-9_\-]+$/', $slug)) {
        echo json_encode(['ok'=>false,'error'=>'Slug inválido']); return;
    }
    $res = sb_request('GET', 'email_templates', null, 'slug=eq.' . rawurlencode($slug) . '&select=slug,subject,body_html,active&limit=1');
    $row = $res['body'][0] ?? null;
    echo json_encode(['ok' => (bool)$row, 'data' => $row]);
}

function save_email_template() {
    $slug      = trim($_POST['slug']      ?? '');
    $subject   = trim($_POST['subject']   ?? '');
    $body_html = $_POST['body_html']      ?? '';
    if (!$slug || !preg_match('/^[a-z0-9_\-]+$/', $slug)) {
        echo json_encode(['ok'=>false,'error'=>'Slug inválido']); return;
    }
    if (!$subject) { echo json_encode(['ok'=>false,'error'=>'Assunto obrigatório']); return; }
    $data = ['slug'=>$slug,'subject'=>$subject,'body_html'=>$body_html,'updated_at'=>date('c')];
    $res = sb_upsert('email_templates', $data, 'slug');
    echo json_encode(['ok' => $res]);
}

function toggle_email_template() {
    $slug   = trim($_POST['slug']   ?? '');
    $active = ($_POST['active'] ?? '1') === '1';
    if (!$slug) { echo json_encode(['ok'=>false,'error'=>'Slug obrigatório']); return; }
    $res = sb_request('PATCH', 'email_templates?slug=eq.' . rawurlencode($slug), ['active'=>$active,'updated_at'=>date('c')]);
    echo json_encode(['ok' => ($res['status'] >= 200 && $res['status'] < 300)]);
}

function delete_email_template() {
    $slug = trim($_POST['slug'] ?? '');
    if (!$slug || !preg_match('/^[a-z0-9_\-]+$/', $slug)) {
        echo json_encode(['ok'=>false,'error'=>'Slug inválido']); return;
    }
    $res = sb_request('DELETE', 'email_templates?slug=eq.' . rawurlencode($slug));
    echo json_encode(['ok' => ($res['status'] >= 200 && $res['status'] < 300)]);
}

// ── WPP TEMPLATES HANDLERS ────────────────────────────────────────────────────
function list_wpp_templates() {
    $res = sb_request('GET', 'wpp_templates', null, 'select=id,slug,name,message,active,updated_at&order=name.asc&limit=200');
    echo json_encode(['ok' => true, 'data' => $res['body'] ?? []]);
}

function get_wpp_template() {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); return; }
    $res = sb_request('GET', 'wpp_templates', null, 'id=eq.' . $id . '&select=id,slug,name,message,active&limit=1');
    $row = $res['body'][0] ?? null;
    echo json_encode(['ok' => (bool)$row, 'data' => $row]);
}

function save_wpp_template() {
    $id      = (int)($_POST['id']      ?? 0);
    $slug    = trim($_POST['slug']    ?? '');
    $name    = trim($_POST['name']    ?? '');
    $message = $_POST['message']      ?? '';
    if (!$name)    { echo json_encode(['ok'=>false,'error'=>'Nome obrigatório']); return; }
    if (!$message) { echo json_encode(['ok'=>false,'error'=>'Mensagem obrigatória']); return; }
    if (!$slug)    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name));

    $data = ['slug'=>$slug,'name'=>$name,'message'=>$message,'updated_at'=>date('c')];
    if ($id) {
        $res = sb_request('PATCH', 'wpp_templates?id=eq.' . $id, $data);
        echo json_encode(['ok' => ($res['status'] >= 200 && $res['status'] < 300)]);
    } else {
        $res = sb_upsert('wpp_templates', $data, 'slug');
        echo json_encode(['ok' => $res]);
    }
}

function toggle_wpp_template() {
    $id     = (int)($_POST['id']     ?? 0);
    $active = ($_POST['active'] ?? '1') === '1';
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); return; }
    $res = sb_request('PATCH', 'wpp_templates?id=eq.' . $id, ['active'=>$active,'updated_at'=>date('c')]);
    echo json_encode(['ok' => ($res['status'] >= 200 && $res['status'] < 300)]);
}

function delete_wpp_template() {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); return; }
    $res = sb_request('DELETE', 'wpp_templates?id=eq.' . $id);
    echo json_encode(['ok' => ($res['status'] >= 200 && $res['status'] < 300)]);
}

// ── USERS HANDLERS ────────────────────────────────────────────────────────────
function list_users() {
    $user = $_SESSION['admin'];
    if ($user['role'] !== 'super_admin') {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Apenas super_admin pode gerenciar usuários']); return;
    }
    $res = sb_request('GET', 'admin_users', null,
        'select=id,username,role,active,last_login,perm_dashboard,perm_leads,perm_videos,perm_textos,perm_quiz,perm_mentoras,perm_config,perm_usuarios&order=username.asc&limit=200');
    echo json_encode(['ok' => true, 'data' => $res['body'] ?? []]);
}

function create_user() {
    $user = $_SESSION['admin'];
    if ($user['role'] !== 'super_admin') {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Apenas super_admin pode criar usuários']); return;
    }
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role     = $_POST['role'] ?? 'viewer';

    if (!$username || strlen($username) < 3) { echo json_encode(['ok'=>false,'error'=>'Usuário deve ter ao menos 3 caracteres']); return; }
    if (strlen($password) < 8) { echo json_encode(['ok'=>false,'error'=>'Senha deve ter ao menos 8 caracteres']); return; }
    if (!in_array($role, ['super_admin','editor_videos','editor_textos','viewer'])) {
        echo json_encode(['ok'=>false,'error'=>'Cargo inválido']); return;
    }

    $data = [
        'username'        => $username,
        'password_hash'   => password_hash($password, PASSWORD_BCRYPT),
        'role'            => $role,
        'active'          => true,
        'perm_dashboard'  => ($_POST['perm_dashboard']  ?? '0') === '1',
        'perm_leads'      => ($_POST['perm_leads']      ?? '0') === '1',
        'perm_videos'     => ($_POST['perm_videos']     ?? '0') === '1',
        'perm_textos'     => ($_POST['perm_textos']     ?? '0') === '1',
        'perm_quiz'       => ($_POST['perm_quiz']       ?? '0') === '1',
        'perm_mentoras'   => ($_POST['perm_mentoras']   ?? '0') === '1',
        'perm_config'     => ($_POST['perm_config']     ?? '0') === '1',
        'perm_usuarios'   => ($_POST['perm_usuarios']   ?? '0') === '1',
    ];

    $res = sb_request('POST', 'admin_users', $data);
    echo json_encode(['ok' => ($res['status'] === 201 || $res['status'] === 200),'error'=>$res['body']['message']??null]);
}

function update_user() {
    $user = $_SESSION['admin'];
    if ($user['role'] !== 'super_admin') {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Apenas super_admin pode editar usuários']); return;
    }
    $id   = trim($_POST['id']   ?? '');
    $role = trim($_POST['role'] ?? '');
    if (!is_valid_uuid($id)) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); return; }
    if (!in_array($role, ['super_admin','editor_videos','editor_textos','viewer'])) {
        echo json_encode(['ok'=>false,'error'=>'Cargo inválido']); return;
    }

    $data = [
        'role'           => $role,
        'perm_dashboard' => ($_POST['perm_dashboard']  ?? '0') === '1',
        'perm_leads'     => ($_POST['perm_leads']      ?? '0') === '1',
        'perm_videos'    => ($_POST['perm_videos']     ?? '0') === '1',
        'perm_textos'    => ($_POST['perm_textos']     ?? '0') === '1',
        'perm_quiz'      => ($_POST['perm_quiz']       ?? '0') === '1',
        'perm_mentoras'  => ($_POST['perm_mentoras']   ?? '0') === '1',
        'perm_config'    => ($_POST['perm_config']     ?? '0') === '1',
        'perm_usuarios'  => ($_POST['perm_usuarios']   ?? '0') === '1',
        'updated_at'     => date('c'),
    ];

    $res = sb_request('PATCH', 'admin_users?id=eq.' . rawurlencode($id), $data);
    echo json_encode(['ok' => ($res['status'] >= 200 && $res['status'] < 300)]);
}

function reset_password() {
    $user = $_SESSION['admin'];
    if ($user['role'] !== 'super_admin') {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Apenas super_admin pode redefinir senhas']); return;
    }
    $id       = trim($_POST['id']       ?? '');
    $password = trim($_POST['password'] ?? '');
    if (!is_valid_uuid($id)) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); return; }
    if (strlen($password) < 8) { echo json_encode(['ok'=>false,'error'=>'Senha deve ter ao menos 8 caracteres']); return; }

    $res = sb_request('PATCH', 'admin_users?id=eq.' . rawurlencode($id), [
        'password_hash' => password_hash($password, PASSWORD_BCRYPT),
        'updated_at'    => date('c'),
    ]);
    echo json_encode(['ok' => ($res['status'] >= 200 && $res['status'] < 300)]);
}

function toggle_user() {
    $user = $_SESSION['admin'];
    if ($user['role'] !== 'super_admin') {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Apenas super_admin pode ativar/desativar usuários']); return;
    }
    $id     = trim($_POST['id']     ?? '');
    $active = ($_POST['active'] ?? '0') === '1';
    if (!is_valid_uuid($id)) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); return; }

    $res = sb_request('PATCH', 'admin_users?id=eq.' . rawurlencode($id), ['active'=>$active,'updated_at'=>date('c')]);
    echo json_encode(['ok' => ($res['status'] >= 200 && $res['status'] < 300)]);
}

function delete_user() {
    $user = $_SESSION['admin'];
    if ($user['role'] !== 'super_admin') {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Apenas super_admin pode excluir usuários']); return;
    }
    $id = trim($_POST['id'] ?? '');
    if (!is_valid_uuid($id)) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); return; }
    // Não permitir excluir a si mesmo
    if ($id === $user['id']) { echo json_encode(['ok'=>false,'error'=>'Você não pode excluir sua própria conta']); return; }

    $res = sb_request('DELETE', 'admin_users?id=eq.' . rawurlencode($id));
    echo json_encode(['ok' => ($res['status'] >= 200 && $res['status'] < 300)]);
}

// ── UPLOAD DE ARQUIVO ─────────────────────────────────────────────────────────
function upload_file() {
    if (empty($_FILES['file']['tmp_name'])) {
        echo json_encode(['ok'=>false,'error'=>'Nenhum arquivo enviado']); return;
    }
    $bucket  = preg_replace('/[^a-z0-9\-_]/', '', $_POST['bucket'] ?? 'uploads');
    $ext     = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png','gif','webp','svg','mp4','pdf'];
    if (!in_array($ext, $allowed)) {
        echo json_encode(['ok'=>false,'error'=>'Tipo de arquivo não permitido']); return;
    }

    $path = trim($_POST['path'] ?? '');
    if (!$path) $path = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $path = ltrim($path, '/');

    $url = SUPABASE_URL . '/storage/v1/object/' . $bucket . '/' . $path;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_HTTPHEADER     => [
            'apikey: '        . SUPABASE_SERVICE_KEY,
            'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
            'Content-Type: ' . ($_FILES['file']['type'] ?: 'application/octet-stream'),
            'x-upsert: true',
        ],
        CURLOPT_POSTFIELDS     => file_get_contents($_FILES['file']['tmp_name']),
        CURLOPT_TIMEOUT        => 60,
    ]);
    $res  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code >= 200 && $code < 300) {
        $pub_url = SUPABASE_URL . '/storage/v1/object/public/' . $bucket . '/' . $path;
        echo json_encode(['ok'=>true,'url'=>$pub_url,'path'=>$path]);
    } else {
        echo json_encode(['ok'=>false,'error'=>'Upload falhou (HTTP '.$code.')', 'detail'=>json_decode($res,true)]);
    }
}

// ── HELPERS ───────────────────────────────────────────────────────────────────
function require_auth() {
    if (!isset($_SESSION['admin'])) {
        http_response_code(401);
        echo json_encode(['ok'=>false,'error'=>'Não autenticado. Faça login novamente.']);
        exit;
    }
}

function is_valid_uuid($str) {
    return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $str);
}

function sb_upsert(string $table, array $data, string $conflict_col): bool {
    $url  = SUPABASE_URL . '/rest/v1/' . $table;
    $hdrs = [
        'apikey: '        . SUPABASE_SERVICE_KEY,
        'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
        'Content-Type: application/json',
        'Prefer: resolution=merge-duplicates,return=minimal',
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_HTTPHEADER     => $hdrs,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

function sb_request(string $method, string $path, ?array $body = null, string $query = ''): array {
    if (strlen(SUPABASE_SERVICE_KEY) < 20) {
        return ['status' => 503, 'body' => null, 'error' => 'Service key não configurada'];
    }

    $url = SUPABASE_URL . '/rest/v1/' . $path . ($query ? '?' . $query : '');

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
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['status' => 0, 'body' => null, 'error' => $curlErr];
    }

    return ['status' => $status, 'body' => json_decode($response, true)];
}
