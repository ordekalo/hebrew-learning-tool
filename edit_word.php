<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
$user = require_user($pdo); // אם אין מערכת משתמשים, החלף/הסר לפי הצורך

$csrf  = ensure_token();
$flash = get_flash();
$wordId = isset($_GET['id']) ? max(0, (int) $_GET['id']) : 0;

// ------------------- Helpers: fetch -------------------
function fetch_word(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare('SELECT * FROM words WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}
function fetch_translations(PDO $pdo, int $wordId): array {
    $stmt = $pdo->prepare('SELECT * FROM translations WHERE word_id = ? ORDER BY id ASC');
    $stmt->execute([$wordId]);
    return $stmt->fetchAll();
}

// ------------------- Tags helpers (local in this file) -------------------
function fetch_word_tags(PDO $pdo, int $wordId): string {
    try {
        $stmt = $pdo->prepare('SELECT tg.name FROM word_tags wt INNER JOIN tags tg ON tg.id = wt.tag_id WHERE wt.word_id = ? ORDER BY tg.name');
        $stmt->execute([$wordId]);
        return implode(', ', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        return '';
    }
}
function sync_tags(PDO $pdo, int $wordId, string $tagsInput): void {
    try {
        $pdo->prepare('DELETE FROM word_tags WHERE word_id = ?')->execute([$wordId]);
        $tags = array_filter(array_map(static fn(string $t): string => trim($t), preg_split('/[,\s]+/', $tagsInput) ?: []));
        if (!$tags) return;

        $tagStmt  = $pdo->prepare('INSERT INTO tags (name) VALUES (?) ON DUPLICATE KEY UPDATE name = name');
        $lookup   = $pdo->prepare('SELECT id FROM tags WHERE name = ?');
        $linkStmt = $pdo->prepare('INSERT IGNORE INTO word_tags (word_id, tag_id) VALUES (?, ?)');

        foreach ($tags as $tag) {
            if ($tag === '') continue;
            $tagStmt->execute([$tag]);
            $tagId = (int)$pdo->lastInsertId();
            if ($tagId === 0) {
                $lookup->execute([$tag]);
                $tagId = (int)$lookup->fetchColumn();
            }
            if ($tagId > 0) {
                $linkStmt->execute([$wordId, $tagId]);
            }
        }
    } catch (Throwable $e) {
        // טבלאות tags/word_tags לא קיימות? נתעלם בשקט
    }
}

// ------------------- Minimal image upload (fallback) -------------------
/**
 * מחזיר מחרוזת נתיב יחסי (uploads/xxx.ext) או null אם אין קובץ.
 * אם כבר יש לך handle_image_upload ב-config.php — אפשר להסיר פונקציה זו,
 * ולהחליף את הקריאות אליה לפונקציה הגלובלית שלך.
 */
function local_handle_image_upload(array $file, string $uploadDir): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Image upload failed.');
    if (($file['size'] ?? 0) > 8 * 1024 * 1024) throw new RuntimeException('Image is larger than 8MB.');

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']) ?: '';
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    if (!isset($allowed[$mime])) throw new RuntimeException('Unsupported image format.');

    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, $allowed, true)) $ext = $allowed[$mime];
    $ext = preg_replace('/[^a-z0-9]/i', '', $ext) ?: $allowed[$mime];

    $filename = sprintf('img_%s_%s.%s', date('YmdHis'), bin2hex(random_bytes(4)), $ext);
    $target   = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new RuntimeException('Unable to save uploaded image.');
    }
    return 'uploads/' . $filename;
}

// ------------------- Preload data for form -------------------
$word          = $wordId > 0 ? fetch_word($pdo, $wordId) : null;
$translations  = $wordId > 0 ? fetch_translations($pdo, $wordId) : [];
$primaryTranslation = $translations[0] ?? null;

// Decks from config helpers (safe even if tables missing)
$decks = fetch_all_decks($pdo);
if ($wordId <= 0 && empty($decks)) {
    ensure_default_deck($pdo);
    $decks = fetch_all_decks($pdo);
}
$selectedDeckFlags = $wordId > 0 ? fetch_word_decks($pdo, $wordId) : []; // map: deck_id => is_reversed(bool)

// Tags
$wordTags = $wordId > 0 ? fetch_word_tags($pdo, $wordId) : '';

