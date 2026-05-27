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
    case 'list_imported_leads':       require_auth(); list_imported_leads();       break;
    case 'import_csv_batch':          require_auth(); import_csv_batch();          break;
    case 'toggle_imported_optin':     require_auth(); toggle_imported_optin();     break;
    case 'enqueue_imported_lead':     require_auth(); enqueue_imported_lead();     break;
    case 'enqueue_imported_bulk':     require_auth(); enqueue_imported_bulk();     break;
    case 'delete_imported_lead':      require_auth(); delete_imported_lead();      break;

    // Gestão de usuários (apenas super_admin ou perm_usuarios)
    case 'list_users':    require_auth(); list_users();    break;
    case 'create_user':   require_auth(); create_user();   break;
    case 'update_user':   require_auth(); update_user();   break;
    case 'reset_password':require_auth(); reset_password();break;
    case 'toggle_user':   require_auth(); toggle_user();   break;
    case 'delete_user':   require_auth(); delete_user();   break;

    // Painel — diagnóstico e gestão de filas
    case 'painel_stats':         require_auth(); painel_stats();         break;
    case 'painel_maintenance':   require_auth(); require_perm('config'); painel_maintenance();   break;
    case 'painel_queue_list':    require_auth(); painel_queue_list();    break;
    case 'painel_force_send':    require_auth(); require_perm('config'); painel_force_send();    break;
    case 'painel_process_now':   require_auth(); require_perm('config'); painel_process_now();   break;
    case 'painel_worker_status': require_auth(); painel_worker_status(); break;
    case 'painel_run_worker':    require_auth(); require_perm('config'); painel_run_worker();    break;
    case 'painel_test_email':    require_auth(); require_perm('config'); painel_test_email();    break;
    case 'painel_test_wpp':      require_auth(); require_perm('config'); painel_test_wpp();      break;
    case 'painel_activate_leads':require_auth(); require_perm('config'); painel_activate_leads();break;

    // Importação com sequência
    case 'import_with_sequence': require_auth(); require_perm('leads'); import_with_sequence(); break;

    // Fase A — Lead Profile & Funnel
    case 'lead_profile':        require_auth(); require_perm('leads'); lead_profile();        break;
    case 'lead_update_stage':   require_auth(); require_perm('leads'); lead_update_stage();   break;
    case 'lead_send_now':       require_auth(); require_perm('leads'); lead_send_now();       break;
    case 'lead_pause_sequence':      require_auth(); require_perm('leads'); lead_pause_sequence();      break;
    case 'lead_unsubscribe_email':   require_auth(); require_perm('leads'); lead_unsubscribe_email();   break;
    case 'lead_unsubscribe_wpp':     require_auth(); require_perm('leads'); lead_unsubscribe_wpp();     break;
    case 'leads_list':               require_auth(); require_perm('leads'); leads_list();               break;

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

    $nome      = $lead['name'] ?? 'você';
    $video_url = defined('OPTIN_VIDEO_URL') ? OPTIN_VIDEO_URL : getenv('OPTIN_VIDEO_URL');

    // Mensagem 1 (imediata): apresentação + vídeo do programa
    if ($video_url) {
        $msg1 = "Olá, *{$nome}*! 👋\n\nSou da equipe do Programa *EmagreSer* com a Dra. Daniely e a Ira.\n\nGravamos um vídeo especial de apresentação para você entender como nosso programa funciona 👇\n\n{$video_url}";
    } else {
        $msg1 = "Olá, *{$nome}*! 👋\n\nSou da equipe do Programa *EmagreSer* com a Dra. Daniely e a Ira.\n\nVocê faz parte de uma lista especial e queremos te apresentar nosso programa de emagrecimento saudável, que trabalha os padrões neurológicos que sabotam o resultado.";
    }

    // Mensagem 2 (5 min depois): pergunta de opt-in
    $msg2 = "*{$nome}*, o que achou? 😊\n\nGostaríamos de continuar te enviando conteúdos gratuitos e o acesso à nossa *Masterclass ao vivo* no dia *11/06 às 20h* — 100% gratuita.\n\n✅ *Responda SIM* para continuar recebendo.\n🚫 *Responda NÃO* se preferir não receber mais.\n\nSua resposta é muito importante para nós! 🙏";

    $now      = gmdate('Y-m-d\TH:i:s\Z');
    $five_min = gmdate('Y-m-d\TH:i:s\Z', strtotime('+5 minutes'));

    $r = sb_request('POST', 'whatsapp_queue', [
        [
            'lead_id'      => $lead['id'],
            'to_phone'     => $lead['phone'],
            'to_name'      => $nome,
            'message'      => $msg1,
            'scheduled_at' => $now,
            'status'       => 'pending',
        ],
        [
            'lead_id'      => $lead['id'],
            'to_phone'     => $lead['phone'],
            'to_name'      => $nome,
            'message'      => $msg2,
            'scheduled_at' => $five_min,
            'status'       => 'pending',
        ],
    ]);

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

// ── IMPORTED LEADS ───────────────────────────────────────────────────────────

function list_imported_leads() {
    require_perm('leads');
    $batch  = trim($_POST['batch']  ?? '');
    $filter = trim($_POST['filter'] ?? '');
    $qs = 'select=id,name,email,phone,sabotador,source,source_campaign,optin_wpp,optin_email,email_blocked,wpp_optout,automation_enrolled,sequence_queued_at,import_batch,notes,created_at&order=created_at.desc&limit=2000';
    if ($batch) $qs .= '&import_batch=eq.' . rawurlencode($batch);
    $r = sb_request('GET', 'imported_leads', null, $qs);
    echo json_encode(['ok' => $r['status'] < 300, 'data' => $r['body'] ?? []]);
}

