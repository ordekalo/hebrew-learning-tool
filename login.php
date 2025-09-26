<?php

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';

$csrf = ensure_token();
handle_login($pdo);
$flash = get_flash();

if (current_user($pdo)) {
    redirect('index.php');
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · Hebrew Learner</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="manifest" href="manifest.json">
</head>
<body class="auth-body">
    <main class="auth-card" role="main">
        <h1 class="auth-title">Welcome back</h1>
        <?php if ($flash): ?>
            <div class="flash <?= h($flash['type']) ?>" role="status"><?= h($flash['message']) ?></div>
        <?php endif; ?>
        <form method="post" action="login.php" class="auth-form">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" autocomplete="email" required>

            <label for="password">Password</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required>

            <button type="submit" class="btn auth-btn">Sign in</button>
        </form>
        <p class="auth-switch">Need an account? <a href="register.php">Create one</a>.</p>
    </main>
    <script>
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('service-worker.js').catch(() => {});
    }
    </script>
</body>
</html>
