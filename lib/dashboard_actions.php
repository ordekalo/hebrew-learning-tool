<?php
declare(strict_types=1);

function handle_dashboard_action(string $action, PDO $pdo, array $context): void
{
    if ($action === 'view' || !is_post()) {
        return;
    }

    switch ($action) {
        case 'create_word':
            handle_create_word_action($pdo, $context);
            break;
        case 'seed_openers':
            handle_seed_openers_action($pdo, $context);
            break;
        case 'create_deck':
            handle_create_deck_action($pdo);
            break;
        case 'update_deck_details':
            handle_update_deck_details_action($pdo);
            break;
        case 'move_deck':
            handle_move_deck_action($pdo);
            break;
        case 'select_deck':
            handle_select_deck_action($pdo);
            break;
        case 'toggle_deck_flag':
            handle_toggle_deck_flag_action($pdo);
            break;
        case 'duplicate_deck':
            handle_duplicate_deck_action($pdo);
            break;
        case 'delete_deck':
            handle_delete_deck_action($pdo, $context);
            break;
        case 'publish_deck':
            handle_publish_deck_action($pdo);
            break;
        case 'update_tts':
            handle_update_tts_action($pdo);
            break;
        case 'reset_deck_progress':
            handle_reset_deck_progress_action($pdo, $context);
            break;
        case 'archive_deck':
            handle_archive_deck_action($pdo);
            break;
    }
}

function dashboard_redirect(string $defaultPath): void
{
    $candidate = $_POST['redirect'] ?? $_GET['redirect'] ?? null;
    if (is_string($candidate)) {
        $candidate = trim($candidate);
        if ($candidate !== '' && !preg_match('#^(?:https?:)?//#', $candidate)) {
            redirect($candidate);
        }
    }

    redirect($defaultPath);
}

function handle_create_word_action(PDO $pdo, array $context): void
{
    check_token($_POST['csrf'] ?? null);

    $hebrew = trim($_POST['hebrew'] ?? '');
    $transliteration = trim($_POST['transliteration'] ?? '');
    $partOfSpeech = trim($_POST['part_of_speech'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($hebrew === '') {
        flash('Please enter the Hebrew word.', 'error');
        redirect('index.php?screen=home');
    }

    $uploadDir = $context['uploadDir'] ?? (__DIR__ . '/../uploads');

    try {
        $audioPath = handle_audio_upload($_FILES['audio'] ?? [], $uploadDir);
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
        redirect('index.php?screen=home');
    }

    $recordedAudio = $_POST['recorded_audio'] ?? '';

    if ($audioPath === null && $recordedAudio !== '') {
        try {
            $audioPath = save_recorded_audio($recordedAudio, $uploadDir);
        } catch (RuntimeException $e) {
            flash($e->getMessage(), 'error');
            redirect('index.php?screen=home');
        }
    }

    $selectedDeckId = (int) ($context['selectedDeckId'] ?? 0);
    $defaultDeckId = (int) ($context['defaultDeckId'] ?? 0);

    $deckIds = [];
    if (isset($_POST['deck_ids']) && is_array($_POST['deck_ids'])) {
        $deckIds = array_map('intval', $_POST['deck_ids']);
    }
    if (isset($_POST['deck_id']) && $_POST['deck_id'] !== '') {
        $deckIds[] = (int) $_POST['deck_id'];
    }
    if (!$deckIds) {
        $deckIds[] = $selectedDeckId ?: $defaultDeckId;
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

    foreach ($deckIds as $deckId) {
        if ($deckId <= 0) {
            continue;
        }
        add_word_to_deck($pdo, $deckId, $wordId);
    }

    $pdo->commit();

    if (request_wants_json()) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'נוסף בהצלחה',
            'word_id' => $wordId,
        ]);
        exit;
    }

    flash('Word added to your decks.', 'success');
    redirect('index.php?screen=home');
}