function import_csv_batch() {
    require_perm('leads');
    $rows_json = $_POST['rows'] ?? '[]';
    $batch     = trim($_POST['batch'] ?? '');
    if (!$batch) { echo json_encode(['ok'=>false,'error'=>'Batch obrigatório']); return; }
    $rows = json_decode($rows_json, true);
    if (!is_array($rows) || empty($rows)) { echo json_encode(['ok'=>false,'error'=>'Sem registros']); return; }

    $records = [];
    foreach ($rows as $row) {
        $name  = trim($row['nome']  ?? $row['name']     ?? '');
        $email = trim($row['email'] ?? '');
        $phone = trim($row['telefone'] ?? $row['phone'] ?? '');
        $sab   = trim($row['sabotador'] ?? '') ?: 'IG — Sem mapeamento';
        $camp  = trim($row['campanha']  ?? $row['source_campaign'] ?? '');
        if (!$name && !$email && !$phone) continue;
        // Normaliza telefone
        $ph = preg_replace('/\D/','',$phone);
        if (strlen($ph)===11) $ph='55'.$ph;
        elseif(strlen($ph)===10) $ph='550'.$ph;
        $records[] = [
            'name'            => $name,
            'email'           => $email ?: null,
            'phone'           => $ph    ?: null,
            'sabotador'       => $sab,
            'source_campaign' => $camp  ?: null,
            'import_batch'    => $batch,
            'created_at'      => gmdate('c'),
            'updated_at'      => gmdate('c'),
        ];
    }
    if (empty($records)) { echo json_encode(['ok'=>false,'error'=>'Nenhum registro válido']); return; }
    $r = sb_request('POST', 'imported_leads', $records, 'on_conflict=email');
    echo json_encode(['ok' => $r['status'] < 300, 'imported' => count($records)]);
}

function toggle_imported_optin() {
    require_perm('leads');
    $id    = trim($_POST['id']    ?? '');
    $field = trim($_POST['field'] ?? ''); // optin_wpp | optin_email
    $val   = ($_POST['value'] ?? '0') === '1';
    if (!is_valid_uuid($id)) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); return; }
    if (!in_array($field, ['optin_wpp','optin_email','email_blocked','wpp_optout'], true)) {
        echo json_encode(['ok'=>false,'error'=>'Campo inválido']); return;
    }
    $r = sb_request('PATCH', 'imported_leads?id=eq.' . rawurlencode($id), [$field => $val, 'updated_at' => gmdate('c')]);
    echo json_encode(['ok' => $r['status'] < 300]);
}

function enqueue_imported_lead() {
    require_perm('leads');
    $id   = trim($_POST['id']   ?? '');
    $type = trim($_POST['type'] ?? ''); // email | wpp | both
    if (!is_valid_uuid($id)) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); return; }
    if (!in_array($type, ['email','wpp','both'], true)) { echo json_encode(['ok'=>false,'error'=>'Tipo inválido']); return; }

    $leads = sb_get('imported_leads?id=eq.' . rawurlencode($id) . '&select=id,name,email,phone,sabotador&limit=1');
    if (empty($leads)) { echo json_encode(['ok'=>false,'error'=>'Lead não encontrado']); return; }
    $lead = $leads[0];
    $now  = gmdate('c');
    $inserted = 0;

    if (in_array($type, ['email','both']) && !empty($lead['email'])) {
        $r = sb_request('POST', 'email_queue', [[
            'lead_id'       => $lead['id'],
            'template_slug' => 'reengajamento_1',
            'to_email'      => $lead['email'],
            'to_name'       => $lead['name'] ?? '',
            'scheduled_at'  => $now,
            'status'        => 'pending',
        ]]);
        if ($r['status'] < 300) $inserted++;
    }
    if (in_array($type, ['wpp','both']) && !empty($lead['phone'])) {
        $nome = $lead['name'] ?? 'você';
        $msg  = "Olá, *{$nome}*! 🌱\n\nAqui é a Daniely, do Programa EmagreSer.\n\nVocê faz parte do nosso grupo especial e eu quero te convidar para a nossa Masterclass gratuita *\"O Código dos Sabotadores\"* — 11/06 às 20h.\n\nResponda esta mensagem para receber o link! 👇";
        $r = sb_request('POST', 'whatsapp_queue', [[
            'lead_id'      => $lead['id'],
            'to_phone'     => $lead['phone'],
            'to_name'      => $nome,
            'message'      => $msg,
            'scheduled_at' => $now,
            'status'       => 'pending',
        ]]);
        if ($r['status'] < 300) $inserted++;
    }

    sb_request('PATCH', 'imported_leads?id=eq.' . rawurlencode($id), ['sequence_queued_at' => $now, 'updated_at' => $now]);
    echo json_encode(['ok' => $inserted > 0, 'inserted' => $inserted]);
}

function enqueue_imported_bulk() {
    require_perm('leads');
    $ids_json = $_POST['ids']  ?? '[]';
    $type     = trim($_POST['type'] ?? 'both');
    $ids = json_decode($ids_json, true);
    if (!is_array($ids) || empty($ids)) { echo json_encode(['ok'=>false,'error'=>'Sem IDs']); return; }
    if (!in_array($type, ['email','wpp','both'], true)) { echo json_encode(['ok'=>false,'error'=>'Tipo inválido']); return; }

    // Only valid UUIDs
    $ids = array_filter($ids, 'is_valid_uuid');
    if (empty($ids)) { echo json_encode(['ok'=>false,'error'=>'IDs inválidos']); return; }

    $ids_qs = implode(',', array_map('rawurlencode', $ids));
    $leads  = sb_get('imported_leads?id=in.(' . $ids_qs . ')&select=id,name,email,phone&limit=500');

    $email_rows = []; $wpp_rows = [];
    $now = gmdate('c');
    foreach ($leads as $lead) {
        if (in_array($type, ['email','both']) && !empty($lead['email'])) {
            $email_rows[] = ['lead_id'=>$lead['id'],'template_slug'=>'reengajamento_1','to_email'=>$lead['email'],'to_name'=>$lead['name']??'','scheduled_at'=>$now,'status'=>'pending'];
        }
        if (in_array($type, ['wpp','both']) && !empty($lead['phone'])) {
            $nome = $lead['name'] ?? 'você';
            $wpp_rows[] = ['lead_id'=>$lead['id'],'to_phone'=>$lead['phone'],'to_name'=>$nome,'message'=>"Olá, *{$nome}*! Aqui é a Daniely do Programa EmagreSer. Masterclass gratuita 11/06 às 20h — guarde a data!",'scheduled_at'=>$now,'status'=>'pending'];
        }
    }
    $inserted = 0;
    if (!empty($email_rows)) { $r=sb_request('POST','email_queue',$email_rows); if($r['status']<300) $inserted+=count($email_rows); }
    if (!empty($wpp_rows))   { $r=sb_request('POST','whatsapp_queue',$wpp_rows); if($r['status']<300) $inserted+=count($wpp_rows); }

    echo json_encode(['ok'=>true, 'inserted'=>$inserted]);
}

function delete_imported_lead() {
    require_perm('leads');
    $id = trim($_POST['id'] ?? '');
    if (!is_valid_uuid($id)) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); return; }
    $r = sb_request('DELETE', 'imported_leads?id=eq.' . rawurlencode($id));
    echo json_encode(['ok' => $r['status'] < 300]);
}

