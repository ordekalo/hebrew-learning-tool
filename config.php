<?php
declare(strict_types=1);

// -----------------------------------------------------------------------------
// Database configuration (override via environment variables if desired)
// -----------------------------------------------------------------------------
$DB_HOST = getenv('HEBREW_APP_DB_HOST') ?: 'sql303.ezyro.com';
$DB_NAME = getenv('HEBREW_APP_DB_NAME') ?: 'ezyro_40031468_hebrew_vocab';
$DB_USER = getenv('HEBREW_APP_DB_USER') ?: 'ezyro_40031468';
$DB_PASS = getenv('HEBREW_APP_DB_PASS') ?: '450bd088fa3';

$pdo = null;
$primaryDsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $DB_HOST, $DB_NAME);

try {
    // ---- Primary: MySQL/MariaDB ----
    $pdo = new PDO($primaryDsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    // ---- Fallback: SQLite (dev/local) ----
    $fallbackPath = getenv('HEBREW_APP_SQLITE_PATH') ?: __DIR__ . '/database.sqlite';
    $fallbackDsn  = 'sqlite:' . $fallbackPath;

    try {
        $pdo = new PDO($fallbackDsn, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // SQLite pragmas & minimal schema to run locally
        $pdo->exec('PRAGMA foreign_keys = ON');

        // Base tables (align with app; include image_path as used by UI)
        $pdo->exec('CREATE TABLE IF NOT EXISTS words (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            hebrew TEXT NOT NULL,
            transliteration TEXT NULL,
            part_of_speech TEXT NULL,
            notes TEXT NULL,
            audio_path TEXT NULL,
            image_path TEXT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS translations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            word_id INTEGER NOT NULL,
            lang_code TEXT NOT NULL,
            other_script TEXT NULL,
            meaning TEXT NULL,
            example TEXT NULL,
            FOREIGN KEY(word_id) REFERENCES words(id) ON DELETE CASCADE
        )');

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_translations_word ON translations(word_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_translations_lang  ON translations(lang_code)');
    } catch (Throwable $fallbackException) {
        http_response_code(500);
        echo 'DB connection failed: ' . htmlspecialchars($fallbackException->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        exit;
    }
}

// -----------------------------------------------------------------------------
// Session and shared helpers
// -----------------------------------------------------------------------------
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$APP_BASE   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
$UPLOAD_DIR = __DIR__ . '/uploads';
$UPLOAD_URL = ($APP_BASE === '' ? '' : $APP_BASE) . '/uploads';

if (!is_dir($UPLOAD_DIR)) {
    @mkdir($UPLOAD_DIR, 0755, true);
}

function h(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function redirect(string $path): void {
    header('Location: ' . $path);
    exit;
}
function is_post(): bool {
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}
function ensure_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}
/**
 * check_token(): תואם לאחור – אפשר בלי פרמטר (יקרא מ-$_POST['csrf'])
 */
function check_token(?string $token = null): void {
    $provided = $token ?? ($_POST['csrf'] ?? null);
    if ($provided === null || !hash_equals($_SESSION['csrf'] ?? '', $provided)) {
        http_response_code(403);
        echo 'Invalid CSRF token.';
        exit;
    }
}
function flash(string $message, string $type = 'info'): void {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}
function get_flash(): ?array {
    if (!isset($_SESSION['flash'])) return null;
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

// -----------------------------------------------------------------------------
// Upload helpers (audio & recorded audio)
// -----------------------------------------------------------------------------
function handle_audio_upload(array $file, string $uploadDir): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Audio upload failed.');
    if (($file['size'] ?? 0) > 10 * 1024 * 1024) throw new RuntimeException('Audio file is larger than 10MB.');

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']) ?: '';
    $allowed = [
        'audio/mpeg' => 'mp3',
        'audio/wav'  => 'wav',
        'audio/x-wav'=> 'wav',
        'audio/ogg'  => 'ogg',
        'audio/mp3'  => 'mp3',
    ];
    if (!array_key_exists($mime, $allowed)) throw new RuntimeException('Unsupported audio format.');

    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if ($ext === '') $ext = $allowed[$mime];
    $ext = preg_replace('/[^a-z0-9]/i', '', $ext) ?: $allowed[$mime];

    $filename = sprintf('word_%s_%s.%s', date('YmdHis'), bin2hex(random_bytes(4)), $ext);
    $target   = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($file['tmp_name'], $target)) throw new RuntimeException('Unable to save uploaded audio file.');

    return 'uploads/' . $filename;
}

function save_recorded_audio(?string $dataUrl, string $uploadDir): ?string {
    if ($dataUrl === null || $dataUrl === '') return null;

    if (!preg_match('#^data:(audio/(?:webm|ogg|mp3|mpeg|wav))(?:;codecs=[^;]+)?;base64,(.+)$#i', $dataUrl, $m)) {
        throw new RuntimeException('Unrecognized recorded audio format.');
    }
    $mime   = strtolower($m[1]);
    $base64 = str_replace(' ', '+', $m[2]);
    $binary = base64_decode($base64, true);
    if ($binary === false) throw new RuntimeException('Failed to decode recorded audio.');
    if (strlen($binary) > 10 * 1024 * 1024) throw new RuntimeException('Recorded audio is larger than 10MB.');

    $allowed = [
        'audio/webm' => 'webm',
        'audio/ogg'  => 'ogg',
        'audio/mp3'  => 'mp3',
        'audio/mpeg' => 'mp3',
        'audio/wav'  => 'wav',
    ];
    if (!isset($allowed[$mime])) throw new RuntimeException('Unsupported recorded audio type.');

    $filename = sprintf('recording_%s_%s.%s', date('YmdHis'), bin2hex(random_bytes(4)), $allowed[$mime]);
    $target   = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
    if (file_put_contents($target, $binary) === false) throw new RuntimeException('Unable to save recorded audio file.');

    return 'uploads/' . $filename;
}

function delete_upload(?string $relativePath, string $uploadDir): void {
    if ($relativePath === null || $relativePath === '') return;
    $normalized = str_replace('\\', '/', $relativePath);
    if (strpos($normalized, 'uploads/') !== 0) return;
    $filename = basename($normalized);
    $fullPath = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
    if (is_file($fullPath)) { @unlink($fullPath); }
}

// -----------------------------------------------------------------------------
// DB helpers (driver/random/translation summary)
// -----------------------------------------------------------------------------
function db_driver(): string {
    static $driver;
    if ($driver === null) {
        global $pdo;
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }
    return $driver;
}
function db_random_function(): string {
    return db_driver() === 'sqlite' ? 'RANDOM()' : 'RAND()';
}
function db_translation_summary_select(): string {
    if (db_driver() === 'sqlite') {
        // SQLite concat + separator
        return "GROUP_CONCAT(t.lang_code || ':' || IFNULL(t.meaning, ''), '\n') AS translations_summary";
    }
    // MySQL/MariaDB
    return "GROUP_CONCAT(CONCAT(t.lang_code, ':', COALESCE(t.meaning, '')) SEPARATOR '\n') AS translations_summary";
}

// -----------------------------------------------------------------------------
// Deck helpers (safe-guarded with try/catch if tables not present)
// -----------------------------------------------------------------------------
/**
 * מוודא שיש Deck ברירת־מחדל ומחזיר את המזהה שלו.
 * יחזיר 0 אם טבלת decks אינה קיימת.
 */
function ensure_default_deck(PDO $pdo): int {
    try {
        $stmt = $pdo->query('SELECT id FROM decks ORDER BY id ASC LIMIT 1');
        $existing = $stmt ? $stmt->fetchColumn() : false;
        if ($existing) return (int)$existing;

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
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        return 0; // decks table probably not present yet
    }
}

/**
 * מחזיר Deck לפי שם או יוצר אחד חדש.
 * יחזיר 0 אם טבלת decks אינה קיימת.
 */
function get_or_create_deck_by_name(PDO $pdo, string $name, string $category = 'General'): int {
    try {
        $stmt = $pdo->prepare('SELECT id FROM decks WHERE name = ? LIMIT 1');
        $stmt->execute([$name]);
        $found = $stmt->fetchColumn();
        if ($found) return (int)$found;

        $insert = $pdo->prepare(
            'INSERT INTO decks (name, category, icon, color, rating, learners_count)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        $palette = ['#6366f1','#22d3ee','#f97316','#facc15','#34d399','#ec4899','#0ea5e9','#a855f7'];
        $icons   = ['sparkles','book','globe','lightbulb','star','leaf','compass'];
        $color   = $palette[random_int(0, count($palette) - 1)];
        $icon    = $icons[random_int(0, count($icons) - 1)];

        $insert->execute([
            $name,
            $category !== '' ? $category : 'General',
            $icon,
            $color,
            4.7,
            0,
        ]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        return 0;
    }
}

/** מחזיר את כל ה־Decks; אם אין טבלה יחזיר מערך ריק. */
function fetch_all_decks(PDO $pdo): array {
    try {
        $stmt = $pdo->query('SELECT * FROM decks ORDER BY name ASC');
        return $stmt ? $stmt->fetchAll() : [];
    } catch (Throwable $e) {
        return [];
    }
}

/** המיקום הבא בתוך deck_words (אם אין טבלה – 1). */
function deck_next_position(PDO $pdo, int $deckId): int {
    try {
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM deck_words WHERE deck_id = ?');
        $stmt->execute([$deckId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 1;
    }
}

/** מוסיף מילה ל־Deck אם אינה קיימת. מתחשב בשדה position; אם אין טבלה – יתעלם בשקט. */
function add_word_to_deck(PDO $pdo, int $deckId, int $wordId): void {
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM deck_words WHERE deck_id = ? AND word_id = ?');
        $stmt->execute([$deckId, $wordId]);
        if ($stmt->fetchColumn()) return;

        $position = deck_next_position($pdo, $deckId);
        $insert   = $pdo->prepare('INSERT INTO deck_words (deck_id, word_id, position) VALUES (?, ?, ?)');
        $insert->execute([$deckId, $wordId, $position]);
    } catch (Throwable $e) {
        // ignore if table not present
    }
}

/**
 * מסנכרן רשימת Decks עבור מילה נתונה.
 * אם הטבלה אינה קיימת – מתעלם.
 */
function sync_word_decks(PDO $pdo, int $wordId, array $deckIds): void {
    $normalized = array_values(array_unique(array_map(static fn($id) => max(0, (int)$id), $deckIds)));
    try {
        $pdo->beginTransaction();
        if ($normalized) {
            $placeholders = implode(', ', array_fill(0, count($normalized), '?'));
            $delete = $pdo->prepare("DELETE FROM deck_words WHERE word_id = ? AND deck_id NOT IN ($placeholders)");
            $delete->execute(array_merge([$wordId], $normalized));
        } else {
            $deleteAll = $pdo->prepare('DELETE FROM deck_words WHERE word_id = ?');
            $deleteAll->execute([$wordId]);
        }
        foreach ($normalized as $deckId) {
            if ($deckId <= 0) continue;
            add_word_to_deck($pdo, $deckId, $wordId);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        // ignore if table not present
    }
}

/**
 * מחזיר מפה deck_id=>is_reversed עבור מילה.
 * יחזיר [] אם הטבלה/עמודה אינם קיימים.
 */
function fetch_word_decks(PDO $pdo, int $wordId): array {
    try {
        $stmt = $pdo->prepare('SELECT deck_id, is_reversed FROM deck_words WHERE word_id = ?');
        $stmt->execute([$wordId]);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int)$row['deck_id']] = (int)($row['is_reversed'] ?? 0) === 1;
        }
        return $map;
    } catch (Throwable $e) {
        return [];
    }
}

/** קובע אם הכרטיס הפוך (reverse) עבור מילה ב־Deck נתון; מתעלם אם אין טבלה/עמודה. */
function set_deck_word_reversed(PDO $pdo, int $deckId, int $wordId, bool $isReversed): void {
    try {
        $update = $pdo->prepare('UPDATE deck_words SET is_reversed = ? WHERE deck_id = ? AND word_id = ?');
        $update->execute([$isReversed ? 1 : 0, $deckId, $wordId]);
    } catch (Throwable $e) {
        // ignore
    }
}