function handle_seed_openers_action(PDO $pdo, array $context): void
{
    check_token($_POST['csrf'] ?? null);

    $starterPhrases = $context['starterPhrases'] ?? [];
    $selectedDeckId = (int) ($context['selectedDeckId'] ?? 0);
    $defaultDeckId = (int) ($context['defaultDeckId'] ?? 0);

    $deckId = (int) ($_POST['deck_id'] ?? $selectedDeckId ?: $defaultDeckId);
    if ($deckId <= 0) {
        $deckId = $defaultDeckId;
    }

    $inserted = 0;

    $pdo->beginTransaction();

    foreach ($starterPhrases as $entry) {
        $stmt = $pdo->prepare('SELECT id FROM words WHERE hebrew = ? LIMIT 1');
        $stmt->execute([$entry['hebrew']]);
        $wordId = (int) ($stmt->fetchColumn() ?: 0);

        if ($wordId === 0) {
            $insertWord = $pdo->prepare('INSERT INTO words (hebrew, transliteration, part_of_speech, notes, audio_path) VALUES (?, ?, ?, ?, ?)');
            $insertWord->execute([$entry['hebrew'], $entry['transliteration'], 'phrase', null, null]);
            $wordId = (int) $pdo->lastInsertId();
        }

        if ($wordId > 0) {
            $checkTranslation = $pdo->prepare('SELECT id FROM translations WHERE word_id = ? AND lang_code = ? LIMIT 1');
            $checkTranslation->execute([$wordId, $entry['lang']]);
            if (!$checkTranslation->fetchColumn()) {
                $insertTranslation = $pdo->prepare('INSERT INTO translations (word_id, lang_code, meaning, example) VALUES (?, ?, ?, ?)');
                $insertTranslation->execute([$wordId, $entry['lang'], $entry['meaning'], $entry['example']]);
            }

            add_word_to_deck($pdo, $deckId, $wordId);
            $inserted += 1;
        }
    }

    $pdo->commit();

    if (request_wants_json()) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'added' => $inserted,
        ]);
        exit;
    }

    flash(sprintf('%d starter phrases added to the deck.', $inserted), 'success');
    redirect('index.php?screen=home');
}

function handle_create_deck_action(PDO $pdo): void
{
    check_token($_POST['csrf'] ?? null);

    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category = trim($_POST['category'] ?? 'General');

    if ($name === '') {
        flash('Deck name is required.', 'error');
        dashboard_redirect('index.php?screen=library');
    }

    $palette = ['#6366f1', '#22d3ee', '#f97316', '#facc15', '#34d399', '#ec4899', '#0ea5e9', '#a855f7'];
    $icons = ['sparkles', 'book', 'globe', 'lightbulb', 'star', 'leaf', 'compass', 'puzzle'];

    $stmt = $pdo->prepare(
        'INSERT INTO decks (name, description, category, icon, color, rating, learners_count)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $name,
        $description !== '' ? $description : null,
        $category !== '' ? $category : 'General',
        $icons[random_int(0, count($icons) - 1)],
        $palette[random_int(0, count($palette) - 1)],
        4.7,
        0,
    ]);

    $newDeckId = (int) $pdo->lastInsertId();
    $_SESSION['selected_deck'] = $newDeckId;

    flash('Deck created.', 'success');
    dashboard_redirect('index.php?screen=library');
}

