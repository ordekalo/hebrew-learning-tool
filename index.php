<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

$csrf = ensure_token();
$flash = get_flash();

if (!isset($_SESSION['user_identifier'])) {
    $_SESSION['user_identifier'] = 'guest-' . bin2hex(random_bytes(5));
}

$userIdentifier = $_SESSION['user_identifier'];
$screen = $_GET['screen'] ?? 'home';
$screen = in_array($screen, ['home', 'library', 'settings'], true) ? $screen : 'home';
$action = $_GET['a'] ?? 'view';
$langFilter = isset($_GET['lang']) && $_GET['lang'] !== '' ? substr($_GET['lang'], 0, 10) : null;
$searchTerm = trim($_GET['q'] ?? '');

$defaultDeckId = ensure_default_deck($pdo);
if (!isset($_SESSION['selected_deck']) || (int) $_SESSION['selected_deck'] <= 0) {
    $_SESSION['selected_deck'] = $defaultDeckId;
}
$selectedDeckId = (int) ($_SESSION['selected_deck'] ?? $defaultDeckId);

function request_wants_json(): bool
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';

    if (stripos($requestedWith, 'xmlhttprequest') !== false) {
        return true;
    }

    return str_contains($accept, 'application/json');
}

$starterPhrases = [
    ['hebrew' => 'מה שלומך?', 'transliteration' => 'ma shlomkha?', 'meaning' => 'How are you?', 'lang' => 'en', 'example' => 'מה שלומך היום?'],
    ['hebrew' => 'נעים להכיר', 'transliteration' => 'naim lehakir', 'meaning' => 'Nice to meet you', 'lang' => 'en', 'example' => 'נעים להכיר אותך סוף סוף.'],
    ['hebrew' => 'קוראים לי...', 'transliteration' => 'korim li…', 'meaning' => 'My name is…', 'lang' => 'en', 'example' => 'קוראים לי דנה.'],
    ['hebrew' => 'מאיפה אתה?', 'transliteration' => 'me eifo ata?', 'meaning' => 'Where are you from?', 'lang' => 'en', 'example' => 'מאיפה אתה במקור?'],
    ['hebrew' => 'אני מדבר קצת עברית', 'transliteration' => 'ani medaber ktsat ivrit', 'meaning' => 'I speak a little Hebrew', 'lang' => 'en', 'example' => 'אני מדבר קצת עברית אבל לומד מהר.'],
    ['hebrew' => 'אפשר לשאול שאלה?', 'transliteration' => 'efshar lishol sheelah?', 'meaning' => 'May I ask a question?', 'lang' => 'en', 'example' => 'אפשר לשאול שאלה על השיעור?'],
    ['hebrew' => 'תודה רבה', 'transliteration' => 'toda raba', 'meaning' => 'Thank you very much', 'lang' => 'en', 'example' => 'תודה רבה על העזרה.'],
    ['hebrew' => 'סליחה, לא הבנתי', 'transliteration' => 'slicha, lo hevanti', 'meaning' => 'Sorry, I did not understand', 'lang' => 'en', 'example' => 'סליחה, לא הבנתי מה אמרת.'],
    ['hebrew' => 'אתה יכול לחזור על זה?', 'transliteration' => 'ata yakhol lakhzor al ze?', 'meaning' => 'Can you repeat that?', 'lang' => 'en', 'example' => 'אתה יכול לחזור על זה לאט יותר?'],
    ['hebrew' => 'בוא נתחיל', 'transliteration' => 'bo natchil', 'meaning' => 'Let’s get started', 'lang' => 'en', 'example' => 'בוא נתחיל עם תרגול קצר.'],
];

function fetch_random_cards(PDO $pdo, ?string $lang, int $limit = 1, bool $requireMeaning = false, ?int $deckId = null): array
{
    $limit = max(1, $limit);
    $conditions = [];
    $params = [];
    $joins = '';

    if ($lang !== null) {
        $conditions[] = 't.lang_code = ?';
        $params[] = $lang;
    }

    if ($requireMeaning) {
        $conditions[] = "(t.meaning IS NOT NULL AND t.meaning <> '')";
    }

    if ($deckId !== null) {
        $joins .= ' INNER JOIN deck_words dw ON dw.word_id = w.id AND dw.deck_id = ?';
        $params[] = $deckId;
    }

    $sql = 'SELECT w.*, t.lang_code, t.other_script, t.meaning, t.example';
    if ($deckId !== null) {
        $sql .= ', dw.is_reversed';
    }
    $sql .= ' FROM words w';
    $sql .= ' LEFT JOIN (';
    $sql .= '     SELECT tr.word_id, tr.lang_code, tr.other_script, tr.meaning, tr.example';
    $sql .= '     FROM translations tr';
    $sql .= '     INNER JOIN (';
    $sql .= '         SELECT word_id, MIN(id) AS min_id';
    $sql .= '         FROM translations';
    $sql .= '         GROUP BY word_id';
    $sql .= '     ) picked ON picked.word_id = tr.word_id AND picked.min_id = tr.id';
    $sql .= ' ) t ON t.word_id = w.id';
    $sql .= $joins;

    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }

    $sql .= ' ORDER BY ' . db_random_function() . ' LIMIT ' . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll() ?: [];
}

function fetch_random_card(PDO $pdo, ?string $lang, ?int $deckId = null): ?array
{
    $cards = fetch_random_cards($pdo, $lang, 1, false, $deckId);

    return $cards[0] ?? null;
}

function fetch_decks_with_stats(PDO $pdo, string $userIdentifier): array
{
    $stmt = $pdo->prepare(
        'SELECT d.*, COUNT(DISTINCT dw.word_id) AS cards_count,
                COUNT(DISTINCT up.word_id) AS studied_count,
                SUM(CASE WHEN up.proficiency >= 3 THEN 1 ELSE 0 END) AS mastered_count,
                MAX(up.last_reviewed_at) AS last_reviewed_at
         FROM decks d
         LEFT JOIN deck_words dw ON dw.deck_id = d.id
         LEFT JOIN user_progress up ON up.word_id = dw.word_id AND up.user_identifier = :user
         GROUP BY d.id
         ORDER BY d.created_at DESC'
    );
    $stmt->execute(['user' => $userIdentifier]);
    $rows = $stmt->fetchAll() ?: [];

    foreach ($rows as &$row) {
        $cardsCount = (int) ($row['cards_count'] ?? 0);
        $studied = (int) ($row['studied_count'] ?? 0);
        $row['progress_percent'] = $cardsCount > 0 ? (int) round(($studied / $cardsCount) * 100) : 0;
        $row['mastered_count'] = (int) ($row['mastered_count'] ?? 0);
    }

    return $rows;
}