// ── PAINEL — DIAGNÓSTICO E GESTÃO DE FILAS ────────────────────────────────────

function painel_stats() {
    $now_iso = gmdate('Y-m-d\TH:i:s\Z');
    $counts = [];
    foreach ([
        'leads_total'   => 'leads?select=id',
        'leads_queued'  => 'leads?sequence_queued_at=not.is.null&select=id',
        'email_sent'    => 'email_queue?status=eq.sent&select=id',
        'email_pend'    => 'email_queue?status=eq.pending&select=id',
        'email_fail'    => 'email_queue?status=eq.failed&select=id',
        'email_stuck'   => 'email_queue?status=eq.processing&select=id',
        'wpp_sent'      => 'whatsapp_queue?status=eq.sent&select=id',
        'wpp_pend'      => 'whatsapp_queue?status=eq.pending&select=id',
        'wpp_fail'      => 'whatsapp_queue?status=eq.failed&select=id',
        'wpp_stuck'     => 'whatsapp_queue?status=eq.processing&select=id',
        'email_overdue' => 'email_queue?status=eq.pending&scheduled_at=lte.' . $now_iso . '&select=id',
        'wpp_overdue'   => 'whatsapp_queue?status=eq.pending&scheduled_at=lte.' . $now_iso . '&select=id',
    ] as $key => $path) {
        $r = sb_request('GET', $path, null, '', ['Prefer: count=exact']);
        $counts[$key] = $r['count'] ?? count((array)($r['body'] ?? []));
    }
    echo json_encode(array_merge(['ok' => true], $counts));
}

function painel_maintenance() {
    $op      = trim($_POST['op'] ?? '');
    $item_id = trim($_POST['item_id'] ?? '');

    switch ($op) {
        case 'retry_failed_email':
            $r = sb_request('PATCH', 'email_queue?status=eq.failed', ['status'=>'pending','attempts'=>0,'error_msg'=>null]);
            echo json_encode(['ok' => $r['status'] < 300, 'msg' => 'E-mails com falha reenfileirados']);
            break;
        case 'retry_failed_wpp':
            $r = sb_request('PATCH', 'whatsapp_queue?status=eq.failed', ['status'=>'pending','attempts'=>0,'error_msg'=>null]);
            echo json_encode(['ok' => $r['status'] < 300, 'msg' => 'WPP com falha reenfileirados']);
            break;
        case 'reset_stuck_email':
            $r = sb_request('PATCH', 'email_queue?status=eq.processing', ['status'=>'pending','attempts'=>0]);
            echo json_encode(['ok' => $r['status'] < 300, 'msg' => 'E-mails travados resetados']);
            break;
        case 'reset_stuck_wpp':
            $r = sb_request('PATCH', 'whatsapp_queue?status=eq.processing', ['status'=>'pending','attempts'=>0]);
            echo json_encode(['ok' => $r['status'] < 300, 'msg' => 'WPP travados resetados']);
            break;
        case 'delete_failed_email':
            $r = sb_request('DELETE', 'email_queue?status=eq.failed');
            echo json_encode(['ok' => $r['status'] < 300, 'msg' => 'E-mails com falha excluídos']);
            break;
        case 'delete_failed_wpp':
            $r = sb_request('DELETE', 'whatsapp_queue?status=eq.failed');
            echo json_encode(['ok' => $r['status'] < 300, 'msg' => 'WPP com falha excluídos']);
            break;
        case 'delete_pending_email':
            $r = sb_request('DELETE', 'email_queue?status=eq.pending');
            echo json_encode(['ok' => $r['status'] < 300, 'msg' => 'Fila de e-mails pendentes limpa']);
            break;
        case 'delete_pending_wpp':
            $r = sb_request('DELETE', 'whatsapp_queue?status=eq.pending');
            echo json_encode(['ok' => $r['status'] < 300, 'msg' => 'Fila de WPP pendentes limpa']);
            break;
        case 'delete_item_email':
            if (!is_valid_uuid($item_id)) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); return; }
            $r = sb_request('DELETE', 'email_queue?id=eq.' . rawurlencode($item_id));
            echo json_encode(['ok' => $r['status'] < 300, 'msg' => 'Item removido da fila']);
            break;
        case 'delete_item_wpp':
            if (!is_valid_uuid($item_id)) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); return; }
            $r = sb_request('DELETE', 'whatsapp_queue?id=eq.' . rawurlencode($item_id));
            echo json_encode(['ok' => $r['status'] < 300, 'msg' => 'Item removido da fila']);
            break;
        default:
            echo json_encode(['ok'=>false,'error'=>'Operação desconhecida: '.$op]);
    }
}

function painel_queue_list() {
    $queue = trim($_POST['queue'] ?? '');
    switch ($queue) {
        case 'email_pending':
            $r = sb_request('GET', 'email_queue', null,
                'status=eq.pending&order=scheduled_at.asc&limit=200&select=id,lead_id,to_email,to_name,template_slug,scheduled_at,attempts');
            break;
        case 'email_failed':
            $r = sb_request('GET', 'email_queue', null,
                'status=eq.failed&order=created_at.desc&limit=50&select=id,lead_id,to_email,to_name,template_slug,attempts,error_msg');
            break;
        case 'email_sent':
            $r = sb_request('GET', 'email_queue', null,
                'status=eq.sent&order=sent_at.desc&limit=30&select=id,lead_id,to_email,to_name,template_slug,sent_at');
            break;
        case 'wpp_pending':
            $r = sb_request('GET', 'whatsapp_queue', null,
                'status=eq.pending&order=scheduled_at.asc&limit=200&select=id,lead_id,to_phone,to_name,message,scheduled_at,attempts');
            break;
        case 'wpp_failed':
            $r = sb_request('GET', 'whatsapp_queue', null,
                'status=eq.failed&order=created_at.desc&limit=50&select=id,lead_id,to_phone,to_name,message,attempts,error_msg');
            break;
        case 'wpp_sent':
            $r = sb_request('GET', 'whatsapp_queue', null,
                'status=eq.sent&order=sent_at.desc&limit=50&select=id,lead_id,to_phone,to_name,message,sent_at,delivery_status');
            break;
        default:
            echo json_encode(['ok'=>false,'error'=>'Queue inválida: '.$queue]); return;
    }
    echo json_encode(['ok' => $r['status'] < 300, 'items' => $r['body'] ?? []]);
}

