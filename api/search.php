<?php

declare(strict_types=1);

require __DIR__ . '/../config.php';

$user = require_user($pdo);

$rawQuery = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(50, max(1, (int) ($_GET['per_page'] ?? 20)));
$offset = ($page - 1) * $perPage;

$filters = parse_search_query($rawQuery);
$tagFilter = $_GET['tag'] ?? ($filters['tag'] ?? null);
$langFilter = $_GET['lang'] ?? ($filters['lang'] ?? null);
$posFilter = $_GET['pos'] ?? ($filters['pos'] ?? null);

$sql = "SELECT DISTINCT w.id, w.hebrew, w.transliteration, w.part_of_speech, w.notes, w.audio_path, w.image_path,
               GROUP_CONCAT(DISTINCT CONCAT(t.lang_code, '\t', COALESCE(t.meaning,'')) SEPARATOR '\n') AS translations,
               GROUP_CONCAT(DISTINCT tg.name SEPARATOR ', ') AS tags
        FROM words w
        LEFT JOIN translations t ON t.word_id = w.id
        LEFT JOIN word_tags wt ON wt.word_id = w.id
        LEFT JOIN tags tg ON tg.id = wt.tag_id
        LEFT JOIN deck_words dw ON dw.word_id = w.id
        WHERE 1 = 1";

$params = [];

if ($filters['q'] ?? '') {
    $like = '%' . $filters['q'] . '%';
    $sql .= " AND (w.hebrew LIKE ? OR w.transliteration LIKE ? OR t.meaning LIKE ? OR t.other_script LIKE ?)";
    $params = array_merge($params, [$like, $like, $like, $like]);
}

if ($filters['quote'] ?? '') {
    $exact = $filters['quote'];
    $sql .= " AND (w.hebrew = ? OR t.meaning = ? OR t.other_script = ?)";
    $params = array_merge($params, [$exact, $exact, $exact]);
}

if ($tagFilter) {
    $sql .= " AND tg.name = ?";
    $params[] = $tagFilter;
}

if ($langFilter) {
    $sql .= " AND t.lang_code = ?";
    $params[] = $langFilter;
}

if ($posFilter) {
    $sql .= " AND w.part_of_speech = ?";
    $params[] = $posFilter;
}

if (($filters['has_audio'] ?? false) || ($_GET['has_audio'] ?? '') === '1') {
    $sql .= " AND w.audio_path IS NOT NULL";
}

if (($filters['has_image'] ?? false) || ($_GET['has_image'] ?? '') === '1') {
    $sql .= " AND w.image_path IS NOT NULL";
}

$sql .= " GROUP BY w.id ORDER BY w.created_at DESC LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$results = [];
while ($row = $stmt->fetch()) {
    $translations = [];
    if (!empty($row['translations'])) {
        foreach (explode("\n", $row['translations']) as $line) {
            [$lang, $meaning] = array_pad(explode("\t", $line), 2, '');
            $translations[] = ['lang_code' => $lang, 'meaning' => $meaning];
        }
    }
    $results[] = [
        'id' => (int) $row['id'],
        'hebrew' => $row['hebrew'],
        'transliteration' => $row['transliteration'],
        'part_of_speech' => $row['part_of_speech'],
        'notes' => $row['notes'],
        'audio_path' => $row['audio_path'],
        'image_path' => $row['image_path'],
        'translations' => $translations,
        'tags' => $row['tags'] ? explode(', ', $row['tags']) : [],
    ];
}

json_response([
    'data' => $results,
    'page' => $page,
    'perPage' => $perPage,
]);

function parse_search_query(string $query): array
{
    $filters = [
        'q' => '',
        'quote' => '',
    ];

    if ($query === '') {
        return $filters;
    }

    $remaining = $query;
    if (preg_match_all('/(\w+):"([^"]+)"/', $query, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $filters[strtolower($match[1])] = $match[2];
            $remaining = str_replace($match[0], '', $remaining);
        }
    }

    if (preg_match_all('/(\w+):([^\s]+)/', $remaining, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $filters[strtolower($match[1])] = $match[2];
            $remaining = str_replace($match[0], '', $remaining);
        }
    }

    $remaining = trim($remaining);
    if ($remaining !== '') {
        if ($remaining[0] === '"' && substr($remaining, -1) === '"') {
            $filters['quote'] = trim($remaining, '"');
        } else {
            $filters['q'] = $remaining;
        }
    }

    if (isset($filters['tag'])) {
        $filters['tag'] = mb_strtolower($filters['tag']);
    }

    if (isset($filters['lang'])) {
        $filters['lang'] = substr($filters['lang'], 0, 5);
    }

    if (isset($filters['pos'])) {
        $filters['pos'] = substr($filters['pos'], 0, 32);
    }

    if (isset($filters['has_audio'])) {
        $filters['has_audio'] = in_array(strtolower((string) $filters['has_audio']), ['1', 'true', 'yes'], true);
    }
    if (isset($filters['has_image'])) {
        $filters['has_image'] = in_array(strtolower((string) $filters['has_image']), ['1', 'true', 'yes'], true);
    }

    return $filters;
}