function group_decks_by_category(array $decks): array
{
    $grouped = [];
    foreach ($decks as $deck) {
        $category = $deck['category'] ?? 'General';
        if (!isset($grouped[$category])) {
            $grouped[$category] = [];
        }
        $grouped[$category][] = $deck;
    }

    return $grouped;
}

function fetch_deck_sample_card(PDO $pdo, int $deckId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT w.*, t.lang_code, t.meaning, t.other_script, dw.is_reversed
         FROM deck_words dw
         INNER JOIN words w ON w.id = dw.word_id
         LEFT JOIN (
             SELECT tr.word_id, tr.lang_code, tr.other_script, tr.meaning
             FROM translations tr
             INNER JOIN (
                 SELECT word_id, MIN(id) AS min_id
                 FROM translations
                 GROUP BY word_id
             ) picked ON picked.word_id = tr.word_id AND picked.min_id = tr.id
         ) t ON t.word_id = w.id
         WHERE dw.deck_id = ?
         ORDER BY dw.position ASC, w.created_at DESC
         LIMIT 1'
    );
    $stmt->execute([$deckId]);

    return $stmt->fetch() ?: null;
}

function fetch_deck_learning_history(PDO $pdo, int $deckId, string $userIdentifier): array
{
    $stmt = $pdo->prepare(
        'SELECT w.hebrew, w.transliteration, up.proficiency, up.last_reviewed_at
         FROM deck_words dw
         INNER JOIN user_progress up ON up.word_id = dw.word_id AND up.user_identifier = ?
         INNER JOIN words w ON w.id = dw.word_id
         WHERE dw.deck_id = ?
         ORDER BY up.last_reviewed_at DESC
         LIMIT 20'
    );
    $stmt->execute([$userIdentifier, $deckId]);

    return $stmt->fetchAll() ?: [];
}

function update_deck_flag(PDO $pdo, int $deckId, string $column, bool $value): void
{
    $allowed = ['is_frozen', 'is_reversed', 'ai_generation_enabled', 'offline_enabled', 'tts_enabled', 'tts_autoplay'];
    if (!in_array($column, $allowed, true)) {
        throw new InvalidArgumentException('Unsupported deck flag');
    }

    $stmt = $pdo->prepare("UPDATE decks SET {$column} = ? WHERE id = ?");
    $stmt->execute([$value ? 1 : 0, $deckId]);
}

function duplicate_deck(PDO $pdo, int $deckId): int
{
    $stmt = $pdo->prepare('SELECT * FROM decks WHERE id = ?');
    $stmt->execute([$deckId]);
    $deck = $stmt->fetch();

    if (!$deck) {
        throw new RuntimeException('Deck not found');
    }

    $newName = $deck['name'] . ' Copy';
    $insert = $pdo->prepare(
        'INSERT INTO decks (name, description, category, icon, color, rating, learners_count, is_frozen, is_reversed,
                            ai_generation_enabled, offline_enabled, published_at, published_description, min_cards_required,
                            tts_enabled, tts_autoplay, tts_front_lang, tts_back_lang)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $insert->execute([
        $newName,
        $deck['description'],
        $deck['category'],
        $deck['icon'],
        $deck['color'],
        $deck['rating'],
        $deck['learners_count'],
        $deck['is_frozen'],
        $deck['is_reversed'],
        $deck['ai_generation_enabled'],
        $deck['offline_enabled'],
        null,
        null,
        $deck['min_cards_required'],
        $deck['tts_enabled'],
        $deck['tts_autoplay'],
        $deck['tts_front_lang'],
        $deck['tts_back_lang'],
    ]);

    $newDeckId = (int) $pdo->lastInsertId();

    $pairs = $pdo->prepare('SELECT word_id, position, is_reversed FROM deck_words WHERE deck_id = ?');
    $pairs->execute([$deckId]);
    $rows = $pairs->fetchAll();

    foreach ($rows as $row) {
        $pdo->prepare('INSERT INTO deck_words (deck_id, word_id, position, is_reversed) VALUES (?, ?, ?, ?)')
            ->execute([$newDeckId, (int) $row['word_id'], (int) $row['position'], (int) $row['is_reversed']]);
    }

    return $newDeckId;
}

