<?php if (!$selectedDeck): ?>
<section class="panel">
    <h3><?= h(t('settings.no_deck_heading')) ?></h3>
    <p class="panel-subtitle"><?= h(t('settings.no_deck_subtitle')) ?></p>
    <a class="btn primary" href="index.php?screen=library&amp;lang=<?= h($langCode) ?>"><?= h(t('settings.to_library')) ?></a>
</section>
<?php else: ?>
<section class="panel panel--form" aria-labelledby="deck-details-title">
    <h3 id="deck-details-title"><?= h(t('settings.update_heading')) ?></h3>
    <p class="panel-subtitle"><?= h(t('settings.update_subtitle')) ?></p>
    <form method="post" action="index.php?screen=settings&amp;a=update_deck_details" class="stacked-form">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
        <input type="hidden" name="redirect" value="index.php?screen=settings">

        <label for="settings-name"><?= h(t('settings.name_label')) ?></label>
        <input id="settings-name" name="name" required value="<?= h($selectedDeck['name']) ?>">

        <label for="settings-category"><?= h(t('settings.category_label')) ?></label>
        <input id="settings-category" name="category" value="<?= h($selectedDeck['category'] ?? '') ?>">

        <label for="settings-description"><?= h(t('settings.description_label')) ?></label>
        <textarea id="settings-description" name="description" rows="3"><?= h($selectedDeck['description'] ?? '') ?></textarea>

        <button type="submit" class="btn primary"><?= h(t('settings.save')) ?></button>
    </form>
</section>

<section class="panel" aria-labelledby="preferences-title">
    <h3 id="preferences-title"><?= h(t('settings.preferences_heading')) ?></h3>
    <p class="panel-subtitle"><?= h(t('settings.preferences_subtitle')) ?></p>

    <div class="toggle-grid">
        <form method="post" action="index.php?screen=settings&amp;a=toggle_deck_flag" class="toggle-card">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
            <input type="hidden" name="flag" value="is_reversed">
            <input type="hidden" name="value" value="<?= (int) ($selectedDeck['is_reversed'] ?? 0) === 1 ? 0 : 1 ?>">
            <input type="hidden" name="redirect" value="index.php?screen=settings">
            <h4><?= h(t('settings.card_reversal_heading')) ?></h4>
            <p><?= h(t('settings.card_reversal_description')) ?></p>
            <button type="submit" class="toggle-button<?= (int) ($selectedDeck['is_reversed'] ?? 0) === 1 ? ' is-on' : '' ?>">
                <?= (int) ($selectedDeck['is_reversed'] ?? 0) === 1 ? h(t('settings.toggle_enabled')) : h(t('settings.toggle_disabled')) ?>
            </button>
        </form>

        <form method="post" action="index.php?screen=settings&amp;a=toggle_deck_flag" class="toggle-card">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
            <input type="hidden" name="flag" value="tts_enabled">
            <input type="hidden" name="value" value="<?= (int) ($selectedDeck['tts_enabled'] ?? 0) === 1 ? 0 : 1 ?>">
            <input type="hidden" name="redirect" value="index.php?screen=settings">
            <h4><?= h(t('settings.tts_heading')) ?></h4>
            <p><?= h(t('settings.tts_description')) ?></p>
            <button type="submit" class="toggle-button<?= (int) ($selectedDeck['tts_enabled'] ?? 0) === 1 ? ' is-on' : '' ?>">
                <?= (int) ($selectedDeck['tts_enabled'] ?? 0) === 1 ? h(t('settings.toggle_enabled')) : h(t('settings.toggle_disabled')) ?>
            </button>
        </form>

        <form method="post" action="index.php?screen=settings&amp;a=duplicate_deck" class="toggle-card">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
            <input type="hidden" name="redirect" value="index.php?screen=settings">
            <h4><?= h(t('settings.duplicate_heading')) ?></h4>
            <p><?= h(t('settings.duplicate_description')) ?></p>
            <button type="submit" class="toggle-button"><?= h(t('settings.duplicate_action')) ?></button>
        </form>

        <form method="post" action="index.php?screen=settings&amp;a=delete_deck" class="toggle-card" onsubmit="return confirm('<?= h(t('settings.delete_confirm')) ?>');">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
            <h4><?= h(t('settings.delete_heading')) ?></h4>
            <p><?= h(t('settings.delete_description')) ?></p>
            <button type="submit" class="toggle-button danger"><?= h(t('settings.delete_action')) ?></button>
        </form>
    </div>
</section>
<?php endif; ?>
