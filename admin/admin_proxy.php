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

// ── CONFIGURAÇÃO ──────────────────────────────────────────────────────────────
// Carrega _env.php: funciona na raiz (Hostinger) e em subpastas (dev)
foreach ([__DIR__ . '/_env.php', __DIR__ . '/../_env.php'] as $_p) {
    if (file_exists($_p)) { require_once $_p; break; }
}

define('SUPABASE_URL',         'https://drgrwpmhmrrhxuwxabow.supabase.co');
define('SUPABASE_SERVICE_KEY', getenv('SUPABASE_SERVICE_KEY') ?: '');

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
    case 'delete_testimonial':    require_auth(); delete_testimonial();    break;
    case 'update_mentor':         require_auth(); update_mentor();         break;
    case 'upload_file':           require_auth(); upload_file();           break;
    case 'list_email_templates':  require_auth(); list_email_templates();  break;
    case 'get_email_template':    require_auth(); get_email_template();    break;
    case 'save_email_template':   require_auth(); save_email_template();   break;
    case 'delete_email_template': require_auth(); delete_email_template(); break;

    // WPP Templates
    case 'list_wpp_templates':  require_auth(); list_wpp_templates();  break;
    case 'get_wpp_template':    require_auth(); get_wpp_template();    break;
    case 'save_wpp_template':   require_auth(); save_wpp_template();   break;
    case 'delete_wpp_template': require_auth(); delete_wpp_template(); break;
    case 'toggle_wpp_template': require_auth(); toggle_wpp_template(); break;

    // Sequências de envio
    case 'list_sequences':   require_auth(); list_sequences();   break;
    case 'get_sequence':     require_auth(); get_sequence();     break;
    case 'save_sequence':    require_auth(); save_sequence();    break;
    case 'delete_sequence':  require_auth(); delete_sequence();  break;
    case 'toggle_sequence':  require_auth(); toggle_sequence();  break;

    // WhatsApp queue
    case 'wpp_stats':         require_auth(); wpp_stats();         break;
    case 'wpp_reset_stuck':   require_auth(); wpp_reset_stuck();   break;
    case 'wpp_retry_failed':  require_auth(); wpp_retry_failed();  break;
    case 'wpp_cancel_msg':    require_auth(); wpp_cancel_msg();    break;
    case 'wpp_queue_import':  require_auth(); wpp_queue_import();  break;

    // Gestão de leads importados
    case 'toggle_email_block': require_auth(); toggle_email_block(); break;
    case 'set_optin_status':   require_auth(); set_optin_status();   break;
    case 'send_optin_wpp':     require_auth(); send_optin_wpp();     break;
    case 'cancel_lead_emails': require_auth(); cancel_lead_emails(); break;

    // Gestão de usuários (apenas super_admin ou perm_usuarios)
    case 'list_users':    require_auth(); list_users();    break;
    case 'create_user':   require_auth(); create_user();   break;
    case 'update_user':   require_auth(); update_user();   break;
    case 'reset_password':require_auth(); reset_password();break;
    case 'toggle_user':   require_auth(); toggle_user();   break;
    case 'delete_user':   require_auth(); delete_user();   break;

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

    if ($res['status'] >= 200 && $res['status'] < 300) {
        echo json_encode(['ok'=>true]);
    } else {
        echo json_encode(['ok'=>false,'error'=>'Supabase retornou HTTP '.$res['status']]);
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
    ];

    foreach ($data as $k => $v) {
        if (!$v) { echo json_encode(['ok'=>false,'error'=>"Campo {$k} não pode ser vazio"]); return; }
    }

    $res = sb_request('PATCH', 'quiz_questions?id=eq.' . rawurlencode($id), $data);
    echo json_encode(['ok' => ($res['status'] >= 200 && $res['status'] < 300)]);
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

