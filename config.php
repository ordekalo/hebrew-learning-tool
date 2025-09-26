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

    if (!preg_match('#^data:(audio/(?:webm|ogg|mp3|mpeg|wav));base64,(.+)$#', $dataUrl, $matches)) {
        throw new RuntimeException('Unrecognized recorded audio format.');
    }

    $mime = $matches[1];
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

function ensure_default_deck(PDO $pdo): int
{
    $stmt = $pdo->query('SELECT id FROM decks ORDER BY id ASC LIMIT 1');
    $existing = $stmt ? $stmt->fetchColumn() : false;

    if ($existing) {
        return (int) $existing;
    }

    $insert = $pdo->prepare(
        'INSERT INTO decks (name, description, category, icon, color, rating, learners_count)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    $insert->execute([
        'Core Hebrew Starter',
        'Daily phrases and essential vocabulary to kick off your Hebrew journey.',
        'General',
        'sparkles',
        '#6366f1',
        4.8,
        1250,
    ]);

    return (int) $pdo->lastInsertId();
}

function get_or_create_deck_by_name(PDO $pdo, string $name, string $category = 'General'): int
{
    $stmt = $pdo->prepare('SELECT id FROM decks WHERE name = ? LIMIT 1');
    $stmt->execute([$name]);
    $found = $stmt->fetchColumn();

    if ($found) {
        return (int) $found;
    }

    $insert = $pdo->prepare(
        'INSERT INTO decks (name, category, icon, color, rating, learners_count)
         VALUES (?, ?, ?, ?, ?, ?)'
    );

    $palette = [
        '#6366f1', '#22d3ee', '#f97316', '#facc15', '#34d399', '#ec4899', '#0ea5e9', '#a855f7'
    ];
    $icons = ['sparkles', 'book', 'globe', 'lightbulb', 'star', 'leaf', 'compass'];
    $color = $palette[random_int(0, count($palette) - 1)];
    $icon = $icons[random_int(0, count($icons) - 1)];

    $insert->execute([
        $name,
        $category !== '' ? $category : 'General',
        $icon,
        $color,
        4.7,
        0,
    ]);

    return (int) $pdo->lastInsertId();
}

function fetch_all_decks(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT * FROM decks ORDER BY name ASC');

    return $stmt ? $stmt->fetchAll() : [];
}

function deck_next_position(PDO $pdo, int $deckId): int
{
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM deck_words WHERE deck_id = ?');
    $stmt->execute([$deckId]);

    return (int) $stmt->fetchColumn();
}

function add_word_to_deck(PDO $pdo, int $deckId, int $wordId): void
{
    $stmt = $pdo->prepare('SELECT 1 FROM deck_words WHERE deck_id = ? AND word_id = ?');
    $stmt->execute([$deckId, $wordId]);

    if ($stmt->fetchColumn()) {
        return;
    }

    $position = deck_next_position($pdo, $deckId);
    $insert = $pdo->prepare('INSERT INTO deck_words (deck_id, word_id, position) VALUES (?, ?, ?)');
    $insert->execute([$deckId, $wordId, $position]);
}

function sync_word_decks(PDO $pdo, int $wordId, array $deckIds): void
{
    $normalized = array_values(array_unique(array_map(static fn($id) => max(0, (int) $id), $deckIds)));

    $pdo->beginTransaction();

    try {
        if ($normalized) {
            $placeholders = implode(', ', array_fill(0, count($normalized), '?'));
            $delete = $pdo->prepare(
                "DELETE FROM deck_words WHERE word_id = ? AND deck_id NOT IN ($placeholders)"
            );
            $delete->execute(array_merge([$wordId], $normalized));
        } else {
            $deleteAll = $pdo->prepare('DELETE FROM deck_words WHERE word_id = ?');
            $deleteAll->execute([$wordId]);
        }

        foreach ($normalized as $deckId) {
            if ($deckId <= 0) {
                continue;
            }
            add_word_to_deck($pdo, $deckId, $wordId);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function fetch_word_decks(PDO $pdo, int $wordId): array
{
    $stmt = $pdo->prepare('SELECT deck_id, is_reversed FROM deck_words WHERE word_id = ?');
    $stmt->execute([$wordId]);

    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int) $row['deck_id']] = (int) $row['is_reversed'] === 1;
    }

    return $map;
}

function set_deck_word_reversed(PDO $pdo, int $deckId, int $wordId, bool $isReversed): void
{
    $update = $pdo->prepare('UPDATE deck_words SET is_reversed = ? WHERE deck_id = ? AND word_id = ?');
    $update->execute([$isReversed ? 1 : 0, $deckId, $wordId]);
}
