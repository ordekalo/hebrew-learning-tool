<?php
declare(strict_types=1);

/**
 * Load SQL statements stored in sql/dashboard.
 */
function load_dashboard_sql(string $filename): string
{
    static $cache = [];
    if (isset($cache[$filename])) {
        return $cache[$filename];
    }

    $path = __DIR__ . '/../sql/dashboard/' . $filename;
    $sql = @file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException(sprintf('Missing SQL definition: %s', $filename));
    }

    return $cache[$filename] = trim($sql);
}

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
    $stmt = $pdo->prepare(load_dashboard_sql('decks_with_stats.sql'));
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
        $grouped[$category][] = $deck;
    }

    return $grouped;
}

function fetch_deck_sample_card(PDO $pdo, int $deckId): ?array
{
    $stmt = $pdo->prepare(load_dashboard_sql('deck_sample_card.sql'));
    $stmt->execute([$deckId]);

    return $stmt->fetch() ?: null;
}

function fetch_deck_learning_history(PDO $pdo, int $deckId, string $userIdentifier): array
{
    $stmt = $pdo->prepare(load_dashboard_sql('deck_learning_history.sql'));
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
                            tts_enabled, tts_autoplay, tts_front_lang, tts_back_lang, tts_front_voice, tts_back_voice)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
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
        $deck['tts_front_voice'] ?? '',
        $deck['tts_back_voice'] ?? '',
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
