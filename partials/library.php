<section class="panel panel--form" aria-labelledby="create-deck-title">
    <h3 id="create-deck-title"><?= h(t('library.create_heading')) ?></h3>
    <p class="panel-subtitle"><?= h(t('library.create_subtitle')) ?></p>
    <form method="post" action="index.php?screen=library&amp;a=create_deck" class="stacked-form">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <label for="deck-name"><?= h(t('library.name_label')) ?></label>
        <input id="deck-name" name="name" required placeholder="<?= h(t('library.name_placeholder')) ?>">

        <label for="deck-category"><?= h(t('library.category_label')) ?></label>
        <input id="deck-category" name="category" placeholder="<?= h(t('library.category_placeholder')) ?>">

        <label for="deck-description"><?= h(t('library.description_label')) ?></label>
        <textarea id="deck-description" name="description" rows="2" placeholder="<?= h(t('library.description_placeholder')) ?>"></textarea>

        <button type="submit" class="btn primary"><?= h(t('library.submit')) ?></button>
    </form>
</section>

<section class="panel" aria-labelledby="deck-list-title">
    <div class="panel-header">
        <div>
            <h3 id="deck-list-title"><?= h(t('library.list_heading')) ?></h3>
            <p class="panel-subtitle"><?= h(t('library.list_subtitle')) ?></p>
        </div>
        <a class="btn ghost" href="decks.php"><?= h(t('library.manage_link')) ?></a>
    </div>

    <?php if (!$decks): ?>
        <p class="empty-state"><?= h(t('library.empty_state')) ?></p>
    <?php else: ?>
        <div class="deck-list">
            <?php foreach ($decks as $deck): ?>
                <article class="deck-card<?= $deck['id'] == $selectedDeckId ? ' is-selected' : '' ?>">
                    <header>
                        <h4><?= h($deck['name']) ?></h4>
                        <?php if (!empty($deck['description'])): ?>
                            <p class="deck-card__description"><?= h($deck['description']) ?></p>
                        <?php endif; ?>
                    </header>
                    <dl class="deck-card__stats">
                        <div>
                            <dt><?= h(t('library.cards')) ?></dt>
                            <dd><?= (int) ($deck['cards_count'] ?? 0) ?></dd>
                        </div>
                        <div>
                            <dt><?= h(t('library.studied')) ?></dt>
                            <dd><?= (int) ($deck['studied_count'] ?? 0) ?></dd>
                        </div>
                        <div>
                            <dt><?= h(t('library.mastered')) ?></dt>
                            <dd><?= (int) ($deck['mastered_count'] ?? 0) ?></dd>
                        </div>
                        <div>
                            <dt><?= h(t('library.progress')) ?></dt>
                            <dd><?= (int) ($deck['progress_percent'] ?? 0) ?>%</dd>
                        </div>
                    </dl>
                    <footer class="deck-card__actions">
                        <a class="btn primary" href="study.php?deck=<?= (int) $deck['id'] ?>"><?= h(t('library.study')) ?></a>
                        <a class="btn ghost" href="index.php?screen=<?= h($screen) ?>&amp;deck=<?= (int) $deck['id'] ?>&amp;lang=<?= h($langCode) ?>"><?= h(t('library.set_active')) ?></a>
                        <a class="btn ghost" href="words.php?deck=<?= (int) $deck['id'] ?>"><?= h(t('library.view_cards')) ?></a>
                    </footer>
                    <?php if (!empty($deck['last_reviewed_at'])): ?>
                        <?php $lastReviewedTs = strtotime((string) $deck['last_reviewed_at']); ?>
                        <p class="deck-card__meta">
                            <?= h(t('library.last_review', ['date' => $lastReviewedTs ? date('M j, Y', $lastReviewedTs) : '—'])) ?>
                        </p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