function delete_testimonial() {
    $id = trim($_POST['id'] ?? '');
    if (!is_valid_uuid($id)) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); return; }
    $user = $_SESSION['admin'];
    if (!$user['perms']['videos'] && !$user['perms']['textos'] && $user['role'] !== 'super_admin') {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Sem permissão']); return;
    }
    $res = sb_request('DELETE', 'testimonials?id=eq.' . rawurlencode($id));
    echo json_encode(['ok' => $res['status'] >= 200 && $res['status'] < 300]);
}

function update_mentor() {
    $id = trim($_POST['id'] ?? '');
    if (!is_valid_uuid($id)) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); return; }

    $user = $_SESSION['admin'];
    if (!$user['perms']['mentoras'] && $user['role'] !== 'super_admin') {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Sem permissão para editar mentoras']); return;
    }

    $pos = trim($_POST['photo_position'] ?? 'top');
    if (!in_array($pos, ['top','center','bottom'])) $pos = 'top';

    $data = [
        'name'           => trim($_POST['name']          ?? ''),
        'role'           => trim($_POST['role']          ?? ''),
        'bio'            => trim($_POST['bio']           ?? ''),
        'photo_url'      => trim($_POST['photo_url']     ?? ''),
        'photo_position' => $pos,
        'video_url'      => trim($_POST['video_url']     ?? ''),
        'instagram_url'  => trim($_POST['instagram_url'] ?? ''),
    ];

    $res = sb_request('PATCH', 'mentors?id=eq.' . rawurlencode($id), $data);
    echo json_encode(['ok' => ($res['status'] >= 200 && $res['status'] < 300)]);
}

function upload_file() {
    $user = $_SESSION['admin'];

    $bucket = trim($_POST['bucket'] ?? '');
    $allowed_buckets = ['mentoras', 'assets', 'depoimentos'];
    if (!in_array($bucket, $allowed_buckets)) {
        echo json_encode(['ok'=>false,'error'=>'Bucket inválido']); return;
    }

    // Permissão por bucket
    if ($bucket === 'mentoras' && !$user['perms']['mentoras'] && $user['role'] !== 'super_admin') {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Sem permissão para upload de fotos']); return;
    }
    if ($bucket === 'depoimentos' && !$user['perms']['videos'] && !$user['perms']['textos'] && $user['role'] !== 'super_admin') {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Sem permissão para upload de depoimentos']); return;
    }
    if ($bucket === 'assets' && !$user['perms']['config'] && $user['role'] !== 'super_admin') {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Sem permissão para upload de assets']); return;
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $code = $_FILES['file']['error'] ?? 'sem arquivo';
        echo json_encode(['ok'=>false,'error'=>'Erro no upload (código '.$code.')']); return;
    }

    $file = $_FILES['file'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_exts = ['jpg','jpeg','png','webp','gif','svg'];
    if (!in_array($ext, $allowed_exts)) {
        echo json_encode(['ok'=>false,'error'=>'Extensão não permitida: '.$ext]); return;
    }

    // Valida MIME real (não só extensão)
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowed_mimes = ['image/jpeg','image/jpg','image/png','image/webp','image/gif','image/svg+xml'];
    if (!in_array($mime, $allowed_mimes)) {
        echo json_encode(['ok'=>false,'error'=>'Tipo de arquivo não permitido: '.$mime]); return;
    }

    // Sanitiza path e garante unicidade
    $path = trim($_POST['path'] ?? '');
    if (!$path) {
        $path = uniqid('img_', true) . '.' . $ext;
    } else {
        $path = preg_replace('/[^a-zA-Z0-9._\-\/]/', '_', $path);
        $path = ltrim($path, '/');
    }

    $upload_url = SUPABASE_URL . '/storage/v1/object/' . $bucket . '/' . $path;

    $fp = fopen($file['tmp_name'], 'r');
    $ch = curl_init($upload_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_PUT            => true,
        CURLOPT_INFILE         => $fp,
        CURLOPT_INFILESIZE     => $file['size'],
        CURLOPT_HTTPHEADER     => [
            'apikey: '               . SUPABASE_SERVICE_KEY,
            'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
            'Content-Type: '         . $mime,
            'x-upsert: true',
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    if ($curlErr) {
        echo json_encode(['ok'=>false,'error'=>'cURL: '.$curlErr]); return;
    }

    if ($status === 200 || $status === 201) {
        $public_url = SUPABASE_URL . '/storage/v1/object/public/' . $bucket . '/' . $path;
        echo json_encode(['ok'=>true, 'url'=>$public_url, 'path'=>$path]);
    } else {
        $body = json_decode($response, true);
        $msg  = $body['message'] ?? ($body['error'] ?? ('HTTP '.$status));
        echo json_encode(['ok'=>false,'error'=>$msg]);
    }
}

// ── HELPER DE PERMISSÃO ──────────────────────────────────────────────────────
function require_perm(string $perm): void {
    $user = $_SESSION['admin'];
    if ($user['role'] === 'super_admin') return;
    if (!($user['perms'][$perm] ?? false)) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'Sem permissão: '.$perm]);
        exit;
    }
}

