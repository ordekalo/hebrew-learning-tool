<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
$user = require_user($pdo);

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

function fetch_all_decks(PDO $pdo): array
{
    return $pdo->query('SELECT id, name FROM decks ORDER BY name')->fetchAll();
}

function fetch_word_decks(PDO $pdo, int $wordId): array
{
    $stmt = $pdo->prepare('SELECT deck_id FROM deck_words WHERE word_id = ?');
    $stmt->execute([$wordId]);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function fetch_word_tags(PDO $pdo, int $wordId): string
{
    $stmt = $pdo->prepare('SELECT tg.name FROM word_tags wt INNER JOIN tags tg ON tg.id = wt.tag_id WHERE wt.word_id = ? ORDER BY tg.name');
    $stmt->execute([$wordId]);

    return implode(', ', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function sync_decks(PDO $pdo, int $wordId, array $deckIds): void
{
    $pdo->prepare('DELETE FROM deck_words WHERE word_id = ?')->execute([$wordId]);
    if (!$deckIds) {
        return;
    }
    $stmt = $pdo->prepare('INSERT IGNORE INTO deck_words (deck_id, word_id) VALUES (?, ?)');
    foreach ($deckIds as $deckId) {
        if ($deckId > 0) {
            $stmt->execute([$deckId, $wordId]);
        }
    }
}

function sync_tags(PDO $pdo, int $wordId, string $tagsInput): void
{
    $pdo->prepare('DELETE FROM word_tags WHERE word_id = ?')->execute([$wordId]);
    $tags = array_filter(array_map(static fn(string $tag): string => trim($tag), preg_split('/[,\s]+/', $tagsInput))); 
    if (!$tags) {
        return;
    }
    $tagStmt = $pdo->prepare('INSERT INTO tags (name) VALUES (?) ON DUPLICATE KEY UPDATE name = name');
    $lookup = $pdo->prepare('SELECT id FROM tags WHERE name = ?');
    $linkStmt = $pdo->prepare('INSERT IGNORE INTO word_tags (word_id, tag_id) VALUES (?, ?)');
    foreach ($tags as $tag) {
        if ($tag === '') {
            continue;
        }
        $tagStmt->execute([$tag]);
        $tagId = (int) $pdo->lastInsertId();
        if ($tagId === 0) {
            $lookup->execute([$tag]);
            $tagId = (int) $lookup->fetchColumn();
        }
        if ($tagId > 0) {
            $linkStmt->execute([$wordId, $tagId]);
        }
    }
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
        $newAudio = handle_audio_upload($_FILES['audio'] ?? [], $UPLOAD_AUDIO_DIR);
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

    $existingImage = $_POST['existing_image'] ?? null;
    $removeImage = isset($_POST['remove_image']);
    try {
        $newImage = handle_image_upload($_FILES['image'] ?? [], $UPLOAD_IMAGE_DIR);
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
        redirect($wordId > 0 ? 'edit_word.php?id=' . $wordId : 'edit_word.php');
    }

    $imagePath = $existingImage;
    if ($newImage !== null) {
        if ($existingImage) {
            delete_upload($existingImage, $UPLOAD_DIR);
        }
        $imagePath = $newImage['thumbnail'];
    } elseif ($removeImage) {
        delete_upload($existingImage, $UPLOAD_DIR);
        $imagePath = null;
    }

    $selectedDecks = array_map('intval', $_POST['deck_ids'] ?? []);
    $tagInput = trim($_POST['tags'] ?? '');

    if ($wordId > 0) {
        $stmt = $pdo->prepare('UPDATE words SET hebrew = ?, transliteration = ?, part_of_speech = ?, notes = ?, audio_path = ?, image_path = ? WHERE id = ?');
        $stmt->execute([$hebrew, $transliteration, $partOfSpeech, $notes, $audioPath, $imagePath, $wordId]);
        sync_decks($pdo, $wordId, $selectedDecks);
        sync_tags($pdo, $wordId, $tagInput);
        flash('Word updated.', 'success');
        redirect('edit_word.php?id=' . $wordId);
    }

    $stmt = $pdo->prepare('INSERT INTO words (hebrew, transliteration, part_of_speech, notes, audio_path, image_path) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$hebrew, $transliteration, $partOfSpeech, $notes, $audioPath, $imagePath]);
    $newId = (int) $pdo->lastInsertId();
    sync_decks($pdo, $newId, $selectedDecks);
    sync_tags($pdo, $newId, $tagInput);
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
$decks = fetch_all_decks($pdo);
$wordDecks = $wordId > 0 ? fetch_word_decks($pdo, $wordId) : [];
$wordTags = $wordId > 0 ? fetch_word_tags($pdo, $wordId) : '';
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

            <div class="grid grid-2">
                <div>
                    <label for="deck_ids">Decks</label>
                    <select id="deck_ids" name="deck_ids[]" multiple size="<?= max(3, min(count($decks), 6)) ?>">
                        <?php foreach ($decks as $deck): ?>
                            <option value="<?= (int) $deck['id'] ?>" <?= in_array((int) $deck['id'], $wordDecks ?? [], true) ? 'selected' : '' ?>><?= h($deck['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="form-help">Hold Ctrl/⌘ to select multiple decks.</p>
                </div>
                <div>
                    <label for="tags">Tags</label>
                    <input id="tags" name="tags" value="<?= h($wordTags) ?>" placeholder="grammar,verb,lesson1">
                    <p class="form-help">Separated by comma or space.</p>
                </div>
            </div>

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

            <label for="image">Image</label>
            <input type="hidden" name="existing_image" value="<?= h($word['image_path'] ?? '') ?>">
            <input id="image" type="file" name="image" accept="image/*" capture="environment">
            <?php if (!empty($word['image_path'])): ?>
                <div class="media-preview">
                    <img src="<?= h($word['image_path']) ?>" alt="Current word image" style="max-width:180px; border-radius:12px;">
                </div>
                <label class="flex" style="margin-top:8px; align-items:center; gap:8px;">
                    <input type="checkbox" name="remove_image" value="1" style="width:auto;"> Remove existing image
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
