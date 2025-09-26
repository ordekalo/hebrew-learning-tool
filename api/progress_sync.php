<?php

declare(strict_types=1);

require __DIR__ . '/../config.php';

if (!is_post()) {
    http_response_code(405);
    exit;
}

$user = require_user($pdo);
check_token($_SERVER['HTTP_X_CSRF'] ?? null);
$payload = json_decode(file_get_contents('php://input') ?: '[]', true);
if (!is_array($payload) || !isset($payload['records']) || !is_array($payload['records'])) {
    json_response(['error' => 'Invalid payload'], 422);
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'INSERT INTO user_progress (user_id, word_id, interval_days, ease, due_at, reps, lapses, last_result)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE interval_days = VALUES(interval_days), ease = VALUES(ease), due_at = VALUES(due_at),
         reps = VALUES(reps), lapses = VALUES(lapses), last_result = VALUES(last_result)'
    );

    foreach ($payload['records'] as $row) {
        $wordId = (int) ($row['word_id'] ?? 0);
        if ($wordId <= 0) {
            continue;
        }
        $interval = (int) ($row['interval_days'] ?? 0);
        $ease = isset($row['ease']) ? (float) $row['ease'] : 2.5;
        $dueAt = $row['due_at'] ?? null;
        $reps = (int) ($row['reps'] ?? 0);
        $lapses = (int) ($row['lapses'] ?? 0);
        $last = $row['last_result'] ?? null;
        $stmt->execute([
            (int) $user['id'],
            $wordId,
            $interval,
            $ease,
            $dueAt,
            $reps,
            $lapses,
            $last,
        ]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    json_response(['error' => 'Sync failed'], 500);
}

$latestStmt = $pdo->prepare('SELECT word_id, interval_days, ease, due_at, reps, lapses, last_result FROM user_progress WHERE user_id = ?');
$latestStmt->execute([(int) $user['id']]);
$records = $latestStmt->fetchAll();

json_response(['records' => $records]);