// ── EMAIL TEMPLATES ──────────────────────────────────────────────────────────
function list_email_templates() {
    require_perm('leads');
    $r = sb_request('GET', 'email_templates', null, 'select=slug,subject,created_at&order=created_at.asc');
    echo json_encode(['ok' => $r['status'] < 300, 'data' => $r['body'] ?? []]);
}

function get_email_template() {
    require_perm('leads');
    $slug = trim($_POST['slug'] ?? '');
    if (!$slug) { echo json_encode(['ok'=>false,'error'=>'slug required']); return; }
    $r = sb_request('GET', 'email_templates', null, 'slug=eq.' . urlencode($slug) . '&limit=1');
    $data = $r['body'][0] ?? null;
    echo json_encode(['ok' => !!$data, 'data' => $data]);
}

function save_email_template() {
    require_perm('leads');
    $slug      = trim($_POST['slug'] ?? '');
    $subject   = trim($_POST['subject'] ?? '');
    $body_html = $_POST['body_html'] ?? '';
    if (!$slug || !$subject || !$body_html) {
        echo json_encode(['ok'=>false,'error'=>'slug, subject e body_html são obrigatórios']); return;
    }
    // Upsert: tenta atualizar; se não existir, cria
    $r = sb_request('PATCH', 'email_templates', ['subject'=>$subject,'body_html'=>$body_html], 'slug=eq.' . urlencode($slug));
    if ($r['status'] === 200 || $r['status'] === 204) {
        echo json_encode(['ok'=>true,'action'=>'updated']); return;
    }
    $r2 = sb_request('POST', 'email_templates', [['slug'=>$slug,'subject'=>$subject,'body_html'=>$body_html]]);
    echo json_encode(['ok' => $r2['status'] < 300, 'action' => 'created', 'error' => $r2['error'] ?? null]);
}

function delete_email_template() {
    require_perm('leads');
    $slug = trim($_POST['slug'] ?? '');
    if (!$slug) { echo json_encode(['ok'=>false,'error'=>'slug required']); return; }
    $r = sb_request('DELETE', 'email_templates', null, 'slug=eq.' . urlencode($slug));
    echo json_encode(['ok' => $r['status'] < 300]);
}

// ── GESTÃO DE USUÁRIOS ────────────────────────────────────────────────────────
function require_user_admin() {
    $user = $_SESSION['admin'];
    if ($user['role'] !== 'super_admin' && !$user['perms']['usuarios']) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'Sem permissão para gerenciar usuários']);
        exit;
    }
}

function list_users() {
    require_user_admin();
    $r = sb_request('GET', 'admin_users', null,
        'select=id,username,role,active,perm_dashboard,perm_leads,perm_videos,perm_textos,perm_quiz,perm_mentoras,perm_config,perm_usuarios,last_login,created_at&order=created_at.asc'
    );
    echo json_encode(['ok' => $r['status'] < 300, 'data' => $r['body'] ?? []]);
}

