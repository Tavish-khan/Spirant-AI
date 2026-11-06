<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

require_guest();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        error_flash('Invalid security token. Please try again.');
        redirect('register.php');
    }

    if ($name === '' || $email === '' || $password === '') {
        error_flash('Please complete all fields.');
        redirect('register.php');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        error_flash('Please provide a valid email address.');
        redirect('register.php');
    }

    if (strlen($password) < 8) {
        error_flash('Password must be at least 8 characters long.');
        redirect('register.php');
    }

    if ($password !== $confirmPassword) {
        error_flash('Passwords do not match.');
        redirect('register.php');
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);

    if ($stmt->fetch()) {
        error_flash('This email is already registered.');
        redirect('register.php');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $insert = $pdo->prepare('INSERT INTO users (name, email, password) VALUES (:name, :email, :password)');
    $insert->execute([
        'name' => $name,
        'email' => $email,
        'password' => $hash,
    ]);

    success_flash('Registration successful. Please sign in.');
    redirect('login.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Spirant AI | Register</title>
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
                    <p>Create your account</p>
                </div>
            </div>

            <?php $registerError = flash_message('error'); if ($registerError): ?>
                <div class="auth-alert error"><?= htmlspecialchars($registerError, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form method="POST" class="auth-form">
                <?= csrf_field(); ?>
                <div class="form-group">
                    <label for="name">Full Name</label>
                    <input type="text" id="name" name="name" placeholder="Enter your name" required>
                </div>
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" placeholder="you@example.com" required>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Create a password" required>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" placeholder="Repeat your password" required>
                </div>
                <button type="submit" class="primary-btn full-width">Create Account</button>
            </form>

            <div class="auth-footer">
                <span>Already have an account?</span>
                <a href="login.php">Login</a>
            </div>
        </div>
    </div>
</body>
</html>
