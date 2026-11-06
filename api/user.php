<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response(true, 'User profile loaded.', [
        'user' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
        ],
    ]);
}

json_response(false, 'Unsupported request.', [], 405);
