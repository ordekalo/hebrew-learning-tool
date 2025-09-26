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

function render_icon_svg(string $name): string
{
    $icons = [
        'sparkles' => [
            'label' => 'Sparkles',
            'markup' => '<path fill="currentColor" d="M12 2.5l1.3 4.1 4.2.2-3.3 2.6 1.1 4-3.3-2.2-3.3 2.2 1.1-4-3.3-2.6 4.2-.2z"/><path fill="currentColor" opacity=".6" d="M5 14.5l.8 2.5 2.6.1-2 1.5.7 2.4-2.1-1.4-2.1 1.4.7-2.4-2-1.5 2.6-.1z"/><path fill="currentColor" opacity=".6" d="M18 14l.6 1.8 1.9.1-1.4 1.1.5 1.8-1.6-1-1.6 1 .5-1.8-1.4-1.1 1.9-.1z"/>'
        ],
        'book' => [
            'label' => 'Book',
            'markup' => '<path fill="currentColor" d="M5 4.5a2.5 2.5 0 0 1 2.5-2.5H13a2 2 0 0 1 2 2v15a1 1 0 0 1-1.5.87L12 18.9l-1.5 1.47A1 1 0 0 1 9 19V4.5a.5.5 0 0 0-.5-.5H5z"/><path fill="currentColor" opacity=".6" d="M14.5 2H18a2 2 0 0 1 2 2v15a1 1 0 0 1-1.54.83L17 18.7V5a3 3 0 0 0-2.5-3z"/>'
        ],
        'globe' => [
            'label' => 'Globe',
            'markup' => '<circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M3 12h18" stroke="currentColor" stroke-width="1.5"/><path d="M12 3a9.5 9.5 0 0 1 0 18c-2.5-2.5-3.5-5.5-3.5-9S9.5 5.5 12 3z" fill="none" stroke="currentColor" stroke-width="1.5"/>'
        ],
        'lightbulb' => [
            'label' => 'Lightbulb',
            'markup' => '<path fill="currentColor" d="M12 2a6 6 0 0 1 3.5 10.9V15a1 1 0 0 1-.3.7l-1.2 1.2v1.6a1 1 0 0 1-1 1h-1a1 1 0 0 1-1-1v-1.6l-1.2-1.2a1 1 0 0 1-.3-.7v-2.1A6 6 0 0 1 12 2z"/><path fill="currentColor" opacity=".6" d="M10 20h4v1a1 1 0 0 1-1 1h-2a1 1 0 0 1-1-1v-1z"/>'
        ],
        'star' => [
            'label' => 'Star',
            'markup' => '<path fill="currentColor" d="M12 3.2l2 4 4.4.6-3.2 3.1.8 4.3-4-2.1-4 2.1.8-4.3L5.6 7.8l4.4-.6z"/>'
        ],
        'leaf' => [
            'label' => 'Leaf',
            'markup' => '<path fill="currentColor" d="M20 4c-7 0-11.5 3.5-14 9 2.6-.1 4.6-1 6-3-1.2 4.5-3.6 6.7-7.3 7.3 2.3 2 5 2.7 7.3 2.7 6.1 0 9.9-3.9 9.9-10.3V4z" opacity=".85"/><path fill="currentColor" opacity=".6" d="M10.5 12.5c-.5 2.9-2.1 5-4.5 6.5"/>'
        ],
        'compass' => [
            'label' => 'Compass',
            'markup' => '<circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.5"/><path fill="currentColor" d="M9.5 9.5 15 7l-2.5 5.5L9 17z"/>'
        ],
        'puzzle' => [
            'label' => 'Puzzle',
            'markup' => '<path fill="currentColor" d="M9 3a2 2 0 1 1 4 0h2.5A1.5 1.5 0 0 1 17 4.5V8h1.5a1.5 1.5 0 1 1 0 3H17v3.5A1.5 1.5 0 0 1 15.5 16H13a2 2 0 1 1-4 0H6.5A1.5 1.5 0 0 1 5 14.5V11H3.5a1.5 1.5 0 1 1 0-3H5V4.5A1.5 1.5 0 0 1 6.5 3H9z"/>'
        ],
    ];

    $icon = $icons[$name] ?? $icons['sparkles'];
    $label = h($icon['label']);

    return '<svg class="icon-svg" role="img" aria-label="' . $label . '" viewBox="0 0 24 24" focusable="false" aria-hidden="false">' . $icon['markup'] . '</svg>';
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
    flash('Word added to your decks.', 'success');
    redirect('index.php?screen=home');
}

