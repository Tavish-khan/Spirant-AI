<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

require_guest();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        error_flash('Please enter both email and password.');
        redirect('login.php');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        error_flash('Please enter a valid email address.');
        redirect('login.php');
    }

    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        error_flash('Invalid security token. Please try again.');
        redirect('login.php');
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        error_flash('Invalid email or password.');
        redirect('login.php');
    }

    spirant_session_start();
    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
    ];

    success_flash('Welcome back!');
    redirect('dashboard.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Spirant AI | Login</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-body">
    <div class="auth-shell">
        <div class="auth-card">
            <div class="auth-brand">
                <div class="brand-mark">S</div>
                <div>
                    <h1>Spirant AI</h1>
                    <p>Secure workspace access</p>
                </div>
            </div>

            <?php $loginError = flash_message('error'); if ($loginError): ?>
                <div class="auth-alert error"><?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <?php $loginSuccess = flash_message('success'); if ($loginSuccess): ?>
                <div class="auth-alert success"><?= htmlspecialchars($loginSuccess, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form method="POST" class="auth-form">
                <?= csrf_field(); ?>
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" placeholder="you@example.com" required>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Enter your password" required>
                </div>
                <button type="submit" class="primary-btn full-width">Sign In</button>
            </form>

            <div class="auth-footer">
                <span>New to Spirant AI?</span>
                <a href="register.php">Create account</a>
            </div>
        </div>
    </div>
</body>
</html>
