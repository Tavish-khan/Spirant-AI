<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$user = current_user();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $search = trim((string) ($_GET['search'] ?? ''));
    $sql = 'SELECT * FROM conversations WHERE user_id = :user_id';
    $params = ['user_id' => $user['id']];

    if ($search !== '') {
        $sql .= ' AND title LIKE :search';
        $params['search'] = '%' . $search . '%';
    }

    $sql .= ' ORDER BY updated_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $conversations = $stmt->fetchAll();

    $payload = [];
    foreach ($conversations as $conversation) {
        $payload[] = [
            'id' => (int) $conversation['id'],
            'title' => $conversation['title'],
            'updated_at' => $conversation['updated_at'],
            'created_at' => $conversation['created_at'],
        ];
    }

    json_response(true, 'Conversations retrieved successfully.', ['conversations' => $payload]);
}

if ($method === 'POST') {
    $action = $_POST['action'] ?? 'create';

    if ($action === 'create') {
        $title = trim((string) ($_POST['title'] ?? 'New conversation'));
        if ($title === '') {
            $title = 'New conversation';
        }

        $stmt = $pdo->prepare('INSERT INTO conversations (user_id, title) VALUES (:user_id, :title)');
        $stmt->execute([
            'user_id' => $user['id'],
            'title' => $title,
        ]);

        $conversationId = (int) $pdo->lastInsertId();
        json_response(true, 'Conversation created.', ['conversation_id' => $conversationId]);
    }

    if ($action === 'rename') {
        $conversationId = (int) ($_POST['conversation_id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));

        if ($conversationId <= 0 || $title === '') {
            json_response(false, 'Invalid conversation data.', [], 400);
        }

        $check = $pdo->prepare('SELECT id FROM conversations WHERE id = :id AND user_id = :user_id LIMIT 1');
        $check->execute(['id' => $conversationId, 'user_id' => $user['id']]);
        if (!$check->fetch()) {
            json_response(false, 'You do not have access to this conversation.', [], 403);
        }

        $stmt = $pdo->prepare('UPDATE conversations SET title = :title, updated_at = NOW() WHERE id = :id AND user_id = :user_id');
        $stmt->execute([
            'title' => $title,
            'id' => $conversationId,
            'user_id' => $user['id'],
        ]);

        json_response(true, 'Conversation renamed successfully.', ['conversation_id' => $conversationId]);
    }

    if ($action === 'delete') {
        $conversationId = (int) ($_POST['conversation_id'] ?? 0);

        if ($conversationId <= 0) {
            json_response(false, 'Invalid conversation.', [], 400);
        }

        $check = $pdo->prepare('SELECT id FROM conversations WHERE id = :id AND user_id = :user_id LIMIT 1');
        $check->execute(['id' => $conversationId, 'user_id' => $user['id']]);
        if (!$check->fetch()) {
            json_response(false, 'You do not have access to this conversation.', [], 403);
        }

        $stmt = $pdo->prepare('DELETE FROM conversations WHERE id = :id AND user_id = :user_id');
        $stmt->execute(['id' => $conversationId, 'user_id' => $user['id']]);

        json_response(true, 'Conversation deleted successfully.', ['conversation_id' => $conversationId]);
    }
}

json_response(false, 'Unsupported request.', [], 400);