function painel_force_send() {
    $queue_type = trim($_POST['queue_type'] ?? '');
    $item_id    = trim($_POST['item_id']    ?? '');
    if (!is_valid_uuid($item_id))      { echo json_encode(['ok'=>false,'error'=>'ID inválido']); return; }
    if (!in_array($queue_type, ['email','wpp'], true)) { echo json_encode(['ok'=>false,'error'=>'queue_type inválido']); return; }

    if ($queue_type === 'email') {
        $items = sb_get('email_queue?id=eq.' . rawurlencode($item_id) . '&status=in.(pending,failed)&limit=1');
        if (empty($items)) { echo json_encode(['ok'=>false,'error'=>'Item não encontrado ou já enviado']); return; }
        $item = $items[0];
        sb_request('PATCH', 'email_queue?id=eq.' . rawurlencode($item_id), ['status'=>'processing','attempts'=>($item['attempts']+1)]);
        $tpls = sb_get('email_templates?slug=eq.' . rawurlencode($item['template_slug']) . '&limit=1');
        if (empty($tpls)) {
            sb_request('PATCH', 'email_queue?id=eq.' . rawurlencode($item_id), ['status'=>'failed','error_msg'=>'template not found']);
            echo json_encode(['ok'=>false,'error'=>'Template não encontrado']); return;
        }
        $tpl   = $tpls[0];
        $extra = $item['extra_vars'] ?? [];
        if (is_string($extra)) $extra = json_decode($extra, true) ?: [];
        $vars = array_merge([
            '{{nome}}'             => htmlspecialchars($item['to_name'] ?? 'você'),
            '{{link_descadastro}}' => 'https://www.oficialemagreser.com/descadastro.php?email='.urlencode($item['to_email']),
            '{{link_wpp}}'         => 'https://chat.whatsapp.com/GsMAVm3KVncGNR5nHRQ3yQ',
            '{{link_site}}'        => 'https://www.oficialemagreser.com',
            '{{emoji}}'            => '', '{{tipo}}' => '', '{{titulo}}' => '', '{{descricao}}' => '',
        ], $extra);
        $subject = str_replace(array_keys($vars), array_values($vars), $tpl['subject']);
        $body    = str_replace(array_keys($vars), array_values($vars), $tpl['body_html']);
        if (stripos($body, '<html') === false)
            $body = '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"></head><body>'.$body.'</body></html>';
        $result = send_smtp($item['to_email'], $item['to_name'] ?? '', $subject, $body);
        if ($result['ok']) {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            sb_request('PATCH', 'email_queue?id=eq.' . rawurlencode($item_id), ['status'=>'sent','sent_at'=>$now]);
            sb_request('POST', 'email_log', [['queue_id'=>$item_id,'lead_id'=>$item['lead_id'],'to_email'=>$item['to_email'],'template_slug'=>$item['template_slug'],'smtp_response'=>'forced']]);
            echo json_encode(['ok'=>true,'msg'=>'E-mail enviado: '.$item['to_email']]);
        } else {
            sb_request('PATCH', 'email_queue?id=eq.' . rawurlencode($item_id), ['status'=>'failed','error_msg'=>$result['msg'],'attempts'=>($item['attempts']+1)]);
            echo json_encode(['ok'=>false,'msg'=>$result['msg']]);
        }
    } else {
        // wpp
        $items = sb_get('whatsapp_queue?id=eq.' . rawurlencode($item_id) . '&limit=1');
        if (empty($items)) { echo json_encode(['ok'=>false,'error'=>'Item não encontrado']); return; }
        $item   = $items[0];
        $result = send_whatsapp($item['to_phone'], $item['message']);
        if ($result['ok']) {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            sb_request('PATCH', 'whatsapp_queue?id=eq.' . rawurlencode($item_id), ['status'=>'sent','sent_at'=>$now,'zapi_message_id'=>$result['zaapId']??null,'delivery_status'=>'sent']);
            echo json_encode(['ok'=>true,'msg'=>'WPP enviado para '.$item['to_phone']]);
        } else {
            sb_request('PATCH', 'whatsapp_queue?id=eq.' . rawurlencode($item_id), ['status'=>'failed','error_msg'=>$result['msg'],'attempts'=>($item['attempts']+1)]);
            echo json_encode(['ok'=>false,'msg'=>$result['msg']]);
        }
    }
}

function painel_process_now() {
    $now_iso = gmdate('Y-m-d\TH:i:s\Z');
    $pending = sb_get('email_queue?status=eq.pending&scheduled_at=lte.'.$now_iso.'&attempts=lt.3&order=scheduled_at.asc&limit=20');
    $sent_c = 0; $fail_c = 0;
    foreach ($pending as $item) {
        if (!is_array($item) || !isset($item['id'])) continue;
        sb_request('PATCH', 'email_queue?id=eq.' . rawurlencode($item['id']), ['attempts'=>($item['attempts']+1)]);
        $tpls = sb_get('email_templates?slug=eq.'.rawurlencode($item['template_slug']).'&limit=1');
        if (empty($tpls)) {
            sb_request('PATCH', 'email_queue?id=eq.' . rawurlencode($item['id']), ['status'=>'failed','error_msg'=>'template not found']);
            $fail_c++; continue;
        }
        $tpl   = $tpls[0];
        $extra = $item['extra_vars'] ?? [];
        if (is_string($extra)) $extra = json_decode($extra, true) ?: [];
        $vars = array_merge([
            '{{nome}}'             => htmlspecialchars($item['to_name'] ?? 'você'),
            '{{link_descadastro}}' => 'https://www.oficialemagreser.com/descadastro.php?email='.urlencode($item['to_email']),
            '{{link_wpp}}'         => 'https://chat.whatsapp.com/GsMAVm3KVncGNR5nHRQ3yQ',
            '{{link_site}}'        => 'https://www.oficialemagreser.com',
            '{{emoji}}'            => '', '{{tipo}}' => '', '{{titulo}}' => '', '{{descricao}}' => '',
        ], $extra);
        $subject = str_replace(array_keys($vars), array_values($vars), $tpl['subject']);
        $body    = str_replace(array_keys($vars), array_values($vars), $tpl['body_html']);
        if (stripos($body,'<html') === false)
            $body = '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"></head><body>'.$body.'</body></html>';
        $result = send_smtp($item['to_email'], $item['to_name'] ?? '', $subject, $body);
        if ($result['ok']) {
            sb_request('PATCH', 'email_queue?id=eq.' . rawurlencode($item['id']), ['status'=>'sent','sent_at'=>$now_iso]);
            sb_request('POST', 'email_log', [['queue_id'=>$item['id'],'lead_id'=>$item['lead_id'],'to_email'=>$item['to_email'],'template_slug'=>$item['template_slug'],'smtp_response'=>'process_now']]);
            $sent_c++;
        } else {
            $status_new = ($item['attempts'] >= 2) ? 'failed' : 'pending';
            sb_request('PATCH', 'email_queue?id=eq.' . rawurlencode($item['id']), ['status'=>$status_new,'error_msg'=>$result['msg']]);
            $fail_c++;
        }
        usleep(300000);
    }

    $wpp_pending = sb_get('whatsapp_queue?status=eq.pending&scheduled_at=lte.'.$now_iso.'&attempts=lt.3&order=scheduled_at.asc&limit=10');
    $wpp_sent_c = 0;
    foreach ($wpp_pending as $item) {
        if (!is_array($item) || !isset($item['id'])) continue;
        sb_request('PATCH', 'whatsapp_queue?id=eq.' . rawurlencode($item['id']), ['attempts'=>($item['attempts']+1)]);
        $result = send_whatsapp($item['to_phone'], $item['message']);
        if ($result['ok']) {
            sb_request('PATCH', 'whatsapp_queue?id=eq.' . rawurlencode($item['id']), ['status'=>'sent','sent_at'=>$now_iso,'zapi_message_id'=>$result['zaapId']??null,'delivery_status'=>'sent']);
            $wpp_sent_c++;
        } else {
            $status_new = ($item['attempts'] >= 2) ? 'failed' : 'pending';
            sb_request('PATCH', 'whatsapp_queue?id=eq.' . rawurlencode($item['id']), ['status'=>$status_new,'error_msg'=>$result['msg']]);
        }
        usleep(300000);
    }

    if ($sent_c === 0 && $fail_c === 0 && $wpp_sent_c === 0) {
        $msg = 'Nenhum item vencido na fila agora.';
    } else {
        $msg = "E-mails enviados: {$sent_c} | Falhas e-mail: {$fail_c} | WPP enviados: {$wpp_sent_c}";
    }
    echo json_encode(['ok'=>true,'msg'=>$msg,'email_sent'=>$sent_c,'email_fail'=>$fail_c,'wpp_sent'=>$wpp_sent_c]);
}