// ------------------- Handle main save (not add/delete translation) -------------------
if (is_post() && !isset($_POST['add_translation'], $_POST['delete_translation'])) {
    check_token($_POST['csrf'] ?? null);

    $hebrew          = trim($_POST['hebrew'] ?? '');
    $transliteration = trim($_POST['transliteration'] ?? '');
    $partOfSpeech    = trim($_POST['part_of_speech'] ?? '');
    $notes           = trim($_POST['notes'] ?? '');

    if ($hebrew === '') {
        flash('Hebrew word is required.', 'error');
        redirect($wordId > 0 ? 'edit_word.php?id=' . $wordId : 'edit_word.php');
    }

    // ----- AUDIO -----
    $existingAudio = $_POST['existing_audio'] ?? null;
    $removeAudio   = isset($_POST['remove_audio']);
    try {
        $newAudio = handle_audio_upload($_FILES['audio'] ?? [], $UPLOAD_DIR);
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
        redirect($wordId > 0 ? 'edit_word.php?id=' . $wordId : 'edit_word.php');
    }
    $audioPath = $existingAudio;
    if ($newAudio !== null) {
        if ($existingAudio) delete_upload($existingAudio, $UPLOAD_DIR);
        $audioPath = $newAudio;
    } elseif ($removeAudio) {
        delete_upload($existingAudio, $UPLOAD_DIR);
        $audioPath = null;
    }

    // ----- IMAGE -----
    $existingImage = $_POST['existing_image'] ?? null;
    $removeImage   = isset($_POST['remove_image']);
    try {
        // אם יש לך handle_image_upload גלובלי ─ החלף את השורה הבאה לקריאה לפונקציה שלך:
        $newImagePath = local_handle_image_upload($_FILES['image'] ?? [], $UPLOAD_DIR);
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
        redirect($wordId > 0 ? 'edit_word.php?id=' . $wordId : 'edit_word.php');
    }
    $imagePath = $existingImage;
    if ($newImagePath !== null) {
        if ($existingImage) delete_upload($existingImage, $UPLOAD_DIR);
        $imagePath = $newImagePath;
    } elseif ($removeImage) {
        delete_upload($existingImage, $UPLOAD_DIR);
        $imagePath = null;
    }

    // ----- DECKS + Reverse flags -----
    $deckIds = isset($_POST['deck_ids']) && is_array($_POST['deck_ids'])
        ? array_map('intval', $_POST['deck_ids'])
        : [];
    $reverseFlags = isset($_POST['reverse_flags']) && is_array($_POST['reverse_flags'])
        ? array_map('intval', array_keys(array_filter($_POST['reverse_flags'], static fn($v): bool => (int)$v === 1)))
        : [];

    // ----- PRIMARY translation (back side fields) -----
    $primaryLang    = trim($_POST['primary_lang_code'] ?? '');
    $primaryMeaning = trim($_POST['primary_meaning'] ?? '');
    $primaryOther   = trim($_POST['primary_other_script'] ?? '');
    $primaryExample = trim($_POST['primary_example'] ?? '');

    // ----- TAGS -----
    $tagInput = trim($_POST['tags'] ?? '');

    // ----- UPSERT word -----
    if ($wordId > 0) {
        $stmt = $pdo->prepare('UPDATE words SET hebrew = ?, transliteration = ?, part_of_speech = ?, notes = ?, audio_path = ?, image_path = ? WHERE id = ?');
        $stmt->execute([$hebrew, $transliteration, $partOfSpeech, $notes, $audioPath, $imagePath, $wordId]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO words (hebrew, transliteration, part_of_speech, notes, audio_path, image_path) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$hebrew, $transliteration, $partOfSpeech, $notes, $audioPath, $imagePath]);
        $wordId = (int)$pdo->lastInsertId();
    }

    // ----- Sync decks & reverse -----
    sync_word_decks($pdo, $wordId, $deckIds);
    foreach ($deckIds as $deckId) {
        $isReversed = in_array($deckId, $reverseFlags, true);
        set_deck_word_reversed($pdo, $deckId, $wordId, $isReversed);
    }

    // ----- Upsert primary translation -----
    if ($primaryLang !== '' || $primaryMeaning !== '' || $primaryOther !== '' || $primaryExample !== '') {
        if ($primaryTranslation) {
            $updateTranslation = $pdo->prepare('UPDATE translations SET lang_code = ?, other_script = ?, meaning = ?, example = ? WHERE id = ?');
            $updateTranslation->execute([
                $primaryLang !== '' ? $primaryLang : 'und',
                $primaryOther !== '' ? $primaryOther : null,
                $primaryMeaning !== '' ? $primaryMeaning : null,
                $primaryExample !== '' ? $primaryExample : null,
                (int)$primaryTranslation['id'],
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

    // ----- Sync tags -----
    sync_tags($pdo, $wordId, $tagInput);

    flash('Word saved.', 'success');
    redirect('edit_word.php?id=' . $wordId);
}

// ------------------- Add/Delete translation -------------------
if (isset($_POST['add_translation'])) {
    check_token($_POST['csrf'] ?? null);
    $wordId     = (int) $_POST['word_id'];
    $langCode   = trim($_POST['lang_code'] ?? '');
    $otherScript= trim($_POST['other_script'] ?? '');
    $meaning    = trim($_POST['meaning'] ?? '');
    $example    = trim($_POST['example'] ?? '');

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
    $wordId        = (int) $_POST['word_id'];
    $translationId = (int) $_POST['delete_translation'];
    $pdo->prepare('DELETE FROM translations WHERE id = ?')->execute([$translationId]);
    flash('Translation deleted.', 'success');
    redirect('edit_word.php?id=' . $wordId);
}

// ------------------- Reload for view -------------------
$word          = $wordId > 0 ? fetch_word($pdo, $wordId) : null;
$translations  = $wordId > 0 ? fetch_translations($pdo, $wordId) : [];
$primaryTranslation = $translations[0] ?? null;
$decks         = fetch_all_decks($pdo);
$selectedDeckFlags = $wordId > 0 ? fetch_word_decks($pdo, $wordId) : [];
$wordTags      = $wordId > 0 ? fetch_word_tags($pdo, $wordId) : '';
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
            <p class="header-sub">Update both sides of the flashcard, manage deck placement, tags, image and pronunciation.</p>
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
            <input type="hidden" name="existing_image" value="<?= h($word['image_path'] ?? '') ?>">

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

            <div class="grid grid-2">
                <div>
                    <label for="tags">Tags</label>
                    <input id="tags" name="tags" value="<?= h($wordTags) ?>" placeholder="grammar,verb,lesson1">
                    <p class="form-help">Separated by comma or space.</p>
                </div>
                <div>
                    <label for="audio">Pronunciation (replace to upload new)</label>
                    <input id="audio" type="file" name="audio" accept="audio/*">
                    <?php if (!empty($word['audio_path'])): ?>
                        <div class="audio"><audio controls src="<?= h($word['audio_path']) ?>"></audio></div>
                        <label class="checkbox-inline"><input type="checkbox" name="remove_audio" value="1"> Remove existing audio</label>
                    <?php endif; ?>
                </div>
            </div>

            <label for="image">Image</label>
            <input id="image" type="file" name="image" accept="image/*" capture="environment">
            <?php if (!empty($word['image_path'])): ?>
                <div class="media-preview">
                    <img src="<?= h($word['image_path']) ?>" alt="Current word image" style="max-width:180px; border-radius:12px;">
                </div>
                <label class="flex" style="margin-top:8px; align-items:center; gap:8px;">
                    <input type="checkbox" name="remove_image" value="1" style="width:auto;"> Remove existing image
                </label>
            <?php endif; ?>

            <div class="deck-assignment">
                <h3>Deck placement</h3>
                <p class="section-subtitle">Choose decks and decide whether the card should be reversed in each context.</p>
                <div class="deck-assignment-list">
                    <?php foreach ($decks as $deck): ?>
                        <?php
                        $deckId   = (int)($deck['id'] ?? 0);
                        $checked  = array_key_exists($deckId, $selectedDeckFlags);
                        $reversed = $checked ? (bool)$selectedDeckFlags[$deckId] : false;
                        ?>
                        <label class="deck-checkbox">
                            <span class="deck-checkbox-control">
                                <input type="checkbox" name="deck_ids[]" value="<?= $deckId ?>" <?= $checked ? 'checked' : '' ?>>
                                <span class="deck-checkbox-name"><?= h($deck['name'] ?? ('Deck #' . $deckId)) ?></span>
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
