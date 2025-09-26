<?php

declare(strict_types=1);

require __DIR__ . '/config.php';
require_user($pdo);

$csrf = ensure_token();
$user = current_user($pdo);

if (!$user) {
    redirect('login.php');
}

if (is_post() && ($_POST['mode'] ?? '') === 'download') {
    check_token($_POST['csrf'] ?? null);

    $userId = (int) $user['id'];

    $progressStmt = $pdo->prepare('SELECT up.word_id, up.interval_days, up.ease, up.due_at, up.reps, up.lapses, up.last_result,
        w.hebrew, w.transliteration, w.part_of_speech
        FROM user_progress up
        INNER JOIN words w ON w.id = up.word_id
        WHERE up.user_id = ?
        ORDER BY COALESCE(up.due_at, "1970-01-01") ASC, w.hebrew ASC');
    $progressStmt->execute([$userId]);
    $progress = [];
    while ($row = $progressStmt->fetch()) {
        $progress[] = [
            'word_id' => (int) $row['word_id'],
            'interval_days' => (int) $row['interval_days'],
            'ease' => (float) $row['ease'],
            'due_at' => $row['due_at'],
            'reps' => (int) $row['reps'],
            'lapses' => (int) $row['lapses'],
            'last_result' => $row['last_result'],
            'word' => [
                'hebrew' => $row['hebrew'],
                'transliteration' => $row['transliteration'],
                'part_of_speech' => $row['part_of_speech'],
            ],
        ];
    }

    $streakStmt = $pdo->prepare('SELECT day, learned, correct_rate FROM streaks WHERE user_id = ? ORDER BY day ASC');
    $streakStmt->execute([$userId]);
    $streaks = $streakStmt->fetchAll();

    $achievementsStmt = $pdo->prepare('SELECT code, unlocked_at FROM achievements WHERE user_id = ? ORDER BY unlocked_at ASC');
    $achievementsStmt->execute([$userId]);
    $achievements = $achievementsStmt->fetchAll();

    $decksStmt = $pdo->prepare('SELECT d.id, d.name, d.description
        FROM decks d
        INNER JOIN deck_words dw ON dw.deck_id = d.id
        WHERE dw.word_id IN (SELECT word_id FROM user_progress WHERE user_id = ?)
        GROUP BY d.id, d.name, d.description
        ORDER BY d.name ASC');
    $decksStmt->execute([$userId]);
    $decks = $decksStmt->fetchAll();

    $export = [
        'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
        'user' => [
            'id' => $userId,
            'email' => $user['email'],
            'created_at' => $user['created_at'],
        ],
        'progress' => $progress,
        'streaks' => array_map(static function (array $row): array {
            return [
                'day' => $row['day'],
                'learned' => (int) $row['learned'],
                'correct_rate' => (float) $row['correct_rate'],
            ];
        }, $streaks),
        'achievements' => array_map(static function (array $row): array {
            return [
                'code' => $row['code'],
                'unlocked_at' => $row['unlocked_at'],
            ];
        }, $achievements),
        'decks' => array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'description' => $row['description'],
            ];
        }, $decks),
    ];

    $filename = sprintf('hebrew-export-%s.json', (new DateTimeImmutable('now'))->format('Ymd-His'));

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$progressCountStmt = $pdo->prepare('SELECT COUNT(*) FROM user_progress WHERE user_id = ?');
$progressCountStmt->execute([(int) $user['id']]);
$progressCount = (int) $progressCountStmt->fetchColumn();

$streakCountStmt = $pdo->prepare('SELECT COUNT(*) FROM streaks WHERE user_id = ?');
$streakCountStmt->execute([(int) $user['id']]);
$streakCount = (int) $streakCountStmt->fetchColumn();

$achievementCountStmt = $pdo->prepare('SELECT COUNT(*) FROM achievements WHERE user_id = ?');
$achievementCountStmt->execute([(int) $user['id']]);
$achievementCount = (int) $achievementCountStmt->fetchColumn();

?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ייצוא נתונים · Hebrew Learner</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="manifest" href="manifest.json">
</head>
<body class="app-body">
<header class="topbar" role="banner">
    <div class="topbar__left">
        <span class="logo" aria-hidden="true">⬇️</span>
        <div class="topbar__meta">
            <strong><?= h($user['email']) ?></strong>
            <span>ייצוא נתונים אישיים</span>
        </div>
    </div>
    <nav class="topbar__actions" aria-label="Primary">
        <a class="btn btn-icon" href="index.php" title="בית">🏠</a>
        <a class="btn btn-icon" href="study.php" title="סשן">▶️</a>
    </nav>
</header>
<main class="layout" role="main">
    <section class="card" aria-labelledby="export-heading">
        <h1 id="export-heading">ייצוא נתונים</h1>
        <p>קבל עותק JSON של ההתקדמות, הישגים והיסטוריית streak לשמירה אישית או גיבוי.</p>
        <dl class="hero__stats" dir="ltr">
            <div>
                <dt>Cards tracked</dt>
                <dd><?= $progressCount ?></dd>
            </div>
            <div>
                <dt>Streak days stored</dt>
                <dd><?= $streakCount ?></dd>
            </div>
            <div>
                <dt>Achievements</dt>
                <dd><?= $achievementCount ?></dd>
            </div>
        </dl>
    </section>
    <section class="card" aria-labelledby="export-download">
        <h2 id="export-download">הורדה</h2>
        <p>הקובץ כולל:</p>
        <ul>
            <li>פרטי משתמש בסיסיים (אימייל, תאריך יצירה).</li>
            <li>כל פרטי התקדמות ה־SM-2 לכל כרטיס שנלמד.</li>
            <li>היסטוריית streak מלאה ודיוק יומי.</li>
            <li>הישגים שנפתחו ו־Decks רלוונטיים.</li>
        </ul>
        <form method="post" class="form-actions">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="mode" value="download">
            <button class="btn btn-large" type="submit">הורדת JSON</button>
        </form>
    </section>
</main>
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('service-worker.js').catch(() => {});
    });
}
</script>
</body>
</html>