function create_user() {
    require_user_admin();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role     = $_POST['role'] ?? 'editor';

    if (!$username || !$password) {
        echo json_encode(['ok'=>false,'error'=>'Usuário e senha são obrigatórios']); return;
    }
    if (!preg_match('/^[a-zA-Z0-9_\.]{3,40}$/', $username)) {
        echo json_encode(['ok'=>false,'error'=>'Usuário inválido (3-40 chars, letras/números/_ apenas)']); return;
    }
    if (strlen($password) < 8) {
        echo json_encode(['ok'=>false,'error'=>'Senha deve ter ao menos 8 caracteres']); return;
    }
    if (!in_array($role, ['super_admin','editor_videos','editor_textos','viewer'])) $role = 'viewer';

    // Só super_admin pode criar outro super_admin
    $current = $_SESSION['admin'];
    if ($role === 'super_admin' && $current['role'] !== 'super_admin') {
        echo json_encode(['ok'=>false,'error'=>'Apenas super_admin pode criar outro super_admin']); return;
    }

    $perms = collect_perms();

    $data = array_merge([
        'username'      => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role'          => $role,
        'active'        => true,
        'created_at'    => date('c'),
        'updated_at'    => date('c'),
    ], $perms);

    $r = sb_request('POST', 'admin_users', [$data]);
    if ($r['status'] === 201 || $r['status'] === 200) {
        echo json_encode(['ok'=>true]);
    } else {
        $msg = $r['body'][0]['message'] ?? $r['body']['message'] ?? ('HTTP '.$r['status']);
        // Username duplicado
        if (strpos($msg, 'duplicate') !== false || strpos($msg, 'unique') !== false) {
            $msg = 'Nome de usuário já existe';
        }
        echo json_encode(['ok'=>false,'error'=>$msg]);
    }
}

function update_user() {
    require_user_admin();
    $id   = trim($_POST['id'] ?? '');
    $role = $_POST['role'] ?? 'editor';
    if (!is_valid_uuid($id)) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); return; }

    // Não pode rebaixar a si mesmo
    if ($id === $_SESSION['admin']['id'] && $role !== $_SESSION['admin']['role']) {
        echo json_encode(['ok'=>false,'error'=>'Você não pode alterar o próprio cargo']); return;
    }

    if (!in_array($role, ['super_admin','editor_videos','editor_textos','viewer'])) $role = 'viewer';
    $current = $_SESSION['admin'];
    if ($role === 'super_admin' && $current['role'] !== 'super_admin') {
        echo json_encode(['ok'=>false,'error'=>'Apenas super_admin pode promover para super_admin']); return;
    }

    $data = array_merge(['role' => $role, 'updated_at' => date('c')], collect_perms());
    $r = sb_request('PATCH', 'admin_users?id=eq.' . rawurlencode($id), $data);
    echo json_encode(['ok' => $r['status'] < 300]);
}

function reset_password() {
    require_user_admin();
    $id       = trim($_POST['id'] ?? '');
    $password = $_POST['password'] ?? '';
    if (!is_valid_uuid($id)) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); return; }
    if (strlen($password) < 8) {
        echo json_encode(['ok'=>false,'error'=>'Senha deve ter ao menos 8 caracteres']); return;
    }
    $r = sb_request('PATCH', 'admin_users?id=eq.' . rawurlencode($id),
        ['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'updated_at' => date('c')]
    );
    echo json_encode(['ok' => $r['status'] < 300]);
}

function toggle_user() {
    require_user_admin();
    $id     = trim($_POST['id'] ?? '');
    $active = $_POST['active'] === '1';
    if (!is_valid_uuid($id)) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); return; }
    // Não pode desativar a si mesmo
    if ($id === $_SESSION['admin']['id']) {
        echo json_encode(['ok'=>false,'error'=>'Você não pode desativar sua própria conta']); return;
    }
    $r = sb_request('PATCH', 'admin_users?id=eq.' . rawurlencode($id),
        ['active' => $active, 'updated_at' => date('c')]
    );
    echo json_encode(['ok' => $r['status'] < 300]);
}