function painel_worker_status() {
    $docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\');
    $candidates = [
        $docroot . '/email_worker.log',
        dirname(__DIR__) . '/email_worker.log',
        __DIR__ . '/../email_worker.log',
        __DIR__ . '/../../email_worker.log',
    ];
    $log_file  = $candidates[0];
    $log_exists = false;
    foreach ($candidates as $c) {
        if ($c && file_exists($c)) { $log_file = $c; $log_exists = true; break; }
    }
    $log_lines  = [];
    $log_age_ok = false;
    if ($log_exists) {
        $content    = file_get_contents($log_file);
        $all_lines  = array_filter(explode("\n", trim($content)));
        $log_lines  = array_values(array_slice($all_lines, -5));
        $log_age_ok = (time() - filemtime($log_file)) < 1800;
    }
    echo json_encode([
        'ok'             => true,
        'log_available'  => $log_exists,
        'log_age_ok'     => $log_age_ok,
        'log_lines'      => $log_lines,
        'resend_key_ok'  => strlen(getenv('RESEND_API_KEY') ?: '') > 10,
    ]);
}

function painel_run_worker() {
    $docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\');
    $candidates = [
        $docroot . '/email_worker.php',
        dirname(__DIR__) . '/email_worker.php',
        __DIR__ . '/../email_worker.php',
    ];
    $worker = null;
    foreach ($candidates as $c) {
        if ($c && file_exists($c)) { $worker = $c; break; }
    }
    if (!$worker) { echo json_encode(['ok'=>false,'error'=>'email_worker.php não encontrado']); return; }
    exec('php ' . escapeshellarg($worker) . ' > /dev/null 2>&1 &');
    echo json_encode(['ok'=>true,'msg'=>'Worker disparado em background — aguarde alguns segundos e verifique o log.']);
}

function painel_test_email() {
    $to   = trim($_POST['to']   ?? '');
    $name = trim($_POST['name'] ?? 'Teste');
    if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) { echo json_encode(['ok'=>false,'error'=>'E-mail inválido']); return; }
    $subject = 'Teste de envio — Programa EmagreSer';
    $body    = '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"></head><body style="font-family:Arial;padding:24px;background:#f4f3ef"><div style="max-width:500px;margin:0 auto;background:#fff;padding:28px;border-radius:10px"><h2 style="color:#0d9488">&#x2705; E-mail funcionando!</h2><p>Olá, <strong>' . htmlspecialchars($name) . '</strong>!</p><p>Este é um teste da automação do <strong>Programa EmagreSer</strong>.</p><p style="color:#6b7c67;font-size:13px">Enviado em ' . date('d/m/Y H:i:s') . '</p></div></body></html>';
    $result  = send_smtp($to, $name, $subject, $body);
    echo json_encode(['ok' => $result['ok'], 'msg' => $result['ok'] ? "E-mail enviado para {$to}" : $result['msg']]);
}

function painel_test_wpp() {
    $phone = preg_replace('/\D/', '', $_POST['phone'] ?? '');
    $name  = trim($_POST['name'] ?? 'Teste');
    if (strlen($phone) < 10) { echo json_encode(['ok'=>false,'error'=>'Telefone inválido']); return; }
    $result = send_whatsapp('55' . $phone, "✅ *Teste EmagreSer*\n\nOlá, {$name}! A automação de WhatsApp está funcionando.\n\n_" . date('d/m/Y H:i') . "_");
    echo json_encode(['ok' => $result['ok'], 'msg' => $result['ok'] ? "WhatsApp enviado para {$phone}" : $result['msg']]);
}

function painel_activate_leads() {
    $unqueued = sb_get('leads?sequence_queued_at=is.null&select=id,name,email&limit=200');
    $count = 0;
    foreach ($unqueued as $lead) {
        if (empty($lead['email'])) continue;
        $url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/email_trigger.php';
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>json_encode(['lead_id'=>$lead['id'],'event'=>'new_lead']),
            CURLOPT_HTTPHEADER=>['Content-Type: application/json'], CURLOPT_TIMEOUT=>10]);
        curl_exec($ch); curl_close($ch);
        $count++; usleep(100000);
    }
    echo json_encode(['ok'=>true,'msg'=>"Automação ativada para {$count} leads",'count'=>$count]);
}

// ── IMPORTAÇÃO COM SEQUÊNCIA ──────────────────────────────────────────────────

