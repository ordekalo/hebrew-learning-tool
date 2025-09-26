<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/lib/dashboard_service.php';
require __DIR__ . '/lib/dashboard_actions.php';
require __DIR__ . '/lib/i18n.php';

$csrf = ensure_token();
$flash = get_flash();

[$langCode, $localeMeta] = bootstrap_locale($_GET['lang'] ?? null);
$supportedLocales = supported_locales();

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
    'home' => t('nav.home'),
    'library' => t('nav.library'),
    'settings' => t('nav.settings'),
];
?>
<!DOCTYPE html>
<html lang="<?= h($localeMeta['lang']) ?>" dir="<?= h($localeMeta['dir']) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h(t('app.name_fallback')) ?></title>
    <link rel="stylesheet" href="styles.css">
</head>
<body class="app-body" data-screen="<?= h($screen) ?>">
<div class="app-container">
    <aside class="app-sidebar" role="complementary">
        <div class="brand-block">
            <span class="brand-icon" aria-hidden="true">🧠</span>
            <h1><?= h(t('app.name')) ?></h1>
            <p><?= h(t('app.tagline')) ?></p>
        </div>
        <nav class="app-nav" aria-label="Main navigation">
            <?php foreach ($navLinks as $key => $label): ?>
                <a class="app-nav__link<?= $screen === $key ? ' is-active' : '' ?>"
                   href="index.php?screen=<?= h($key) ?>&amp;lang=<?= h($langCode) ?>">
                    <?= h($label) ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <form method="get" class="language-switcher">
            <input type="hidden" name="screen" value="<?= h($screen) ?>">
            <?php if ($selectedDeckId): ?>
                <input type="hidden" name="deck" value="<?= (int) $selectedDeckId ?>">
            <?php endif; ?>
            <label for="lang-select"><?= h(t('app.language_label')) ?></label>
            <select id="lang-select" name="lang" onchange="this.form.submit()">
                <?php foreach ($supportedLocales as $code => $meta): ?>
                    <option value="<?= h($code) ?>"<?= $code === $langCode ? ' selected' : '' ?>>
                        <?= h($meta['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <div class="sidebar-footer">
            <p class="sidebar-note"><?= h(t('app.auth_prompt')) ?></p>
            <div class="sidebar-actions">
                <a class="btn ghost" href="login.php"><?= h(t('app.sign_in')) ?></a>
                <a class="btn ghost" href="register.php"><?= h(t('app.register')) ?></a>
            </div>
        </div>
    </aside>

    <main class="app-main" role="main">
        <header class="main-header">
            <div>
                <h2><?= h($navLinks[$screen] ?? t('nav.home')) ?></h2>
                <p class="main-subtitle"><?= h(t('app.subtitle')) ?></p>
            </div>
            <div class="deck-picker">
                <?php if ($decks): ?>
                    <form method="get" action="index.php" class="deck-picker__form">
                        <input type="hidden" name="screen" value="<?= h($screen) ?>">
                        <input type="hidden" name="lang" value="<?= h($langCode) ?>">
                        <label for="deck-select"><?= h(t('deck_picker.label')) ?></label>
                        <select id="deck-select" name="deck" onchange="this.form.submit()">
                            <?php foreach ($decks as $deck): ?>
                                <option value="<?= (int) $deck['id'] ?>"<?= (int)$deck['id'] === (int)$selectedDeckId ? ' selected' : '' ?>>
                                    <?= h($deck['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <noscript>
                            <button type="submit" class="btn ghost"><?= h(t('deck_picker.update')) ?></button>
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
            'langCode' => $langCode,
        ];

        $templatePath = __DIR__ . '/partials/' . $screen . '.php';
        if (is_file($templatePath)) {
            extract($templateData, EXTR_SKIP);
            require $templatePath;
        } else {
            echo '<p class="empty-state">' . h(t('app.content_unavailable')) . '</p>';
        }
        ?>
    </main>
</div>
</body>
</html>
