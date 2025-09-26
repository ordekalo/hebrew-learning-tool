<section class="panel panel--form" aria-labelledby="create-deck-title">
    <h3 id="create-deck-title">יצירת Deck חדש</h3>
    <p class="panel-subtitle">ארגנו מילים בקבוצות קטנות. כל Deck מתמקד בנושא מסוים כמו נסיעות, שיחות יומיומיות או דקדוק.</p>
    <form method="post" action="index.php?screen=library&amp;a=create_deck" class="stacked-form">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <label for="deck-name">שם Deck</label>
        <input id="deck-name" name="name" required placeholder="לדוגמה: שיחות מסעדה">

        <label for="deck-category">קטגוריה</label>
        <input id="deck-category" name="category" placeholder="תחבורה, עבודה, משפחה...">

        <label for="deck-description">תיאור קצר</label>
        <textarea id="deck-description" name="description" rows="2" placeholder="מה נלמד ב-Deck הזה?"></textarea>

        <button type="submit" class="btn primary">צור Deck</button>
    </form>
</section>

<section class="panel" aria-labelledby="deck-list-title">
    <div class="panel-header">
        <div>
            <h3 id="deck-list-title">הספריה האישית</h3>
            <p class="panel-subtitle">בחרו Deck כדי להתחיל ללמוד או לעדכן אותו. המדדים מסבירים מה מצבכם ביחס לכל Deck.</p>
        </div>
        <a class="btn ghost" href="decks.php">ניהול מתקדם</a>
    </div>

    <?php if (!$decks): ?>
        <p class="empty-state">עוד לא יצרתם Deck. התחילו עם אחד חדש ותוסיפו אליו כרטיסיות.</p>
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
                            <dt>כרטיסים</dt>
                            <dd><?= (int) ($deck['cards_count'] ?? 0) ?></dd>
                        </div>
                        <div>
                            <dt>נלמדו</dt>
                            <dd><?= (int) ($deck['studied_count'] ?? 0) ?></dd>
                        </div>
                        <div>
                            <dt>שולטים</dt>
                            <dd><?= (int) ($deck['mastered_count'] ?? 0) ?></dd>
                        </div>
                        <div>
                            <dt>התקדמות</dt>
                            <dd><?= (int) ($deck['progress_percent'] ?? 0) ?>%</dd>
                        </div>
                    </dl>
                    <footer class="deck-card__actions">
                        <a class="btn primary" href="study.php?deck=<?= (int) $deck['id'] ?>">למידה</a>
                        <a class="btn ghost" href="index.php?screen=<?= h($screen) ?>&amp;deck=<?= (int) $deck['id'] ?>">הפוך לפעיל</a>
                        <a class="btn ghost" href="words.php?deck=<?= (int) $deck['id'] ?>">כרטיסיות</a>
                    </footer>
                    <?php if (!empty($deck['last_reviewed_at'])): ?>
                        <?php $lastReviewedTs = strtotime((string) $deck['last_reviewed_at']); ?>
                        <p class="deck-card__meta">
                            חזרה אחרונה: <?= $lastReviewedTs ? h(date('d.m.Y', $lastReviewedTs)) : '—' ?>
                        </p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