function delete_user() {
    require_user_admin();
    $id = trim($_POST['id'] ?? '');
    if (!is_valid_uuid($id)) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); return; }
    if ($id === $_SESSION['admin']['id']) {
        echo json_encode(['ok'=>false,'error'=>'Você não pode excluir sua própria conta']); return;
    }
    $r = sb_request('DELETE', 'admin_users?id=eq.' . rawurlencode($id));
    echo json_encode(['ok' => $r['status'] < 300]);
}

function collect_perms(): array {
    return [
        'perm_dashboard' => isset($_POST['perm_dashboard']),
        'perm_leads'     => isset($_POST['perm_leads']),
        'perm_videos'    => isset($_POST['perm_videos']),
        'perm_textos'    => isset($_POST['perm_textos']),
        'perm_quiz'      => isset($_POST['perm_quiz']),
        'perm_mentoras'  => isset($_POST['perm_mentoras']),
        'perm_config'    => isset($_POST['perm_config']),
        'perm_usuarios'  => isset($_POST['perm_usuarios']),
    ];
}

// ── WHATSAPP QUEUE ────────────────────────────────────────────────────────────
function wpp_stats() {
    require_perm('leads');
    // Counts por status via Prefer: count=exact
    $statuses = ['pending','processing','sent','failed'];
    $counts = [];
    foreach ($statuses as $s) {
        $r = sb_request('GET', 'whatsapp_queue', null,
            'status=eq.' . $s . '&select=id&limit=1',
            ['Prefer: count=exact']
        );
        $counts[$s] = $r['count'] ?? 0;
    }
    // Counts de confirmação de entrega
    $r = sb_request('GET', 'whatsapp_queue', null,
        'read_at=not.is.null&select=id&limit=1',
        ['Prefer: count=exact']
    );
    $counts['read'] = $r['count'] ?? 0;
    $r = sb_request('GET', 'whatsapp_queue', null,
        'delivered_at=not.is.null&read_at=is.null&select=id&limit=1',
        ['Prefer: count=exact']
    );
    $counts['delivered'] = $r['count'] ?? 0;

    // Últimas 100 msgs enviadas
    $sent = sb_request('GET', 'whatsapp_queue', null,
        'status=eq.sent&select=id,to_name,to_phone,message,sent_at,scheduled_at,delivery_status,delivered_at,read_at,zapi_message_id&order=sent_at.desc&limit=100'
    );

    // Fila pendente/falha (próximas a processar)
    $queue = sb_request('GET', 'whatsapp_queue', null,
        'status=in.(pending,failed,processing)&select=id,to_name,to_phone,message,status,scheduled_at,attempts,error_msg&order=scheduled_at.asc&limit=200'
    );

    echo json_encode([
        'ok'     => true,
        'counts' => $counts,
        'sent'   => $sent['body']  ?? [],
        'queue'  => $queue['body'] ?? [],
    ]);
}

function wpp_reset_stuck() {
    // Desbloqueia mensagens travadas em 'processing' → 'pending'
    $r = sb_request('PATCH', 'whatsapp_queue?status=eq.processing',
        ['status' => 'pending']
    );
    $affected = is_array($r['body']) ? count($r['body']) : 0;
    echo json_encode(['ok' => $r['status'] < 300, 'affected' => $affected]);
}

function wpp_retry_failed() {
    // Recoloca mensagens falhas na fila zerandoattempts
    $r = sb_request('PATCH', 'whatsapp_queue?status=eq.failed',
        ['status' => 'pending', 'attempts' => 0, 'error_msg' => null]
    );
    $affected = is_array($r['body']) ? count($r['body']) : 0;
    echo json_encode(['ok' => $r['status'] < 300, 'affected' => $affected]);
}

