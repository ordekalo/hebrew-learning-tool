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
$screen = in_array($screen, ['home', 'library', 'settings'], true) ? $screen : 'home';
$action = $_GET['a'] ?? 'view';
$langFilter = isset($_GET['lang']) && $_GET['lang'] !== '' ? substr($_GET['lang'], 0, 10) : null;
$searchTerm = trim($_GET['q'] ?? '');

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

if (!isset($deckLookup[$selectedDeckId])) {
    $selectedDeckId = $defaultDeckId;
    $_SESSION['selected_deck'] = $selectedDeckId;
}

$selectedDeck = $deckLookup[$selectedDeckId] ?? null;
$deckSample = $selectedDeck ? fetch_deck_sample_card($pdo, $selectedDeckId) : null;
$deckHistory = $selectedDeck ? fetch_deck_learning_history($pdo, $selectedDeckId, $userIdentifier) : [];
$groupedDecks = group_decks_by_category($decks);

$popularDecks = array_filter($decks, static fn(array $deck): bool => (float) $deck['rating'] >= 4.5 || (int) $deck['learners_count'] >= 200);
$categories = array_keys($groupedDecks);
sort($categories);

$card = fetch_random_card($pdo, $langFilter, $selectedDeckId);
$carouselCards = fetch_random_cards($pdo, $langFilter, 8, false, $selectedDeckId);
$memoryPairs = fetch_random_cards($pdo, $langFilter, 6, true, $selectedDeckId);
$memoryPairs = array_values(array_filter($memoryPairs, static fn(array $row): bool => ($row['meaning'] ?? '') !== ''));
$memoryData = array_map(
    static function (array $row): array {
        $isReversed = isset($row['is_reversed']) && (int) $row['is_reversed'] === 1;
        $hebrew = $row['hebrew'] ?? '';
        $meaning = $row['meaning'] ?? '';
        if ($isReversed) {
            return [
                'id' => (int) $row['id'],
                'hebrew' => $meaning,
                'meaning' => $hebrew,
                'other_script' => $row['other_script'] ?? '',
                'transliteration' => $row['transliteration'] ?? '',
            ];
        }

        return [
            'id' => (int) $row['id'],
            'hebrew' => $hebrew,
            'meaning' => $meaning,
            'other_script' => $row['other_script'] ?? '',
            'transliteration' => $row['transliteration'] ?? '',
        ];
    },
    $memoryPairs
);

$interfaceLocale = 'he-IL';
if ($langFilter === 'ar') {
    $interfaceLocale = 'ar';
} elseif ($langFilter === 'ru') {
    $interfaceLocale = 'ru-RU';
} elseif ($langFilter === 'en') {
    $interfaceLocale = 'en-US';
}

$hasDeckCards = (int) ($selectedDeck['cards_count'] ?? 0) > 0;

$searchResults = [];
if ($searchTerm !== '') {
    // בניית שאילתת סיכום התרגומים באמצעות פונקציה קיימת
    $translationSummarySelect = db_translation_summary_select();

    $sql = 'SELECT w.*, ' . $translationSummarySelect . ',
                   GROUP_CONCAT(DISTINCT d.name SEPARATOR ", ") AS deck_names
            FROM words w
            LEFT JOIN translations t ON t.word_id = w.id
            LEFT JOIN deck_words dw ON dw.word_id = w.id
            LEFT JOIN decks d ON d.id = dw.deck_id
            WHERE w.hebrew LIKE ?
               OR w.transliteration LIKE ?
               OR t.meaning LIKE ?
               OR t.other_script LIKE ?
            GROUP BY w.id
            ORDER BY w.created_at DESC
            LIMIT 50';

    $stmt = $pdo->prepare($sql);
    $like = '%' . $searchTerm . '%';
    $stmt->execute([$like, $like, $like, $like]);
    $searchResults = $stmt->fetchAll();
}


?>
<!DOCTYPE html>
<html lang="<?= h(str_replace('_', '-', $interfaceLocale)) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hebrew Study Hub</title>
    <link rel="stylesheet" href="styles.css">
    <script src="scripts/apply-direction.js"></script>
