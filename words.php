<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

$csrf = ensure_token();
$flash = get_flash();

if (is_post() && isset($_POST['delete_id'])) {
    check_token($_POST['csrf'] ?? null);
    $wordId = (int) $_POST['delete_id'];

    $stmt = $pdo->prepare('SELECT audio_path FROM words WHERE id = ?');
    $stmt->execute([$wordId]);
    $existing = $stmt->fetch();

    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM words WHERE id = ?')->execute([$wordId]);
    $pdo->commit();

    if ($existing && !empty($existing['audio_path'])) {
        delete_upload($existing['audio_path'], $UPLOAD_DIR);
    }

    flash('Word deleted.', 'success');
    redirect('words.php');
}

$stmt = $pdo->query(
    'SELECT w.*, COUNT(t.id) AS translations_count
     FROM words w
     LEFT JOIN translations t ON t.word_id = w.id
     GROUP BY w.id
     ORDER BY w.created_at DESC
     LIMIT 500'
);
$words = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Words Admin</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<div class="container">
    <header class="header">
        <nav class="nav">
            <a href="index.php">← Back</a>
            <a href="import_csv.php">Bulk Import CSV</a>
        </nav>
        <a class="btn" href="edit_word.php">+ New Word</a>
    </header>

    <?php if ($flash): ?>
        <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <section class="card">
        <h2>Words (<?= count($words) ?>)</h2>
        <table class="table">
            <thead>
            <tr>
                <th>ID</th>
                <th>Hebrew</th>
                <th>Translit</th>
                <th>POS</th>
                <th>Translations</th>
                <th>Audio</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($words as $word): ?>
                <tr>
                    <td><?= (int) $word['id'] ?></td>
                    <td><?= h($word['hebrew']) ?></td>
                    <td><?= h($word['transliteration']) ?></td>
                    <td><?= h($word['part_of_speech']) ?></td>
                    <td><span class="badge"><?= (int) $word['translations_count'] ?> langs</span></td>
                    <td>
                        <?php if (!empty($word['audio_path'])): ?>
                            <audio controls src="<?= h($word['audio_path']) ?>"></audio>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td class="flex">
                        <a class="btn secondary" href="edit_word.php?id=<?= (int) $word['id'] ?>">Edit</a>
                        <form method="post" onsubmit="return confirm('Delete this word and all its translations?');">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="delete_id" value="<?= (int) $word['id'] ?>">
                            <button class="btn danger" type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</div>
</body>
</html>
