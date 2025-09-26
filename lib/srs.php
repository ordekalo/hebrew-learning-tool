<?php

declare(strict_types=1);

function hydrate_word_row(array $row): array
{
    $row['translations'] = $row['translations'] !== null && $row['translations'] !== ''
        ? array_map(static function (string $line): array {
            [$lang, $meaning, $other, $example] = array_pad(explode("\t", $line), 4, '');
            return [
                'lang_code' => $lang,
                'meaning' => $meaning,
                'other_script' => $other,
                'example' => $example,
            ];
        }, explode("\n", $row['translations']))
        : [];

    $row['tags'] = $row['tags'] !== null && $row['tags'] !== '' ? explode("\n", $row['tags']) : [];

    return $row;
}

function fetch_due_word(PDO $pdo, int $userId, ?int $deckId = null): ?array
{
    $params = [$userId];
    $deckJoin = '';
    $deckWhere = '';
    if ($deckId !== null) {
        $deckJoin = 'INNER JOIN deck_words dw ON dw.word_id = w.id';
        $deckWhere = 'AND dw.deck_id = ?';
        $params[] = $deckId;
    }

    $sql = "SELECT w.id, w.hebrew, w.transliteration, w.part_of_speech, w.notes, w.audio_path, w.image_path,
                   up.interval_days, up.ease, up.due_at, up.reps, up.lapses,
                   GROUP_CONCAT(DISTINCT CONCAT(t.lang_code, '\t', COALESCE(t.meaning,''), '\t', COALESCE(t.other_script,''), '\t', COALESCE(t.example,'')) SEPARATOR '\n') AS translations,
                   GROUP_CONCAT(DISTINCT tg.name SEPARATOR '\n') AS tags
            FROM words w
            LEFT JOIN user_progress up ON up.word_id = w.id AND up.user_id = ?
            LEFT JOIN translations t ON t.word_id = w.id
            LEFT JOIN word_tags wt ON wt.word_id = w.id
            LEFT JOIN tags tg ON tg.id = wt.tag_id
            $deckJoin
            WHERE (up.due_at IS NULL OR up.due_at <= NOW())
            $deckWhere
            ORDER BY COALESCE(up.due_at, '1970-01-01') ASC, w.id ASC
            LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    return hydrate_word_row($row);
}

function fetch_word_by_id(PDO $pdo, int $userId, int $wordId): ?array
{
    $sql = "SELECT w.id, w.hebrew, w.transliteration, w.part_of_speech, w.notes, w.audio_path, w.image_path,
                   up.interval_days, up.ease, up.due_at, up.reps, up.lapses,
                   GROUP_CONCAT(DISTINCT CONCAT(t.lang_code, '\t', COALESCE(t.meaning,''), '\t', COALESCE(t.other_script,''), '\t', COALESCE(t.example,'')) SEPARATOR '\n') AS translations,
                   GROUP_CONCAT(DISTINCT tg.name SEPARATOR '\n') AS tags
            FROM words w
            LEFT JOIN user_progress up ON up.word_id = w.id AND up.user_id = ?
            LEFT JOIN translations t ON t.word_id = w.id
            LEFT JOIN word_tags wt ON wt.word_id = w.id
            LEFT JOIN tags tg ON tg.id = wt.tag_id
            WHERE w.id = ?
            GROUP BY w.id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $wordId]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    return hydrate_word_row($row);
}