function import_with_sequence() {
    $rows_json   = $_POST['rows_json']   ?? '[]';
    $sequence_id = trim($_POST['sequence_id'] ?? '');
    $canal       = trim($_POST['canal']       ?? 'ambos');

    if (!is_valid_uuid($sequence_id)) { echo json_encode(['ok'=>false,'error'=>'sequence_id inválido']); return; }
    if (!in_array($canal, ['email','wpp','ambos'], true)) $canal = 'ambos';

    $rows = json_decode($rows_json, true);
    if (!is_array($rows) || empty($rows)) { echo json_encode(['ok'=>false,'error'=>'Nenhuma linha no CSV']); return; }

    // Carrega sequência ativa
    $seq_data = sb_get('sequences?id=eq.' . rawurlencode($sequence_id) . '&is_active=eq.true&select=name,items&limit=1');
    if (empty($seq_data[0]['items'])) { echo json_encode(['ok'=>false,'error'=>'Sequência não encontrada ou inativa']); return; }
    $seq_items = $seq_data[0]['items'];

    $tz   = new DateTimeZone('America/Sao_Paulo');
    $base = new DateTime('now', $tz);

    $total    = count($rows);
    $imported = 0;
    $skipped  = 0;
    $errors   = 0;

    foreach ($rows as $i => $row) {
        $name   = trim($row['nome']     ?? $row['name']      ?? '');
        $email  = strtolower(trim($row['email'] ?? $row['e-mail'] ?? ''));
        $phone  = preg_replace('/\D/', '', $row['telefone']  ?? $row['phone'] ?? $row['whatsapp'] ?? '');
        $cidade = trim($row['cidade']   ?? '');
        $estado = strtoupper(trim($row['estado'] ?? ''));
        $sab    = strtoupper(trim($row['sabotador'] ?? $row['perfil'] ?? ''));
        $tipo   = strtolower(trim($row['tipo'] ?? $row['origem'] ?? ''));

        // Validações por canal
        if (in_array($canal, ['email', 'ambos']) && (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            if ($canal === 'email') { $skipped++; continue; }
        }
        if (in_array($canal, ['wpp', 'ambos']) && (!$phone || strlen($phone) < 10)) {
            if ($canal === 'wpp') { $skipped++; continue; }
        }
        if (!$name) $name = $email ? explode('@', $email)[0] : 'Lead ' . ($i + 2);

        // Upsert lead
        $existing = $email
            ? sb_get('leads?email=eq.' . rawurlencode($email) . '&select=id,sequence_queued_at&limit=1')
            : [];

        if (!empty($existing)) {
            if (!empty($existing[0]['sequence_queued_at'])) { $skipped++; continue; }
            $lead_id = $existing[0]['id'];
        } else {
            $created = sb_get_with_return('leads', [
                'name'            => $name,
                'email'           => $email  ?: null,
                'phone'           => $phone  ?: null,
                'cidade'          => $cidade ?: null,
                'estado'          => $estado ?: null,
                'sabotador'       => $sab    ?: null,
                'source'          => 'import',
                'source_campaign' => 'reengajamento_2026',
                'utm_medium'      => $tipo   ?: null,
            ]);
            if (empty($created[0]['id'])) { $errors++; continue; }
            $lead_id = $created[0]['id'];
        }

        $perfis_nomes = [
            'A' => 'Recompensadora', 'B' => 'Piloto Automático',
            'C' => 'Prisioneira do Esforço', 'D' => 'Compensadora Noturna',
        ];
        $nome_perfil = $sab ? ($perfis_nomes[$sab] ?? 'Perfil Sabotador') : 'Perfil Sabotador';

        $vars_email = [
            '{{nome}}'             => htmlspecialchars($name),
            '{{nome_lead}}'        => htmlspecialchars($name),
            '{{nome_perfil}}'      => htmlspecialchars($nome_perfil),
            '{{link_site}}'        => 'https://www.oficialemagreser.com',
            '{{link_wpp}}'         => 'https://chat.whatsapp.com/GsMAVm3KVncGNR5nHRQ3yQ',
            '{{link_vip}}'         => 'https://chat.whatsapp.com/GsMAVm3KVncGNR5nHRQ3yQ',
            '{{link_hotmart}}'     => getenv('HOTMART_LINK') ?: 'https://pay.hotmart.com/emagreser',
            '{{link_descadastro}}' => 'https://www.oficialemagreser.com/descadastro.php?email='.urlencode($email),
            '{{emoji}}'            => '🎯', '{{tipo}}' => $nome_perfil,
            '{{titulo}}'           => 'Descubra seu padrão comportamental', '{{descricao}}' => '',
        ];

        $tel = $phone ? '55' . ltrim($phone, '0') : '';

        foreach ($seq_items as $item) {
            $type = $item['type'] ?? '';
            if (!in_array($type, ['wpp', 'email'])) continue;
            if ($canal === 'email' && $type !== 'email') continue;
            if ($canal === 'wpp'   && $type !== 'wpp')   continue;

            $send_at = seq_schedule_proxy($item, $base, $tz);
            if (!$send_at || strtotime($send_at) < time() - 60) continue;

            if ($type === 'email' && $email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $slug = trim($item['template_slug'] ?? '');
                if (!$slug) continue;
                sb_request('POST', 'email_queue', [[
                    'lead_id'       => $lead_id,
                    'template_slug' => $slug,
                    'to_email'      => $email,
                    'to_name'       => $name,
                    'extra_vars'    => $vars_email,
                    'scheduled_at'  => $send_at,
                    'status'        => 'pending',
                ]]);
            } elseif ($type === 'wpp' && $tel && strlen($phone) >= 10) {
                $msg = seq_sub_vars_proxy($item['message'] ?? '', $name, $nome_perfil);
                if (!$msg) continue;
                sb_request('POST', 'whatsapp_queue', [[
                    'lead_id'      => $lead_id,
                    'to_phone'     => $tel,
                    'to_name'      => $name,
                    'message'      => $msg,
                    'scheduled_at' => $send_at,
                    'status'       => 'pending',
                ]]);
            }
        }

        sb_request('PATCH', 'leads?id=eq.' . rawurlencode($lead_id), [
            'sequence_queued_at' => $base->format(DateTime::ATOM),
            'source_campaign'    => 'reengajamento_2026',
            'source'             => 'import',
        ]);

        $imported++;
        usleep(50000);
    }

    echo json_encode(['ok'=>true,'total'=>$total,'imported'=>$imported,'skipped'=>$skipped,'errors'=>$errors]);
}

// ── FASE A — LEAD PROFILE & FUNNEL ───────────────────────────────────────────

function lead_profile() {
    $id = trim($_POST['lead_id'] ?? '');
    if (!is_valid_uuid($id)) { echo json_encode(['ok'=>false,'error'=>'lead_id inválido']); return; }

    $leads = sb_get('leads?id=eq.' . rawurlencode($id) . '&limit=1');
    if (empty($leads)) { echo json_encode(['ok'=>false,'error'=>'Lead não encontrado']); return; }
    $lead = $leads[0];

    $email_history = sb_get(
        'email_queue?lead_id=eq.' . rawurlencode($id) .
        '&order=scheduled_at.asc' .
        '&select=id,template_slug,status,scheduled_at,sent_at,attempts,error_msg'
    );
    $wpp_history = sb_get(
        'whatsapp_queue?lead_id=eq.' . rawurlencode($id) .
        '&order=scheduled_at.asc' .
        '&select=id,to_phone,message,status,scheduled_at,sent_at,attempts,error_msg,delivery_status'
    );

    $email_next = array_values(array_filter($email_history, function($e) {
        return in_array($e['status'] ?? '', ['pending', 'failed'], true);
    }));
    $wpp_next = array_values(array_filter($wpp_history, function($w) {
        return in_array($w['status'] ?? '', ['pending', 'failed'], true);
    }));

    echo json_encode([
        'ok'            => true,
        'lead'          => $lead,
        'email_history' => $email_history,
        'wpp_history'   => $wpp_history,
        'email_next'    => $email_next,
        'wpp_next'      => $wpp_next,
    ]);
}

function lead_update_stage() {
    $lead_id = trim($_POST['lead_id'] ?? '');
    $stage   = trim($_POST['stage']   ?? '');
    if (!is_valid_uuid($lead_id)) { echo json_encode(['ok'=>false,'error'=>'lead_id inválido']); return; }
    $allowed = ['novo','engajado','interessado','quente','convertido','frio'];
    if (!in_array($stage, $allowed, true)) {
        echo json_encode(['ok'=>false,'error'=>'stage inválido: use ' . implode('|', $allowed)]); return;
    }
    $r = sb_request('PATCH', 'leads?id=eq.' . rawurlencode($lead_id), ['funnel_stage' => $stage]);
    echo json_encode(['ok' => $r['status'] >= 200 && $r['status'] < 300]);
}

function lead_send_now() {
    $lead_id       = trim($_POST['lead_id']       ?? '');
    $type          = trim($_POST['type']          ?? ''); // email | wpp
    $template_slug = trim($_POST['template_slug'] ?? '');
    if (!is_valid_uuid($lead_id))              { echo json_encode(['ok'=>false,'error'=>'lead_id inválido']); return; }
    if (!in_array($type, ['email','wpp'],true)) { echo json_encode(['ok'=>false,'error'=>'type deve ser email ou wpp']); return; }
    if (!$template_slug)                        { echo json_encode(['ok'=>false,'error'=>'template_slug obrigatório']); return; }

    $leads = sb_get('leads?id=eq.' . rawurlencode($lead_id) . '&select=id,name,email,phone,sabotador&limit=1');
    if (empty($leads)) { echo json_encode(['ok'=>false,'error'=>'Lead não encontrado']); return; }
    $lead = $leads[0];
    $nome = $lead['name'] ?? 'você';

    if ($type === 'email') {
        if (empty($lead['email'])) { echo json_encode(['ok'=>false,'error'=>'Lead sem e-mail']); return; }
        $tpls = sb_get('email_templates?slug=eq.' . rawurlencode($template_slug) . '&limit=1');
        if (empty($tpls)) { echo json_encode(['ok'=>false,'error'=>'Template de e-mail não encontrado']); return; }
        $tpl  = $tpls[0];
        $vars = [
            '{{nome}}'             => htmlspecialchars($nome),
            '{{link_descadastro}}' => 'https://www.oficialemagreser.com/descadastro.php?email=' . urlencode($lead['email']),
            '{{link_wpp}}'         => 'https://chat.whatsapp.com/GsMAVm3KVncGNR5nHRQ3yQ',
            '{{link_site}}'        => 'https://www.oficialemagreser.com',
            '{{emoji}}'            => '', '{{tipo}}' => '', '{{titulo}}' => '', '{{descricao}}' => '',
        ];
        $subject = str_replace(array_keys($vars), array_values($vars), $tpl['subject']);
        $body    = str_replace(array_keys($vars), array_values($vars), $tpl['body_html']);
        if (stripos($body, '<html') === false)
            $body = '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"></head><body>' . $body . '</body></html>';
        $result = send_smtp($lead['email'], $nome, $subject, $body);
        if ($result['ok']) {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            // Insert a sent record in email_queue so the timeline shows it
            sb_request('POST', 'email_queue', [[
                'lead_id'       => $lead_id,
                'template_slug' => $template_slug,
                'to_email'      => $lead['email'],
                'to_name'       => $nome,
                'scheduled_at'  => $now,
                'sent_at'       => $now,
                'status'        => 'sent',
                'attempts'      => 1,
            ]]);
            echo json_encode(['ok'=>true,'msg'=>'E-mail enviado para ' . $lead['email']]);
        } else {
            echo json_encode(['ok'=>false,'msg'=>$result['msg']]);
        }
    } else {
        // wpp
        if (empty($lead['phone'])) { echo json_encode(['ok'=>false,'error'=>'Lead sem telefone']); return; }
        $tpls = sb_get('whatsapp_templates?slug=eq.' . rawurlencode($template_slug) . '&limit=1');
        if (empty($tpls)) {
            // Try wpp_templates table as fallback
            $tpls = sb_get('wpp_templates?slug=eq.' . rawurlencode($template_slug) . '&limit=1');
        }
        if (empty($tpls)) { echo json_encode(['ok'=>false,'error'=>'Template WPP não encontrado']); return; }
        $tpl  = $tpls[0];
        $msg  = str_replace(
            ['{{nome}}','{{telefone}}'],
            [$nome, $lead['phone']],
            $tpl['body'] ?? $tpl['message'] ?? ''
        );
        $phone = $lead['phone'];
        if (!str_starts_with($phone, '55')) $phone = '55' . $phone;
        $result = send_whatsapp($phone, $msg);
        if ($result['ok']) {
            echo json_encode(['ok'=>true,'msg'=>'WPP enviado para ' . $lead['phone']]);
        } else {
            echo json_encode(['ok'=>false,'msg'=>$result['msg'] ?? 'Erro ao enviar WPP']);
        }
    }
}

function lead_pause_sequence() {
    $lead_id = trim($_POST['lead_id'] ?? '');
    $pause   = ($_POST['pause'] ?? '0') === '1' || ($_POST['pause'] ?? '') === 'true';
    if (!is_valid_uuid($lead_id)) { echo json_encode(['ok'=>false,'error'=>'lead_id inválido']); return; }
    $r = sb_request('PATCH', 'leads?id=eq.' . rawurlencode($lead_id), ['sequence_paused' => $pause]);
    $ok = $r['status'] >= 200 && $r['status'] < 300;
    echo json_encode(['ok'=>$ok, 'paused'=>$pause, 'http_status'=>$r['status']]);
}

function lead_unsubscribe_email() {
    $lead_id = trim($_POST['lead_id'] ?? '');
    if (!is_valid_uuid($lead_id)) { echo json_encode(['ok'=>false,'error'=>'lead_id inválido']); return; }

    // Seta email_optout = true
    $r1 = sb_request('PATCH', 'leads?id=eq.' . rawurlencode($lead_id), ['email_optout' => true]);

    // Cancela todos os e-mails pendentes
    $r2 = sb_request('PATCH',
        'email_queue?lead_id=eq.' . rawurlencode($lead_id) . '&status=eq.pending',
        ['status' => 'cancelled', 'error_msg' => 'descadastro manual pelo admin']
    );

    // Conta quantos foram cancelados
    $pending = sb_get('email_queue?lead_id=eq.' . rawurlencode($lead_id) .
        '&status=eq.cancelled&error_msg=eq.' . rawurlencode('descadastro manual pelo admin') .
        '&select=id&limit=200');

    $ok = $r1['status'] >= 200 && $r1['status'] < 300;
    echo json_encode(['ok' => $ok, 'cancelled' => count((array)$pending)]);
}

function lead_unsubscribe_wpp() {
    $lead_id = trim($_POST['lead_id'] ?? '');
    if (!is_valid_uuid($lead_id)) { echo json_encode(['ok'=>false,'error'=>'lead_id inválido']); return; }

    // Seta wpp_optout = true
    $r1 = sb_request('PATCH', 'leads?id=eq.' . rawurlencode($lead_id), ['wpp_optout' => true]);

    // Cancela todos os WPP pendentes
    sb_request('PATCH',
        'whatsapp_queue?lead_id=eq.' . rawurlencode($lead_id) . '&status=eq.pending',
        ['status' => 'cancelled', 'error_msg' => 'descadastro manual pelo admin']
    );

    $ok = $r1['status'] >= 200 && $r1['status'] < 300;
    echo json_encode(['ok' => $ok]);
}

function leads_list() {
    $stage  = trim($_POST['stage']  ?? '');
    $search = trim($_POST['search'] ?? '');
    $limit  = max(1, min(500, (int)($_POST['limit'] ?? 50)));
    $offset = max(0, (int)($_POST['offset'] ?? 0));

    $qs = 'select=id,name,email,phone,sabotador,funnel_stage,sequence_queued_at,created_at'
        . '&order=created_at.desc'
        . '&limit=' . $limit
        . '&offset=' . $offset;

    if ($stage !== '') {
        $qs .= '&funnel_stage=eq.' . rawurlencode($stage);
    }
    if ($search !== '') {
        $qs .= '&or=(name.ilike.*' . rawurlencode($search) . '*,email.ilike.*' . rawurlencode($search) . '*)';
    }

    $r = sb_request('GET', 'leads', null, $qs, ['Prefer: count=exact']);
    $total = $r['count'] ?? count((array)($r['body'] ?? []));
    echo json_encode([
        'ok'    => $r['status'] < 300,
        'leads' => $r['body'] ?? [],
        'total' => $total,
    ]);
}

// ── HELPERS PRIVADOS ──────────────────────────────────────────────────────────

function sb_get(string $path): array {
    $r = sb_request('GET', $path);
    return is_array($r['body']) ? $r['body'] : [];
}

function sb_get_with_return(string $table, array $data): array {
    $r = sb_request('POST', $table, [$data], '', ['Prefer: return=representation']);
    return is_array($r['body']) ? $r['body'] : [];
}

function seq_schedule_proxy(array $item, DateTime $base, DateTimeZone $tz): ?string {
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

function seq_sub_vars_proxy(string $msg, string $name, string $perfil): string {
    return str_replace(
        ['{{nome_lead}}', '{{nome}}', '{{nome_perfil}}', '{{link_vip}}', '{{link_hotmart}}', '{{link_site}}'],
        [$name, $name, $perfil, 'https://chat.whatsapp.com/GsMAVm3KVncGNR5nHRQ3yQ', getenv('HOTMART_LINK') ?: 'https://pay.hotmart.com/emagreser', 'https://www.oficialemagreser.com'],
        $msg
    );
}

function send_smtp(string $to, string $toName, string $subject, string $body): array {
    $resend_key = getenv('RESEND_API_KEY') ?: '';
    if (!$resend_key) return ['ok'=>false,'msg'=>'RESEND_API_KEY não configurada'];
    $from    = 'Programa EmagreSer <emagreser@oficialemagreser.com>';
    $text    = strip_tags(str_replace(['<br>','<br/>','<br />'], "\n", $body));
    $payload = json_encode([
        'from'     => $from,
        'to'       => [$toName ? "{$toName} <{$to}>" : $to],
        'reply_to' => 'emagreser@oficialemagreser.com',
        'subject'  => $subject,
        'html'     => $body,
        'text'     => $text,
    ]);
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer '.$resend_key, 'Content-Type: application/json'],
    ]);
    $res  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) return ['ok'=>false,'msg'=>"cURL: {$err}"];
    $data = json_decode($res, true);
    if ($code === 200 || $code === 201) return ['ok'=>true,'msg'=>'resend_id:'.($data['id']??'ok')];
    return ['ok'=>false,'msg'=>"Resend [{$code}]: ".($data['message']??$res)];
}

function send_whatsapp(string $phone, string $message): array {
    $instance      = getenv('ZAPI_INSTANCE')     ?: '';
    $token         = getenv('ZAPI_TOKEN')        ?: '';
    $client_token  = getenv('ZAPI_CLIENT_TOKEN') ?: '';
    if (!$instance || !$token) return ['ok'=>false,'msg'=>'ZAPI não configurado','zaapId'=>null];
    $url = 'https://api.z-api.io/instances/' . $instance . '/token/' . $token . '/send-text';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['phone'=>$phone,'message'=>$message]),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json','Client-Token: '.$client_token],
    ]);
    $res  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($res, true);
    if ($code === 200 && !empty($data['zaapId'])) return ['ok'=>true,'msg'=>'sent','zaapId'=>$data['zaapId']];
    return ['ok'=>false,'msg'=>$res,'zaapId'=>null];
}