function handle_update_deck_details_action(PDO $pdo): void
{
    check_token($_POST['csrf'] ?? null);

    $deckId = (int) ($_POST['deck_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category = trim($_POST['category'] ?? 'General');

    if ($deckId <= 0 || $name === '') {
        flash('Unable to update deck.', 'error');
        dashboard_redirect('index.php?screen=library');
    }

    $stmt = $pdo->prepare('UPDATE decks SET name = ?, description = ?, category = ? WHERE id = ?');
    $stmt->execute([$name, $description !== '' ? $description : null, $category, $deckId]);

    flash('Deck details updated.', 'success');
    dashboard_redirect('index.php?screen=library');
}

function handle_move_deck_action(PDO $pdo): void
{
    check_token($_POST['csrf'] ?? null);

    $deckId = (int) ($_POST['deck_id'] ?? 0);
    $category = trim($_POST['category'] ?? 'General');

    if ($deckId <= 0) {
        flash('Deck not found.', 'error');
        dashboard_redirect('index.php?screen=library');
    }

    $stmt = $pdo->prepare('UPDATE decks SET category = ? WHERE id = ?');
    $stmt->execute([$category, $deckId]);

    flash('Deck moved to ' . $category . '.', 'success');
    dashboard_redirect('index.php?screen=library');
}

function handle_select_deck_action(PDO $pdo): void
{
    check_token($_POST['csrf'] ?? null);
    $deckId = (int) ($_POST['deck_id'] ?? 0);

    $stmt = $pdo->prepare('SELECT id FROM decks WHERE id = ?');
    $stmt->execute([$deckId]);
    if ($stmt->fetchColumn()) {
        $_SESSION['selected_deck'] = $deckId;
        flash('Deck selected for study.', 'success');
    } else {
        flash('Deck not found.', 'error');
    }
    dashboard_redirect('index.php?screen=library');
}

function handle_toggle_deck_flag_action(PDO $pdo): void
{
    check_token($_POST['csrf'] ?? null);
    $deckId = (int) ($_POST['deck_id'] ?? 0);
    $flag = $_POST['flag'] ?? '';
    $value = isset($_POST['value']) ? (bool) $_POST['value'] : false;

    try {
        update_deck_flag($pdo, $deckId, $flag, $value);
        flash('Deck preferences updated.', 'success');
    } catch (Throwable $e) {
        flash('Unable to update deck preferences.', 'error');
    }

    dashboard_redirect('index.php?screen=library');
}

function handle_duplicate_deck_action(PDO $pdo): void
{
    check_token($_POST['csrf'] ?? null);
    $deckId = (int) ($_POST['deck_id'] ?? 0);

    try {
        $newDeckId = duplicate_deck($pdo, $deckId);
        $_SESSION['selected_deck'] = $newDeckId;
        flash('Deck duplicated.', 'success');
    } catch (Throwable $e) {
        flash('Unable to duplicate deck.', 'error');
    }

    dashboard_redirect('index.php?screen=library');
}

function handle_delete_deck_action(PDO $pdo, array $context): void
{
    check_token($_POST['csrf'] ?? null);
    $deckId = (int) ($_POST['deck_id'] ?? 0);
    $defaultDeckId = (int) ($context['defaultDeckId'] ?? 0);

    if ($deckId <= 0) {
        flash('Deck not found.', 'error');
        dashboard_redirect('index.php?screen=library');
    }

    $pdo->prepare('DELETE FROM decks WHERE id = ?')->execute([$deckId]);
    if (($_SESSION['selected_deck'] ?? null) === $deckId) {
        $_SESSION['selected_deck'] = $defaultDeckId;
    }

    flash('Deck deleted.', 'success');
    dashboard_redirect('index.php?screen=library');
}

function handle_publish_deck_action(PDO $pdo): void
{
    check_token($_POST['csrf'] ?? null);
    $deckId = (int) ($_POST['deck_id'] ?? 0);
    $description = trim($_POST['publish_description'] ?? '');

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM deck_words WHERE deck_id = ?');
    $countStmt->execute([$deckId]);
    $cardsCount = (int) $countStmt->fetchColumn();

    $minStmt = $pdo->prepare('SELECT min_cards_required FROM decks WHERE id = ?');
    $minStmt->execute([$deckId]);
    $minRequired = (int) $minStmt->fetchColumn();
    if ($minRequired <= 0) {
        $minRequired = 75;
    }

    if ($cardsCount < $minRequired) {
        flash(sprintf('Add %d more cards before publishing.', $minRequired - $cardsCount), 'error');
        dashboard_redirect('index.php?screen=library');
    }

    $pdo->prepare('UPDATE decks SET published_at = NOW(), published_description = ? WHERE id = ?')
        ->execute([$description !== '' ? $description : null, $deckId]);

    flash('Deck submitted to the community library.', 'success');
    dashboard_redirect('index.php?screen=library');
}

function handle_update_tts_action(PDO $pdo): void
{
    check_token($_POST['csrf'] ?? null);
    $deckId = (int) ($_POST['deck_id'] ?? 0);
    $frontLang = substr(trim($_POST['front_lang'] ?? 'en-US'), 0, 20);
    $backLang = substr(trim($_POST['back_lang'] ?? 'he-IL'), 0, 20);
    $frontVoice = substr(trim($_POST['front_voice'] ?? ''), 0, 120);
    $backVoice = substr(trim($_POST['back_voice'] ?? ''), 0, 120);

    $stmt = $pdo->prepare('UPDATE decks SET tts_front_lang = ?, tts_back_lang = ?, tts_front_voice = ?, tts_back_voice = ? WHERE id = ?');
    $stmt->execute([$frontLang, $backLang, $frontVoice, $backVoice, $deckId]);

    flash('העדפות TTS נשמרו.', 'success');
    dashboard_redirect('index.php?screen=settings');
}

function handle_reset_deck_progress_action(PDO $pdo, array $context): void
{
    check_token($_POST['csrf'] ?? null);
    $deckId = (int) ($_POST['deck_id'] ?? 0);
    $userIdentifier = (string) ($context['userIdentifier'] ?? '');

    $delete = $pdo->prepare(
        'DELETE up FROM user_progress up
         INNER JOIN deck_words dw ON dw.word_id = up.word_id
         WHERE up.user_identifier = ? AND dw.deck_id = ?'
    );
    $delete->execute([$userIdentifier, $deckId]);

    flash('Progress reset for this deck.', 'success');
    dashboard_redirect('index.php?screen=library');
}

function handle_archive_deck_action(PDO $pdo): void
{
    check_token($_POST['csrf'] ?? null);
    $deckId = (int) ($_POST['deck_id'] ?? 0);

    $stmt = $pdo->prepare('UPDATE decks SET category = ?, is_frozen = 1 WHERE id = ?');
    $stmt->execute(['Archived', $deckId]);

    flash('Deck archived.', 'success');
    dashboard_redirect('index.php?screen=library');
}
