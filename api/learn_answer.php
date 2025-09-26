<?php

declare(strict_types=1);

require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/srs.php';

if (!is_post()) {
    http_response_code(405);
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

rate_limit('learn_answer:' . session_id(), 60, 60);

$user = require_user($pdo);
check_token($_SERVER['HTTP_X_CSRF'] ?? null);

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '[]', true);
if (!is_array($data)) {
    json_response(['error' => 'Invalid payload'], 422);
}

$wordId = isset($data['word_id']) ? (int) $data['word_id'] : 0;
$result = $data['result'] ?? '';
$deckParam = $data['deck'] ?? null;
$deckId = null;
if ($deckParam !== null && $deckParam !== '') {
    $deckId = max(1, (int) $deckParam);
}

$validResults = ['again', 'hard', 'good', 'easy'];
if ($wordId <= 0 || !in_array($result, $validResults, true)) {
    json_response(['error' => 'Missing parameters'], 422);
}

$next = upsert_progress($pdo, (int) $user['id'], $wordId, $result);
record_daily_stats($pdo, (int) $user['id'], in_array($result, ['good', 'easy'], true));

if ($next['reps'] >= 50) {
    ensure_achievement($pdo, (int) $user['id'], 'power-user');
}

$key = 'deck:' . ($deckId ?? 'all');
if (!isset($_SESSION['learn'][$key])) {
    $_SESSION['learn'][$key] = [
        'seen' => 0,
        'queue' => [],
    ];
}

if (in_array($result, ['again', 'hard'], true)) {
    $state = &$_SESSION['learn'][$key];
    $exists = false;
    foreach ($state['queue'] as $item) {
        if ((int) $item['word_id'] === $wordId) {
            $exists = true;
            break;
        }
    }
    if (!$exists) {
        $state['queue'][] = [
            'word_id' => $wordId,
            'user_id' => (int) $user['id'],
            'due_after' => (int) ($state['seen'] ?? 0) + random_int(4, 6),
        ];
    }
}

json_response([
    'next' => $next,
    'dueCount' => count_due_words($pdo, (int) $user['id'], $deckId),
]);
