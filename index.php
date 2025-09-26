<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/lib/dashboard_service.php';
require __DIR__ . '/lib/dashboard_actions.php';

$csrf = ensure_token();
$flash = get_flash();

if (!isset($_SESSION['user_identifier'])) {
    $_SESSION['user_identifier'] = 'guest-' . bin2hex(random_bytes(5));
}

$userIdentifier = $_SESSION['user_identifier'];
$screen = $_GET['screen'] ?? 'home';
$allowedScreens = ['home', 'library', 'settings'];
if (!in_array($screen, $allowedScreens, true)) {
    $screen = 'home';
}
$action = $_GET['a'] ?? 'view';

$defaultDeckId = ensure_default_deck($pdo);
if (!isset($_SESSION['selected_deck']) || (int) $_SESSION['selected_deck'] <= 0) {
    $_SESSION['selected_deck'] = $defaultDeckId;
}
$selectedDeckId = (int) ($_SESSION['selected_deck'] ?? $defaultDeckId);

$starterPhrases = require __DIR__ . '/config/starter_phrases.php';

handle_dashboard_action($action, $pdo, [
    'starterPhrases' => $starterPhrases,
    'selectedDeckId' => $selectedDeckId,
    'defaultDeckId' => $defaultDeckId,
    'userIdentifier' => $userIdentifier,
    'uploadDir' => $UPLOAD_DIR,
]);
$selectedDeckId = (int) ($_SESSION['selected_deck'] ?? $selectedDeckId);

$decks = fetch_decks_with_stats($pdo, $userIdentifier);
if (!$decks) {
    $defaultDeckId = ensure_default_deck($pdo);
    $decks = fetch_decks_with_stats($pdo, $userIdentifier);
}

$deckLookup = [];
foreach ($decks as $deck) {
    $deckLookup[(int) $deck['id']] = $deck;
}

if (isset($_GET['deck'])) {
    $requestedDeckId = (int) $_GET['deck'];
    if (isset($deckLookup[$requestedDeckId])) {
        $_SESSION['selected_deck'] = $requestedDeckId;
        $selectedDeckId = $requestedDeckId;
    }
}

if (!isset($deckLookup[$selectedDeckId]) && isset($deckLookup[$defaultDeckId])) {
    $selectedDeckId = $defaultDeckId;
    $_SESSION['selected_deck'] = $selectedDeckId;
}

$selectedDeck = $deckLookup[$selectedDeckId] ?? null;
$sampleCard = $selectedDeck ? fetch_deck_sample_card($pdo, $selectedDeckId) : null;
$recentHistory = $selectedDeck ? array_slice(fetch_deck_learning_history($pdo, $selectedDeckId, $userIdentifier), 0, 5) : [];

$dueSummary = ['total' => null, 'deck' => null];
try {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM user_progress WHERE user_identifier = ? AND (due_at IS NULL OR due_at <= NOW())');
    $stmt->execute([$userIdentifier]);
    $dueSummary['total'] = (int) $stmt->fetchColumn();

    if ($selectedDeckId > 0) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM user_progress up
             INNER JOIN deck_words dw ON dw.word_id = up.word_id
             WHERE up.user_identifier = ?
               AND dw.deck_id = ?
               AND (up.due_at IS NULL OR up.due_at <= NOW())'
        );
        $stmt->execute([$userIdentifier, $selectedDeckId]);
        $dueSummary['deck'] = (int) $stmt->fetchColumn();
    }
} catch (Throwable $e) {
    $dueSummary = ['total' => null, 'deck' => null];
}

$navLinks = [
    'home' => 'דף הבית',
    'library' => 'ספריה',
    'settings' => 'העדפות',
];
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hebrew Study Hub</title>
    <link rel="stylesheet" href="styles.css">
    <script src="scripts/apply-direction.js" defer></script>
</head>
<body class="app-body" data-screen="<?= h($screen) ?>">
<div class="app-container">
    <aside class="app-sidebar" role="complementary">
        <div class="brand-block">
            <span class="brand-icon" aria-hidden="true">🧠</span>
            <h1>Hebrew Study Hub</h1>
            <p>חוויית לימוד בהשראת Noji — כרטיסיות דיגיטליות וחזרתיות מבוזרת.</p>
        </div>
        <nav class="app-nav" aria-label="תפריט ראשי">
            <?php foreach ($navLinks as $key => $label): ?>
                <a class="app-nav__link<?= $screen === $key ? ' is-active' : '' ?>" href="index.php?screen=<?= h($key) ?>">
                    <?= h($label) ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer">
            <p class="sidebar-note">התחבר/י כדי לעקוב אחרי ההתקדמות האישית שלך לאורך זמן.</p>
            <div class="sidebar-actions">
                <a class="btn ghost" href="login.php">כניסה</a>
                <a class="btn ghost" href="register.php">הרשמה</a>
            </div>
        </div>
    </aside>

    <main class="app-main" role="main">
        <header class="main-header">
            <div>
                <h2><?= h($navLinks[$screen] ?? 'דף הבית') ?></h2>
                <p class="main-subtitle">ממשק נקי שמרכז את כל מה שצריך כדי לשנן מילים בקצב שלך.</p>
            </div>
            <div class="deck-picker">
                <?php if ($decks): ?>
                    <form method="get" action="index.php" class="deck-picker__form">
                        <input type="hidden" name="screen" value="<?= h($screen) ?>">
                        <label for="deck-select">Deck פעיל</label>
                        <select id="deck-select" name="deck" onchange="this.form.submit()">
                            <?php foreach ($decks as $deck): ?>
                                <option value="<?= (int) $deck['id'] ?>"<?= $deck['id'] == $selectedDeckId ? ' selected' : '' ?>>
                                    <?= h($deck['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <noscript>
                            <button type="submit" class="btn ghost">עדכן</button>
                        </noscript>
                    </form>
                <?php endif; ?>
            </div>
        </header>

        <?php if ($flash): ?>
            <div class="flash <?= h($flash['type'] ?? 'info') ?>" role="status">
                <?= h($flash['message'] ?? '') ?>
            </div>
        <?php endif; ?>

        <?php
        $templateData = [
            'csrf' => $csrf,
            'selectedDeck' => $selectedDeck,
            'selectedDeckId' => $selectedDeckId,
            'decks' => $decks,
            'dueSummary' => $dueSummary,
            'sampleCard' => $sampleCard,
            'recentHistory' => $recentHistory,
            'starterPhrases' => $starterPhrases,
        ];

        $templatePath = __DIR__ . '/partials/' . $screen . '.php';
        if (is_file($templatePath)) {
            extract($templateData, EXTR_SKIP);
            require $templatePath;
        } else {
            echo '<p class="empty-state">התוכן אינו זמין.</p>';
        }
        ?>
    </main>
</div>
</body>
</html>
