<?php
declare(strict_types=1);

// -----------------------------------------------------------------------------
// Database configuration (override via environment variables if desired)
// -----------------------------------------------------------------------------
$DB_HOST = getenv('HEBREW_APP_DB_HOST') ?: 'sql303.ezyro.com';
$DB_NAME = getenv('HEBREW_APP_DB_NAME') ?: 'ezyro_40031468_hebrew_vocab';
$DB_USER = getenv('HEBREW_APP_DB_USER') ?: 'ezyro_40031468';
$DB_PASS = getenv('HEBREW_APP_DB_PASS') ?: '450bd088fa3';

$dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $DB_HOST, $DB_NAME);

try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'DB connection failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    exit;
}

// -----------------------------------------------------------------------------
// Session and shared helpers
// -----------------------------------------------------------------------------
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$APP_BASE = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
$UPLOAD_DIR = __DIR__ . '/uploads';
$UPLOAD_URL = ($APP_BASE === '' ? '' : $APP_BASE) . '/uploads';

if (!is_dir($UPLOAD_DIR)) {
    mkdir($UPLOAD_DIR, 0755, true);
}

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function is_post(): bool
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function ensure_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }

    return $_SESSION['csrf'];
}

function check_token(?string $token): void
{
    if ($token === null || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(403);
        echo 'Invalid CSRF token.';
        exit;
    }
}

function flash(string $message, string $type = 'info'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function get_flash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

function handle_audio_upload(array $file, string $uploadDir): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Audio upload failed.');
    }

    if (($file['size'] ?? 0) > 10 * 1024 * 1024) {
        throw new RuntimeException('Audio file is larger than 10MB.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']) ?: '';
    $allowed = [
        'audio/mpeg' => 'mp3',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'audio/ogg' => 'ogg',
        'audio/mp3' => 'mp3',
    ];

    if (!array_key_exists($mime, $allowed)) {
        throw new RuntimeException('Unsupported audio format.');
    }

    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if ($ext === '') {
        $ext = $allowed[$mime];
    }

    $ext = preg_replace('/[^a-z0-9]/i', '', $ext) ?: $allowed[$mime];
    $filename = sprintf('word_%s_%s.%s', date('YmdHis'), bin2hex(random_bytes(4)), $ext);
    $target = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new RuntimeException('Unable to save uploaded audio file.');
    }

    return 'uploads/' . $filename;
}

function save_recorded_audio(?string $dataUrl, string $uploadDir): ?string
{
    if ($dataUrl === null || $dataUrl === '') {
        return null;
    }

    if (!preg_match('#^data:(audio/(?:webm|ogg|mp3|mpeg|wav))(?:;codecs=[^;]+)?;base64,(.+)$#i', $dataUrl, $matches)) {
        throw new RuntimeException('Unrecognized recorded audio format.');
    }

    $mime = strtolower($matches[1]);
    $base64 = str_replace(' ', '+', $matches[2]);
    $binary = base64_decode($base64, true);

    if ($binary === false) {
        throw new RuntimeException('Failed to decode recorded audio.');
    }

    if (strlen($binary) > 10 * 1024 * 1024) {
        throw new RuntimeException('Recorded audio is larger than 10MB.');
    }

    $allowed = [
        'audio/webm' => 'webm',
        'audio/ogg' => 'ogg',
        'audio/mp3' => 'mp3',
        'audio/mpeg' => 'mp3',
        'audio/wav' => 'wav',
    ];

    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Unsupported recorded audio type.');
    }

    $filename = sprintf('recording_%s_%s.%s', date('YmdHis'), bin2hex(random_bytes(4)), $allowed[$mime]);
    $target = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

    if (file_put_contents($target, $binary) === false) {
        throw new RuntimeException('Unable to save recorded audio file.');
    }

    return 'uploads/' . $filename;
}

function delete_upload(?string $relativePath, string $uploadDir): void
{
    if ($relativePath === null || $relativePath === '') {
        return;
    }

    $normalized = str_replace('\\', '/', $relativePath);
    if (strpos($normalized, 'uploads/') !== 0) {
        return;
    }

    $filename = basename($normalized);
    $fullPath = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}
