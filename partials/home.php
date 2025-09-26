<section class="panel hero">
    <div class="hero__content">
        <h3>לימוד חכם ומרוכז</h3>
        <p>
            Noji הראתה כמה פשוט יכול להיות שינון יעיל: כרטיס אחד בכל פעם, משוב קצר,
            וקצב שמותאם אליך. הבית המרכזי מציג את הסטטוס היומי ומאפשר לצלול מיד לתרגול.
        </p>
        <dl class="hero-metrics">
            <div>
                <dt>כרטיסים לתרגול היום</dt>
                <dd><?= $dueSummary['deck'] !== null ? (int) $dueSummary['deck'] : '—' ?></dd>
            </div>
            <div>
                <dt>כרטיסים בכל הסטים</dt>
                <dd><?= $dueSummary['total'] !== null ? (int) $dueSummary['total'] : '—' ?></dd>
            </div>
            <?php if ($selectedDeck): ?>
                <div>
                    <dt>התקדמות ב-<?= h($selectedDeck['name']) ?></dt>
                    <dd><?= (int) ($selectedDeck['progress_percent'] ?? 0) ?>%</dd>
                </div>
            <?php endif; ?>
        </dl>
        <div class="hero-actions">
            <?php if ($selectedDeckId > 0): ?>
                <a class="btn primary" href="study.php?deck=<?= (int) $selectedDeckId ?>">התחלת סשן</a>
            <?php else: ?>
                <a class="btn primary" href="study.php">התחלת סשן</a>
            <?php endif; ?>
            <a class="btn ghost" href="study.php">תרגול חופשי</a>
            <?php if ($selectedDeckId > 0): ?>
                <form method="post" action="index.php?screen=home&amp;a=seed_openers" class="inline-form">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
                    <button type="submit" class="btn ghost">הוספת משפטי פתיחה</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="hero__visual" aria-hidden="true">
        <div class="hero-bubble">🗂️</div>
        <p>החזרות נבנות סביב הרגע שבו כמעט שוכחים — בדיוק כמו ב-Noji.</p>
    </div>
</section>

<?php if ($sampleCard): ?>
<section class="panel sample-card" aria-labelledby="sample-card-title">
    <div class="panel-header">
        <div>
            <h3 id="sample-card-title">טעימה מהכרטיס הבא</h3>
            <p class="panel-subtitle">ככה תראה הכרטיסיה שלך בלמידה. צד אחד עברית, צד שני משמעות.</p>
        </div>
        <a class="btn ghost" href="words.php">ניהול אוצר מילים</a>
    </div>
    <div class="sample-card__body">
        <div class="sample-card__word">
            <span class="sample-card__label">עברית</span>
            <strong dir="rtl"><?= h($sampleCard['hebrew'] ?? '—') ?></strong>
            <?php if (!empty($sampleCard['transliteration'])): ?>
                <span class="sample-card__translit" dir="ltr"><?= h($sampleCard['transliteration']) ?></span>
            <?php endif; ?>
        </div>
        <div class="sample-card__meaning">
            <span class="sample-card__label">משמעות</span>
            <p><?= h($sampleCard['meaning'] ?? 'אין תרגום שמור עדיין') ?></p>
            <?php if (!empty($sampleCard['other_script'])): ?>
                <p class="sample-card__alt"><?= h($sampleCard['other_script']) ?></p>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="panel panel--form" aria-labelledby="quick-add-title">
    <h3 id="quick-add-title">הוספת כרטיס חדש</h3>
    <p class="panel-subtitle">שמור/י מילה או ביטוי חדש ישירות ל-Deck הנוכחי. ההוספה פשוטה ומהירה.</p>
    <form method="post" action="index.php?screen=home&amp;a=create_word" class="stacked-form">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="deck_id" value="<?= (int) $selectedDeckId ?>">
        <input type="hidden" name="lang_code" value="en">
        <label for="hebrew-input">עברית</label>
        <input id="hebrew-input" name="hebrew" required placeholder="לדוגמה: מה שלומך?">

        <label for="translit-input">תעתיק (לא חובה)</label>
        <input id="translit-input" name="transliteration" placeholder="ma shlomkha?">

        <label for="meaning-input">משמעות / תרגום</label>
        <input id="meaning-input" name="meaning" placeholder="How are you?">

        <label for="notes-input">הערות (לא חובה)</label>
        <textarea id="notes-input" name="notes" rows="3" placeholder="הקשר, דוגמאות או טיפים לזכירה"></textarea>

        <button type="submit" class="btn primary">שמירה לכרטיסיות</button>
    </form>
</section>

<?php if ($recentHistory): ?>
<section class="panel" aria-labelledby="history-title">
    <h3 id="history-title">מה תרגלת לאחרונה</h3>
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
                    <?= h($entry['last_reviewed_at'] ? date('d.m.Y H:i', strtotime($entry['last_reviewed_at'])) : '—') ?> ·
                    <?= h($entry['proficiency'] !== null ? 'רמה ' . $entry['proficiency'] : 'חזרה ראשונה') ?>
                </span>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