function wpp_cancel_msg() {
    $id = trim($_POST['id'] ?? '');
    if (!is_valid_uuid($id)) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); return; }
    // Cancela apenas mensagens ainda não enviadas
    $r = sb_request('DELETE', 'whatsapp_queue?id=eq.' . rawurlencode($id) . '&status=neq.sent');
    echo json_encode(['ok' => $r['status'] < 300]);
}

// ── WPP TEMPLATES ────────────────────────────────────────────────────────────
function list_wpp_templates() {
    require_perm('leads');
    $r = sb_request('GET', 'wpp_templates', null, 'select=id,name,slug,message,active,created_at&order=created_at.asc');
    echo json_encode(['ok' => $r['status'] < 300, 'data' => $r['body'] ?? []]);
}
function get_wpp_template() {
    require_perm('leads');
    $id = trim($_POST['id'] ?? '');
    if (!is_valid_id($id)) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); return; }
    $r  = sb_request('GET', 'wpp_templates', null, 'id=eq.' . rawurlencode($id) . '&limit=1');
    echo json_encode(['ok' => !empty($r['body'][0]), 'data' => $r['body'][0] ?? null]);
}
function save_wpp_template() {
    require_perm('leads');
    $id      = trim($_POST['id'] ?? '');
    $name    = trim($_POST['name'] ?? '');
    $slug    = trim($_POST['slug'] ?? '') ?: strtolower(preg_replace('/[\s\-]+/', '_', $name));
    $message = $_POST['message'] ?? '';
    if (!$name || !$message) { echo json_encode(['ok'=>false,'error'=>'Nome e mensagem obrigatórios']); return; }
    $data = ['name'=>$name,'slug'=>$slug,'message'=>$message,'updated_at'=>date('c')];
    if ($id && is_valid_id($id)) {
        $r = sb_request('PATCH', 'wpp_templates?id=eq.' . rawurlencode($id), $data);
        echo json_encode(['ok' => $r['status'] < 300, 'action'=>'updated']);
    } else {
        $data['active'] = true; $data['created_at'] = date('c');
        $r = sb_request('POST', 'wpp_templates', [$data]);
        echo json_encode(['ok' => $r['status'] < 300, 'action'=>'created']);
    }
}
function toggle_wpp_template() {
    require_perm('leads');
    $id = trim($_POST['id'] ?? ''); $active = ($_POST['active'] ?? '0') === '1';
    if (!is_valid_id($id)) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); return; }
    $r = sb_request('PATCH', 'wpp_templates?id=eq.' . rawurlencode($id), ['active'=>$active,'updated_at'=>date('c')]);
    echo json_encode(['ok' => $r['status'] < 300]);
}
function delete_wpp_template() {
    require_perm('leads');
    $id = trim($_POST['id'] ?? '');
    if (!is_valid_id($id)) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); return; }
    $r = sb_request('DELETE', 'wpp_templates?id=eq.' . rawurlencode($id));
    echo json_encode(['ok' => $r['status'] < 300]);
}

