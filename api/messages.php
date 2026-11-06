<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$user = current_user();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(false, 'Unsupported method.', [], 405);
}

$conversationId = (int) ($_GET['conversation_id'] ?? 0);
if ($conversationId <= 0) {
    json_response(false, 'Conversation ID is required.', [], 400);
}

$check = $pdo->prepare('SELECT id FROM conversations WHERE id = :id AND user_id = :user_id LIMIT 1');
$check->execute(['id' => $conversationId, 'user_id' => $user['id']]);
if (!$check->fetch()) {
    json_response(false, 'You do not have access to this conversation.', [], 403);
}

$stmt = $pdo->prepare('SELECT m.*, CASE WHEN ui.file_path LIKE "%/api/uploads/%" THEN REPLACE(ui.file_path, "/api/uploads", "/uploads") ELSE ui.file_path END AS image_path FROM messages m LEFT JOIN uploaded_images ui ON ui.message_id = m.id WHERE m.conversation_id = :conversation_id AND m.user_id = :user_id ORDER BY m.created_at ASC');
$stmt->execute(['conversation_id' => $conversationId, 'user_id' => $user['id']]);
$messages = $stmt->fetchAll();

json_response(true, 'Messages retrieved successfully.', ['messages' => $messages]);
