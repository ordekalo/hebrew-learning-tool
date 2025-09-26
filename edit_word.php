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
    $stmt = $pdo->prepare('SELECT * FROM translations WHERE word_id = ? ORDER BY id ASC');
    $stmt->execute([$wordId]);

    return $stmt->fetchAll();
}

$word = $wordId > 0 ? fetch_word($pdo, $wordId) : null;
$translations = $wordId > 0 ? fetch_translations($pdo, $wordId) : [];
$primaryTranslation = $translations[0] ?? null;
$decks = fetch_all_decks($pdo);
$selectedDeckFlags = $wordId > 0 ? fetch_word_decks($pdo, $wordId) : [];

if ($wordId <= 0 && empty($decks)) {
    ensure_default_deck($pdo);
    $decks = fetch_all_decks($pdo);
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

    $deckIds = isset($_POST['deck_ids']) && is_array($_POST['deck_ids']) ? array_map('intval', $_POST['deck_ids']) : [];
    $reverseFlags = isset($_POST['reverse_flags']) && is_array($_POST['reverse_flags'])
        ? array_map('intval', array_keys(array_filter($_POST['reverse_flags'], static fn($value): bool => (int) $value === 1)))
        : [];

    $primaryLang = trim($_POST['primary_lang_code'] ?? '');
    $primaryMeaning = trim($_POST['primary_meaning'] ?? '');
    $primaryOther = trim($_POST['primary_other_script'] ?? '');
    $primaryExample = trim($_POST['primary_example'] ?? '');

    if ($wordId > 0) {
        $stmt = $pdo->prepare('UPDATE words SET hebrew = ?, transliteration = ?, part_of_speech = ?, notes = ?, audio_path = ? WHERE id = ?');
        $stmt->execute([$hebrew, $transliteration, $partOfSpeech, $notes, $audioPath, $wordId]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO words (hebrew, transliteration, part_of_speech, notes, audio_path) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$hebrew, $transliteration, $partOfSpeech, $notes, $audioPath]);
        $wordId = (int) $pdo->lastInsertId();
    }

    sync_word_decks($pdo, $wordId, $deckIds);
    foreach ($deckIds as $deckId) {
        $isReversed = in_array($deckId, $reverseFlags, true);
        set_deck_word_reversed($pdo, $deckId, $wordId, $isReversed);
    }

    if ($primaryLang !== '' || $primaryMeaning !== '' || $primaryOther !== '') {
        if ($primaryTranslation) {
            $updateTranslation = $pdo->prepare('UPDATE translations SET lang_code = ?, other_script = ?, meaning = ?, example = ? WHERE id = ?');
            $updateTranslation->execute([
                $primaryLang !== '' ? $primaryLang : 'und',
                $primaryOther !== '' ? $primaryOther : null,
                $primaryMeaning !== '' ? $primaryMeaning : null,
                $primaryExample !== '' ? $primaryExample : null,
                (int) $primaryTranslation['id'],
            ]);
        } else {
            $insertTranslation = $pdo->prepare('INSERT INTO translations (word_id, lang_code, other_script, meaning, example) VALUES (?, ?, ?, ?, ?)');
            $insertTranslation->execute([
                $wordId,
                $primaryLang !== '' ? $primaryLang : 'und',
                $primaryOther !== '' ? $primaryOther : null,
                $primaryMeaning !== '' ? $primaryMeaning : null,
                $primaryExample !== '' ? $primaryExample : null,
            ]);
        }
    }

    flash('Word saved.', 'success');
    redirect('edit_word.php?id=' . $wordId);
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
$primaryTranslation = $translations[0] ?? null;
$selectedDeckFlags = $wordId > 0 ? fetch_word_decks($pdo, $wordId) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $wordId > 0 ? 'Edit Word #' . $wordId : 'New Word' ?></title>
    <link rel="stylesheet" href="styles.css">
