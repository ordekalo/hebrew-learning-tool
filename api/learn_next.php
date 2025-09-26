<?php

declare(strict_types=1);

require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/srs.php';

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$user = require_user($pdo);

$deckParam = $_GET['deck'] ?? null;
$deckId = null;
if ($deckParam !== null && $deckParam !== '') {
    $deckId = max(1, (int) $deckParam);
}

$key = 'deck:' . ($deckId ?? 'all');
if (!isset($_SESSION['learn'][$key])) {
    $_SESSION['learn'][$key] = [
        'seen' => 0,
        'queue' => [],
    ];
}

$state = &$_SESSION['learn'][$key];
$state['seen'] = (int) ($state['seen'] ?? 0);
$state['queue'] = array_values(array_filter($state['queue'] ?? [], static function (array $entry) use ($user) {
    return isset($entry['word_id']) && $entry['user_id'] === $user['id'];
}));

$nextFromQueueIndex = null;
foreach ($state['queue'] as $idx => $entry) {
    if (($entry['due_after'] ?? 0) <= $state['seen']) {
        $nextFromQueueIndex = $idx;
        break;
    }
}

if ($nextFromQueueIndex !== null) {
    $entry = $state['queue'][$nextFromQueueIndex];
    array_splice($state['queue'], $nextFromQueueIndex, 1);
    $word = fetch_word_by_id($pdo, (int) $user['id'], (int) $entry['word_id']);
    if ($word === null) {
        json_response(['card' => null, 'dueCount' => 0]);
    }
    $state['seen']++;
    json_response([
        'card' => build_card_payload($word),
        'dueCount' => count_due_words($pdo, (int) $user['id'], $deckId),
        'source' => 'review',
    ]);
}

$dueWord = fetch_due_word($pdo, (int) $user['id'], $deckId);
if ($dueWord === null) {
    json_response([
        'card' => null,
        'dueCount' => 0,
        'source' => 'empty',
    ]);
}

$state['seen']++;
json_response([
    'card' => build_card_payload($dueWord),
    'dueCount' => max(0, count_due_words($pdo, (int) $user['id'], $deckId) - 1),
    'source' => 'due',
]);

function build_card_payload(array $word): array
{
    return [
        'id' => (int) $word['id'],
        'hebrew' => $word['hebrew'],
        'transliteration' => $word['transliteration'],
        'part_of_speech' => $word['part_of_speech'],
        'notes' => $word['notes'],
        'audio_path' => $word['audio_path'],
        'image_path' => $word['image_path'],
        'interval_days' => (int) ($word['interval_days'] ?? 0),
        'ease' => (float) ($word['ease'] ?? 2.5),
        'reps' => (int) ($word['reps'] ?? 0),
        'lapses' => (int) ($word['lapses'] ?? 0),
        'translations' => $word['translations'],
        'tags' => $word['tags'],
    ];
}
