<?php
/**
 * cron_ping.php — Pseudo-cron para hosting compartilhado.
 * Chamado de forma silenciosa pelo frontend a cada carregamento de página.
 * Dispara email_worker.php em background se já passou 60s do último run.
 *
 * Acesso: GET /cron_ping.php (sem autenticação — não expõe dados, só dispara)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store, no-cache');

define('WORKER_SECRET',  getenv('WORKER_SECRET'));
define('MIN_INTERVAL',   60);   // segundos mínimos entre runs
define('STAMP_FILE',     sys_get_temp_dir() . '/emagreser_cron_last.txt');

// Lê o timestamp do último disparo
$last = (int)(file_exists(STAMP_FILE) ? file_get_contents(STAMP_FILE) : 0);
$now  = time();
$elapsed = $now - $last;

if ($elapsed < MIN_INTERVAL) {
    echo json_encode(['ok' => true, 'skipped' => true, 'next_in' => (MIN_INTERVAL - $elapsed)]);
    exit;
}

// Atualiza o timestamp ANTES de disparar (evita duplo disparo em requisições simultâneas)
file_put_contents(STAMP_FILE, $now, LOCK_EX);

// Monta URL do worker (detecta HTTP/HTTPS e host automaticamente)
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base   = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
$base   = rtrim($base, '/');
$worker_url = "{$scheme}://{$host}{$base}/email_worker.php?secret=" . WORKER_SECRET;

// Dispara worker de forma assíncrona (não-bloqueante, timeout 0)
$ch = curl_init($worker_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_NOBODY         => false,
    CURLOPT_TIMEOUT        => 1,        // fecha conexão em 1s (worker continua em background)
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,    // shared hosting pode ter cert auto-assinado
    CURLOPT_USERAGENT      => 'EmagreSer-Cron/1.0',
]);
curl_exec($ch);
curl_close($ch);

echo json_encode(['ok' => true, 'fired' => true, 'elapsed' => $elapsed]);
