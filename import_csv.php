<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
// אם אין מערכת משתמשים, אפשר להעיר את השורה הבאה:
// $user = null;
$user = require_user($pdo);

$csrf   = ensure_token();
$flash  = get_flash();
$report = $_SESSION['import_report'] ?? null;
unset($_SESSION['import_report']);

if (is_post() && ($_POST['mode'] ?? '') === 'import') {
    check_token($_POST['csrf'] ?? null);

    if (empty($_FILES['csv']['name'])) {
        flash('Please choose a CSV file.', 'error');
        redirect('import_csv.php');
    }
    if (($_FILES['csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        flash('CSV upload failed.', 'error');
        redirect('import_csv.php');
    }

    $tmpPath = $_FILES['csv']['tmp_name'];
    $handle  = fopen($tmpPath, 'r');
    if ($handle === false) {
        flash('Unable to read the uploaded CSV file.', 'error');
        redirect('import_csv.php');
    }

    $header = fgetcsv($handle);
    if ($header === false) {
        fclose($handle);
        flash('CSV file is empty.', 'error');
        redirect('import_csv.php');
    }

    // מיפוי כותרות לאינדקסים ללא תלות באותיות גדולות/קטנות
    $headerMap = array_change_key_case(array_flip($header), CASE_LOWER);

    if (!isset($headerMap['hebrew'])) {
        fclose($handle);
        flash('Missing required column: hebrew', 'error');
        redirect('import_csv.php');
    }

    $imported  = 0;
    $errors    = [];
    $line      = 1;
    $batchSize = 0;
    $pdo->beginTransaction();

    while (($row = fgetcsv($handle)) !== false) {
        $line++;

        $hebrew = trim($row[$headerMap['hebrew']] ?? '');
        if ($hebrew === '') {
            $errors[] = ['line' => $line, 'message' => 'Missing hebrew'];
            continue;
        }

        $transliteration = isset($headerMap['transliteration']) ? trim($row[$headerMap['transliteration']] ?? '') : '';
        $partOfSpeech    = isset($headerMap['part_of_speech']) ? trim($row[$headerMap['part_of_speech']] ?? '') : '';
        $notes           = isset($headerMap['notes']) ? trim($row[$headerMap['notes']] ?? '') : '';
        $langCode        = isset($headerMap['lang_code']) ? trim($row[$headerMap['lang_code']] ?? '') : '';
        $otherScript     = isset($headerMap['other_script']) ? trim($row[$headerMap['other_script']] ?? '') : '';
        $meaning         = isset($headerMap['meaning']) ? trim($row[$headerMap['meaning']] ?? '') : '';
        $example         = isset($headerMap['example']) ? trim($row[$headerMap['example']] ?? '') : '';
        $audioUrl        = isset($headerMap['audio_url']) ? trim($row[$headerMap['audio_url']] ?? '') : '';
        $imageUrl        = isset($headerMap['image_url']) ? trim($row[$headerMap['image_url']] ?? '') : '';
        $deckValue       = isset($headerMap['deck']) ? trim($row[$headerMap['deck']] ?? '') : '';
        $tagsValue       = isset($headerMap['tags']) ? trim($row[$headerMap['tags']] ?? '') : '';

        try {
            $audioPath = resolve_audio_path($audioUrl, $UPLOAD_DIR); // יחזיר 'uploads/...' או null
            $imagePath = resolve_image_path($imageUrl, $UPLOAD_DIR); // יחזיר 'uploads/...' או null
        } catch (RuntimeException $e) {
            $errors[] = ['line' => $line, 'message' => $e->getMessage()];
            continue;
        }

        // יצירת המילה
        $pdo->prepare('INSERT INTO words (hebrew, transliteration, part_of_speech, notes, audio_path, image_path) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$hebrew, $transliteration, $partOfSpeech, $notes, $audioPath, $imagePath]);
        $wordId = (int)$pdo->lastInsertId();

        // תרגום ראשי (אופציונלי)
        if ($langCode !== '' || $meaning !== '' || $otherScript !== '' || $example !== '') {
            $pdo->prepare('INSERT INTO translations (word_id, lang_code, other_script, meaning, example) VALUES (?, ?, ?, ?, ?)')
                ->execute([
                    $wordId,
                    $langCode !== '' ? $langCode : 'und',
                    $otherScript !== '' ? $otherScript : null,
                    $meaning !== '' ? $meaning : null,
                    $example !== '' ? $example : null,
                ]);
        }

        // שיוך ל־Decks (תמיכה בפורמט: "Basics|Verbs" או "Basics;Verbs" או "Basics, Verbs")
        if ($deckValue !== '') {
            sync_import_decks($pdo, $wordId, $deckValue);
        }

        // תגיות (תמיכה ב־"grammar,verb" או "grammar|verb")
        if ($tagsValue !== '') {
            sync_import_tags($pdo, $wordId, $tagsValue);
        }

        $imported++;
        $batchSize++;

        // קומיט ביניים כל 500 שורות
        if ($batchSize >= 500) {
            $pdo->commit();
            $pdo->beginTransaction();
            $batchSize = 0;
        }
    }

    fclose($handle);
    $pdo->commit();

    $_SESSION['import_report'] = [
        'imported' => $imported,
        'errors'   => $errors,
    ];

    if ($errors) {
        flash(sprintf('Imported %d rows with %d errors.', $imported, count($errors)), 'warn');
    } else {
        flash(sprintf('Imported %d rows.', $imported), 'success');
    }
    redirect('import_csv.php');
}

// ------------------- Helpers: download/validate audio/image -------------------
function resolve_audio_path(string $audioUrl, string $uploadDir): ?string {
    if ($audioUrl === '') return null;

    // נתיב מקומי שכבר נמצא ב-uploads/
    if (preg_match('~^uploads/[\w./-]+$~', $audioUrl)) {
        return $audioUrl;
    }

    if (!filter_var($audioUrl, FILTER_VALIDATE_URL)) {
        throw new RuntimeException('Invalid audio_url value.');
    }

    // הורדה לקובץ זמני
    $context = stream_context_create(['http' => ['timeout' => 10]]);
    $stream  = @fopen($audioUrl, 'rb', false, $context);
    if (!$stream) throw new RuntimeException('Unable to download audio.');

    $temp   = tmpfile();
    $meta   = stream_get_meta_data($temp);
    $tmp    = $meta['uri'];
    $limit  = 10 * 1024 * 1024; // 10MB
    $bytes  = stream_copy_to_stream($stream, $temp, $limit + 1);
    fclose($stream);
    if ($bytes === false || $bytes > $limit) {
        fclose($temp);
        throw new RuntimeException('Audio file exceeds 10MB.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
    $allowed = [
        'audio/mpeg' => 'mp3',
        'audio/mp3'  => 'mp3',
        'audio/ogg'  => 'ogg',
        'audio/wav'  => 'wav',
    ];
    if (!isset($allowed[$mime])) {
        fclose($temp);
        throw new RuntimeException('Unsupported audio mime type.');
    }

    $filename  = sprintf('import_audio_%s.%s', bin2hex(random_bytes(6)), $allowed[$mime]);
    $target    = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
    $contents  = stream_get_contents($temp);
    file_put_contents($target, $contents);
    fclose($temp);

    return 'uploads/' . $filename;
}

function resolve_image_path(string $imageUrl, string $uploadDir): ?string {
    if ($imageUrl === '') return null;

    // נתיב מקומי שכבר נמצא ב-uploads/
    if (preg_match('~^uploads/[\w./-]+$~', $imageUrl)) {
        return $imageUrl;
    }

    if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
        throw new RuntimeException('Invalid image_url value.');
    }

    $context = stream_context_create(['http' => ['timeout' => 10]]);
    $stream  = @fopen($imageUrl, 'rb', false, $context);
    if (!$stream) throw new RuntimeException('Unable to download image.');

    $temp   = tmpfile();
    $meta   = stream_get_meta_data($temp);
    $tmp    = $meta['uri'];
    $limit  = 5 * 1024 * 1024; // 5MB
    $bytes  = stream_copy_to_stream($stream, $temp, $limit + 1);
    fclose($stream);
    if ($bytes === false || $bytes > $limit) {
        fclose($temp);
        throw new RuntimeException('Image file exceeds 5MB.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    if (!isset($allowed[$mime])) {
        fclose($temp);
        throw new RuntimeException('Unsupported image mime type.');
    }

    $filename  = sprintf('import_image_%s.%s', bin2hex(random_bytes(6)), $allowed[$mime]);
    $target    = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
    $contents  = stream_get_contents($temp);
    file_put_contents($target, $contents);
    fclose($temp);

    return 'uploads/' . $filename;
}

// ------------------- Helpers: decks & tags for import -------------------
function sync_import_decks(PDO $pdo, int $wordId, string $deckValue): void {
    // מפריד לפי | ; או ,
    $names = array_filter(array_map(static fn(string $n): string => trim($n), preg_split('/[|;,]+/', $deckValue) ?: []));
    if (!$names) return;

    foreach ($names as $name) {
        if ($name === '') continue;
        // שימוש בפונקציות מה-config.php המאוחד (בטוח גם אם טבלאות לא קיימות)
        $deckId = get_or_create_deck_by_name($pdo, $name);
        if ($deckId > 0) {
            add_word_to_deck($pdo, $deckId, $wordId);
        }
    }
}

function sync_import_tags(PDO $pdo, int $wordId, string $tagsValue): void {
    // מפריד לפי | ; או , או רווחים
    $tags = array_filter(array_map(static fn(string $t): string => trim($t), preg_split('/[|;,\s]+/', $tagsValue) ?: []));
    if (!$tags) return;

    try {
        $stmt   = $pdo->prepare('INSERT INTO tags (name) VALUES (?) ON DUPLICATE KEY UPDATE name = name');
        $lookup = $pdo->prepare('SELECT id FROM tags WHERE name = ?');
        $link   = $pdo->prepare('INSERT IGNORE INTO word_tags (word_id, tag_id) VALUES (?, ?)');

        foreach ($tags as $tag) {
            if ($tag === '') continue;
            $stmt->execute([$tag]);
            $tagId = (int)$pdo->lastInsertId();
            if ($tagId === 0) {
                $lookup->execute([$tag]);
                $tagId = (int)$lookup->fetchColumn();
            }
            if ($tagId > 0) {
                $link->execute([$wordId, $tagId]);
            }
        }
    } catch (Throwable $e) {
        // טבלאות tags/word_tags לא קיימות? מתעלמים בשקט
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bulk Import CSV</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<div class="container">
    <header class="header">
        <nav class="nav">
            <a href="index.php">← Back</a>
            <a href="words.php">Words</a>
        </nav>
    </header>

    <?php if ($flash): ?>
        <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <?php if ($report): ?>
        <section class="card">
            <h3>Last Import Report</h3>
            <p>Imported <?= (int)($report['imported'] ?? 0) ?> rows.</p>
            <?php if (!empty($report['errors'])): ?>
                <details open>
                    <summary><?= count($report['errors']) ?> errors</summary>
                    <ul class="error-list">
                        <?php foreach ($report['errors'] as $error): ?>
                            <li>Line <?= (int)($error['line'] ?? 0) ?>: <?= h($error['message'] ?? '') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="card">
        <h2>CSV Import</h2>
        <p>Upload a CSV with headers (comma-separated):
            <code>hebrew, transliteration, part_of_speech, notes, lang_code, other_script, meaning, example, audio_url, image_url, deck, tags</code>
        </p>
        <details>
            <summary>Download sample CSV</summary>
<pre class="translations-pre">hebrew,transliteration,part_of_speech,notes,lang_code,other_script,meaning,example,audio_url,image_url,deck,tags
שלום,shalom,noun,greeting,ru,привет,hello,"שלום! מה שלומך?",uploads/sample.mp3,uploads/sample.jpg,Basics,"greeting,intro"
כלב,kelev,noun,masc.,ru,собака,dog,"הכלב רץ בפארק",,,"Animals|Beginner","animals pet"
לאכול,le'echol,verb,pa'al,en,,eat,"אני אוהב לאכול",,,,"Verbs","food"
</pre>
        </details>

        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="mode" value="import">
            <label for="csv">CSV file</label>
            <input id="csv" type="file" name="csv" accept=".csv" required>
            <div class="form-actions">
                <button class="btn" type="submit">Import</button>
            </div>
        </form>
    </section>
</div>
</body>
</html>
