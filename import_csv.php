<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

$csrf = ensure_token();
$flash = get_flash();

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
    $handle = fopen($tmpPath, 'r');

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

    $headerMap = array_change_key_case(array_flip($header), CASE_LOWER);

    if (!isset($headerMap['hebrew'])) {
        fclose($handle);
        flash('Missing required column: hebrew', 'error');
        redirect('import_csv.php');
    }

    $imported = 0;
    $pdo->beginTransaction();

    while (($row = fgetcsv($handle)) !== false) {
        $hebrew = trim($row[$headerMap['hebrew']] ?? '');
        if ($hebrew === '') {
            continue;
        }

        $transliteration = isset($headerMap['transliteration']) ? trim($row[$headerMap['transliteration']] ?? '') : '';
        $partOfSpeech = isset($headerMap['part_of_speech']) ? trim($row[$headerMap['part_of_speech']] ?? '') : '';
        $notes = isset($headerMap['notes']) ? trim($row[$headerMap['notes']] ?? '') : '';
        $langCode = isset($headerMap['lang_code']) ? trim($row[$headerMap['lang_code']] ?? '') : '';
        $otherScript = isset($headerMap['other_script']) ? trim($row[$headerMap['other_script']] ?? '') : '';
        $meaning = isset($headerMap['meaning']) ? trim($row[$headerMap['meaning']] ?? '') : '';
        $example = isset($headerMap['example']) ? trim($row[$headerMap['example']] ?? '') : '';
        $audioUrl = isset($headerMap['audio_url']) ? trim($row[$headerMap['audio_url']] ?? '') : '';
        $deckName = isset($headerMap['deck']) ? trim($row[$headerMap['deck']] ?? '') : '';

        $audioPath = null;
        if ($audioUrl !== '' && preg_match('~^uploads/[\w./-]+$~', $audioUrl)) {
            $audioPath = $audioUrl;
        }

        $pdo->prepare('INSERT INTO words (hebrew, transliteration, part_of_speech, notes, audio_path) VALUES (?, ?, ?, ?, ?)')
            ->execute([$hebrew, $transliteration, $partOfSpeech, $notes, $audioPath]);
        $wordId = (int) $pdo->lastInsertId();

        if ($langCode !== '' || $meaning !== '' || $otherScript !== '') {
            $pdo->prepare('INSERT INTO translations (word_id, lang_code, other_script, meaning, example) VALUES (?, ?, ?, ?, ?)')
                ->execute([
                    $wordId,
                    $langCode !== '' ? $langCode : 'und',
                    $otherScript !== '' ? $otherScript : null,
                    $meaning !== '' ? $meaning : null,
                    $example !== '' ? $example : null,
                ]);
        }

        if ($deckName !== '') {
            $deckId = get_or_create_deck_by_name($pdo, $deckName);
            add_word_to_deck($pdo, $deckId, $wordId);
        }

        $imported++;
    }

    fclose($handle);
    $pdo->commit();

    flash(sprintf('Imported %d rows.', $imported), 'success');
    redirect('import_csv.php');
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

    <section class="card">
        <h2>CSV Import</h2>
        <p>Upload a CSV with headers (comma-separated):
            <code>hebrew, transliteration, part_of_speech, notes, deck, lang_code, other_script, meaning, example, audio_url</code>
        </p>
        <details>
            <summary>Download sample CSV</summary>
            <pre class="translations-pre">hebrew,transliteration,part_of_speech,notes,deck,lang_code,other_script,meaning,example,audio_url
שלום,shalom,noun,greeting,Core Hebrew Starter,ru,привет,hello,"שלום! מה שלומך?",
כלב,kelev,noun,masc.,Animals,ru,собака,dog,"הכלב רץ בפארק",
לאכול,le'echol,verb,pa'al,Verbs,en,,eat,"אני אוהב לאכול",
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
