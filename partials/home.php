<section class="panel hero">
    <div class="hero__content">
        <h3><?= h(t('hero.heading')) ?></h3>
        <p><?= h(t('hero.description')) ?></p>
        <dl class="hero-metrics">
            <div>
                <dt><?= h(t('hero.metric_today')) ?></dt>
                <dd><?= $dueSummary['deck'] !== null ? (int) $dueSummary['deck'] : '—' ?></dd>
            </div>
            <div>
                <dt><?= h(t('hero.metric_total')) ?></dt>
                <dd><?= $dueSummary['total'] !== null ? (int) $dueSummary['total'] : '—' ?></dd>
            </div>
            <?php if ($selectedDeck): ?>
                <div>
                    <dt><?= h(t('hero.progress_in_deck', ['deck' => $selectedDeck['name']])) ?></dt>
                    <dd><?= (int) ($selectedDeck['progress_percent'] ?? 0) ?>%</dd>
                </div>
            <?php endif; ?>
        </dl>
        <div class="hero-actions">
            <?php if ($selectedDeckId > 0): ?>
                <a class="btn primary" href="study.php?deck=<?= (int) $selectedDeckId ?>"><?= h(t('hero.start_session')) ?></a>
            <?php else: ?>
                <a class="btn primary" href="study.php"><?= h(t('hero.start_session')) ?></a>
            <?php endif; ?>
            <a class="btn ghost" href="study.php"><?= h(t('hero.free_practice')) ?></a>

            <?php if ($selectedDeckId > 0): ?>
                <form method="post" action="index.php?screen=home&amp;a=seed_openers" class="inline-form">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
                    <button type="submit" class="btn ghost"><?= h(t('hero.seed_openers')) ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="hero__visual" aria-hidden="true">
        <div class="hero-bubble">🗂️</div>
        <p><?= h(t('hero.visual_caption')) ?></p>
    </div>
</section>

<?php if ($sampleCard): ?>
<section class="panel sample-card" aria-labelledby="sample-card-title">
    <div class="panel-header">
        <div>
            <h3 id="sample-card-title"><?= h(t('sample.heading')) ?></h3>
            <p class="panel-subtitle"><?= h(t('sample.subtitle')) ?></p>
        </div>
        <a class="btn ghost" href="words.php"><?= h(t('sample.manage_vocab')) ?></a>
    </div>
    <div class="sample-card__body">
        <div class="sample-card__word">
            <span class="sample-card__label"><?= h(t('sample.hebrew_label')) ?></span>
            <strong dir="rtl"><?= h($sampleCard['hebrew'] ?? '—') ?></strong>
            <?php if (!empty($sampleCard['transliteration'])): ?>
                <span class="sample-card__translit" dir="ltr"><?= h($sampleCard['transliteration']) ?></span>
            <?php endif; ?>
        </div>
        <div class="sample-card__meaning">
            <span class="sample-card__label"><?= h(t('sample.meaning_label')) ?></span>
            <p><?= h($sampleCard['meaning'] ?? t('sample.no_translation')) ?></p>
            <?php if (!empty($sampleCard['other_script'])): ?>
                <p class="sample-card__alt"><?= h($sampleCard['other_script']) ?></p>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="panel panel--form" aria-labelledby="quick-add-title">
    <h3 id="quick-add-title"><?= h(t('quick_add.heading')) ?></h3>
    <p class="panel-subtitle"><?= h(t('quick_add.subtitle')) ?></p>
    <form method="post" action="index.php?screen=home&amp;a=create_word" class="stacked-form">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
        <input type="hidden" name="lang_code" value="<?= h($langCode) ?>">

        <label for="hebrew-input"><?= h(t('quick_add.hebrew_label')) ?></label>
        <input id="hebrew-input" name="hebrew" required placeholder="<?= h(t('quick_add.hebrew_placeholder')) ?>">

        <label for="translit-input"><?= h(t('quick_add.translit_label')) ?></label>
        <input id="translit-input" name="transliteration" placeholder="<?= h(t('quick_add.translit_placeholder')) ?>">

        <label for="meaning-input"><?= h(t('quick_add.meaning_label')) ?></label>
        <input id="meaning-input" name="meaning" placeholder="<?= h(t('quick_add.meaning_placeholder')) ?>">

        <label for="notes-input"><?= h(t('quick_add.notes_label')) ?></label>
        <textarea id="notes-input" name="notes" rows="3" placeholder="<?= h(t('quick_add.notes_placeholder')) ?>"></textarea>

        <button type="submit" class="btn primary"><?= h(t('quick_add.submit')) ?></button>
    </form>
</section>

<?php if ($recentHistory): ?>
<section class="panel" aria-labelledby="history-title">
    <h3 id="history-title"><?= h(t('history.heading')) ?></h3>
    <ul class="history-list">
        <?php foreach ($recentHistory as $entry): ?>
            <li>
                <div>
                    <strong dir="rtl"><?= h($entry['hebrew']) ?></strong>
                    <?php if (!empty($entry['transliteration'])): ?>
                        <span class="history-translit" dir="ltr"><?= h($entry['transliteration']) ?></span>
                    <?php endif; ?>
                </div>
                <span class="history-meta">
                    <?php
                    $timestamp = $entry['last_reviewed_at'] ? date('M j, Y H:i', strtotime($entry['last_reviewed_at'])) : '—';
                    $status = $entry['proficiency'] !== null
                        ? t('history.level', ['level' => (int) $entry['proficiency']])
                        : t('history.first_review');
                    echo h(t('history.timestamp', ['date' => $timestamp, 'status' => $status]));
                    ?>
                </span>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
