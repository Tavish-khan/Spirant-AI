<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

spirant_session_start();

if (empty($_SESSION['user'])) {
    if (basename($_SERVER['SCRIPT_NAME']) !== 'login.php' && basename($_SERVER['SCRIPT_NAME']) !== 'register.php') {
        redirect('login.php');
    }
}
