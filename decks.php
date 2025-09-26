<?php

declare(strict_types=1);

require __DIR__ . '/config.php';

$user = require_user($pdo);
$csrf = ensure_token();
$flash = get_flash();

if (is_post()) {
    check_token($_POST['csrf'] ?? null);

    if (isset($_POST['delete_id'])) {
        $deckId = (int) $_POST['delete_id'];
        $stmt = $pdo->prepare('SELECT cover_image FROM decks WHERE id = ?');
        $stmt->execute([$deckId]);
        $cover = $stmt->fetchColumn();
        $pdo->prepare('DELETE FROM decks WHERE id = ?')->execute([$deckId]);
        if ($cover) {
            delete_upload($cover, $UPLOAD_DIR);
        }
        flash('Deck deleted.', 'success');
        redirect('decks.php');
    }

    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        flash('Deck name is required.', 'error');
        redirect('decks.php');
    }

    $description = trim($_POST['description'] ?? '');
    $cover = null;
    try {
        $coverData = handle_image_upload($_FILES['cover'] ?? [], $UPLOAD_IMAGE_DIR);
        if ($coverData !== null) {
            $cover = $coverData['thumbnail'];
        }
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
        redirect('decks.php');
    }

    $pdo->prepare('INSERT INTO decks (name, description, cover_image) VALUES (?, ?, ?)')->execute([
        $name,
        $description !== '' ? $description : null,
        $cover,
    ]);

    flash('Deck created.', 'success');
    redirect('decks.php');
}

$stmt = $pdo->query('SELECT d.*, COUNT(dw.word_id) AS total_words,
    (SELECT COUNT(*) FROM deck_words dw2 LEFT JOIN user_progress up ON up.word_id = dw2.word_id AND up.user_id = ' . (int) $user['id'] . ' WHERE dw2.deck_id = d.id AND (up.due_at IS NULL OR up.due_at <= NOW())) AS due_count
    FROM decks d
    LEFT JOIN deck_words dw ON dw.deck_id = d.id
    GROUP BY d.id
    ORDER BY d.created_at DESC');
$decks = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Decks · Hebrew Learner</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<div class="container">
    <header class="header">
        <nav class="nav">
            <a href="index.php">← Back</a>
            <a href="words.php">Words</a>
        </nav>
    </header>

    <?php if ($flash): ?>
        <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <section class="card">
        <h2>Create deck</h2>
        <form method="post" enctype="multipart/form-data" class="grid grid-2">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <div>
                <label for="name">Name</label>
                <input id="name" name="name" required placeholder="Beginner verbs">
            </div>
            <div>
                <label for="description">Description</label>
                <input id="description" name="description" placeholder="Short summary">
            </div>
            <div>
                <label for="cover">Cover image</label>
                <input id="cover" type="file" name="cover" accept="image/*" capture="environment">
            </div>
            <div class="form-actions">
                <button class="btn" type="submit">Save</button>
            </div>
        </form>
    </section>

    <section class="card">
        <h2>Existing decks</h2>
        <?php if ($decks): ?>
            <table class="table">
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Description</th>
                    <th>Words</th>
                    <th>Due</th>
                    <th>Cover</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($decks as $deck): ?>
                    <tr>
                        <td><?= h($deck['name']) ?></td>
                        <td><?= h($deck['description']) ?></td>
                        <td><?= (int) $deck['total_words'] ?></td>
                        <td><?= (int) $deck['due_count'] ?></td>
                        <td>
                            <?php if (!empty($deck['cover_image'])): ?>
                                <img src="<?= h($deck['cover_image']) ?>" alt="" style="max-width:80px; border-radius:8px;">
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="post" onsubmit="return confirm('Delete this deck? Words remain in library.');">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="delete_id" value="<?= (int) $deck['id'] ?>">
                                <button class="btn danger" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No decks yet.</p>
        <?php endif; ?>
    </section>
</div>
</body>
</html>