</head>
<body class="app-body" data-screen="settings">
<div class="app-shell">
    <header class="app-header">
        <div class="header-main">
            <h1><?= $wordId > 0 ? 'Edit card' : 'Create card' ?></h1>
            <p class="header-sub">Update both sides of the flashcard, manage deck placement and pronunciation.</p>
        </div>
        <nav class="nav"><a class="btn ghost" href="words.php">← Back to list</a></nav>
    </header>

    <?php if ($flash): ?>
        <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <section class="card">
        <form method="post" enctype="multipart/form-data" class="editor-form">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="existing_audio" value="<?= h($word['audio_path'] ?? '') ?>">
            <div class="editor-grid">
                <div class="editor-side">
                    <h2>Front side</h2>
                    <label for="hebrew">Hebrew *</label>
                    <textarea id="hebrew" name="hebrew" rows="3" required><?= h($word['hebrew'] ?? '') ?></textarea>
                    <label for="transliteration">Transliteration</label>
                    <input id="transliteration" name="transliteration" value="<?= h($word['transliteration'] ?? '') ?>">
                    <label for="part_of_speech">Part of speech</label>
                    <input id="part_of_speech" name="part_of_speech" value="<?= h($word['part_of_speech'] ?? '') ?>">
                    <label for="notes">Notes</label>
                    <textarea id="notes" name="notes" rows="3" placeholder="Hints, gender, irregular forms..."><?= h($word['notes'] ?? '') ?></textarea>
                </div>
                <div class="editor-side">
                    <h2>Back side</h2>
                    <label for="primary_meaning">Meaning</label>
                    <textarea id="primary_meaning" name="primary_meaning" rows="3" placeholder="Primary translation"><?= h($primaryTranslation['meaning'] ?? '') ?></textarea>
                    <label for="primary_lang_code">Language code</label>
                    <input id="primary_lang_code" name="primary_lang_code" placeholder="en, ru, ar" value="<?= h($primaryTranslation['lang_code'] ?? '') ?>">
                    <label for="primary_other_script">Other script</label>
                    <input id="primary_other_script" name="primary_other_script" value="<?= h($primaryTranslation['other_script'] ?? '') ?>">
                    <label for="primary_example">Example</label>
                    <textarea id="primary_example" name="primary_example" rows="2" placeholder="Use in a sentence"><?= h($primaryTranslation['example'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="editor-audio">
                <div>
                    <label for="audio">Pronunciation (replace to upload new)</label>
                    <input id="audio" type="file" name="audio" accept="audio/*">
                    <?php if (!empty($word['audio_path'])): ?>
                        <div class="audio">
                            <audio controls src="<?= h($word['audio_path']) ?>"></audio>
                        </div>
                        <label class="checkbox-inline">
                            <input type="checkbox" name="remove_audio" value="1"> Remove existing audio
                        </label>
                    <?php endif; ?>
                </div>
            </div>

            <div class="deck-assignment">
                <h3>Deck placement</h3>
                <p class="section-subtitle">Choose decks and decide whether the card should be reversed in each context.</p>
                <div class="deck-assignment-list">
                    <?php foreach ($decks as $deck): ?>
                        <?php $deckId = (int) $deck['id']; $checked = array_key_exists($deckId, $selectedDeckFlags); $reversed = $checked ? $selectedDeckFlags[$deckId] : false; ?>
                        <label class="deck-checkbox">
                            <span class="deck-checkbox-control">
                                <input type="checkbox" name="deck_ids[]" value="<?= $deckId ?>" <?= $checked ? 'checked' : '' ?>>
                                <span class="deck-checkbox-name"><?= h($deck['name']) ?></span>
                                <span class="deck-checkbox-meta"><?= h($deck['category'] ?? 'General') ?></span>
                            </span>
                            <span class="deck-checkbox-switch">
                                <label class="flip-toggle">
                                    <input type="checkbox" name="reverse_flags[<?= $deckId ?>]" value="1" <?= $reversed ? 'checked' : '' ?>>
                                    <span class="flip-toggle-track"></span>
                                    <span class="flip-toggle-thumb"></span>
                                </label>
                                <span class="deck-checkbox-meta">Reverse</span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="editor-actions">
                <button class="btn primary" type="submit">Save card</button>
            </div>
        </form>
    </section>

    <?php if ($wordId > 0): ?>
        <form method="post" action="words.php" class="delete-card-form" onsubmit="return confirm('Delete this word and all translations?');">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="delete_id" value="<?= (int) $wordId ?>">
            <button class="btn danger" type="submit">Delete card</button>
        </form>
    <?php endif; ?>

    <section class="card">
        <h3>Additional translations</h3>
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
                <?php foreach ($translations as $index => $translation): ?>
                    <?php if ($index === 0) { continue; } ?>
                    <tr>
                        <td><span class="badge"><?= h($translation['lang_code']) ?></span></td>
                        <td><?= h($translation['other_script']) ?></td>
                        <td><?= h($translation['meaning']) ?></td>
                        <td><?= nl2br(h($translation['example'])) ?></td>
                        <td>
                            <form method="post" onsubmit="return confirm('Delete translation?');">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="word_id" value="<?= (int) $wordId ?>">
                                <input type="hidden" name="delete_translation" value="<?= (int) $translation['id'] ?>">
                                <button class="btn ghost" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="section-subtitle">No additional translations yet.</p>
        <?php endif; ?>

        <form method="post" class="add-translation-form">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="word_id" value="<?= (int) $wordId ?>">
            <input type="hidden" name="add_translation" value="1">
            <div class="grid grid-3 responsive">
                <div>
                    <label for="lang_code">Language</label>
                    <input id="lang_code" name="lang_code" placeholder="en, ru, fr">
                </div>
                <div>
                    <label for="other_script">Other script</label>
                    <input id="other_script" name="other_script" placeholder="пример">
                </div>
                <div>
                    <label for="meaning">Meaning</label>
                    <input id="meaning" name="meaning" placeholder="example">
                </div>
            </div>
            <label for="example">Example</label>
            <textarea id="example" name="example" rows="2"></textarea>
            <div class="form-actions">
                <button class="btn ghost" type="submit">Add translation</button>
            </div>
        </form>
    </section>
</div>
</body>
</html>
