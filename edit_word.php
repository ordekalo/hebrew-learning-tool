<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

$csrf = ensure_token();
$flash = get_flash();
$wordId = isset($_GET['id']) ? max(0, (int) $_GET['id']) : 0;

function fetch_word(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM words WHERE id = ?');
    $stmt->execute([$id]);

    return $stmt->fetch() ?: null;
}

function fetch_translations(PDO $pdo, int $wordId): array
{
    $stmt = $pdo->prepare('SELECT * FROM translations WHERE word_id = ? ORDER BY lang_code');
    $stmt->execute([$wordId]);

    return $stmt->fetchAll();
}

if (is_post() && !isset($_POST['add_translation'], $_POST['delete_translation'])) {
    check_token($_POST['csrf'] ?? null);

    $hebrew = trim($_POST['hebrew'] ?? '');
    $transliteration = trim($_POST['transliteration'] ?? '');
    $partOfSpeech = trim($_POST['part_of_speech'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $existingAudio = $_POST['existing_audio'] ?? null;
    $removeAudio = isset($_POST['remove_audio']);

    if ($hebrew === '') {
        flash('Hebrew word is required.', 'error');
        redirect($wordId > 0 ? 'edit_word.php?id=' . $wordId : 'edit_word.php');
    }

    try {
        $newAudio = handle_audio_upload($_FILES['audio'] ?? [], $UPLOAD_DIR);
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
        redirect($wordId > 0 ? 'edit_word.php?id=' . $wordId : 'edit_word.php');
    }

    $audioPath = $existingAudio;

    if ($newAudio !== null) {
        if ($existingAudio) {
            delete_upload($existingAudio, $UPLOAD_DIR);
        }
        $audioPath = $newAudio;
    } elseif ($removeAudio) {
        delete_upload($existingAudio, $UPLOAD_DIR);
        $audioPath = null;
    }

    if ($wordId > 0) {
        $stmt = $pdo->prepare('UPDATE words SET hebrew = ?, transliteration = ?, part_of_speech = ?, notes = ?, audio_path = ? WHERE id = ?');
        $stmt->execute([$hebrew, $transliteration, $partOfSpeech, $notes, $audioPath, $wordId]);
        flash('Word updated.', 'success');
        redirect('edit_word.php?id=' . $wordId);
    }

    $stmt = $pdo->prepare('INSERT INTO words (hebrew, transliteration, part_of_speech, notes, audio_path) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$hebrew, $transliteration, $partOfSpeech, $notes, $audioPath]);
    $newId = (int) $pdo->lastInsertId();
    flash('Word created.', 'success');
    redirect('edit_word.php?id=' . $newId);
}

if (isset($_POST['add_translation'])) {
    check_token($_POST['csrf'] ?? null);
    $wordId = (int) $_POST['word_id'];
    $langCode = trim($_POST['lang_code'] ?? '');
    $otherScript = trim($_POST['other_script'] ?? '');
    $meaning = trim($_POST['meaning'] ?? '');
    $example = trim($_POST['example'] ?? '');

    $stmt = $pdo->prepare('INSERT INTO translations (word_id, lang_code, other_script, meaning, example) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([
        $wordId,
        $langCode !== '' ? $langCode : 'und',
        $otherScript !== '' ? $otherScript : null,
        $meaning !== '' ? $meaning : null,
        $example !== '' ? $example : null,
    ]);

    flash('Translation added.', 'success');
    redirect('edit_word.php?id=' . $wordId);
}

if (isset($_POST['delete_translation'])) {
    check_token($_POST['csrf'] ?? null);
    $wordId = (int) $_POST['word_id'];
    $translationId = (int) $_POST['delete_translation'];

    $pdo->prepare('DELETE FROM translations WHERE id = ?')->execute([$translationId]);
    flash('Translation deleted.', 'success');
    redirect('edit_word.php?id=' . $wordId);
}

$word = $wordId > 0 ? fetch_word($pdo, $wordId) : null;
$translations = $wordId > 0 ? fetch_translations($pdo, $wordId) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $wordId > 0 ? 'Edit Word #' . $wordId : 'New Word' ?></title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<div class="container">
    <header class="header">
        <nav class="nav"><a href="words.php">← Back to list</a></nav>
    </header>

    <?php if ($flash): ?>
        <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <section class="card">
        <h2><?= $wordId > 0 ? 'Edit Word' : 'Create Word' ?></h2>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <div class="grid grid-3">
                <div>
                    <label for="hebrew">Hebrew *</label>
                    <input id="hebrew" name="hebrew" value="<?= h($word['hebrew'] ?? '') ?>" required>
                </div>
                <div>
                    <label for="transliteration">Transliteration</label>
                    <input id="transliteration" name="transliteration" value="<?= h($word['transliteration'] ?? '') ?>">
                </div>
                <div>
                    <label for="part_of_speech">Part of speech</label>
                    <input id="part_of_speech" name="part_of_speech" value="<?= h($word['part_of_speech'] ?? '') ?>">
                </div>
            </div>
            <label for="notes">Notes</label>
            <textarea id="notes" name="notes" rows="3"><?= h($word['notes'] ?? '') ?></textarea>

            <label for="audio">Pronunciation (replace to upload new)</label>
            <input type="hidden" name="existing_audio" value="<?= h($word['audio_path'] ?? '') ?>">
            <input id="audio" type="file" name="audio" accept="audio/*">
            <?php if (!empty($word['audio_path'])): ?>
                <div class="audio">
                    <audio controls src="<?= h($word['audio_path']) ?>"></audio>
                </div>
                <label class="flex" style="margin-top:8px; align-items: center;">
                    <input type="checkbox" name="remove_audio" value="1" style="width:auto;"> Remove existing audio
                </label>
            <?php endif; ?>

            <div class="form-actions">
                <button class="btn" type="submit">Save</button>
            </div>
        </form>
    </section>

    <?php if ($wordId > 0): ?>
        <section class="card">
            <h3>Translations</h3>
            <?php if ($translations): ?>
                <table class="table">
                    <thead>
                    <tr>
                        <th>Lang</th>
                        <th>Other script</th>
                        <th>Meaning</th>
                        <th>Example</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($translations as $translation): ?>
                        <tr>
                            <td><span class="badge"><?= h($translation['lang_code']) ?></span></td>
                            <td><?= h($translation['other_script']) ?></td>
                            <td><?= h($translation['meaning']) ?></td>
                            <td><?= nl2br(h($translation['example'])) ?></td>
                            <td>
                                <form method="post" onsubmit="return confirm('Delete translation?');" style="display:inline-block;">
                                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="word_id" value="<?= (int) $wordId ?>">
                                    <input type="hidden" name="delete_translation" value="<?= (int) $translation['id'] ?>">
                                    <button class="btn danger" type="submit">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No translations yet.</p>
            <?php endif; ?>

            <h4>Add translation</h4>
            <form method="post" class="grid grid-3">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="word_id" value="<?= (int) $wordId ?>">
                <input type="hidden" name="add_translation" value="1">
                <div>
                    <label for="lang_code">Language code</label>
                    <input id="lang_code" name="lang_code" placeholder="ru/en/ar">
                </div>
                <div>
                    <label for="other_script">Other script (spelling)</label>
                    <input id="other_script" name="other_script" placeholder="пример / example">
                </div>
                <div>
                    <label for="meaning">Meaning (gloss)</label>
                    <input id="meaning" name="meaning" placeholder="meaning">
                </div>
                <div style="grid-column: 1 / -1;">
                    <label for="example">Example</label>
                    <textarea id="example" name="example" rows="2"></textarea>
                </div>
                <div class="form-actions">
                    <button class="btn" type="submit">Add</button>
                </div>
            </form>
        </section>
    <?php endif; ?>
</div>
</body>
</html>