function count_due_words(PDO $pdo, int $userId, ?int $deckId = null): int
{
    $params = [$userId];
    $deckJoin = '';
    $deckWhere = '';
    if ($deckId !== null) {
        $deckJoin = 'INNER JOIN deck_words dw ON dw.word_id = w.id';
        $deckWhere = 'AND dw.deck_id = ?';
        $params[] = $deckId;
    }

    $sql = "SELECT COUNT(*) FROM words w
            LEFT JOIN user_progress up ON up.word_id = w.id AND up.user_id = ?
            $deckJoin
            WHERE (up.due_at IS NULL OR up.due_at <= NOW())
            $deckWhere";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}

function calculate_next_interval(array $progress, string $result): array
{
    $ease = (float) ($progress['ease'] ?? 2.5);
    $interval = (int) ($progress['interval_days'] ?? 0);
    $reps = (int) ($progress['reps'] ?? 0);
    $lapses = (int) ($progress['lapses'] ?? 0);

    $gradeMap = [
        'again' => 0,
        'hard' => 3,
        'good' => 4,
        'easy' => 5,
    ];

    $grade = $gradeMap[$result] ?? 0;

    if ($grade < 3) {
        $reps = 0;
        $lapses += 1;
        $interval = 1;
    } else {
        $reps += 1;
        if ($reps === 1) {
            $interval = 1;
        } elseif ($reps === 2) {
            $interval = 6;
        } else {
            $interval = (int) round($interval * $ease);
            if ($interval < 1) {
                $interval = 1;
            }
        }
    }

    $ease += 0.1 - (5 - $grade) * (0.08 + (5 - $grade) * 0.02);
    $ease = max(1.3, min($ease, 2.8));

    $dueAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $dueAt = $dueAt->add(new DateInterval('P' . max($interval, 1) . 'D'));

    return [
        'interval_days' => $interval,
        'ease' => round($ease, 2),
        'reps' => $reps,
        'lapses' => $lapses,
        'due_at' => $dueAt->format('Y-m-d H:i:s'),
    ];
}

function upsert_progress(PDO $pdo, int $userId, int $wordId, string $result): array
{
    $stmt = $pdo->prepare('SELECT * FROM user_progress WHERE user_id = ? AND word_id = ?');
    $stmt->execute([$userId, $wordId]);
    $existing = $stmt->fetch() ?: [];

    $next = calculate_next_interval($existing, $result);
    $params = [
        $userId,
        $wordId,
        $next['interval_days'],
        $next['ease'],
        $next['due_at'],
        $next['reps'],
        $next['lapses'],
        $result,
    ];

    $pdo->prepare(
        'INSERT INTO user_progress (user_id, word_id, interval_days, ease, due_at, reps, lapses, last_result)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE interval_days = VALUES(interval_days), ease = VALUES(ease), due_at = VALUES(due_at),
         reps = VALUES(reps), lapses = VALUES(lapses), last_result = VALUES(last_result)'
    )->execute($params);

    return $next;
}

function record_daily_stats(PDO $pdo, int $userId, bool $correct): void
{
    $today = (new DateTimeImmutable('today', new DateTimeZone('UTC')))->format('Y-m-d');
    $stmt = $pdo->prepare('SELECT learned, correct_rate FROM streaks WHERE user_id = ? AND day = ?');
    $stmt->execute([$userId, $today]);
    $row = $stmt->fetch();

    if (!$row) {
        $learned = 1;
        $correctRate = $correct ? 100.0 : 0.0;
        $pdo->prepare('INSERT INTO streaks (user_id, day, learned, correct_rate) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $today, $learned, $correctRate]);
        return;
    }

    $learned = (int) $row['learned'] + 1;
    $previousCorrect = (float) $row['correct_rate'] * (int) $row['learned'] / 100.0;
    $newCorrect = $previousCorrect + ($correct ? 1 : 0);
    $correctRate = $learned > 0 ? ($newCorrect / $learned) * 100.0 : 0.0;

    $pdo->prepare('UPDATE streaks SET learned = ?, correct_rate = ? WHERE user_id = ? AND day = ?')
        ->execute([$learned, round($correctRate, 2), $userId, $today]);
}

function streak_summary(PDO $pdo, int $userId): array
{
    $today = (new DateTimeImmutable('today', new DateTimeZone('UTC')))->format('Y-m-d');
    $stmt = $pdo->prepare('SELECT day FROM streaks WHERE user_id = ? ORDER BY day DESC LIMIT 30');
    $stmt->execute([$userId]);
    $days = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $streak = 0;
    $dateCursor = new DateTimeImmutable('today', new DateTimeZone('UTC'));
    foreach ($days as $day) {
        if ($day === $dateCursor->format('Y-m-d')) {
            $streak++;
            $dateCursor = $dateCursor->sub(new DateInterval('P1D'));
        } else {
            break;
        }
    }

    $todayStats = $pdo->prepare('SELECT learned, correct_rate FROM streaks WHERE user_id = ? AND day = ?');
    $todayStats->execute([$userId, $today]);
    $stats = $todayStats->fetch() ?: ['learned' => 0, 'correct_rate' => 0];

    return [
        'current_streak' => $streak,
        'today_learned' => (int) ($stats['learned'] ?? 0),
        'today_correct_rate' => (float) ($stats['correct_rate'] ?? 0),
    ];
}

function ensure_achievement(PDO $pdo, int $userId, string $code): void
{
    $pdo->prepare('INSERT IGNORE INTO achievements (user_id, code) VALUES (?, ?)')->execute([$userId, $code]);
}
