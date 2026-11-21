<?php
/**
 * QG de la famille — API de stockage (PHP 7.4+)
 * Rôles : "parent" (tout) et "enfant" (lecture limitée, écriture courses/agenda/sorties).
 */
declare(strict_types=1);
ini_set('display_errors', '0');
header_remove('X-Powered-By');

const DATA_DIR  = __DIR__ . '/data';
const DOCS      = ['menu', 'courses', 'agenda', 'noel', 'stock', 'maison', 'sorties'];
const KID_READ  = ['menu', 'courses', 'agenda', 'stock', 'maison', 'sorties']; // pas "noel"
const KID_WRITE = ['courses', 'agenda', 'sorties'];
const MAX_BODY  = 512 * 1024;           // 512 Ko par document
const SESSION_DAYS = 180;               // reste connecté 6 mois sur un appareil
const MAX_ATTEMPTS = 10;                // essais de code…
const ATTEMPT_WINDOW = 900;             // …par tranche de 15 minutes et par IP

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
function config(): ?array {
    $f = DATA_DIR . '/config.json';
    if (!is_file($f)) return null;
    $c = json_decode((string)file_get_contents($f), true);
    return is_array($c) ? $c : null;
}
/** Les identifiants (id, src) ne doivent contenir que lettres, chiffres, _ et - */
function validIds($v, int $depth = 0): bool {
    if ($depth > 12) return false;
    if (!is_array($v)) return true;
    foreach ($v as $k => $x) {
        if (($k === 'id' || $k === 'src') && !(is_string($x) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $x))) return false;
        if (is_array($x) && !validIds($x, $depth + 1)) return false;
    }
    return true;
}
function docPath(string $doc): string { return DATA_DIR . '/' . $doc . '.json'; }
function readDoc(string $doc): array {
    $f = docPath($doc);
    if (!is_file($f)) {                       // première utilisation : données de départ fournies avec l'appli
        $seed = __DIR__ . '/seed/' . $doc . '.json';
        $j = is_file($seed) ? json_decode((string)file_get_contents($seed), true) : null;
        return is_array($j) ? ['version' => 0, 'data' => $j] : ['version' => 0, 'data' => null];
    }
    $j = json_decode((string)file_get_contents($f), true);
    return is_array($j) ? $j : ['version' => 0, 'data' => null];
}

$cfg = config();
if ($cfg === null) out(503, ['error' => 'not_configured', 'message' => "Ouvre setup.php pour choisir les codes."]);

/* --- Session --- */
$sessDir = DATA_DIR . '/sessions';
if (!is_dir($sessDir)) @mkdir($sessDir, 0700, true);
session_save_path($sessDir);
ini_set('session.gc_maxlifetime', (string)(SESSION_DAYS * 86400));
ini_set('session.use_strict_mode', '1');
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('qgfamille');
session_set_cookie_params([
    'lifetime' => SESSION_DAYS * 86400, 'path' => '/', 'secure' => $https,
    'httponly' => true, 'samesite' => 'Strict',
]);
session_start();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';
$role   = $_SESSION['role'] ?? null;

/* Protection CSRF : toutes les requêtes d'écriture doivent venir de l'appli */
if ($method !== 'GET' && ($_SERVER['HTTP_X_QG'] ?? '') !== '1') out(403, ['error' => 'csrf']);

/* --- Connexion --- */
if ($action === 'login' && $method === 'POST') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'inconnu';
    $af = DATA_DIR . '/attempts.json';
    $fh = fopen($af, 'c+');
    flock($fh, LOCK_EX);
    $att = json_decode((string)stream_get_contents($fh), true) ?: [];
    $now = time();
    foreach ($att as $k => $v) if ($now - ($v['t'] ?? 0) > ATTEMPT_WINDOW) unset($att[$k]);
    $key = hash('sha256', $ip);
    if (($att[$key]['n'] ?? 0) >= MAX_ATTEMPTS) {
        flock($fh, LOCK_UN); fclose($fh);
        out(429, ['error' => 'too_many', 'message' => 'Trop d\'essais. Réessaie dans 15 minutes.']);
    }
    $body = json_decode((string)file_get_contents('php://input'), true);
    $code = is_array($body) ? (string)($body['code'] ?? '') : '';
    $newRole = null;
    if ($code !== '' && password_verify($code, $cfg['parent_hash'])) $newRole = 'parent';
    elseif ($code !== '' && password_verify($code, $cfg['kid_hash'])) $newRole = 'enfant';
    if ($newRole === null) {
        $att[$key] = ['n' => ($att[$key]['n'] ?? 0) + 1, 't' => $att[$key]['t'] ?? $now];
    } else {
        unset($att[$key]);
    }
    ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($att));
    flock($fh, LOCK_UN); fclose($fh);
    if ($newRole === null) { usleep(800000); out(401, ['error' => 'bad_code', 'message' => 'Code incorrect.']); }
    session_regenerate_id(true);
    $_SESSION['role'] = $newRole;
    out(200, ['role' => $newRole]);
}
if ($action === 'logout' && $method === 'POST') {
    $_SESSION = [];
    session_destroy();
    out(200, ['ok' => true]);
}
if ($role === null) out(401, ['error' => 'login_required']);
if ($action === 'me') out(200, ['role' => $role]);

/* --- Mises à jour depuis GitHub (parents uniquement) --- */
const REPO       = 'brunoklapita/qg-famille';
const BRANCH     = 'main';
const KEEP_LOCAL  = ['data', 'setup.php'];   // jamais remplacés par une mise à jour

