<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function handle_register(PDO $pdo): void
{
    if (!is_post()) {
        return;
    }

    check_token($_POST['csrf'] ?? null);
    rate_limit('register:' . session_id(), 10, 3600);

    $email = trim(strtolower($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('Invalid email address.', 'error');
        return;
    }

    if ($password === '' || $password !== $confirm) {
        flash('Passwords must match.', 'error');
        return;
    }

    $hash = password_hash_secure($password);

    try {
        $stmt = $pdo->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)');
        $stmt->execute([$email, $hash]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            flash('Email already registered.', 'error');
            return;
        }
        throw $e;
    }

    flash('Account created. Please log in.', 'success');
    redirect('login.php');
}

function handle_login(PDO $pdo): void
{
    if (!is_post()) {
        return;
    }

    check_token($_POST['csrf'] ?? null);
    rate_limit('login:' . session_id(), 30, 900);

    $email = trim(strtolower($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        flash('Missing email or password.', 'error');
        return;
    }

    $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify_secure($password, $user['password_hash'])) {
        flash('Invalid credentials.', 'error');
        return;
    }

    $_SESSION['user_id'] = (int) $user['id'];
    clear_user_cache();
    flash('Welcome back!', 'success');
    redirect('index.php');
}

function handle_logout(): void
{
    session_destroy();
    session_start();
    clear_user_cache();
    flash('Logged out.', 'info');
    redirect('login.php');
}
