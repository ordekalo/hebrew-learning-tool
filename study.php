<?php

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/lib/srs.php';

$user = require_user($pdo);
$deckId = isset($_GET['deck']) ? (int) $_GET['deck'] : null;
$deckName = null;
if ($deckId) {
    $stmt = $pdo->prepare('SELECT name FROM decks WHERE id = ?');
    $stmt->execute([$deckId]);
    $deckName = $stmt->fetchColumn() ?: null;
}

$dueCount = count_due_words($pdo, (int) $user['id'], $deckId);
$csrf = ensure_token();
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>סשן יומי · Hebrew Learner</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="manifest" href="manifest.json">
</head>
<body class="study-body">
<header class="study-header" role="banner">
    <a class="btn secondary" href="index.php" aria-label="חזרה">⬅️</a>
    <div class="study-header__titles">
        <h1><?= $deckName ? h($deckName) : 'סשן יומי' ?></h1>
        <p><?= $deckName ? 'לומדים מתוך Deck נבחר' : 'מערבב בין כל הכרטיסים שלך' ?></p>
    </div>
    <div class="study-header__meta">
        <span id="due-count" aria-live="polite"><?= (int) $dueCount ?></span>
        <small>due</small>
    </div>
</header>
<main class="study-main" role="main" data-deck="<?= $deckId ? (int) $deckId : '' ?>" data-csrf="<?= h($csrf) ?>">
    <article class="study-card" id="study-card" aria-live="polite">
        <div class="study-card__media">
            <img id="card-image" alt="" hidden loading="lazy">
        </div>
        <div class="study-card__content">
            <p class="study-card__label">עברית</p>
            <h2 id="card-hebrew" dir="rtl">—</h2>
            <p id="card-transliteration" class="study-card__translit" dir="ltr"></p>
            <p id="card-notes" class="study-card__notes"></p>
            <ul id="card-translations" class="study-card__translations"></ul>
            <div class="study-card__tags" id="card-tags"></div>
        </div>
        <footer class="study-card__footer">
            <audio id="card-audio" controls preload="none" hidden></audio>
            <button type="button" class="btn secondary" id="tts-button" hidden aria-label="השמע טקסט">🔊</button>
        </footer>
    </article>
    <div class="study-empty" id="study-empty" hidden>
        <h2>אין כרטיסים להיום! 🎉</h2>
        <p>נסה Deck אחר או חזור מאוחר יותר. המשך כך!</p>
        <a class="btn" href="index.php">חזרה לבית</a>
    </div>
</main>
<footer class="study-actions" role="contentinfo">
    <button type="button" class="srs-btn" data-result="again" id="btn-again">שוב</button>
    <button type="button" class="srs-btn" data-result="hard" id="btn-hard">קשה</button>
    <button type="button" class="srs-btn" data-result="good" id="btn-good">טוב</button>
    <button type="button" class="srs-btn" data-result="easy" id="btn-easy">קל</button>
</footer>
<script src="study.js" type="module"></script>
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('service-worker.js').catch(() => {});
    });
}
</script>
</body>
</html>