function httpGet(string $url): ?string {
    if (function_exists('curl_init')) {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_USERAGENT => 'QG-famille-updater', CURLOPT_SSL_VERIFYPEER => true]);
        $r = curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
        return ($r !== false && $code === 200) ? (string)$r : null;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 60, 'header' => "User-Agent: QG-famille-updater\r\n"]]);
    $r = @file_get_contents($url, false, $ctx);
    return $r === false ? null : $r;
}
function localVersion(): string {
    $v = json_decode((string)@file_get_contents(__DIR__ . '/version.json'), true);
    return is_array($v) ? (string)($v['version'] ?? '0') : '0';
}
function rmTree(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f)
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    @rmdir($dir);
}
if ($action === 'version') {
    $out = ['local' => localVersion(), 'git' => is_dir(__DIR__ . '/.git')];
    if ($role === 'parent') {
        $remote = httpGet('https://raw.githubusercontent.com/' . REPO . '/' . BRANCH . '/version.json?t=' . time());
        $rv = $remote ? json_decode($remote, true) : null;
        $out['remote'] = is_array($rv) ? (string)($rv['version'] ?? '') : null;
        $out['notes']  = is_array($rv) ? (string)($rv['notes'] ?? '') : '';
    }
    out(200, $out);
}
if ($action === 'update' && $method === 'POST') {
    if ($role !== 'parent') out(403, ['error' => 'forbidden']);
    if (is_dir(__DIR__ . '/.git')) out(409, ['error' => 'git', 'message' => 'Le QG est déployé par Git chez OVH : les mises à jour arrivent toutes seules.']);
    if (!class_exists('ZipArchive')) out(500, ['error' => 'nozip', 'message' => "L'extension PHP zip n'est pas active sur l'hébergement."]);
    $zipData = httpGet('https://codeload.github.com/' . REPO . '/zip/refs/heads/' . BRANCH);
    if ($zipData === null) out(502, ['error' => 'download', 'message' => 'Téléchargement depuis GitHub impossible.']);
    $tmpZip = DATA_DIR . '/update.zip'; $tmpDir = DATA_DIR . '/update_tmp';
    rmTree($tmpDir); file_put_contents($tmpZip, $zipData);
    $zip = new ZipArchive();
    if ($zip->open($tmpZip) !== true) { @unlink($tmpZip); out(500, ['error' => 'badzip', 'message' => 'Archive illisible.']); }
    $zip->extractTo($tmpDir); $zip->close(); @unlink($tmpZip);
    $roots = glob($tmpDir . '/*', GLOB_ONLYDIR);
    $src = $roots[0] ?? null;
    if (!$src || !is_file($src . '/index.html') || !is_file($src . '/api.php')) { rmTree($tmpDir); out(500, ['error' => 'content', 'message' => 'Version téléchargée incomplète, rien n\'a été modifié.']); }
    $count = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($src))), '/');
        $top = explode('/', $rel)[0];
        if (in_array($top, KEEP_LOCAL, true) || $top === '.git' || $top === '.github') continue;
        $dest = __DIR__ . '/' . $rel;
        if ($f->isDir()) { if (!is_dir($dest)) @mkdir($dest, 0755, true); continue; }
        $tmp = $dest . '.new';
        if (@copy($f->getPathname(), $tmp) && @rename($tmp, $dest)) $count++;
    }
    rmTree($tmpDir);
    out(200, ['ok' => true, 'version' => localVersion(), 'files' => $count]);
}


/* --- Documents --- */
$doc = (string)($_GET['doc'] ?? '');
if (!in_array($doc, DOCS, true)) out(404, ['error' => 'unknown_doc']);
$canRead  = $role === 'parent' || in_array($doc, KID_READ, true);
$canWrite = $role === 'parent' || in_array($doc, KID_WRITE, true);

if ($method === 'GET') {
    if (!$canRead) out(403, ['error' => 'forbidden']);
    out(200, readDoc($doc));
}
if ($method === 'POST') {
    if (!$canWrite) out(403, ['error' => 'forbidden']);
    $raw = (string)file_get_contents('php://input', false, null, 0, MAX_BODY + 1);
    if (strlen($raw) > MAX_BODY) out(413, ['error' => 'too_large']);
    $body = json_decode($raw, true);
    if (!is_array($body) || !isset($body['data']) || !is_array($body['data'])) out(400, ['error' => 'bad_body']);
    if (!validIds($body['data'])) out(400, ['error' => 'bad_id']);
    $base = (int)($body['version'] ?? 0);

    $lock = fopen(docPath($doc) . '.lock', 'c');
    flock($lock, LOCK_EX);
    $cur = readDoc($doc);
    if ($base !== (int)$cur['version']) {           // quelqu'un a écrit entre-temps
        flock($lock, LOCK_UN); fclose($lock);
        out(409, ['error' => 'conflict', 'version' => $cur['version'], 'data' => $cur['data']]);
    }
    $next = ['version' => (int)$cur['version'] + 1, 'data' => $body['data'], 'updated' => date('c')];
    $tmp = docPath($doc) . '.tmp';
    file_put_contents($tmp, json_encode($next, JSON_UNESCAPED_UNICODE));
    rename($tmp, docPath($doc));
    flock($lock, LOCK_UN); fclose($lock);
    out(200, ['version' => $next['version']]);
}
out(405, ['error' => 'method']);
