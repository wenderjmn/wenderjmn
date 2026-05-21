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
define('SUPABASE_URL',         'https://drgrwpmhmrrhxuwxabow.supabase.co');
define('SUPABASE_SERVICE_KEY', getenv('SUPABASE_SERVICE_KEY') ?: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImRyZ3J3cG1obXJyaHh1d3hhYm93Iiwicm9sZSI6InNlcnZpY2Vfcm9sZSIsImlhdCI6MTc3NzQ3ODQ5NywiZXhwIjoyMDkzMDU0NDk3fQ.YeZFa-JaHU5muxktAmYr-B0wtov3Qw3h03P-HrJ_pMU');

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
    case 'upload_file':           require_auth(); upload_file();           break;
    case 'list_email_templates':  require_auth(); list_email_templates();  break;
    case 'get_email_template':    require_auth(); get_email_template();    break;
    case 'save_email_template':   require_auth(); save_email_template();   break;
    case 'delete_email_template': require_auth(); delete_email_template(); break;

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

// ── EMAIL TEMPLATES ──────────────────────────────────────────────────────────
function list_email_templates() {
    $r = sb_request('GET', 'email_templates', null, 'select=slug,subject,created_at&order=created_at.asc');
    echo json_encode(['ok' => $r['status'] < 300, 'data' => $r['body'] ?? []]);
}

function get_email_template() {
    $slug = trim($_POST['slug'] ?? '');
    if (!$slug) { echo json_encode(['ok'=>false,'error'=>'slug required']); return; }
    $r = sb_request('GET', 'email_templates', null, 'slug=eq.' . urlencode($slug) . '&limit=1');
    $data = $r['body'][0] ?? null;
    echo json_encode(['ok' => !!$data, 'data' => $data]);
}

function save_email_template() {
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
    $slug = trim($_POST['slug'] ?? '');
    if (!$slug) { echo json_encode(['ok'=>false,'error'=>'slug required']); return; }
    $r = sb_request('DELETE', 'email_templates', null, 'slug=eq.' . urlencode($slug));
    echo json_encode(['ok' => $r['status'] < 300]);
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

function sb_request(string $method, string $path, ?array $body = null, string $query = ''): array {
    if (SUPABASE_SERVICE_KEY === 'COLE_AQUI_SUA_SERVICE_ROLE_KEY') {
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