</head>
<body class="app-body" data-screen="<?= h($screen) ?>" data-locale="<?= h($interfaceLocale) ?>" data-lang-filter="<?= h($langFilter ?? '') ?>" data-tts-back-lang="<?= h($selectedDeck['tts_back_lang'] ?? 'he-IL') ?>">
<div class="app-shell">
    <header class="app-header">
        <div class="header-main">
            <h1>Hebrew Study Hub</h1>
            <p class="header-sub">Tailored decks, smart drills, and responsive design inspired by mobile-first study apps.</p>
        </div>
        <form method="get" action="index.php" class="search-form">
            <input type="hidden" name="screen" value="<?= h($screen) ?>" data-search-screen>
            <input type="text" name="q" placeholder="Search cards" value="<?= h($searchTerm) ?>" data-rtl-sensitive>
            <button class="btn primary" type="submit">Search</button>
        </form>
    </header>

    <div class="toast-region" aria-live="assertive" aria-atomic="true">
        <div class="toast" id="app-toast" role="status" hidden></div>
    </div>

    <?php if ($flash): ?>
        <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <main class="app-content">
        <section class="screen" data-screen="home" <?= $screen === 'home' ? '' : 'hidden' ?>>
            <div class="hero-card">
                <div class="hero-info">
                    <h2><?= h($selectedDeck['name'] ?? 'Deck') ?></h2>
                    <p><?= h($selectedDeck['description'] ?? 'Curated words to master Hebrew every day.') ?></p>
                    <div class="hero-stats">
                        <span><strong><?= (int) ($selectedDeck['cards_count'] ?? 0) ?></strong> Cards</span>
                        <span><strong><?= (int) ($selectedDeck['studied_count'] ?? 0) ?></strong> Studied</span>
                        <span><strong><?= (int) ($selectedDeck['progress_percent'] ?? 0) ?>%</strong> Complete</span>
                    </div>
                    <div class="hero-actions">
                        <?php if ($hasDeckCards): ?>
                            <a class="btn primary" href="#flashcards">Start session</a>
                        <?php else: ?>
                            <a class="btn primary" href="#quick-add-form" data-roll-example="true">צור כרטיס ראשון</a>
                        <?php endif; ?>
                        <a class="btn ghost" href="?screen=library">Deck settings</a>
                    </div>
                </div>
                <div class="hero-illustration">
                    <span class="deck-icon"><?= h($selectedDeck['icon'] ?? 'sparkles') ?></span>
                </div>
            </div>

            <section class="card flashcard-section" id="flashcards">
                <div class="section-heading">
                    <div>
                        <h2>Flashcards</h2>
                        <p class="section-subtitle">Swipe through your deck with reversible cards and inline audio.</p>
                    </div>
                    <div class="tag-group">
                        <a class="btn ghost" href="index.php?screen=home">Shuffle</a>
                        <label class="sr-only" for="lang-filter">Filter language</label>
                        <select id="lang-filter" name="lang">
                            <option value="">All languages</option>
                            <option value="ru" <?= $langFilter === 'ru' ? 'selected' : '' ?>>Русский</option>
                            <option value="en" <?= $langFilter === 'en' ? 'selected' : '' ?>>English</option>
                            <option value="ar" <?= $langFilter === 'ar' ? 'selected' : '' ?>>العربية</option>
                        </select>
                    </div>
                </div>
                <?php if ($carouselCards): ?>
                    <div class="flashcard-track" tabindex="0">
                        <?php foreach ($carouselCards as $item): ?>
                            <?php $isReversed = isset($item['is_reversed']) && (int) $item['is_reversed'] === 1; ?>
                            <article class="flashcard" role="listitem">
                                <header class="flashcard-header">
                                    <span class="badge">Deck</span>
                                    <span class="badge badge-muted"><?= h($selectedDeck['name'] ?? 'Deck') ?></span>
                                </header>
                                <?php if ($isReversed): ?>
                                    <h3 class="hebrew-word"><?= h($item['meaning'] ?? '') ?></h3>
                                    <p class="flashcard-text"><span class="badge">Hebrew</span> <?= h($item['hebrew'] ?? '') ?></p>
                                <?php else: ?>
                                    <h3 class="hebrew-word"><?= h($item['hebrew'] ?? '') ?></h3>
                                    <?php if (!empty($item['transliteration'])): ?>
                                        <p class="flashcard-text"><span class="badge">Transliteration</span> <?= h($item['transliteration']) ?></p>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <div class="flashcard-translation">
                                    <span class="badge">Meaning</span>
                                    <p class="flashcard-text">
                                        <strong><?= h($item['lang_code'] ?? '—') ?>:</strong>
                                        <?= h($item['meaning'] ?? '—') ?>
                                    </p>
                                    <?php if (!empty($item['other_script'])): ?>
                                        <p class="flashcard-text alt-script"><?= h($item['other_script']) ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($item['example'])): ?>
                                        <p class="flashcard-text example"><?= nl2br(h($item['example'])) ?></p>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($item['audio_path'])): ?>
                                    <audio class="audio" controls preload="none" src="<?= h($item['audio_path']) ?>"></audio>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p>No cards yet in this deck. Add words using the quick form or start with ready-made phrases.</p>
                    <form method="post" action="index.php?a=seed_openers" class="seed-form">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
                        <button type="submit" class="btn primary seed-btn">+ הוסף 10 ביטויי פתיחה</button>
                    </form>
                <?php endif; ?>
            </section>

            <section class="card memory-section">
                <div class="section-heading">
                    <div>
                        <h2>Memory Trainer</h2>
                        <p class="section-subtitle">Match Hebrew words with their translation to reinforce recognition.</p>
                    </div>
                    <button type="button" class="btn ghost" id="memory-reset" <?= empty($memoryPairs) ? 'disabled' : '' ?>>Shuffle board</button>
                </div>
                <div class="memory-status">
                    <span>Matches: <strong id="memory-matches">0</strong> / <?= count($memoryPairs) ?></span>
                    <span id="memory-feedback" aria-live="polite" role="status"></span>
                </div>
                <div class="memory-board" id="memory-board" aria-label="Memory trainer board" data-empty="<?= empty($memoryPairs) ? '1' : '0' ?>"></div>
                <?php if (empty($memoryPairs)): ?>
                    <p class="memory-empty">Add translations to play the memory game.</p>
                <?php endif; ?>
            </section>

            <section class="card">
                <h3>Quick Add Word</h3>
                <form method="post" enctype="multipart/form-data" action="index.php?a=create_word" id="quick-add-form">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="recorded_audio" id="recorded_audio">
                    <div class="grid grid-3 responsive">
                        <div>
                            <label for="hebrew">Hebrew *</label>
                            <input id="hebrew" name="hebrew" required placeholder="לְדֻגְמָה" dir="rtl" inputmode="text" autocomplete="off" spellcheck="false" lang="he">
                        </div>
                        <div>
                            <label for="transliteration">Transliteration</label>
                            <input id="transliteration" name="transliteration" placeholder="le-dugma" autocomplete="off">
                        </div>
                        <div>
                            <label for="part_of_speech">Part of speech</label>
                            <select id="part_of_speech" name="part_of_speech">
                                <option value="">Unspecified</option>
                                <option value="noun">Noun</option>
                                <option value="verb">Verb</option>
                                <option value="adj">Adjective</option>
                                <option value="adv">Adverb</option>
                                <option value="phrase">Phrase</option>
                                <option value="prep">Preposition</option>
                                <option value="pron">Pronoun</option>
                            </select>
                        </div>
                    </div>
                    <label for="notes">Notes</label>
                    <textarea id="notes" name="notes" rows="3" placeholder="Any nuances, gender, irregular forms..." maxlength="320"></textarea>

                    <label for="audio">Pronunciation (audio/mp3/wav/ogg ≤ 10MB)</label>
                    <div class="record-row">
                        <input id="audio" type="file" name="audio" accept="audio/*">
                        <div class="record-controls" id="record-controls">
                            <button type="button" class="btn ghost" id="record-toggle" data-state="idle">🎙️ Record</button>
                            <button type="button" class="btn primary" id="record-save" disabled>Use recording</button>
                        </div>
                    </div>
                    <p class="record-support" id="record-support-message" hidden>Recording is not supported in this browser.</p>
                    <div class="record-preview" id="record-preview" hidden>
                        <audio id="recorded-audio" controls></audio>
                        <button type="button" class="btn ghost" id="record-discard">Discard</button>
                    </div>

                    <div class="grid grid-3 responsive">
                        <div>
                            <label for="lang_code">Translation language</label>
                            <input id="lang_code" name="lang_code" placeholder="e.g., ru, en, fr" pattern="^[a-z]{2}(-[A-Z]{2})?$" title="Use a two-letter language code, e.g. en or ru">
                        </div>
                        <div>
                            <label for="other_script">Other script (spelling)</label>
                            <input id="other_script" name="other_script" placeholder="пример / example">
                        </div>
                        <div>
                            <label for="meaning">Meaning (gloss)</label>
                            <input id="meaning" name="meaning" placeholder="example / пример" maxlength="160">
                        </div>
                    </div>
                    <label for="example">Example (optional)</label>
                    <textarea id="example" name="example" rows="2" placeholder="Use in a sentence" maxlength="320"></textarea>

                    <label for="deck_id">Add to deck</label>
                    <select id="deck_id" name="deck_id">
                        <?php foreach ($decks as $deckOption): ?>
                            <option value="<?= (int) $deckOption['id'] ?>" <?= (int) $deckOption['id'] === $selectedDeckId ? 'selected' : '' ?>>
                                <?= h($deckOption['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div class="form-actions">
                        <button class="btn primary" type="submit">Add</button>
                    </div>
                </form>
            </section>

            <?php if ($searchTerm !== '' && $searchResults): ?>
                <section class="card">
                    <h3>Search Results</h3>
                    <table class="table">
                        <thead>
                        <tr>
                            <th>Hebrew</th>
                            <th>Translit</th>
                            <th>POS</th>
                            <th>Decks</th>
                            <th>Translations</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($searchResults as $row): ?>
                            <tr>
                                <td><?= h($row['hebrew']) ?></td>
                                <td><?= h($row['transliteration']) ?></td>
                                <td><?= h($row['part_of_speech']) ?></td>
                                <td><?= h($row['deck_names'] ?? '—') ?></td>
                                <td><pre class="translations-pre"><?= h($row['translations_summary']) ?></pre></td>
                                <td><a class="btn ghost" href="edit_word.php?id=<?= (int) $row['id'] ?>">Edit</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>
            <?php elseif ($searchTerm !== ''): ?>
                <section class="card">
                    <h3>Search Results</h3>
                    <p>No results found.</p>
                </section>
            <?php endif; ?>
        </section>

        <section class="screen" data-screen="library" <?= $screen === 'library' ? '' : 'hidden' ?>>
            <div class="card">
                <div class="library-header">
                    <div>
                        <h2>Decks library</h2>
                        <p class="section-subtitle">Explore curated decks shared by the community.</p>
                    </div>
                    <button class="btn primary" data-dialog-open="create-deck">New deck</button>
                </div>

                <div class="filter-bar">
                    <button class="filter-btn active" data-filter="all">All (<?= count($decks) ?>)</button>
                    <button class="filter-btn" data-filter="popular">Popular (<?= count($popularDecks) ?>)</button>
                    <?php foreach ($categories as $category): ?>
                        <button class="filter-btn" data-filter="<?= h($category) ?>"><?= h($category) ?></button>
                    <?php endforeach; ?>
                </div>

                <div class="deck-grid" id="deck-grid">
                    <?php foreach ($decks as $deck): ?>
                        <article class="deck-card" data-category="<?= h($deck['category'] ?? 'General') ?>" data-popular="<?= ((float) $deck['rating'] >= 4.5 || (int) $deck['learners_count'] >= 200) ? '1' : '0' ?>" data-frozen="<?= (int) ($deck['is_frozen'] ?? 0) ?>" data-reversed="<?= (int) ($deck['is_reversed'] ?? 0) ?>">
                            <header class="deck-card-header" style="--deck-accent: <?= h($deck['color'] ?? '#6366f1') ?>;">
                                <div class="deck-card-icon"><?= h($deck['icon'] ?? 'book') ?></div>
                                <button class="deck-card-menu" data-deck-sheet="<?= (int) $deck['id'] ?>" aria-label="Deck options">⋮</button>
                            </header>
                            <h3><?= h($deck['name']) ?></h3>
                            <p class="deck-card-desc"><?= h($deck['description'] ?? 'No description yet.') ?></p>
                            <dl class="deck-card-stats">
                                <div>
                                    <dt>Cards</dt>
                                    <dd><?= (int) $deck['cards_count'] ?></dd>
                                </div>
                                <div>
                                    <dt>Rating</dt>
                                    <dd><?= number_format((float) $deck['rating'], 1) ?></dd>
                                </div>
                                <div>
                                    <dt>Studiers</dt>
                                    <dd><?= (int) $deck['learners_count'] ?></dd>
                                </div>
                            </dl>
                            <div class="deck-card-progress">
                                <div class="progress-bar"><span style="width: <?= (int) $deck['progress_percent'] ?>%"></span></div>
                                <span><?= (int) $deck['progress_percent'] ?>% mastered</span>
                            </div>
                            <?php if ((int) $deck['id'] === $selectedDeckId): ?>
                                <span class="badge badge-active">Selected</span>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($selectedDeck): ?>
                <section class="card deck-detail">
                    <header>
                        <h3><?= h($selectedDeck['name']) ?> controls</h3>
                        <p class="section-subtitle">Fine-tune text-to-speech, AI generation, offline mode and more.</p>
                    </header>
                    <div class="deck-settings-grid">
                        <form method="post" action="index.php?a=update_deck_details" class="deck-form">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
                            <label for="deck-name">Deck name</label>
                            <input id="deck-name" name="name" value="<?= h($selectedDeck['name']) ?>" required>
                            <label for="deck-description">Description</label>
                            <textarea id="deck-description" name="description" rows="3" placeholder="Tell learners what to expect."><?= h($selectedDeck['description'] ?? '') ?></textarea>
                            <label for="deck-category">Category</label>
                            <input id="deck-category" name="category" value="<?= h($selectedDeck['category'] ?? 'General') ?>">
                            <button class="btn primary" type="submit">Save deck</button>
                        </form>

                        <div class="deck-toggles">
                            <form method="post" action="index.php?a=toggle_deck_flag" class="toggle-row">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
                                <input type="hidden" name="flag" value="ai_generation_enabled">
                                <input type="hidden" name="value" value="<?= (int) ($selectedDeck['ai_generation_enabled'] ?? 0) ? 0 : 1 ?>">
                                <label>AI cards generation <span class="badge">Beta</span></label>
                                <button class="switch <?= (int) ($selectedDeck['ai_generation_enabled'] ?? 0) ? 'on' : '' ?>" type="submit" aria-pressed="<?= (int) ($selectedDeck['ai_generation_enabled'] ?? 0) ? 'true' : 'false' ?>">
                                    <span class="sr-only"><?= (int) ($selectedDeck['ai_generation_enabled'] ?? 0) ? 'On' : 'Off' ?></span>
                                </button>
                            </form>
                            <form method="post" action="index.php?a=toggle_deck_flag" class="toggle-row">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
                                <input type="hidden" name="flag" value="offline_enabled">
                                <input type="hidden" name="value" value="<?= (int) ($selectedDeck['offline_enabled'] ?? 0) ? 0 : 1 ?>">
                                <label>Offline learning</label>
                                <button class="switch <?= (int) ($selectedDeck['offline_enabled'] ?? 0) ? 'on' : '' ?>" type="submit" aria-pressed="<?= (int) ($selectedDeck['offline_enabled'] ?? 0) ? 'true' : 'false' ?>">
                                    <span class="sr-only"><?= (int) ($selectedDeck['offline_enabled'] ?? 0) ? 'On' : 'Off' ?></span>
                                </button>
                            </form>
                            <form method="post" action="index.php?a=toggle_deck_flag" class="toggle-row">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
                                <input type="hidden" name="flag" value="is_reversed">
                                <input type="hidden" name="value" value="<?= (int) ($selectedDeck['is_reversed'] ?? 0) ? 0 : 1 ?>">
                                <label>Reverse cards</label>
                                <button class="switch <?= (int) ($selectedDeck['is_reversed'] ?? 0) ? 'on' : '' ?>" type="submit" aria-pressed="<?= (int) ($selectedDeck['is_reversed'] ?? 0) ? 'true' : 'false' ?>">
                                    <span class="sr-only"><?= (int) ($selectedDeck['is_reversed'] ?? 0) ? 'On' : 'Off' ?></span>
                                </button>
                            </form>
                            <form method="post" action="index.php?a=toggle_deck_flag" class="toggle-row">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
                                <input type="hidden" name="flag" value="is_frozen">
                                <input type="hidden" name="value" value="<?= (int) ($selectedDeck['is_frozen'] ?? 0) ? 0 : 1 ?>">
                                <label>Freeze deck</label>
                                <button class="switch <?= (int) ($selectedDeck['is_frozen'] ?? 0) ? 'on' : '' ?>" type="submit" aria-pressed="<?= (int) ($selectedDeck['is_frozen'] ?? 0) ? 'true' : 'false' ?>">
                                    <span class="sr-only"><?= (int) ($selectedDeck['is_frozen'] ?? 0) ? 'On' : 'Off' ?></span>
                                </button>
                            </form>
                            <form method="post" action="index.php?a=toggle_deck_flag" class="toggle-row">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
                                <input type="hidden" name="flag" value="tts_enabled">
                                <input type="hidden" name="value" value="<?= (int) ($selectedDeck['tts_enabled'] ?? 0) ? 0 : 1 ?>">
                                <label>Text-to-speech</label>
                                <button class="switch <?= (int) ($selectedDeck['tts_enabled'] ?? 0) ? 'on' : '' ?>" type="submit" aria-pressed="<?= (int) ($selectedDeck['tts_enabled'] ?? 0) ? 'true' : 'false' ?>">
                                    <span class="sr-only"><?= (int) ($selectedDeck['tts_enabled'] ?? 0) ? 'On' : 'Off' ?></span>
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="deck-secondary-actions">
                        <form method="post" action="index.php?a=select_deck">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
                            <button class="btn ghost" type="submit">Select deck</button>
                        </form>
                        <form method="post" action="index.php?a=duplicate_deck">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
                            <button class="btn ghost" type="submit">Duplicate deck</button>
                        </form>
                        <form method="post" action="index.php?a=move_deck">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
                            <input type="hidden" name="category" value="Favorites">
                            <button class="btn ghost" type="submit">Move to Favorites</button>
                        </form>
                        <form method="post" action="index.php?a=reset_deck_progress" onsubmit="return confirm('Reset progress for this deck?');">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
                            <button class="btn ghost" type="submit">Reset progress</button>
                        </form>
                        <form method="post" action="index.php?a=archive_deck" onsubmit="return confirm('Archive this deck?');">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
                            <button class="btn ghost" type="submit">Archive deck</button>
                        </form>
                        <a class="btn ghost" href="import_csv.php">Import cards</a>
                    </div>

                    <form class="publish-card" method="post" action="index.php?a=publish_deck">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
                        <h4>Publish in library</h4>
                        <p>Add a short description to share this deck publicly. You need at least <?= (int) ($selectedDeck['min_cards_required'] ?? 75) ?> cards.</p>
                        <textarea name="publish_description" rows="2" placeholder="Enter text here (30 characters minimum)"></textarea>
                        <button class="btn primary" type="submit">Submit for review</button>
                    </form>

                    <div class="deck-history" id="deck-history">
                        <header>
                            <h4>Learning history</h4>
                            <p class="section-subtitle">Recent reviews with proficiency levels.</p>
                        </header>
                        <?php if ($deckHistory): ?>
                            <ul>
                                <?php foreach ($deckHistory as $row): ?>
                                    <li>
                                        <strong><?= h($row['hebrew']) ?></strong>
                                        <span><?= h($row['transliteration']) ?></span>
                                        <span class="badge">Level <?= (int) $row['proficiency'] ?></span>
                                        <time><?= h($row['last_reviewed_at']) ?></time>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p>No review history yet.</p>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>
        </section>

        <section class="screen" data-screen="settings" <?= $screen === 'settings' ? '' : 'hidden' ?>>
            <section class="card settings-profile">
                <div class="avatar" role="img" aria-label="Kristina Artemova avatar">K</div>
                <div>
                    <h2>Kristina Artemova</h2>
                    <p>@kristinaartemova</p>
                </div>
                <button class="btn ghost" type="button" data-dialog-open="account">Manage account</button>
            </section>

            <section class="card settings-section">
                <h3>General</h3>
                <div class="settings-item">
                    <div>
                        <p>Appearance</p>
                        <span>Switch between light and dark themes.</span>
                    </div>
                    <button class="btn ghost" data-theme-toggle>Auto</button>
                </div>
                <div class="settings-item">
                    <div>
                        <p>Language pack</p>
                        <span>Install additional interface languages.</span>
                    </div>
                    <button class="btn ghost" type="button" data-dialog-open="language">Open language pack</button>
                </div>
                <div class="settings-item">
                    <div>
                        <p>App icon</p>
                        <span>Choose an icon for your home screen.</span>
                    </div>
                    <button class="btn ghost" type="button" data-dialog-open="app-icon">Choose icon</button>
                </div>
            </section>

            <section class="card settings-section">
                <h3>Notifications & Feedback</h3>
                <div class="settings-item">
                    <div>
                        <p>Reminders</p>
                        <span>Receive notifications to study cards.</span>
                    </div>
                    <div class="settings-toggle">
                        <button class="switch" data-toggle="reminders" aria-pressed="false" type="button">
                            <span class="sr-only">Off</span>
                        </button>
                        <p class="settings-hint" data-reminders-message hidden></p>
                    </div>

                </div>
                <div class="settings-item">
                    <div>
                        <p>Haptic feedback</p>
                        <span>Vibrate on correct and incorrect answers.</span>
                    </div>
                    <div class="settings-toggle">
                        <button class="switch" data-toggle="haptics" aria-pressed="false" type="button">
                            <span class="sr-only">Off</span>
                        </button>
                        <p class="settings-hint" data-haptics-message hidden></p>
                    </div>

                </div>
            </section>

            <section class="card settings-section">
                <h3>About</h3>
                <div class="settings-item link">
                    <span>What's new</span>
                    <a href="docs/mobile-roadmap.md" target="_blank" rel="noopener noreferrer">View release notes</a>
                </div>
                <div class="settings-item link">
                    <span>Help center</span>
                    <a href="https://example.com/help" target="_blank" rel="noopener noreferrer">Open help center</a>
                </div>
                <div class="settings-item link">
                    <span>Privacy policy</span>
                    <a href="https://example.com/privacy" target="_blank" rel="noopener noreferrer">Read privacy policy</a>
                </div>
                <div class="settings-item link">
                    <span>Terms of use</span>
                    <a href="https://example.com/terms" target="_blank" rel="noopener noreferrer">Read terms of use</a>
                </div>
                <div class="settings-item link">
                    <span>Send feedback</span>
                    <a href="mailto:hello@example.com">Email feedback<span class="sr-only"> (opens your email client)</span></a>
                </div>
                <div class="settings-item link">
                    <span>Rate us</span>
                    <a href="https://example.com/app" target="_blank" rel="noopener noreferrer">Open app store page</a>
                </div>
            </section>

            <section class="card settings-section">
                <h3>Text-to-speech</h3>
                <form method="post" action="index.php?a=update_tts" class="tts-form" data-tts-form>
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
                    <div class="settings-item">
                        <div>
                            <p>Front side language</p>
                            <span>Choose the language of the prompt.</span>
                        </div>
                        <select name="front_lang" data-rtl-sensitive>
                            <option value="en-US" <?= ($selectedDeck['tts_front_lang'] ?? '') === 'en-US' ? 'selected' : '' ?>>English (US)</option>
                            <option value="en-GB" <?= ($selectedDeck['tts_front_lang'] ?? '') === 'en-GB' ? 'selected' : '' ?>>English (UK)</option>
                            <option value="ru-RU" <?= ($selectedDeck['tts_front_lang'] ?? '') === 'ru-RU' ? 'selected' : '' ?>>Russian</option>
                            <option value="he-IL" <?= ($selectedDeck['tts_front_lang'] ?? '') === 'he-IL' ? 'selected' : '' ?>>Hebrew</option>
                        </select>
                    </div>
                    <div class="settings-item">
                        <div>
                            <p>Front side voice</p>
                            <span>Select the available voice for the prompt.</span>
                        </div>
                        <select name="front_voice" data-voice-select="front" data-rtl-sensitive data-initial-voice="<?= h($selectedDeck['tts_front_voice'] ?? '') ?>">
                            <option value="">System default</option>
                        </select>
                    </div>
                    <div class="settings-item">
                        <div>
                            <p>Back side language</p>
                            <span>Language used for answers.</span>
                        </div>
                        <select name="back_lang" data-rtl-sensitive>
                            <option value="he-IL" <?= ($selectedDeck['tts_back_lang'] ?? '') === 'he-IL' ? 'selected' : '' ?>>Hebrew</option>
                            <option value="en-US" <?= ($selectedDeck['tts_back_lang'] ?? '') === 'en-US' ? 'selected' : '' ?>>English (US)</option>
                            <option value="ru-RU" <?= ($selectedDeck['tts_back_lang'] ?? '') === 'ru-RU' ? 'selected' : '' ?>>Russian</option>
                        </select>
                    </div>
                    <div class="settings-item">
                        <div>
                            <p>Back side voice</p>
                            <span>Voice used for answers.</span>
                        </div>
                        <select name="back_voice" data-voice-select="back" data-rtl-sensitive data-initial-voice="<?= h($selectedDeck['tts_back_voice'] ?? '') ?>">
                            <option value="">System default</option>
                        </select>
                    </div>
                    <p class="settings-hint" data-tts-support hidden></p>
                    <button class="btn primary" type="submit" data-tts-submit disabled>Save TTS preferences</button>
                </form>

                <?php if ($deckSample): ?>
                    <div class="tts-preview" data-tts-sample>
                        <div class="tts-preview-card">
                            <h4><?= h($deckSample['is_reversed'] ? ($deckSample['meaning'] ?? '') : ($deckSample['hebrew'] ?? '')) ?></h4>
                            <p><?= h($deckSample['is_reversed'] ? ($deckSample['hebrew'] ?? '') : ($deckSample['meaning'] ?? '')) ?></p>
                        </div>
                        <button class="btn primary" type="button" data-tts-play>Play preview</button>
                        <p class="settings-hint" data-tts-message hidden></p>
                    </div>
                <?php endif; ?>
            </section>

            <section class="card settings-section">
                <h3>Follow us</h3>
                <div class="social-row">
                    <a href="https://instagram.com" class="social instagram" target="_blank" rel="noopener noreferrer">Instagram</a>
                    <a href="https://youtube.com" class="social youtube" target="_blank" rel="noopener noreferrer">YouTube</a>
                    <a href="https://facebook.com" class="social facebook" target="_blank" rel="noopener noreferrer">Facebook</a>
                </div>
                <p class="app-version">App version: 2.13.5 (1758135553)</p>
            </section>
        </section>
    </main>

    <nav class="bottom-nav" aria-label="Primary">
        <a href="?screen=home" class="bottom-nav-item<?= $screen === 'home' ? ' active' : '' ?>" data-nav="home" <?= $screen === 'home' ? 'aria-current="page"' : '' ?>>Home</a>
        <a href="?screen=library" class="bottom-nav-item<?= $screen === 'library' ? ' active' : '' ?>" data-nav="library" <?= $screen === 'library' ? 'aria-current="page"' : '' ?>>Library</a>
        <a href="?screen=settings" class="bottom-nav-item<?= $screen === 'settings' ? ' active' : '' ?>" data-nav="settings" <?= $screen === 'settings' ? 'aria-current="page"' : '' ?>>Settings</a>

    </nav>
</div>

<div class="deck-sheet" id="deck-sheet" role="dialog" aria-modal="true" aria-labelledby="deck-sheet-title" hidden>
    <div class="deck-sheet-content" tabindex="-1">
        <header>
            <h4 id="deck-sheet-title">Deck actions</h4>
            <button type="button" class="deck-sheet-close" data-deck-sheet-close aria-label="Close">Close</button>
        </header>
        <div class="deck-sheet-actions">
            <form method="post" action="index.php?a=select_deck">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="deck_id" value="">
                <button class="sheet-action" type="submit">Select</button>
            </form>
            <form method="post" action="index.php?a=toggle_deck_flag" data-sheet-toggle="is_frozen">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="deck_id" value="">
                <input type="hidden" name="flag" value="is_frozen">
                <input type="hidden" name="value" value="1">
                <button class="sheet-action" type="submit">Freeze</button>
            </form>
            <form method="post" action="index.php?a=move_deck">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="deck_id" value="">
                <input type="hidden" name="category" value="General">
                <button class="sheet-action" type="submit">Move</button>
            </form>
            <form method="post" action="index.php?a=toggle_deck_flag" data-sheet-toggle="is_reversed">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="deck_id" value="">
                <input type="hidden" name="flag" value="is_reversed">
                <input type="hidden" name="value" value="1">
                <button class="sheet-action" type="submit">Reverse</button>
            </form>
            <form method="post" action="index.php?a=duplicate_deck">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="deck_id" value="">
                <button class="sheet-action" type="submit">Duplicate</button>
            </form>
            <a class="sheet-action" href="?screen=library#deck-history">Learning history</a>
            <form method="post" action="index.php?a=delete_deck" data-confirm-message="Delete this deck?">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="deck_id" value="">
                <button class="sheet-action danger" type="submit">Delete</button>
            </form>
        </div>
    </div>
</div>


<div class="dialog" id="dialog-create-deck" role="dialog" aria-modal="true" aria-labelledby="dialog-create-deck-title" hidden>
    <form class="dialog-content" method="post" action="index.php?a=create_deck" tabindex="-1">
        <h3 id="dialog-create-deck-title">Create a new deck</h3>
        <p>Organise cards by topic or difficulty for quick study sessions.</p>
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <label for="dialog-deck-name">Deck name</label>
        <input id="dialog-deck-name" name="name" required>
        <label for="dialog-deck-description">Description</label>
        <textarea id="dialog-deck-description" name="description" rows="2" placeholder="e.g., Law terms"></textarea>
        <label for="dialog-deck-category">Category</label>
        <input id="dialog-deck-category" name="category" placeholder="Law, Geography, Math...">
        <div class="dialog-actions">
            <button type="button" class="btn ghost" data-dialog-close aria-label="Close dialog">Cancel</button>
            <button type="submit" class="btn primary">Create</button>
        </div>
    </form>
</div>

<div class="dialog" id="dialog-language" role="dialog" aria-modal="true" aria-labelledby="dialog-language-title" aria-describedby="dialog-language-description" hidden>
    <div class="dialog-content" tabindex="-1">
        <h3 id="dialog-language-title">Language packs</h3>
        <p id="dialog-language-description">Download and enable interface translations. Saved language is remembered for your next visit.</p>
        <ul class="dialog-list">
            <li><button type="button" class="btn ghost" data-set-ui-lang="en-US">English (US)</button></li>
            <li><button type="button" class="btn ghost" data-set-ui-lang="he-IL">עברית (Hebrew)</button></li>
        </ul>
        <div class="dialog-actions">
            <button type="button" class="btn primary" data-dialog-close>Done</button>
        </div>
    </div>
</div>

<div class="dialog" id="dialog-app-icon" role="dialog" aria-modal="true" aria-labelledby="dialog-app-icon-title" aria-describedby="dialog-app-icon-description" hidden>
    <div class="dialog-content" tabindex="-1">
        <h3 id="dialog-app-icon-title">Choose app icon</h3>
        <p id="dialog-app-icon-description">Select the colour that fits your home screen best.</p>
        <div class="dialog-grid" data-app-icon-picker>
            <button type="button" class="icon-option" data-icon="sparkles">✨ Sparkles</button>
            <button type="button" class="icon-option" data-icon="book">📘 Book</button>
            <button type="button" class="icon-option" data-icon="compass">🧭 Compass</button>
        </div>
        <div class="dialog-actions">
            <button type="button" class="btn ghost" data-dialog-close>Cancel</button>
            <button type="button" class="btn primary" data-save-app-icon>Apply</button>
        </div>
    </div>
</div>

<div class="dialog" id="dialog-account" role="dialog" aria-modal="true" aria-labelledby="dialog-account-title" aria-describedby="dialog-account-description" hidden>
    <div class="dialog-content" tabindex="-1">
        <h3 id="dialog-account-title">Manage account</h3>
        <p id="dialog-account-description">Update your profile details, change the password, or download your data export.</p>
        <div class="dialog-actions">
            <a class="btn ghost" href="logout.php">Sign out</a>
            <a class="btn primary" href="register.php">Update profile</a>
            <button type="button" class="btn ghost" data-dialog-close>Close</button>
        </div>
    </div>
</div>

<?php if (!empty($memoryData)): ?>
    <script type="application/json" id="memory-data"><?= json_encode($memoryData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?></script>
<?php endif; ?>


<script src="scripts/app-shell.js" defer></script>
=
</body>
</html>