// ── SEQUÊNCIAS ────────────────────────────────────────────────────────────────
function list_sequences() {
    require_perm('leads');
    $r = sb_request('GET', 'sequences', null, 'select=id,name,description,is_active,items,created_at&order=created_at.asc');
    echo json_encode(['ok' => $r['status'] < 300, 'data' => $r['body'] ?? []]);
}
function get_sequence() {
    require_perm('leads');
    $id = trim($_POST['id'] ?? '');
    if (!is_valid_uuid($id)) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); return; }
    $r  = sb_request('GET', 'sequences', null, 'id=eq.' . rawurlencode($id) . '&limit=1');
    echo json_encode(['ok' => !empty($r['body'][0]), 'data' => $r['body'][0] ?? null]);
}
function save_sequence() {
    require_perm('leads');
    $id          = trim($_POST['id'] ?? '');
    $name        = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $items_raw   = $_POST['items'] ?? '[]';
    if (!$name) { echo json_encode(['ok'=>false,'error'=>'Nome obrigatório']); return; }
    $items = json_decode($items_raw, true);
    if (!is_array($items)) { echo json_encode(['ok'=>false,'error'=>'items JSON inválido']); return; }
    $data = ['name'=>$name, 'description'=>$description ?: null, 'items'=>$items, 'updated_at'=>date('c')];
    if ($id && is_valid_uuid($id)) {
        $r = sb_request('PATCH', 'sequences?id=eq.' . rawurlencode($id), $data);
        echo json_encode(['ok' => $r['status'] < 300, 'action'=>'updated', 'id'=>$id]);
    } else {
        $data['is_active'] = true; $data['created_at'] = date('c');
        $r = sb_request('POST', 'sequences', [$data], '', ['Prefer: return=representation']);
        $new_id = $r['body'][0]['id'] ?? null;
        echo json_encode(['ok' => $r['status'] < 300, 'action'=>'created', 'id'=>$new_id]);
    }
}
function delete_sequence() {
    require_perm('leads');
    $id = trim($_POST['id'] ?? '');
    if (!is_valid_uuid($id)) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); return; }
    $r = sb_request('DELETE', 'sequences?id=eq.' . rawurlencode($id));
    echo json_encode(['ok' => $r['status'] < 300]);
}
function toggle_sequence() {
    require_perm('leads');
    $id = trim($_POST['id'] ?? ''); $active = ($_POST['active'] ?? '0') === '1';
    if (!is_valid_uuid($id)) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); return; }
    $r = sb_request('PATCH', 'sequences?id=eq.' . rawurlencode($id), ['is_active'=>$active,'updated_at'=>date('c')]);
    echo json_encode(['ok' => $r['status'] < 300]);
}

// ── GESTÃO DE LEADS IMPORTADOS ────────────────────────────────────────────────

function toggle_email_block() {
    require_perm('leads');
    $id      = trim($_POST['id']      ?? '');
    $blocked = ($_POST['blocked']     ?? '0') === '1';
    if (!is_valid_uuid($id)) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); return; }
    $r = sb_request('PATCH', 'leads?id=eq.' . rawurlencode($id), ['email_blocked'=>$blocked]);
    echo json_encode(['ok' => $r['status'] < 300, 'email_blocked' => $blocked]);
}

function set_optin_status() {
    require_perm('leads');
    $id     = trim($_POST['id']     ?? '');
    $status = trim($_POST['status'] ?? '');
    if (!is_valid_uuid($id)) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); return; }
    $allowed = ['pending','confirmed','refused',''];
    if (!in_array($status, $allowed, true)) { echo json_encode(['ok'=>false,'error'=>'Status inválido']); return; }
    $r = sb_request('PATCH', 'leads?id=eq.' . rawurlencode($id), ['optin_status' => $status ?: null]);
    echo json_encode(['ok' => $r['status'] < 300, 'optin_status' => $status ?: null]);
}

function send_optin_wpp() {
    require_perm('leads');
    $id = trim($_POST['id'] ?? '');
    if (!is_valid_uuid($id)) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); return; }

    $leads = sb_get('leads?id=eq.' . rawurlencode($id) . '&select=id,name,phone,wpp_optout,optin_status&limit=1');
    if (empty($leads)) { echo json_encode(['ok'=>false,'error'=>'Lead não encontrado']); return; }
    $lead = $leads[0];
    if (empty($lead['phone'])) { echo json_encode(['ok'=>false,'error'=>'Lead sem telefone']); return; }
    if ($lead['wpp_optout'] === true) { echo json_encode(['ok'=>false,'error'=>'Lead com opt-out ativo']); return; }

    $nome = $lead['name'] ?? 'você';
    $msg  = "Olá, *{$nome}*! 👋\n\nSou da equipe do Programa *EmagreSer* com a Dra. Daniely.\n\nVocê está na nossa lista e gostaríamos de compartilhar conteúdos gratuitos sobre emagrecimento saudável, incluindo acesso à nossa Masterclass *\"O Código dos Sabotadores\"* em 11/06.\n\n✅ Para continuar recebendo, *responda SIM*.\n🚫 Para não receber mais, *responda NÃO* e não entraremos mais em contato.";

    $now = gmdate('Y-m-d\TH:i:s\Z');
    $r = sb_request('POST', 'whatsapp_queue', [[
        'lead_id'      => $lead['id'],
        'to_phone'     => $lead['phone'],
        'to_name'      => $nome,
        'message'      => $msg,
        'scheduled_at' => $now,
        'status'       => 'pending',
    ]]);

    if ($r['status'] < 300) {
        // Marca opt-in como pendente
        sb_request('PATCH', 'leads?id=eq.' . rawurlencode($id), ['optin_status' => 'pending']);
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok'=>false,'error'=>'Erro ao enfileirar WPP']);
    }
}

