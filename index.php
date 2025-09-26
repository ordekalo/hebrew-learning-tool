<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

$csrf = ensure_token();
$flash = get_flash();
$action = $_GET['a'] ?? 'learn';
$langFilter = isset($_GET['lang']) && $_GET['lang'] !== '' ? substr($_GET['lang'], 0, 10) : null;
$searchTerm = trim($_GET['q'] ?? '');

function fetch_random_card(PDO $pdo, ?string $lang): ?array
{
    $sql = 'SELECT w.*, t.lang_code, t.other_script, t.meaning, t.example
            FROM words w
            LEFT JOIN translations t ON t.word_id = w.id';
    $params = [];

    if ($lang !== null) {
        $sql .= ' AND t.lang_code = ?';
        $params[] = $lang;
    }

    $sql .= ' ORDER BY RAND() LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetch() ?: null;
}

if ($action === 'create_word' && is_post()) {
    check_token($_POST['csrf'] ?? null);

    $hebrew = trim($_POST['hebrew'] ?? '');
    $transliteration = trim($_POST['transliteration'] ?? '');
    $partOfSpeech = trim($_POST['part_of_speech'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($hebrew === '') {
        flash('Please enter the Hebrew word.', 'error');
        redirect('index.php');
    }

    try {
        $audioPath = handle_audio_upload($_FILES['audio'] ?? [], $UPLOAD_DIR);
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
        redirect('index.php');
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare('INSERT INTO words (hebrew, transliteration, part_of_speech, notes, audio_path)
                           VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$hebrew, $transliteration, $partOfSpeech, $notes, $audioPath]);
    $wordId = (int) $pdo->lastInsertId();

    $langCode = trim($_POST['lang_code'] ?? '');
    $otherScript = trim($_POST['other_script'] ?? '');
    $meaning = trim($_POST['meaning'] ?? '');
    $example = trim($_POST['example'] ?? '');

    if ($langCode !== '' || $meaning !== '' || $otherScript !== '') {
        $insertTranslation = $pdo->prepare('INSERT INTO translations (word_id, lang_code, other_script, meaning, example)
                                            VALUES (?, ?, ?, ?, ?)');
        $insertTranslation->execute([
            $wordId,
            $langCode !== '' ? $langCode : 'und',
            $otherScript !== '' ? $otherScript : null,
            $meaning !== '' ? $meaning : null,
            $example !== '' ? $example : null,
        ]);
    }

    $pdo->commit();
    flash('Word added.', 'success');
    redirect('index.php');
}

$searchResults = [];
if ($searchTerm !== '') {
    $stmt = $pdo->prepare(
        'SELECT w.*, GROUP_CONCAT(CONCAT(t.lang_code, ":", COALESCE(t.meaning, "")) SEPARATOR "\n") AS translations_summary
         FROM words w
         LEFT JOIN translations t ON t.word_id = w.id
         WHERE w.hebrew LIKE ?
            OR w.transliteration LIKE ?
            OR t.meaning LIKE ?
            OR t.other_script LIKE ?
         GROUP BY w.id
         ORDER BY w.created_at DESC
         LIMIT 50'
    );
    $like = '%' . $searchTerm . '%';
    $stmt->execute([$like, $like, $like, $like]);
    $searchResults = $stmt->fetchAll();
}

$card = fetch_random_card($pdo, $langFilter);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hebrew Vocabulary App</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<div class="container">
    <header class="header">
        <nav class="nav">
            <a href="index.php">Learn</a>
            <a href="words.php">Admin: Words</a>
            <a href="import_csv.php">Bulk Import CSV</a>
        </nav>
        <form method="get" action="index.php" class="flex">
            <input type="text" name="q" placeholder="Search..." value="<?= h($searchTerm) ?>">
            <button class="btn" type="submit">Search</button>
        </form>
    </header>

    <?php if ($flash): ?>
        <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <section class="card">
        <h2>Flashcard</h2>
        <?php if ($card): ?>
            <div class="grid grid-2">
                <div>
                    <div class="badge">Hebrew</div>
                    <h1 class="hebrew-word"><?= h($card['hebrew']) ?></h1>
                    <?php if (!empty($card['transliteration'])): ?>
                        <div class="badge">Transliteration</div>
                        <div><?= h($card['transliteration']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($card['part_of_speech'])): ?>
                        <div class="badge">Part of speech</div>
                        <div><?= h($card['part_of_speech']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($card['notes'])): ?>
                        <div class="badge">Notes</div>
                        <div><?= nl2br(h($card['notes'])) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($card['audio_path'])): ?>
                        <audio class="audio" controls src="<?= h($card['audio_path']) ?>"></audio>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="badge">Translation</div>
                    <p>
                        <strong>Lang:</strong> <?= h($card['lang_code'] ?? '—') ?><br>
                        <strong>Other script:</strong> <?= h($card['other_script'] ?? '—') ?><br>
                        <strong>Meaning:</strong> <?= h($card['meaning'] ?? '—') ?><br>
                        <?php if (!empty($card['example'])): ?>
                            <strong>Example:</strong> <?= nl2br(h($card['example'])) ?><br>
                        <?php endif; ?>
                    </p>
                    <div class="flex">
                        <a class="btn secondary" href="index.php">New Random</a>
                        <a class="btn" href="index.php?lang=ru">RU</a>
                        <a class="btn" href="index.php?lang=en">EN</a>
                        <a class="btn" href="index.php?lang=ar">AR</a>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <p>No words yet. Add some below.</p>
        <?php endif; ?>
    </section>

    <section class="card">
        <h3>Quick Add Word</h3>
        <form method="post" enctype="multipart/form-data" action="index.php?a=create_word">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <div class="grid grid-3">
                <div>
                    <label for="hebrew">Hebrew *</label>
                    <input id="hebrew" name="hebrew" required placeholder="לְדֻגְמָה">
                </div>
                <div>
                    <label for="transliteration">Transliteration</label>
                    <input id="transliteration" name="transliteration" placeholder="le-dugma">
                </div>
                <div>
                    <label for="part_of_speech">Part of speech</label>
                    <input id="part_of_speech" name="part_of_speech" placeholder="noun/verb/etc">
                </div>
            </div>
            <label for="notes">Notes</label>
            <textarea id="notes" name="notes" rows="3" placeholder="Any nuances, gender, irregular forms..."></textarea>

            <label for="audio">Pronunciation (audio/mp3/wav/ogg ≤ 10MB)</label>
            <input id="audio" type="file" name="audio" accept="audio/*">

            <div class="grid grid-3">
                <div>
                    <label for="lang_code">Translation language</label>
                    <input id="lang_code" name="lang_code" placeholder="e.g., ru, en, fr">
                </div>
                <div>
                    <label for="other_script">Other script (spelling)</label>
                    <input id="other_script" name="other_script" placeholder="пример / example">
                </div>
                <div>
                    <label for="meaning">Meaning (gloss)</label>
                    <input id="meaning" name="meaning" placeholder="example / пример">
                </div>
            </div>
            <label for="example">Example (optional)</label>
            <textarea id="example" name="example" rows="2" placeholder="Use in a sentence"></textarea>

            <div class="form-actions">
                <button class="btn" type="submit">Add</button>
            </div>
        </form>
    </section>

    <?php if ($searchTerm !== '' && $searchResults): ?>
        <section class="card">
            <h3>Search Results</h3>
            <table class="table">
                <thead>
                <tr>
                    <th>Hebrew</th>
                    <th>Translit</th>
                    <th>POS</th>
                    <th>Translations</th>
                    <th>Audio</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($searchResults as $row): ?>
                    <tr>
                        <td><?= h($row['hebrew']) ?></td>
                        <td><?= h($row['transliteration']) ?></td>
                        <td><?= h($row['part_of_speech']) ?></td>
                        <td><pre class="translations-pre"><?= h($row['translations_summary']) ?></pre></td>
                        <td>
                            <?php if (!empty($row['audio_path'])): ?>
                                <audio controls src="<?= h($row['audio_path']) ?>"></audio>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td><a class="btn secondary" href="edit_word.php?id=<?= (int) $row['id'] ?>">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php elseif ($searchTerm !== ''): ?>
        <section class="card">
            <h3>Search Results</h3>
            <p>No results found.</p>
        </section>
    <?php endif; ?>
</div>
</body>
</html>