if ($action === 'create_word' && is_post()) {
    check_token($_POST['csrf'] ?? null);

    $hebrew = trim($_POST['hebrew'] ?? '');
    $transliteration = trim($_POST['transliteration'] ?? '');
    $partOfSpeech = trim($_POST['part_of_speech'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($hebrew === '') {
        flash('Please enter the Hebrew word.', 'error');
        redirect('index.php?screen=home');
    }

    try {
        $audioPath = handle_audio_upload($_FILES['audio'] ?? [], $UPLOAD_DIR);
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
        redirect('index.php?screen=home');
    }

    $recordedAudio = $_POST['recorded_audio'] ?? '';

    if ($audioPath === null && $recordedAudio !== '') {
        try {
            $audioPath = save_recorded_audio($recordedAudio, $UPLOAD_DIR);
        } catch (RuntimeException $e) {
            flash($e->getMessage(), 'error');
            redirect('index.php?screen=home');
        }
    }

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

if ($action === 'seed_openers' && is_post()) {
    check_token($_POST['csrf'] ?? null);

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

if ($action === 'create_deck' && is_post()) {
    check_token($_POST['csrf'] ?? null);

    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category = trim($_POST['category'] ?? 'General');

    if ($name === '') {
        flash('Deck name is required.', 'error');
        redirect('index.php?screen=library');
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
    redirect('index.php?screen=library');
}

if ($action === 'update_deck_details' && is_post()) {
    check_token($_POST['csrf'] ?? null);

    $deckId = (int) ($_POST['deck_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category = trim($_POST['category'] ?? 'General');

    if ($deckId <= 0 || $name === '') {
        flash('Unable to update deck.', 'error');
        redirect('index.php?screen=library');
    }

    $stmt = $pdo->prepare('UPDATE decks SET name = ?, description = ?, category = ? WHERE id = ?');
    $stmt->execute([$name, $description !== '' ? $description : null, $category, $deckId]);

    flash('Deck details updated.', 'success');
    redirect('index.php?screen=library');
}

if ($action === 'move_deck' && is_post()) {
    check_token($_POST['csrf'] ?? null);

    $deckId = (int) ($_POST['deck_id'] ?? 0);
    $category = trim($_POST['category'] ?? 'General');

    if ($deckId <= 0) {
        flash('Deck not found.', 'error');
        redirect('index.php?screen=library');
    }

    $stmt = $pdo->prepare('UPDATE decks SET category = ? WHERE id = ?');
    $stmt->execute([$category, $deckId]);

    flash('Deck moved to ' . $category . '.', 'success');
    redirect('index.php?screen=library');
}

if ($action === 'select_deck' && is_post()) {
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
    redirect('index.php?screen=library');
}

if ($action === 'toggle_deck_flag' && is_post()) {
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

    redirect('index.php?screen=library');
}

if ($action === 'duplicate_deck' && is_post()) {
    check_token($_POST['csrf'] ?? null);
    $deckId = (int) ($_POST['deck_id'] ?? 0);

    try {
        $newDeckId = duplicate_deck($pdo, $deckId);
        $_SESSION['selected_deck'] = $newDeckId;
        flash('Deck duplicated.', 'success');
    } catch (Throwable $e) {
        flash('Unable to duplicate deck.', 'error');
    }

    redirect('index.php?screen=library');
}

if ($action === 'delete_deck' && is_post()) {
    check_token($_POST['csrf'] ?? null);
    $deckId = (int) ($_POST['deck_id'] ?? 0);

    if ($deckId <= 0) {
        flash('Deck not found.', 'error');
        redirect('index.php?screen=library');
    }

    $pdo->prepare('DELETE FROM decks WHERE id = ?')->execute([$deckId]);
    if ($_SESSION['selected_deck'] === $deckId) {
        $_SESSION['selected_deck'] = $defaultDeckId;
    }

    flash('Deck deleted.', 'success');
    redirect('index.php?screen=library');
}

if ($action === 'publish_deck' && is_post()) {
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
        redirect('index.php?screen=library');
    }

    $pdo->prepare('UPDATE decks SET published_at = NOW(), published_description = ? WHERE id = ?')
        ->execute([$description !== '' ? $description : null, $deckId]);

    flash('Deck submitted to the community library.', 'success');
    redirect('index.php?screen=library');
}

if ($action === 'update_tts' && is_post()) {
    check_token($_POST['csrf'] ?? null);
    $deckId = (int) ($_POST['deck_id'] ?? 0);
    $frontLang = substr(trim($_POST['front_lang'] ?? 'en-US'), 0, 20);
    $backLang = substr(trim($_POST['back_lang'] ?? 'he-IL'), 0, 20);

    $stmt = $pdo->prepare('UPDATE decks SET tts_front_lang = ?, tts_back_lang = ? WHERE id = ?');
    $stmt->execute([$frontLang, $backLang, $deckId]);

    flash('Text-to-speech settings saved.', 'success');
    redirect('index.php?screen=settings');
}

if ($action === 'reset_deck_progress' && is_post()) {
    check_token($_POST['csrf'] ?? null);
    $deckId = (int) ($_POST['deck_id'] ?? 0);

    $delete = $pdo->prepare(
        'DELETE up FROM user_progress up
         INNER JOIN deck_words dw ON dw.word_id = up.word_id
         WHERE up.user_identifier = ? AND dw.deck_id = ?'
    );
    $delete->execute([$userIdentifier, $deckId]);

    flash('Progress reset for this deck.', 'success');
    redirect('index.php?screen=library');
}

if ($action === 'archive_deck' && is_post()) {
    check_token($_POST['csrf'] ?? null);
    $deckId = (int) ($_POST['deck_id'] ?? 0);

    $stmt = $pdo->prepare('UPDATE decks SET category = ?, is_frozen = 1 WHERE id = ?');
    $stmt->execute(['Archived', $deckId]);

    flash('Deck archived.', 'success');
    redirect('index.php?screen=library');
}

$decks = fetch_decks_with_stats($pdo, $userIdentifier);

if (!$decks) {
    $defaultDeckId = ensure_default_deck($pdo);
    $decks = fetch_decks_with_stats($pdo, $userIdentifier);
}

$deckLookup = [];
foreach ($decks as $deck) {
    $deckLookup[(int) $deck['id']] = $deck;
}

if (!isset($deckLookup[$selectedDeckId])) {
    $selectedDeckId = $defaultDeckId;
    $_SESSION['selected_deck'] = $selectedDeckId;
}

$selectedDeck = $deckLookup[$selectedDeckId] ?? null;
$deckSample = $selectedDeck ? fetch_deck_sample_card($pdo, $selectedDeckId) : null;
$deckHistory = $selectedDeck ? fetch_deck_learning_history($pdo, $selectedDeckId, $userIdentifier) : [];
$groupedDecks = group_decks_by_category($decks);

$popularDecks = array_filter($decks, static fn(array $deck): bool => (float) $deck['rating'] >= 4.5 || (int) $deck['learners_count'] >= 200);
$categories = array_keys($groupedDecks);
sort($categories);

$card = fetch_random_card($pdo, $langFilter, $selectedDeckId);
$carouselCards = fetch_random_cards($pdo, $langFilter, 8, false, $selectedDeckId);
$memoryPairs = fetch_random_cards($pdo, $langFilter, 6, true, $selectedDeckId);
$memoryPairs = array_values(array_filter($memoryPairs, static fn(array $row): bool => ($row['meaning'] ?? '') !== ''));
$memoryData = array_map(
    static function (array $row): array {
        $isReversed = isset($row['is_reversed']) && (int) $row['is_reversed'] === 1;
        $hebrew = $row['hebrew'] ?? '';
        $meaning = $row['meaning'] ?? '';
        if ($isReversed) {
            return [
                'id' => (int) $row['id'],
                'hebrew' => $meaning,
                'meaning' => $hebrew,
                'other_script' => $row['other_script'] ?? '',
                'transliteration' => $row['transliteration'] ?? '',
            ];
        }

        return [
            'id' => (int) $row['id'],
            'hebrew' => $hebrew,
            'meaning' => $meaning,
            'other_script' => $row['other_script'] ?? '',
            'transliteration' => $row['transliteration'] ?? '',
        ];
    },
    $memoryPairs
);

$interfaceLocale = 'he-IL';
if ($langFilter === 'ar') {
    $interfaceLocale = 'ar';
} elseif ($langFilter === 'ru') {
    $interfaceLocale = 'ru-RU';
} elseif ($langFilter === 'en') {
    $interfaceLocale = 'en-US';
}

$hasDeckCards = (int) ($selectedDeck['cards_count'] ?? 0) > 0;

$searchResults = [];
if ($searchTerm !== '') {
    // בניית שאילתת סיכום התרגומים באמצעות פונקציה קיימת
    $translationSummarySelect = db_translation_summary_select();

    $sql = 'SELECT w.*, ' . $translationSummarySelect . ',
                   GROUP_CONCAT(DISTINCT d.name SEPARATOR ", ") AS deck_names
            FROM words w
            LEFT JOIN translations t ON t.word_id = w.id
            LEFT JOIN deck_words dw ON dw.word_id = w.id
            LEFT JOIN decks d ON d.id = dw.deck_id
            WHERE w.hebrew LIKE ?
               OR w.transliteration LIKE ?
               OR t.meaning LIKE ?
               OR t.other_script LIKE ?
            GROUP BY w.id
            ORDER BY w.created_at DESC
            LIMIT 50';

    $stmt = $pdo->prepare($sql);
    $like = '%' . $searchTerm . '%';
    $stmt->execute([$like, $like, $like, $like]);
    $searchResults = $stmt->fetchAll();
}


?>
<!DOCTYPE html>
<html lang="<?= h(str_replace('_', '-', $interfaceLocale)) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hebrew Study Hub</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body class="app-body" data-screen="<?= h($screen) ?>" data-locale="<?= h($interfaceLocale) ?>" data-lang-filter="<?= h($langFilter ?? '') ?>" data-tts-back-lang="<?= h($selectedDeck['tts_back_lang'] ?? 'he-IL') ?>">
<div class="app-shell">
    <header class="app-header">
        <div class="header-main">
            <h1>Hebrew Study Hub</h1>
            <p class="header-sub">Tailored decks, smart drills, and responsive design inspired by mobile-first study apps.</p>
        </div>
        <form method="get" action="index.php" class="search-form">
            <input type="hidden" name="screen" value="home">
            <input type="text" name="q" placeholder="Search cards" value="<?= h($searchTerm) ?>">
            <button class="btn primary" type="submit">Search</button>
        </form>
    </header>

    <?php if ($flash): ?>
        <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <main class="app-content">
        <section class="screen" data-screen="home" <?= $screen === 'home' ? '' : 'hidden' ?>>
            <div class="hero-card">
                <div class="hero-info">
                    <h2><?= h($selectedDeck['name'] ?? 'Deck') ?></h2>
                    <p><?= h($selectedDeck['description'] ?? 'Curated words to master Hebrew every day.') ?></p>
                    <div class="hero-stats">
                        <span><strong><?= (int) ($selectedDeck['cards_count'] ?? 0) ?></strong> Cards</span>
                        <span><strong><?= (int) ($selectedDeck['studied_count'] ?? 0) ?></strong> Studied</span>
                        <span><strong><?= (int) ($selectedDeck['progress_percent'] ?? 0) ?>%</strong> Complete</span>
                    </div>
                    <div class="hero-actions">
                        <?php if ($hasDeckCards): ?>
                            <a class="btn primary" href="#flashcards">Start session</a>
                        <?php else: ?>
                            <a class="btn primary" href="#quick-add-form" data-roll-example="true">צור כרטיס ראשון</a>
                        <?php endif; ?>
                        <a class="btn ghost" href="?screen=library">Deck settings</a>
                    </div>
                </div>
                <div class="hero-illustration">
                    <span class="deck-icon"><?= h($selectedDeck['icon'] ?? 'sparkles') ?></span>
                </div>
            </div>

            <section class="card flashcard-section" id="flashcards">
                <div class="section-heading">
                    <div>
                        <h2>Flashcards</h2>
                        <p class="section-subtitle">Swipe through your deck with reversible cards and inline audio.</p>
                    </div>
                    <div class="tag-group">
                        <a class="btn ghost" href="index.php?screen=home">Shuffle</a>
                        <label class="sr-only" for="lang-filter">Filter language</label>
                        <select id="lang-filter" name="lang">
                            <option value="">All languages</option>
                            <option value="ru" <?= $langFilter === 'ru' ? 'selected' : '' ?>>Русский</option>
                            <option value="en" <?= $langFilter === 'en' ? 'selected' : '' ?>>English</option>
                            <option value="ar" <?= $langFilter === 'ar' ? 'selected' : '' ?>>العربية</option>
                        </select>
                    </div>
                </div>
                <?php if ($carouselCards): ?>
                    <div class="flashcard-track" tabindex="0">
                        <?php foreach ($carouselCards as $item): ?>
                            <?php $isReversed = isset($item['is_reversed']) && (int) $item['is_reversed'] === 1; ?>
                            <article class="flashcard" role="listitem">
                                <header class="flashcard-header">
                                    <span class="badge">Deck</span>
                                    <span class="badge badge-muted"><?= h($selectedDeck['name'] ?? 'Deck') ?></span>
                                </header>
                                <?php if ($isReversed): ?>
                                    <h3 class="hebrew-word"><?= h($item['meaning'] ?? '') ?></h3>
                                    <p class="flashcard-text"><span class="badge">Hebrew</span> <?= h($item['hebrew'] ?? '') ?></p>
                                <?php else: ?>
                                    <h3 class="hebrew-word"><?= h($item['hebrew'] ?? '') ?></h3>
                                    <?php if (!empty($item['transliteration'])): ?>
                                        <p class="flashcard-text"><span class="badge">Transliteration</span> <?= h($item['transliteration']) ?></p>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <div class="flashcard-translation">
                                    <span class="badge">Meaning</span>
                                    <p class="flashcard-text">
                                        <strong><?= h($item['lang_code'] ?? '—') ?>:</strong>
                                        <?= h($item['meaning'] ?? '—') ?>
                                    </p>
                                    <?php if (!empty($item['other_script'])): ?>
                                        <p class="flashcard-text alt-script"><?= h($item['other_script']) ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($item['example'])): ?>
                                        <p class="flashcard-text example"><?= nl2br(h($item['example'])) ?></p>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($item['audio_path'])): ?>
                                    <audio class="audio" controls preload="none" src="<?= h($item['audio_path']) ?>"></audio>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p>No cards yet in this deck. Add words using the quick form or start with ready-made phrases.</p>
                    <form method="post" action="index.php?a=seed_openers" class="seed-form">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
                        <button type="submit" class="btn primary seed-btn">+ הוסף 10 ביטויי פתיחה</button>
                    </form>
                <?php endif; ?>
            </section>

            <section class="card memory-section">
                <div class="section-heading">
                    <div>
                        <h2>Memory Trainer</h2>
                        <p class="section-subtitle">Match Hebrew words with their translation to reinforce recognition.</p>
                    </div>
                    <button type="button" class="btn ghost" id="memory-reset" <?= empty($memoryPairs) ? 'disabled' : '' ?>>Shuffle board</button>
                </div>
                <div class="memory-status">
                    <span>Matches: <strong id="memory-matches">0</strong> / <?= count($memoryPairs) ?></span>
                    <span id="memory-feedback" aria-live="polite" role="status"></span>
                </div>
                <div class="memory-board" id="memory-board" aria-label="Memory trainer board" data-empty="<?= empty($memoryPairs) ? '1' : '0' ?>"></div>
                <?php if (empty($memoryPairs)): ?>
                    <p class="memory-empty">Add translations to play the memory game.</p>
                <?php endif; ?>
            </section>

            <section class="card">
                <h3>Quick Add Word</h3>
                <form method="post" enctype="multipart/form-data" action="index.php?a=create_word" id="quick-add-form">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="recorded_audio" id="recorded_audio">
                    <div class="grid grid-3 responsive">
                        <div>
                            <label for="hebrew">Hebrew *</label>
                            <input id="hebrew" name="hebrew" required placeholder="לְדֻגְמָה" dir="rtl" inputmode="text" autocomplete="off" spellcheck="false" lang="he">
                        </div>
                        <div>
                            <label for="transliteration">Transliteration</label>
                            <input id="transliteration" name="transliteration" placeholder="le-dugma" autocomplete="off">
                        </div>
                        <div>
                            <label for="part_of_speech">Part of speech</label>
                            <select id="part_of_speech" name="part_of_speech">
                                <option value="">Unspecified</option>
                                <option value="noun">Noun</option>
                                <option value="verb">Verb</option>
                                <option value="adj">Adjective</option>
                                <option value="adv">Adverb</option>
                                <option value="phrase">Phrase</option>
                                <option value="prep">Preposition</option>
                                <option value="pron">Pronoun</option>
                            </select>
                        </div>
                    </div>
                    <label for="notes">Notes</label>
                    <textarea id="notes" name="notes" rows="3" placeholder="Any nuances, gender, irregular forms..." maxlength="320"></textarea>

                    <label for="audio">Pronunciation (audio/mp3/wav/ogg ≤ 10MB)</label>
                    <div class="record-row">
                        <input id="audio" type="file" name="audio" accept="audio/*">
                        <div class="record-controls" id="record-controls">
                            <button type="button" class="btn ghost" id="record-toggle" data-state="idle">🎙️ Record</button>
                            <button type="button" class="btn primary" id="record-save" disabled>Use recording</button>
                        </div>
                    </div>
                    <p class="record-support" id="record-support-message" hidden>Recording is not supported in this browser.</p>
                    <div class="record-preview" id="record-preview" hidden>
                        <audio id="recorded-audio" controls></audio>
                        <button type="button" class="btn ghost" id="record-discard">Discard</button>
                    </div>

                    <div class="grid grid-3 responsive">
                        <div>
                            <label for="lang_code">Translation language</label>
                            <input id="lang_code" name="lang_code" placeholder="e.g., ru, en, fr" pattern="^[a-z]{2}(-[A-Z]{2})?$" title="Use a two-letter language code, e.g. en or ru">
                        </div>
                        <div>
                            <label for="other_script">Other script (spelling)</label>
                            <input id="other_script" name="other_script" placeholder="пример / example">
                        </div>
                        <div>
                            <label for="meaning">Meaning (gloss)</label>
                            <input id="meaning" name="meaning" placeholder="example / пример" maxlength="160">
                        </div>
                    </div>
                    <label for="example">Example (optional)</label>
                    <textarea id="example" name="example" rows="2" placeholder="Use in a sentence" maxlength="320"></textarea>

                    <label for="deck_id">Add to deck</label>
                    <select id="deck_id" name="deck_id">
                        <?php foreach ($decks as $deckOption): ?>
                            <option value="<?= (int) $deckOption['id'] ?>" <?= (int) $deckOption['id'] === $selectedDeckId ? 'selected' : '' ?>>
                                <?= h($deckOption['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div class="form-actions">
                        <button class="btn primary" type="submit">Add</button>
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
                            <th>Decks</th>
                            <th>Translations</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($searchResults as $row): ?>
                            <tr>
                                <td><?= h($row['hebrew']) ?></td>
                                <td><?= h($row['transliteration']) ?></td>
                                <td><?= h($row['part_of_speech']) ?></td>
                                <td><?= h($row['deck_names'] ?? '—') ?></td>
                                <td><pre class="translations-pre"><?= h($row['translations_summary']) ?></pre></td>
                                <td><a class="btn ghost" href="edit_word.php?id=<?= (int) $row['id'] ?>">Edit</a></td>
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
        </section>

        <section class="screen" data-screen="library" <?= $screen === 'library' ? '' : 'hidden' ?>>
            <div class="card">
                <div class="library-header">
                    <div>
                        <h2>Decks library</h2>
                        <p class="section-subtitle">Explore curated decks shared by the community.</p>
                    </div>
                    <button class="btn primary" data-dialog-open="create-deck">New deck</button>
                </div>

                <div class="filter-bar">
                    <button class="filter-btn active" data-filter="all">All (<?= count($decks) ?>)</button>
                    <button class="filter-btn" data-filter="popular">Popular (<?= count($popularDecks) ?>)</button>
                    <?php foreach ($categories as $category): ?>
                        <button class="filter-btn" data-filter="<?= h($category) ?>"><?= h($category) ?></button>
                    <?php endforeach; ?>
                </div>

                <div class="deck-grid" id="deck-grid">
                    <?php foreach ($decks as $deck): ?>
                        <article class="deck-card" data-category="<?= h($deck['category'] ?? 'General') ?>" data-popular="<?= ((float) $deck['rating'] >= 4.5 || (int) $deck['learners_count'] >= 200) ? '1' : '0' ?>" data-frozen="<?= (int) ($deck['is_frozen'] ?? 0) ?>" data-reversed="<?= (int) ($deck['is_reversed'] ?? 0) ?>">
                            <header class="deck-card-header" style="--deck-accent: <?= h($deck['color'] ?? '#6366f1') ?>;">
                                <div class="deck-card-icon"><?= h($deck['icon'] ?? 'book') ?></div>
                                <button class="deck-card-menu" data-deck-sheet="<?= (int) $deck['id'] ?>" aria-label="Deck options">⋮</button>
                            </header>
                            <h3><?= h($deck['name']) ?></h3>
                            <p class="deck-card-desc"><?= h($deck['description'] ?? 'No description yet.') ?></p>
                            <dl class="deck-card-stats">
                                <div>
                                    <dt>Cards</dt>
                                    <dd><?= (int) $deck['cards_count'] ?></dd>
                                </div>
                                <div>
                                    <dt>Rating</dt>
                                    <dd><?= number_format((float) $deck['rating'], 1) ?></dd>
                                </div>
                                <div>
                                    <dt>Studiers</dt>
                                    <dd><?= (int) $deck['learners_count'] ?></dd>
                                </div>
                            </dl>
                            <div class="deck-card-progress">
                                <div class="progress-bar"><span style="width: <?= (int) $deck['progress_percent'] ?>%"></span></div>
                                <span><?= (int) $deck['progress_percent'] ?>% mastered</span>
                            </div>
                            <?php if ((int) $deck['id'] === $selectedDeckId): ?>
                                <span class="badge badge-active">Selected</span>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($selectedDeck): ?>
                <section class="card deck-detail">
                    <header>
                        <h3><?= h($selectedDeck['name']) ?> controls</h3>
                        <p class="section-subtitle">Fine-tune text-to-speech, AI generation, offline mode and more.</p>
                    </header>
                    <div class="deck-settings-grid">
                        <form method="post" action="index.php?a=update_deck_details" class="deck-form">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
                            <label for="deck-name">Deck name</label>
                            <input id="deck-name" name="name" value="<?= h($selectedDeck['name']) ?>" required>
                            <label for="deck-description">Description</label>
                            <textarea id="deck-description" name="description" rows="3" placeholder="Tell learners what to expect."><?= h($selectedDeck['description'] ?? '') ?></textarea>
                            <label for="deck-category">Category</label>
                            <input id="deck-category" name="category" value="<?= h($selectedDeck['category'] ?? 'General') ?>">
                            <button class="btn primary" type="submit">Save deck</button>
                        </form>

                        <div class="deck-toggles">
                            <form method="post" action="index.php?a=toggle_deck_flag" class="toggle-row">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
                                <input type="hidden" name="flag" value="ai_generation_enabled">
                                <input type="hidden" name="value" value="<?= (int) ($selectedDeck['ai_generation_enabled'] ?? 0) ? 0 : 1 ?>">
                                <label>AI cards generation <span class="badge">Beta</span></label>
                                <button class="switch <?= (int) ($selectedDeck['ai_generation_enabled'] ?? 0) ? 'on' : '' ?>" type="submit" aria-pressed="<?= (int) ($selectedDeck['ai_generation_enabled'] ?? 0) ? 'true' : 'false' ?>">
                                    <span class="sr-only"><?= (int) ($selectedDeck['ai_generation_enabled'] ?? 0) ? 'On' : 'Off' ?></span>
                                </button>
                            </form>
                            <form method="post" action="index.php?a=toggle_deck_flag" class="toggle-row">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
                                <input type="hidden" name="flag" value="offline_enabled">
                                <input type="hidden" name="value" value="<?= (int) ($selectedDeck['offline_enabled'] ?? 0) ? 0 : 1 ?>">
                                <label>Offline learning</label>
                                <button class="switch <?= (int) ($selectedDeck['offline_enabled'] ?? 0) ? 'on' : '' ?>" type="submit" aria-pressed="<?= (int) ($selectedDeck['offline_enabled'] ?? 0) ? 'true' : 'false' ?>">
                                    <span class="sr-only"><?= (int) ($selectedDeck['offline_enabled'] ?? 0) ? 'On' : 'Off' ?></span>
                                </button>
                            </form>
                            <form method="post" action="index.php?a=toggle_deck_flag" class="toggle-row">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
                                <input type="hidden" name="flag" value="is_reversed">
                                <input type="hidden" name="value" value="<?= (int) ($selectedDeck['is_reversed'] ?? 0) ? 0 : 1 ?>">
                                <label>Reverse cards</label>
                                <button class="switch <?= (int) ($selectedDeck['is_reversed'] ?? 0) ? 'on' : '' ?>" type="submit" aria-pressed="<?= (int) ($selectedDeck['is_reversed'] ?? 0) ? 'true' : 'false' ?>">
                                    <span class="sr-only"><?= (int) ($selectedDeck['is_reversed'] ?? 0) ? 'On' : 'Off' ?></span>
                                </button>
                            </form>
                            <form method="post" action="index.php?a=toggle_deck_flag" class="toggle-row">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
                                <input type="hidden" name="flag" value="is_frozen">
                                <input type="hidden" name="value" value="<?= (int) ($selectedDeck['is_frozen'] ?? 0) ? 0 : 1 ?>">
                                <label>Freeze deck</label>
                                <button class="switch <?= (int) ($selectedDeck['is_frozen'] ?? 0) ? 'on' : '' ?>" type="submit" aria-pressed="<?= (int) ($selectedDeck['is_frozen'] ?? 0) ? 'true' : 'false' ?>">
                                    <span class="sr-only"><?= (int) ($selectedDeck['is_frozen'] ?? 0) ? 'On' : 'Off' ?></span>
                                </button>
                            </form>
                            <form method="post" action="index.php?a=toggle_deck_flag" class="toggle-row">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
                                <input type="hidden" name="flag" value="tts_enabled">
                                <input type="hidden" name="value" value="<?= (int) ($selectedDeck['tts_enabled'] ?? 0) ? 0 : 1 ?>">
                                <label>Text-to-speech</label>
                                <button class="switch <?= (int) ($selectedDeck['tts_enabled'] ?? 0) ? 'on' : '' ?>" type="submit" aria-pressed="<?= (int) ($selectedDeck['tts_enabled'] ?? 0) ? 'true' : 'false' ?>">
                                    <span class="sr-only"><?= (int) ($selectedDeck['tts_enabled'] ?? 0) ? 'On' : 'Off' ?></span>
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="deck-secondary-actions">
                        <form method="post" action="index.php?a=select_deck">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
                            <button class="btn ghost" type="submit">Select deck</button>
                        </form>
                        <form method="post" action="index.php?a=duplicate_deck">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
                            <button class="btn ghost" type="submit">Duplicate deck</button>
                        </form>
                        <form method="post" action="index.php?a=move_deck">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
                            <input type="hidden" name="category" value="Favorites">
                            <button class="btn ghost" type="submit">Move to Favorites</button>
                        </form>
                        <form method="post" action="index.php?a=reset_deck_progress" onsubmit="return confirm('Reset progress for this deck?');">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
                            <button class="btn ghost" type="submit">Reset progress</button>
                        </form>
                        <form method="post" action="index.php?a=archive_deck" onsubmit="return confirm('Archive this deck?');">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
                            <button class="btn ghost" type="submit">Archive deck</button>
                        </form>
                        <a class="btn ghost" href="import_csv.php">Import cards</a>
                    </div>

                    <form class="publish-card" method="post" action="index.php?a=publish_deck">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
                        <h4>Publish in library</h4>
                        <p>Add a short description to share this deck publicly. You need at least <?= (int) ($selectedDeck['min_cards_required'] ?? 75) ?> cards.</p>
                        <textarea name="publish_description" rows="2" placeholder="Enter text here (30 characters minimum)"></textarea>
                        <button class="btn primary" type="submit">Submit for review</button>
                    </form>

                    <div class="deck-history" id="deck-history">
                        <header>
                            <h4>Learning history</h4>
                            <p class="section-subtitle">Recent reviews with proficiency levels.</p>
                        </header>
                        <?php if ($deckHistory): ?>
                            <ul>
                                <?php foreach ($deckHistory as $row): ?>
                                    <li>
                                        <strong><?= h($row['hebrew']) ?></strong>
                                        <span><?= h($row['transliteration']) ?></span>
                                        <span class="badge">Level <?= (int) $row['proficiency'] ?></span>
                                        <time><?= h($row['last_reviewed_at']) ?></time>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p>No review history yet.</p>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>
        </section>

        <section class="screen" data-screen="settings" <?= $screen === 'settings' ? '' : 'hidden' ?>>
            <section class="card settings-profile">
                <div class="avatar">K</div>
                <div>
                    <h2>Kristina Artemova</h2>
                    <p>@kristinaartemova</p>
                </div>
                <button class="btn ghost">Manage account</button>
            </section>

            <section class="card settings-section">
                <h3>General</h3>
                <div class="settings-item">
                    <div>
                        <p>Appearance</p>
                        <span>Switch between light and dark themes.</span>
                    </div>
                    <button class="btn ghost" data-theme-toggle>Auto</button>
                </div>
                <div class="settings-item">
                    <div>
                        <p>Language pack</p>
                        <span>Install additional interface languages.</span>
                    </div>
                    <button class="btn ghost">Open</button>
                </div>
                <div class="settings-item">
                    <div>
                        <p>App icon</p>
                        <span>Choose an icon for your home screen.</span>
                    </div>
                    <button class="btn ghost">Default</button>
                </div>
            </section>

            <section class="card settings-section">
                <h3>Notifications & Feedback</h3>
                <div class="settings-item">
                    <div>
                        <p>Reminders</p>
                        <span>Receive notifications to study cards.</span>
                    </div>
                    <button class="switch" data-toggle="reminders" aria-pressed="false">
                        <span class="sr-only">Reminders off</span>
                    </button>
                </div>
                <div class="settings-item">
                    <div>
                        <p>Haptic feedback</p>
                        <span>Vibrate on correct and incorrect answers.</span>
                    </div>
                    <button class="switch" data-toggle="haptics" aria-pressed="false">
                        <span class="sr-only">Haptics off</span>
                    </button>
                </div>
            </section>

            <section class="card settings-section">
                <h3>About</h3>
                <div class="settings-item link">
                    <span>What's new</span>
                    <a href="#">View</a>
                </div>
                <div class="settings-item link">
                    <span>Help center</span>
                    <a href="#">Open</a>
                </div>
                <div class="settings-item link">
                    <span>Privacy policy</span>
                    <a href="#">Read</a>
                </div>
                <div class="settings-item link">
                    <span>Terms of use</span>
                    <a href="#">Read</a>
                </div>
                <div class="settings-item link">
                    <span>Send feedback</span>
                    <a href="mailto:hello@example.com">Email</a>
                </div>
                <div class="settings-item link">
                    <span>Rate us</span>
                    <a href="#">Open store</a>
                </div>
            </section>

            <section class="card settings-section">
                <h3>Text-to-speech</h3>
                <form method="post" action="index.php?a=update_tts" class="tts-form">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
                    <div class="settings-item">
                        <div>
                            <p>Front side language</p>
                            <span>Choose the language of the prompt.</span>
                        </div>
                        <select name="front_lang">
                            <option value="en-US" <?= ($selectedDeck['tts_front_lang'] ?? '') === 'en-US' ? 'selected' : '' ?>>English (US)</option>
                            <option value="en-GB" <?= ($selectedDeck['tts_front_lang'] ?? '') === 'en-GB' ? 'selected' : '' ?>>English (UK)</option>
                            <option value="ru-RU" <?= ($selectedDeck['tts_front_lang'] ?? '') === 'ru-RU' ? 'selected' : '' ?>>Russian</option>
                            <option value="he-IL" <?= ($selectedDeck['tts_front_lang'] ?? '') === 'he-IL' ? 'selected' : '' ?>>Hebrew</option>
                        </select>
                    </div>
                    <div class="settings-item">
                        <div>
                            <p>Back side language</p>
                            <span>Language used for answers.</span>
                        </div>
                        <select name="back_lang">
                            <option value="he-IL" <?= ($selectedDeck['tts_back_lang'] ?? '') === 'he-IL' ? 'selected' : '' ?>>Hebrew</option>
                            <option value="en-US" <?= ($selectedDeck['tts_back_lang'] ?? '') === 'en-US' ? 'selected' : '' ?>>English (US)</option>
                            <option value="ru-RU" <?= ($selectedDeck['tts_back_lang'] ?? '') === 'ru-RU' ? 'selected' : '' ?>>Russian</option>
                        </select>
                    </div>
                    <button class="btn primary" type="submit">Save TTS preferences</button>
                </form>

                <?php if ($deckSample): ?>
                    <div class="tts-preview" data-tts-sample>
                        <div class="tts-preview-card">
                            <h4><?= h($deckSample['is_reversed'] ? ($deckSample['meaning'] ?? '') : ($deckSample['hebrew'] ?? '')) ?></h4>
                            <p><?= h($deckSample['is_reversed'] ? ($deckSample['hebrew'] ?? '') : ($deckSample['meaning'] ?? '')) ?></p>
                        </div>
                        <button class="btn primary" type="button" data-tts-play>Play preview</button>
                    </div>
                <?php endif; ?>
            </section>

            <section class="card settings-section">
                <h3>Follow us</h3>
                <div class="social-row">
                    <a href="https://instagram.com" class="social instagram" target="_blank" rel="noreferrer">Instagram</a>
                    <a href="https://youtube.com" class="social youtube" target="_blank" rel="noreferrer">YouTube</a>
                    <a href="https://facebook.com" class="social facebook" target="_blank" rel="noreferrer">Facebook</a>
                </div>
                <p class="app-version">App version: 2.13.5 (1758135553)</p>
            </section>
        </section>
    </main>

    <nav class="bottom-nav" aria-label="Primary">
        <a href="?screen=home" class="bottom-nav-item" data-nav="home" aria-current="<?= $screen === 'home' ? 'page' : 'false' ?>">Home</a>
        <a href="?screen=library" class="bottom-nav-item" data-nav="library" aria-current="<?= $screen === 'library' ? 'page' : 'false' ?>">Library</a>
        <a href="?screen=settings" class="bottom-nav-item" data-nav="settings" aria-current="<?= $screen === 'settings' ? 'page' : 'false' ?>">Settings</a>
    </nav>
</div>

<div class="deck-sheet" id="deck-sheet" role="dialog" aria-modal="true" aria-labelledby="deck-sheet-title" hidden>
    <div class="deck-sheet-content" tabindex="-1">
        <header>
            <h4 id="deck-sheet-title">Deck actions</h4>
            <button type="button" class="deck-sheet-close" data-deck-sheet-close aria-label="Close">Close</button>
        </header>
        <div class="deck-sheet-actions">
            <form method="post" action="index.php?a=select_deck">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="deck_id" value="">
                <button class="sheet-action" type="submit">Select</button>
            </form>
            <form method="post" action="index.php?a=toggle_deck_flag" data-sheet-toggle="is_frozen">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="deck_id" value="">
                <input type="hidden" name="flag" value="is_frozen">
                <input type="hidden" name="value" value="1">
                <button class="sheet-action" type="submit">Freeze</button>
            </form>
            <form method="post" action="index.php?a=move_deck">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="deck_id" value="">
                <input type="hidden" name="category" value="General">
                <button class="sheet-action" type="submit">Move</button>
            </form>
            <form method="post" action="index.php?a=toggle_deck_flag" data-sheet-toggle="is_reversed">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="deck_id" value="">
                <input type="hidden" name="flag" value="is_reversed">
                <input type="hidden" name="value" value="1">
                <button class="sheet-action" type="submit">Reverse</button>
            </form>
            <form method="post" action="index.php?a=duplicate_deck">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="deck_id" value="">
                <button class="sheet-action" type="submit">Duplicate</button>
            </form>
            <a class="sheet-action" href="?screen=library#deck-history">Learning history</a>
            <form method="post" action="index.php?a=delete_deck" onsubmit="return confirm('Delete this deck?');">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="deck_id" value="">
                <button class="sheet-action danger" type="submit">Delete</button>
            </form>
        </div>
    </div>
</div>

<div class="toast" id="global-toast" role="status" aria-live="polite" hidden></div>

<div class="dialog" id="dialog-create-deck" role="dialog" aria-modal="true" aria-labelledby="dialog-create-deck-title" hidden>
    <form class="dialog-content" method="post" action="index.php?a=create_deck" tabindex="-1">
        <h3 id="dialog-create-deck-title">Create a new deck</h3>
        <p>Organise cards by topic or difficulty for quick study sessions.</p>
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <label for="dialog-deck-name">Deck name</label>
        <input id="dialog-deck-name" name="name" required>
        <label for="dialog-deck-description">Description</label>
        <textarea id="dialog-deck-description" name="description" rows="2" placeholder="e.g., Law terms"></textarea>
        <label for="dialog-deck-category">Category</label>
        <input id="dialog-deck-category" name="category" placeholder="Law, Geography, Math...">
        <div class="dialog-actions">
            <button type="button" class="btn ghost" data-dialog-close aria-label="Close dialog">Cancel</button>
            <button type="submit" class="btn primary">Create</button>
        </div>
    </form>
</div>

<script type="application/json" id="memory-data"><?= json_encode($memoryData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?></script>
<script type="application/json" id="starter-phrases"><?= json_encode($starterPhrases, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?></script>
<script src="app.js" defer></script>
</body>
</html>
