<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

function csrf_input(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}
