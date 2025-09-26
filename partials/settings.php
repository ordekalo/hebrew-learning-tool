<?php if (!$selectedDeck): ?>
<section class="panel">
    <h3>אין Deck פעיל</h3>
    <p class="panel-subtitle">צרו Deck חדש בספריה או בחרו אחד קיים כדי לערוך הגדרות מותאמות אישית.</p>
    <a class="btn primary" href="index.php?screen=library">לספריה</a>
</section>
<?php else: ?>
<section class="panel panel--form" aria-labelledby="deck-details-title">
    <h3 id="deck-details-title">עדכון Deck</h3>
    <p class="panel-subtitle">שנו את שם ה-Deck, התיאור או הקטגוריה שלו. זה מסייע להישאר מסודרים כמו ב-Noji.</p>
    <form method="post" action="index.php?screen=settings&amp;a=update_deck_details" class="stacked-form">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
        <input type="hidden" name="redirect" value="index.php?screen=settings">

        <label for="settings-name">שם Deck</label>
        <input id="settings-name" name="name" required value="<?= h($selectedDeck['name']) ?>">

        <label for="settings-category">קטגוריה</label>
        <input id="settings-category" name="category" value="<?= h($selectedDeck['category'] ?? '') ?>">

        <label for="settings-description">תיאור</label>
        <textarea id="settings-description" name="description" rows="3"><?= h($selectedDeck['description'] ?? '') ?></textarea>

        <button type="submit" class="btn primary">שמירה</button>
    </form>
</section>

<section class="panel" aria-labelledby="preferences-title">
    <h3 id="preferences-title">העדפות Deck</h3>
    <p class="panel-subtitle">פעילויות וחוויית לימוד שמתאימות לרמת השליטה שלכם.</p>
    <div class="toggle-grid">
        <form method="post" action="index.php?screen=settings&amp;a=toggle_deck_flag" class="toggle-card">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
            <input type="hidden" name="flag" value="is_reversed">
            <input type="hidden" name="value" value="<?= (int) ($selectedDeck['is_reversed'] ?? 0) === 1 ? 0 : 1 ?>">
            <input type="hidden" name="redirect" value="index.php?screen=settings">
            <h4>היפוך כרטיסים</h4>
            <p>למדו מהתרגום לעברית ולהפך כדי לשפר שליפה דו-כיוונית.</p>
            <button type="submit" class="toggle-button<?= (int) ($selectedDeck['is_reversed'] ?? 0) === 1 ? ' is-on' : '' ?>">
                <?= (int) ($selectedDeck['is_reversed'] ?? 0) === 1 ? 'מופעל' : 'כבוי' ?>
            </button>
        </form>

        <form method="post" action="index.php?screen=settings&amp;a=toggle_deck_flag" class="toggle-card">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
            <input type="hidden" name="flag" value="tts_enabled">
            <input type="hidden" name="value" value="<?= (int) ($selectedDeck['tts_enabled'] ?? 0) === 1 ? 0 : 1 ?>">
            <input type="hidden" name="redirect" value="index.php?screen=settings">
            <h4>קריינות (TTS)</h4>
            <p>השמעת הכרטיס בזמן הלימוד מחזקת את הזיכרון השמיעתי.</p>
            <button type="submit" class="toggle-button<?= (int) ($selectedDeck['tts_enabled'] ?? 0) === 1 ? ' is-on' : '' ?>">
                <?= (int) ($selectedDeck['tts_enabled'] ?? 0) === 1 ? 'מופעל' : 'כבוי' ?>
            </button>
        </form>

        <form method="post" action="index.php?screen=settings&amp;a=duplicate_deck" class="toggle-card">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
            <input type="hidden" name="redirect" value="index.php?screen=settings">
            <h4>שכפול Deck</h4>
            <p>צרו עותק לעבודה נפרדת בלי לפגוע במקור.</p>
            <button type="submit" class="toggle-button">שכפול</button>
        </form>

        <form method="post" action="index.php?screen=settings&amp;a=delete_deck" class="toggle-card" onsubmit="return confirm('למחוק את ה-Deck? לא ניתן לבטל.');">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
            <h4>מחיקת Deck</h4>
            <p>מסירים לגמרי את הקבוצה הזו ואת שיוכי הכרטיסים.</p>
            <button type="submit" class="toggle-button danger">מחיקה</button>
        </form>
    </div>
</section>
<?php endif; ?>
