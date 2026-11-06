<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
spirant_session_start();

if (!empty($_SESSION['user'])) {
    redirect('dashboard.php');
}

redirect('login.php');
