<?php

declare(strict_types=1);

require __DIR__ . '/config.php';

$user = require_user($pdo);

$langs = $pdo->query('SELECT DISTINCT lang_code FROM translations WHERE lang_code IS NOT NULL AND lang_code <> "" ORDER BY lang_code')->fetchAll(PDO::FETCH_COLUMN);
$tags = $pdo->query('SELECT name FROM tags ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
$parts = $pdo->query('SELECT DISTINCT part_of_speech FROM words WHERE part_of_speech IS NOT NULL AND part_of_speech <> "" ORDER BY part_of_speech')->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Search · Hebrew Learner</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="manifest" href="manifest.json">
</head>
<body class="search-body">
<header class="topbar" role="banner">
    <div class="topbar__left">
        <span class="logo">🔍</span>
        <div class="topbar__meta">
            <strong><?= h($user['email']) ?></strong>
            <span>Search your library</span>
        </div>
    </div>
    <nav class="topbar__actions" aria-label="Primary">
        <a class="btn btn-icon" href="index.php">🏠</a>
        <a class="btn btn-icon" href="study.php">▶️</a>
    </nav>
</header>
<main class="layout" role="main">
    <section class="card" aria-labelledby="search-heading">
        <h1 id="search-heading">Advanced search</h1>
        <form id="search-form" class="search-form" autocomplete="off">
            <label for="search-query">Query</label>
            <input id="search-query" name="q" placeholder="e.g., \"בית ספר\" tag:תחביר lang:ru" dir="rtl">
            <div class="grid grid-3">
                <div>
                    <label for="filter-lang">Language</label>
                    <select id="filter-lang" name="lang">
                        <option value="">Any</option>
                        <?php foreach ($langs as $lang): ?>
                            <option value="<?= h($lang) ?>"><?= h($lang) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="filter-pos">Part of speech</label>
                    <select id="filter-pos" name="pos">
                        <option value="">Any</option>
                        <?php foreach ($parts as $pos): ?>
                            <option value="<?= h($pos) ?>"><?= h($pos) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="filter-tag">Tag</label>
                    <select id="filter-tag" name="tag">
                        <option value="">Any</option>
                        <?php foreach ($tags as $tag): ?>
                            <option value="<?= h($tag) ?>"><?= h($tag) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="search-checkboxes">
                <label><input type="checkbox" name="has_audio" value="1"> With audio</label>
                <label><input type="checkbox" name="has_image" value="1"> With image</label>
            </div>
            <button class="btn" type="submit">Search</button>
        </form>
    </section>
    <section class="card" aria-live="polite">
        <h2>Results</h2>
        <div id="search-results" class="search-results" role="list"></div>
        <template id="search-item-template">
            <article class="search-result" role="listitem">
                <div class="search-result__head">
                    <h3 class="search-result__hebrew" dir="rtl"></h3>
                    <span class="search-result__pos"></span>
                </div>
                <p class="search-result__translit"></p>
                <ul class="search-result__translations"></ul>
                <div class="search-result__tags"></div>
            </article>
        </template>
    </section>
</main>
<script src="search.js" type="module"></script>
<script>
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('service-worker.js').catch(() => {});
}
</script>
</body>
</html>