if ($action === 'seed_deck' && is_post()) {
    check_token($_POST['csrf'] ?? null);

    $deckId = (int) ($_POST['deck_id'] ?? $selectedDeckId);
    if ($deckId <= 0) {
        flash('Deck not found.', 'error');
        redirect('index.php?screen=home');
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM deck_words WHERE deck_id = ?');
    $stmt->execute([$deckId]);
    $existing = (int) $stmt->fetchColumn();

    if ($existing > 0) {
        flash('Starter phrases can only be added to an empty deck.', 'error');
        redirect('index.php?screen=home');
    }

    $seeds = [
        ['hebrew' => 'שלום', 'transliteration' => 'shalom', 'meaning' => 'Hello', 'lang' => 'en', 'example' => 'שלום! נעים להכיר.'],
        ['hebrew' => 'תודה', 'transliteration' => 'toda', 'meaning' => 'Thank you', 'lang' => 'en', 'example' => 'תודה על העזרה.'],
        ['hebrew' => 'בבקשה', 'transliteration' => 'bevakasha', 'meaning' => 'Please / You’re welcome', 'lang' => 'en', 'example' => 'כן, בבקשה.'],
        ['hebrew' => 'סליחה', 'transliteration' => 'sliḥa', 'meaning' => 'Excuse me', 'lang' => 'en', 'example' => 'סליחה, איפה התחנה?'],
        ['hebrew' => 'מה שלומך?', 'transliteration' => 'ma shlomkha?', 'meaning' => 'How are you?', 'lang' => 'en', 'example' => 'מה שלומך היום?'],
        ['hebrew' => 'אני לא מבין', 'transliteration' => 'ani lo mevin', 'meaning' => 'I don’t understand', 'lang' => 'en', 'example' => 'סליחה, אני לא מבין עברית טוב.'],
        ['hebrew' => 'אפשר לקבל...', 'transliteration' => 'efshar lekabel…', 'meaning' => 'May I have…', 'lang' => 'en', 'example' => 'אפשר לקבל מים בבקשה?'],
        ['hebrew' => 'כמה זה עולה?', 'transliteration' => 'kama ze oleh?', 'meaning' => 'How much does it cost?', 'lang' => 'en', 'example' => 'כמה זה עולה כרטיס?'],
        ['hebrew' => 'איפה השירותים?', 'transliteration' => 'eifo hasherutim?', 'meaning' => 'Where is the restroom?', 'lang' => 'en', 'example' => 'סליחה, איפה השירותים?'],
        ['hebrew' => 'להתראות', 'transliteration' => 'lehitraot', 'meaning' => 'See you', 'lang' => 'en', 'example' => 'להתראות, נתראה מחר.'],
    ];

    $pdo->beginTransaction();
    try {
        foreach ($seeds as $seed) {
            $insertWord = $pdo->prepare('INSERT INTO words (hebrew, transliteration, part_of_speech, notes, audio_path) VALUES (?, ?, ?, ?, ?)');
            $insertWord->execute([
                $seed['hebrew'],
                $seed['transliteration'],
                'phrase',
                null,
                null,
            ]);
            $wordId = (int) $pdo->lastInsertId();

            $insertTranslation = $pdo->prepare('INSERT INTO translations (word_id, lang_code, meaning, example) VALUES (?, ?, ?, ?)');
            $insertTranslation->execute([
                $wordId,
                $seed['lang'],
                $seed['meaning'],
                $seed['example'],
            ]);

            add_word_to_deck($pdo, $deckId, $wordId);
        }
        $pdo->commit();
        flash('Starter phrases added to your deck.', 'success');
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash('Could not seed starter phrases.', 'error');
    }

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
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hebrew Study Hub</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body class="app-body" data-screen="<?= h($screen) ?>">
<div class="app-shell">
    <div class="toast-container" id="toast-container" aria-live="polite" aria-atomic="true"></div>
    <header class="app-header">
        <div class="header-main">
            <h1>Hebrew Study Hub</h1>
            <p class="header-sub">Tailored decks, smart drills, and responsive design inspired by mobile-first study apps.</p>
        </div>
        <form method="get" action="index.php" class="search-form">
            <input type="hidden" name="screen" value="<?= h($screen) ?>">
            <input type="text" name="q" placeholder="Search cards" value="<?= h($searchTerm) ?>">
            <button class="btn primary" type="submit">Search</button>
        </form>
    </header>

    <?php if ($flash): ?>
        <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <main class="app-content">
        <section class="screen" data-screen="home" <?= $screen === 'home' ? '' : 'hidden' ?>>
            <?php $selectedDeckCards = (int) ($selectedDeck['cards_count'] ?? 0); ?>
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
                        <?php if ($selectedDeckCards > 0): ?>
                            <a class="btn primary" href="#flashcards">Start session</a>
                        <?php else: ?>
                            <a class="btn primary" href="#quick-add-form">Create first card</a>
                            <form method="post" action="index.php?a=seed_deck" class="inline-form" id="seed-phrases-form">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
                                <button class="btn ghost" type="submit">+ Add 10 starter phrases</button>
                            </form>
                        <?php endif; ?>
                        <a class="btn ghost" href="?screen=library">Deck settings</a>
                    </div>
                </div>
                <div class="hero-illustration">
                    <span class="deck-icon"><?= render_icon_svg($selectedDeck['icon'] ?? 'sparkles') ?></span>
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
                        <label class="sr-only" for="language-filter">Choose language</label>
                        <select id="language-filter" name="lang">
                            <option value="">All languages</option>
                            <option value="en">English</option>
                            <option value="ru">Русский</option>
                            <option value="ar">العربية</option>
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
                    <p>No cards yet in this deck. Add words using the quick form.</p>
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
                <div class="memory-status" <?= empty($memoryPairs) ? 'hidden' : '' ?>>
                    <span>Matches: <strong id="memory-matches">0</strong> / <?= count($memoryPairs) ?></span>
                    <span id="memory-feedback" role="status" aria-live="polite"></span>
                </div>
                <div class="memory-board" id="memory-board" aria-label="Memory trainer board"></div>
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
                            <input id="hebrew" name="hebrew" required placeholder="לְדֻגְמָה" dir="rtl" spellcheck="false" autocomplete="off">
                        </div>
                        <div>
                            <label for="transliteration">Transliteration</label>
                            <input id="transliteration" name="transliteration" placeholder="le-dugma">
                        </div>
                        <div>
                            <label for="part_of_speech">Part of speech</label>
                            <select id="part_of_speech" name="part_of_speech">
                                <option value="">Select…</option>
                                <option value="noun">Noun</option>
                                <option value="verb">Verb</option>
                                <option value="adj">Adjective</option>
                                <option value="adv">Adverb</option>
                                <option value="phrase">Phrase</option>
                                <option value="prep">Preposition</option>
                                <option value="conj">Conjunction</option>
                            </select>
                        </div>
                    </div>
                    <label for="notes">Notes</label>
                    <textarea id="notes" name="notes" rows="3" placeholder="Any nuances, gender, irregular forms..."></textarea>

                    <label for="audio">Pronunciation (audio/mp3/wav/ogg ≤ 10MB)</label>
                    <div class="record-row">
                        <input id="audio" type="file" name="audio" accept="audio/*">
                        <div class="record-controls" id="record-controls">
                            <button type="button" class="btn ghost" id="record-toggle" data-state="idle">🎙️ Record</button>
                            <button type="button" class="btn primary" id="record-save" disabled>Use recording</button>
                        </div>
                    </div>
                    <p class="form-error" id="audio-error" role="status" aria-live="polite"></p>
                    <div class="record-preview" id="record-preview" hidden>
                        <audio id="recorded-audio" controls></audio>
                        <button type="button" class="btn ghost" id="record-discard">Discard</button>
                    </div>

                    <div class="grid grid-3 responsive">
                        <div>
                            <label for="lang_code">Translation language</label>
                            <input id="lang_code" name="lang_code" placeholder="e.g., ru, en, fr" pattern="^[a-z]{2}(-[A-Z]{2})?$">
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
                    <button class="filter-btn active" data-filter="all">All (<span data-filter-count="all">0</span>)</button>
                    <button class="filter-btn" data-filter="popular">Popular (<span data-filter-count="popular">0</span>)</button>
                    <?php foreach ($categories as $category): ?>
                        <button class="filter-btn" data-filter="<?= h($category) ?>"><?= h($category) ?></button>
                    <?php endforeach; ?>
                </div>

                <div class="deck-grid" id="deck-grid">
                    <?php foreach ($decks as $deck): ?>
                        <article class="deck-card" data-category="<?= h($deck['category'] ?? 'General') ?>" data-popular="<?= ((float) $deck['rating'] >= 4.5 || (int) $deck['learners_count'] >= 200) ? '1' : '0' ?>" data-frozen="<?= (int) ($deck['is_frozen'] ?? 0) ?>" data-reversed="<?= (int) ($deck['is_reversed'] ?? 0) ?>">
                            <header class="deck-card-header" style="--deck-accent: <?= h($deck['color'] ?? '#6366f1') ?>;">
                                <div class="deck-card-icon"><?= render_icon_svg($deck['icon'] ?? 'book') ?></div>
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
                                <button class="switch <?= (int) ($selectedDeck['ai_generation_enabled'] ?? 0) ? 'on' : '' ?>" type="submit" aria-pressed="<?= (int) ($selectedDeck['ai_generation_enabled'] ?? 0) ? 'true' : 'false' ?>"><span class="sr-only"><?= (int) ($selectedDeck['ai_generation_enabled'] ?? 0) ? 'On' : 'Off' ?></span></button>
                            </form>
                            <form method="post" action="index.php?a=toggle_deck_flag" class="toggle-row">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
                                <input type="hidden" name="flag" value="offline_enabled">
                                <input type="hidden" name="value" value="<?= (int) ($selectedDeck['offline_enabled'] ?? 0) ? 0 : 1 ?>">
                                <label>Offline learning</label>
                                <button class="switch <?= (int) ($selectedDeck['offline_enabled'] ?? 0) ? 'on' : '' ?>" type="submit" aria-pressed="<?= (int) ($selectedDeck['offline_enabled'] ?? 0) ? 'true' : 'false' ?>"><span class="sr-only"><?= (int) ($selectedDeck['offline_enabled'] ?? 0) ? 'On' : 'Off' ?></span></button>
                            </form>
                            <form method="post" action="index.php?a=toggle_deck_flag" class="toggle-row">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
                                <input type="hidden" name="flag" value="is_reversed">
                                <input type="hidden" name="value" value="<?= (int) ($selectedDeck['is_reversed'] ?? 0) ? 0 : 1 ?>">
                                <label>Reverse cards</label>
                                <button class="switch <?= (int) ($selectedDeck['is_reversed'] ?? 0) ? 'on' : '' ?>" type="submit" aria-pressed="<?= (int) ($selectedDeck['is_reversed'] ?? 0) ? 'true' : 'false' ?>"><span class="sr-only"><?= (int) ($selectedDeck['is_reversed'] ?? 0) ? 'On' : 'Off' ?></span></button>
                            </form>
                            <form method="post" action="index.php?a=toggle_deck_flag" class="toggle-row">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
                                <input type="hidden" name="flag" value="is_frozen">
                                <input type="hidden" name="value" value="<?= (int) ($selectedDeck['is_frozen'] ?? 0) ? 0 : 1 ?>">
                                <label>Freeze deck</label>
                                <button class="switch <?= (int) ($selectedDeck['is_frozen'] ?? 0) ? 'on' : '' ?>" type="submit" aria-pressed="<?= (int) ($selectedDeck['is_frozen'] ?? 0) ? 'true' : 'false' ?>"><span class="sr-only"><?= (int) ($selectedDeck['is_frozen'] ?? 0) ? 'On' : 'Off' ?></span></button>
                            </form>
                            <form method="post" action="index.php?a=toggle_deck_flag" class="toggle-row">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
                                <input type="hidden" name="flag" value="tts_enabled">
                                <input type="hidden" name="value" value="<?= (int) ($selectedDeck['tts_enabled'] ?? 0) ? 0 : 1 ?>">
                                <label>Text-to-speech</label>
                                <button class="switch <?= (int) ($selectedDeck['tts_enabled'] ?? 0) ? 'on' : '' ?>" type="submit" aria-pressed="<?= (int) ($selectedDeck['tts_enabled'] ?? 0) ? 'true' : 'false' ?>"><span class="sr-only"><?= (int) ($selectedDeck['tts_enabled'] ?? 0) ? 'On' : 'Off' ?></span></button>
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

                    <form class="publish-card" method="post" action="index.php?a=publish_deck" data-cards-count="<?= (int) ($selectedDeck['cards_count'] ?? 0) ?>">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
                        <h4>Publish in library</h4>
                        <p>Add a short description to share this deck publicly. You need at least <?= (int) ($selectedDeck['min_cards_required'] ?? 75) ?> cards.</p>
                        <textarea name="publish_description" rows="2" placeholder="Enter text here (30 characters minimum)" minlength="30" required id="publish-description"></textarea>
                        <button class="btn primary" type="submit" disabled id="publish-btn">Submit for review</button>
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
                    <button class="switch" data-toggle="reminders"><span class="sr-only">Off</span></button>
                </div>
                <div class="settings-item">
                    <div>
                        <p>Haptic feedback</p>
                        <span>Vibrate on correct and incorrect answers.</span>
                    </div>
                    <button class="switch" data-toggle="haptics"><span class="sr-only">Off</span></button>
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
        <a href="?screen=home" class="bottom-nav-item" data-nav="home"<?= $screen === 'home' ? ' aria-current="page"' : '' ?>>Home</a>
        <a href="?screen=library" class="bottom-nav-item" data-nav="library"<?= $screen === 'library' ? ' aria-current="page"' : '' ?>>Library</a>
        <a href="?screen=settings" class="bottom-nav-item" data-nav="settings"<?= $screen === 'settings' ? ' aria-current="page"' : '' ?>>Settings</a>
    </nav>
</div>

<div class="deck-sheet" id="deck-sheet" role="dialog" aria-modal="true" aria-labelledby="deck-sheet-title" hidden tabindex="-1">
    <div class="deck-sheet-content">
        <header>
            <h4 id="deck-sheet-title">Deck actions</h4>
            <button type="button" class="deck-sheet-close" data-deck-sheet-close data-focus-initial="true">Close</button>
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

<div class="dialog" id="dialog-create-deck" role="dialog" aria-modal="true" aria-labelledby="create-deck-title" hidden tabindex="-1">
    <form class="dialog-content" method="post" action="index.php?a=create_deck">
        <h3 id="create-deck-title">Create a new deck</h3>
        <p>Organise cards by topic or difficulty for quick study sessions.</p>
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <label for="dialog-deck-name">Deck name</label>
        <input id="dialog-deck-name" name="name" required data-focus-initial="true">
        <label for="dialog-deck-description">Description</label>
        <textarea id="dialog-deck-description" name="description" rows="2" placeholder="e.g., Law terms"></textarea>
        <label for="dialog-deck-category">Category</label>
        <input id="dialog-deck-category" name="category" placeholder="Law, Geography, Math...">
        <div class="dialog-actions">
            <button type="button" class="btn ghost" data-dialog-close>Cancel</button>
            <button type="submit" class="btn primary">Create</button>
        </div>
    </form>
</div>

    <script type="application/json" id="memory-data"><?= json_encode($memoryData ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?></script>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const body = document.body;
    const sections = document.querySelectorAll('.screen');
    const navItems = document.querySelectorAll('.bottom-nav-item');
    const searchScreenField = document.querySelector('.search-form input[name="screen"]');
    const params = new URLSearchParams(window.location.search);
    const queryScreen = params.get('screen');
    const storedScreen = sessionStorage.getItem('hebrew-active-screen');
    const defaultScreen = body.dataset.screen || 'home';

    const screenExists = (name) => Array.from(sections).some((section) => section.dataset.screen === name);

    const setNavActive = (screenName) => {
        navItems.forEach((item) => {
            const isActive = item.dataset.nav === screenName;
            item.classList.toggle('active', isActive);
            if (isActive) {
                item.setAttribute('aria-current', 'page');
            } else {
                item.removeAttribute('aria-current');
            }
        });
    };

    const showScreen = (targetScreen) => {
        const resolved = screenExists(targetScreen) ? targetScreen : defaultScreen;
        sections.forEach((section) => {
            section.hidden = section.dataset.screen !== resolved;
        });
        body.dataset.screen = resolved;
        if (searchScreenField) {
            searchScreenField.value = resolved;
        }
        setNavActive(resolved);
        sessionStorage.setItem('hebrew-active-screen', resolved);
    };

    const initialScreen = queryScreen || storedScreen || defaultScreen;
    showScreen(initialScreen);

    navItems.forEach((item) => {
        item.addEventListener('click', () => {
            sessionStorage.setItem('hebrew-active-screen', item.dataset.nav || defaultScreen);
        });
    });

    const languageFilter = document.getElementById('language-filter');
    if (languageFilter) {
        const languageStorageKey = 'hebrew-language-filter';
        const storedLang = window.localStorage.getItem(languageStorageKey) || '';
        const currentLang = params.get('lang');
        const initialLang = currentLang ?? storedLang ?? '';
        if (initialLang && languageFilter.querySelector(`option[value="${initialLang}"]`)) {
            languageFilter.value = initialLang;
        } else {
            languageFilter.value = '';
        }
        if (currentLang) {
            window.localStorage.setItem(languageStorageKey, currentLang);
        }
        languageFilter.addEventListener('change', () => {
            const selected = languageFilter.value;
            const next = new URLSearchParams(window.location.search);
            if (selected) {
                next.set('lang', selected);
                window.localStorage.setItem(languageStorageKey, selected);
            } else {
                next.delete('lang');
                window.localStorage.removeItem(languageStorageKey);
            }
            next.set('screen', body.dataset.screen || 'home');
            window.location.search = next.toString();
        });
    }

    const focusState = new Map();
    const getFocusableElements = (container) => {
        if (!container) {
            return [];
        }
        return Array.from(container.querySelectorAll(
            'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )).filter((el) => !el.hasAttribute('hidden'));
    };

    function closeModal(dialog) {
        if (!dialog) {
            return;
        }
        dialog.setAttribute('hidden', 'hidden');
        dialog.removeAttribute('data-open');
        dialog.removeEventListener('keydown', modalKeydown);
        const state = focusState.get(dialog) || {};
        if (state.opener instanceof HTMLElement) {
            state.opener.focus();
        }
        focusState.delete(dialog);
    }

    function modalKeydown(event) {
        if (!(event.currentTarget instanceof HTMLElement)) {
            return;
        }
        if (event.key === 'Escape') {
            event.preventDefault();
            closeModal(event.currentTarget);
            return;
        }
        if (event.key !== 'Tab') {
            return;
        }
        const focusable = getFocusableElements(event.currentTarget);
        if (focusable.length === 0) {
            event.preventDefault();
            event.currentTarget.focus();
            return;
        }
        const currentIndex = focusable.indexOf(document.activeElement);
        let nextIndex = currentIndex;
        if (event.shiftKey) {
            nextIndex = currentIndex <= 0 ? focusable.length - 1 : currentIndex - 1;
        } else {
            nextIndex = currentIndex === focusable.length - 1 ? 0 : currentIndex + 1;
        }
        focusable[nextIndex].focus();
        event.preventDefault();
    }

    function openModal(dialog, opener) {
        if (!dialog) {
            return;
        }
        dialog.removeAttribute('hidden');
        dialog.setAttribute('data-open', 'true');
        const focusable = getFocusableElements(dialog);
        const initial = focusable.find((node) => node.getAttribute('data-focus-initial') === 'true') || focusable[0] || dialog;
        setTimeout(() => {
            initial.focus();
        }, 0);
        dialog.addEventListener('keydown', modalKeydown);
        focusState.set(dialog, { opener });
    }

    const toastContainer = document.getElementById('toast-container');
    const showToast = (message, variant = 'info') => {
        if (!toastContainer || !message) {
            return;
        }
        const toast = document.createElement('div');
        toast.className = `toast toast-${variant}`;
        toast.role = 'status';
        toast.textContent = message;
        toastContainer.appendChild(toast);
        requestAnimationFrame(() => {
            toast.classList.add('visible');
        });
        setTimeout(() => {
            toast.classList.remove('visible');
            setTimeout(() => {
                toast.remove();
            }, 300);
        }, 4000);
    };

    document.querySelectorAll('.flash').forEach((flash) => {
        const message = flash.textContent.trim();
        const variant = flash.classList.contains('success') ? 'success' : flash.classList.contains('error') ? 'error' : 'info';
        showToast(message, variant);
        flash.remove();
    });

    const memoryDataEl = document.getElementById('memory-data');
    const board = document.getElementById('memory-board');
    const matchesEl = document.getElementById('memory-matches');
    const feedbackEl = document.getElementById('memory-feedback');
    const resetBtn = document.getElementById('memory-reset');

    if (memoryDataEl && board && matchesEl) {
        const basePairs = JSON.parse(memoryDataEl.textContent || '[]');
        const statusWrapper = document.querySelector('.memory-status');
        const matchedPairs = new Set();
        let flipped = [];

        const pickLabel = (item) => item.meaning || item.other_script || item.transliteration || '—';

        if (resetBtn) {
            resetBtn.disabled = basePairs.length === 0;
        }
        if (statusWrapper) {
            statusWrapper.hidden = basePairs.length === 0;
        }

        const buildDeck = () => {
            const deck = [];
            basePairs.forEach((item) => {
                deck.push({ pairId: String(item.id), type: 'hebrew', label: item.hebrew, announce: `Hebrew: ${item.hebrew}` });
                deck.push({ pairId: String(item.id), type: 'translation', label: pickLabel(item), announce: `Translation: ${pickLabel(item)}` });
            });
            for (let i = deck.length - 1; i > 0; i -= 1) {
                const j = Math.floor(Math.random() * (i + 1));
                [deck[i], deck[j]] = [deck[j], deck[i]];
            }
            return deck;
        };

        const clearBoardState = () => {
            matchedPairs.clear();
            flipped = [];
            matchesEl.textContent = '0';
            if (feedbackEl) {
                feedbackEl.textContent = '';
            }
        };

        const announceMatch = () => {
            if (!feedbackEl) {
                return;
            }
            if (matchedPairs.size === basePairs.length) {
                feedbackEl.textContent = 'All pairs matched! Great job!';
            } else {
                feedbackEl.textContent = `Matched ${matchedPairs.size} of ${basePairs.length} pairs.`;
            }
        };

        const renderBoard = () => {
            if (basePairs.length === 0) {
                clearBoardState();
                board.innerHTML = '';
                return;
            }
            const deck = buildDeck();
            clearBoardState();
            board.innerHTML = '';
            deck.forEach((card, index) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'memory-card';
                button.dataset.pairId = card.pairId;
                button.dataset.type = card.type;
                button.setAttribute('aria-label', card.announce);
                button.setAttribute('data-index', String(index));

                const span = document.createElement('span');
                span.textContent = card.label;
                button.appendChild(span);

                button.addEventListener('click', () => handleFlip(button));
                board.appendChild(button);
            });
        };

        const unflipCards = (first, second) => {
            setTimeout(() => {
                first.classList.remove('flipped');
                second.classList.remove('flipped');
                first.disabled = false;
                second.disabled = false;
                flipped = [];
            }, 900);
        };

        const handleFlip = (button) => {
            if (button.classList.contains('matched') || flipped.includes(button)) {
                return;
            }

            button.classList.add('flipped');
            button.disabled = true;
            flipped.push(button);

            if (flipped.length === 2) {
                const [first, second] = flipped;
                const isMatch = first.dataset.pairId === second.dataset.pairId && first.dataset.type !== second.dataset.type;
                if (isMatch) {
                    first.classList.add('matched');
                    second.classList.add('matched');
                    matchedPairs.add(first.dataset.pairId || '');
                    matchesEl.textContent = String(matchedPairs.size);
                    flipped = [];
                    announceMatch();
                } else {
                    unflipCards(first, second);
                }
            }
        };

        resetBtn?.addEventListener('click', () => {
            renderBoard();
        });

        renderBoard();
    }

    const publishForm = document.querySelector('.publish-card');
    const publishBtn = document.getElementById('publish-btn');
    const publishDescription = document.getElementById('publish-description');
    if (publishForm && publishBtn && publishDescription) {
        const cardsCount = Number(publishForm.dataset.cardsCount || '0');
        const updatePublishState = () => {
            const meetsDescription = publishDescription.value.trim().length >= 30;
            publishBtn.disabled = !(cardsCount >= 75 && meetsDescription);
        };
        updatePublishState();
        publishDescription.addEventListener('input', updatePublishState);
    }

    const recordToggle = document.getElementById('record-toggle');
    const recordSave = document.getElementById('record-save');
    const recordDiscard = document.getElementById('record-discard');
    const recordPreview = document.getElementById('record-preview');
    const recordedAudioElement = document.getElementById('recorded-audio');
    const recordedAudioInput = document.getElementById('recorded_audio');
    const fileInput = document.getElementById('audio');
    const audioError = document.getElementById('audio-error');
    const maxAudioSize = 10 * 1024 * 1024;

    let mediaRecorder = null;
    let audioChunks = [];
    let mediaStream = null;
    let recordedBlob = null;

    const stopStream = () => {
        if (mediaStream) {
            mediaStream.getTracks().forEach((track) => track.stop());
            mediaStream = null;
        }
    };

    const resetRecording = () => {
        audioChunks = [];
        recordedBlob = null;
        recordedAudioInput.value = '';
        recordSave.disabled = true;
        recordPreview.hidden = true;
        recordedAudioElement.src = '';
        stopStream();
        if (recordToggle) {
            recordToggle.dataset.state = 'idle';
            recordToggle.textContent = '🎙️ Record';
        }
    };

    const enableRecordingUI = (enabled) => {
        if (fileInput) {
            fileInput.disabled = !enabled;
        }
        if (recordToggle) {
            recordToggle.disabled = !enabled;
        }
    };

    fileInput?.addEventListener('change', () => {
        if (audioError) {
            audioError.textContent = '';
        }
        const file = fileInput.files?.[0];
        if (!file) {
            return;
        }
        if (!file.type.startsWith('audio/')) {
            if (audioError) {
                audioError.textContent = 'Please choose a valid audio file (mp3, wav, ogg).';
            }
            fileInput.value = '';
            return;
        }
        if (file.size > maxAudioSize) {
            if (audioError) {
                audioError.textContent = 'Audio file must be 10MB or smaller.';
            }
            fileInput.value = '';
        }
    });

    const startRecording = async () => {
        try {
            mediaStream = await navigator.mediaDevices.getUserMedia({ audio: true });
            mediaRecorder = new MediaRecorder(mediaStream);
            audioChunks = [];

            mediaRecorder.addEventListener('dataavailable', (event) => {
                if (event.data.size > 0) {
                    audioChunks.push(event.data);
                }
            });

            mediaRecorder.addEventListener('stop', () => {
                recordedBlob = new Blob(audioChunks, { type: mediaRecorder?.mimeType || 'audio/webm' });
                const reader = new FileReader();
                reader.onloadend = () => {
                    recordedAudioInput.value = String(reader.result || '');
                    recordedAudioElement.src = reader.result ? String(reader.result) : '';
                    recordPreview.hidden = false;
                    recordSave.disabled = false;
                };
                reader.readAsDataURL(recordedBlob);
                enableRecordingUI(true);
                stopStream();
            });

            mediaRecorder.start();
            if (recordToggle) {
                recordToggle.dataset.state = 'recording';
                recordToggle.textContent = '⏹️ Stop';
            }
            enableRecordingUI(false);
        } catch (error) {
            console.error('Unable to start recording', error);
            enableRecordingUI(true);
        }
    };

    const stopRecording = () => {
        if (mediaRecorder && mediaRecorder.state !== 'inactive') {
            mediaRecorder.stop();
        }
        if (recordToggle) {
            recordToggle.dataset.state = 'processing';
            recordToggle.textContent = 'Processing…';
        }
    };

    recordToggle?.addEventListener('click', () => {
        const state = recordToggle.dataset.state || 'idle';
        if (state === 'idle') {
            startRecording();
        } else if (state === 'recording') {
            stopRecording();
        }
    });

    recordSave?.addEventListener('click', () => {
        if (recordedBlob && recordedAudioInput.value) {
            recordToggle.dataset.state = 'saved';
            recordToggle.textContent = 'Recorded';
            recordSave.disabled = true;
        }
    });

    recordDiscard?.addEventListener('click', () => {
        resetRecording();
    });

    const remindersToggle = document.querySelector('[data-toggle="reminders"]');
    const hapticsToggle = document.querySelector('[data-toggle="haptics"]');
    const updateSwitchState = (el, value) => {
        if (!el) return;
        el.classList.toggle('on', value);
        el.setAttribute('aria-pressed', value ? 'true' : 'false');
        const label = el.querySelector('.sr-only');
        if (label) {
            label.textContent = value ? 'On' : 'Off';
        }
    };

    const storage = window.localStorage;
    const remindersState = storage.getItem('hebrew-reminders') === 'on';
    const hapticsState = storage.getItem('hebrew-haptics') === 'on';
    updateSwitchState(remindersToggle, remindersState);
    updateSwitchState(hapticsToggle, hapticsState);

    remindersToggle?.addEventListener('click', () => {
        const next = remindersToggle.classList.toggle('on');
        remindersToggle.setAttribute('aria-pressed', next ? 'true' : 'false');
        const srLabel = remindersToggle.querySelector('.sr-only');
        if (srLabel) {
            srLabel.textContent = next ? 'On' : 'Off';
        }
        storage.setItem('hebrew-reminders', next ? 'on' : 'off');
    });

    hapticsToggle?.addEventListener('click', () => {
        const next = hapticsToggle.classList.toggle('on');
        hapticsToggle.setAttribute('aria-pressed', next ? 'true' : 'false');
        const srLabel = hapticsToggle.querySelector('.sr-only');
        if (srLabel) {
            srLabel.textContent = next ? 'On' : 'Off';
        }
        storage.setItem('hebrew-haptics', next ? 'on' : 'off');
        if (next && 'vibrate' in navigator) {
            navigator.vibrate?.(20);
        }
    });

    const deckSheet = document.getElementById('deck-sheet');
    const deckSheetTitle = document.getElementById('deck-sheet-title');
    const deckSheetClose = document.querySelector('[data-deck-sheet-close]');
    const deckSheetForms = deckSheet?.querySelectorAll('form');

    document.querySelectorAll('.deck-card-menu').forEach((button) => {
        button.addEventListener('click', () => {
            const deckId = button.dataset.deckSheet || '';
            const deckName = button.closest('.deck-card')?.querySelector('h3')?.textContent || 'Deck';
            const deckCard = button.closest('.deck-card');
            const isFrozen = deckCard?.dataset.frozen === '1';
            const isReversed = deckCard?.dataset.reversed === '1';
            openModal(deckSheet, button);
            deckSheetTitle.textContent = deckName;
            deckSheetForms?.forEach((form) => {
                const input = form.querySelector('input[name="deck_id"]');
                if (input) {
                    input.value = deckId;
                }
                const toggle = form.dataset.sheetToggle || '';
                const valueInput = form.querySelector('input[name="value"]');
                const actionButton = form.querySelector('.sheet-action');
                if (toggle === 'is_frozen' && valueInput && actionButton) {
                    valueInput.value = isFrozen ? '0' : '1';
                    actionButton.textContent = isFrozen ? 'Unfreeze' : 'Freeze';
                }
                if (toggle === 'is_reversed' && valueInput && actionButton) {
                    valueInput.value = isReversed ? '0' : '1';
                    actionButton.textContent = isReversed ? 'Normal order' : 'Reverse';
                }
            });
        });
    });

    deckSheetClose?.addEventListener('click', () => {
        closeModal(deckSheet);
    });

    deckSheet?.addEventListener('click', (event) => {
        if (event.target === deckSheet) {
            closeModal(deckSheet);
        }
    });

    const dialogTriggers = document.querySelectorAll('[data-dialog-open]');
    const dialogCloseButtons = document.querySelectorAll('[data-dialog-close]');

    dialogTriggers.forEach((trigger) => {
        trigger.addEventListener('click', () => {
            const id = trigger.dataset.dialogOpen;
            const dialog = document.getElementById(`dialog-${id}`);
            openModal(dialog, trigger);
        });
    });

    dialogCloseButtons.forEach((button) => {
        button.addEventListener('click', () => {
            closeModal(button.closest('.dialog'));
        });
    });

    document.querySelectorAll('.dialog').forEach((dialog) => {
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) {
                closeModal(dialog);
            }
        });
    });

    const deckGrid = document.getElementById('deck-grid');
    const filterCountTargets = document.querySelectorAll('[data-filter-count]');
    const setFilterCount = (name, value) => {
        filterCountTargets.forEach((node) => {
            if (node.dataset.filterCount === name) {
                node.textContent = String(value);
            }
        });
    };
    const updateFilterCounts = () => {
        if (!deckGrid) {
            setFilterCount('all', 0);
            setFilterCount('popular', 0);
            return;
        }
        const cards = Array.from(deckGrid.querySelectorAll('.deck-card'));
        const total = cards.length;
        const popularCount = cards.filter((card) => card.dataset.popular === '1').length;
        setFilterCount('all', total);
        setFilterCount('popular', popularCount);
    };
    updateFilterCounts();

    document.querySelectorAll('.filter-btn').forEach((button) => {
        button.addEventListener('click', () => {
            const filter = button.dataset.filter;
            document.querySelectorAll('.filter-btn').forEach((btn) => btn.classList.remove('active'));
            button.classList.add('active');
            if (!deckGrid) return;
            deckGrid.querySelectorAll('.deck-card').forEach((card) => {
                const category = card.dataset.category || 'General';
                const popular = card.dataset.popular === '1';
                let visible = filter === 'all';
                if (filter === 'popular') {
                    visible = popular;
                } else if (filter !== 'all' && filter !== 'popular') {
                    visible = category === filter;
                }
                card.toggleAttribute('hidden', !visible);
            });
        });
    });

    const ttsButton = document.querySelector('[data-tts-play]');
    const ttsSample = document.querySelector('[data-tts-sample]');
    ttsButton?.addEventListener('click', () => {
        if (!window.speechSynthesis || !ttsSample) {
            showToast('Text-to-speech is not available in this browser.', 'error');
            return;
        }
        const text = Array.from(ttsSample.querySelectorAll('h4, p')).map((node) => node.textContent || '').join('. ');
        const utterance = new SpeechSynthesisUtterance(text);
        utterance.lang = '<?= h($selectedDeck['tts_back_lang'] ?? 'he-IL') ?>';
        window.speechSynthesis.speak(utterance);
    });
});
</script>
</body>
</html>