function cancel_lead_emails() {
    require_perm('leads');
    $id = trim($_POST['id'] ?? '');
    if (!is_valid_uuid($id)) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); return; }
    $r = sb_request('DELETE', 'email_queue?lead_id=eq.' . rawurlencode($id) . '&status=in.(pending,processing)');
    echo json_encode(['ok' => $r['status'] < 300]);
}

function wpp_queue_import() {
    // Retorna fila WPP apenas para leads importados (source = 'import')
    require_perm('leads');
    $pending = sb_get(
        'whatsapp_queue?status=in.(pending,processing,failed)' .
        '&order=scheduled_at.asc&limit=500' .
        '&select=id,lead_id,to_name,to_phone,message,status,scheduled_at,attempts,error_msg'
    );
    $sent = sb_get(
        'whatsapp_queue?status=eq.sent' .
        '&order=sent_at.desc&limit=200' .
        '&select=id,lead_id,to_name,to_phone,message,status,sent_at,delivery_status,read_at,delivered_at,zapi_message_id'
    );

    // Busca IDs dos leads importados
    $import_leads = sb_get('leads?source=eq.import&select=id&limit=5000');
    $import_ids = array_column($import_leads, 'id');
    $import_set = array_flip($import_ids);

    $pending_filtered = array_values(array_filter($pending, fn($m) => isset($import_set[$m['lead_id']])));
    $sent_filtered    = array_values(array_filter($sent,    fn($m) => isset($import_set[$m['lead_id']])));

    echo json_encode(['ok'=>true, 'queue'=>$pending_filtered, 'sent'=>$sent_filtered]);
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
// Accepts UUID or positive integer (for tables like wpp_templates that use serial IDs)
function is_valid_id($str) {
    return is_valid_uuid($str) || (ctype_digit((string)$str) && (int)$str > 0);
}

function sb_request(string $method, string $path, ?array $body = null, string $query = '', array $extra_headers = []): array {
    if (SUPABASE_SERVICE_KEY === 'COLE_AQUI_SUA_SERVICE_ROLE_KEY') {
        return ['status' => 503, 'body' => null, 'error' => 'Service key não configurada'];
    }

    $url = SUPABASE_URL . '/rest/v1/' . $path . ($query ? '?' . $query : '');

    $headers = array_merge([
        'apikey: '        . SUPABASE_SERVICE_KEY,
        'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
        'Content-Type: application/json',
        'Prefer: return=representation',
    ], $extra_headers);

    $respHeaders = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HEADERFUNCTION => function($ch, $line) use (&$respHeaders) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        },
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

    // Parse Content-Range header for count=exact: e.g. "0-9/42" → 42
    $count = null;
    if (isset($respHeaders['content-range'])) {
        $parts = explode('/', $respHeaders['content-range']);
        if (isset($parts[1]) && is_numeric($parts[1])) {
            $count = (int)$parts[1];
        }
    }

    return ['status' => $status, 'body' => json_decode($response, true), 'count' => $count];
}
