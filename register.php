<?php

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';

$csrf = ensure_token();
handle_register($pdo);
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
    <title>Create account · Hebrew Learner</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="manifest" href="manifest.json">
</head>
<body class="auth-body">
    <main class="auth-card" role="main">
        <h1 class="auth-title">Create account</h1>
        <?php if ($flash): ?>
            <div class="flash <?= h($flash['type']) ?>" role="status"><?= h($flash['message']) ?></div>
        <?php endif; ?>
        <form method="post" action="register.php" class="auth-form" novalidate>
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" autocomplete="email" required>

            <label for="password">Password</label>
            <input type="password" id="password" name="password" autocomplete="new-password" required minlength="8">

            <label for="confirm">Confirm password</label>
            <input type="password" id="confirm" name="confirm" autocomplete="new-password" required minlength="8">

            <button type="submit" class="btn auth-btn">Create account</button>
        </form>
        <p class="auth-switch">Already registered? <a href="login.php">Sign in</a>.</p>
    </main>
    <script>
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('service-worker.js').catch(() => {});
    }
    </script>
</body>
</html>
