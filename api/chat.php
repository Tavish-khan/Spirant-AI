<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/GeminiAIService.php';

require_login();

$user = current_user();
$geminiConfig = require __DIR__ . '/../config/gemini.php';
$sendChatError = static function (string $type, int $httpStatus): void {
    $messages = [
        'temporary_server' => "I'm having trouble responding right now. Please try again in a moment.",
        'network' => 'Connection issue. Please check your internet connection and try again.',
        'configuration' => 'AI service is temporarily unavailable. Please contact the administrator.',
        'rate_limit' => 'Too many requests right now. Please wait a moment and try again.',
        'unsupported_image' => 'This image format is not supported. Please upload a JPG, PNG, or WEBP image.',
        'image_too_large' => 'This image is too large. Please upload a smaller image.',
        'general' => 'Something went wrong. Please try again.',
    ];
    json_response(false, $messages[$type] ?? $messages['general'], ['error_type' => $type], $httpStatus);
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $sendChatError('general', 405);
}

if (!validate_csrf($_POST['csrf_token'] ?? '')) {
    $sendChatError('general', 403);
}

try {
    $pdo->query('SELECT conversation_id, user_id, provider, provider_interaction_id, model, updated_at FROM conversation_ai_state LIMIT 0');
} catch (PDOException $error) {
    $sqlErrorCode = (int) ($error->errorInfo[1] ?? 0);
    if ($error->getCode() === '42S02' || $sqlErrorCode === 1146) {
        error_log('Spirant chat schema check failed: conversation_ai_state table is missing.');
        $sendChatError('configuration', 503);
    }
    throw $error;
}

$messageText = trim((string) ($_POST['message'] ?? ''));
$conversationId = isset($_POST['conversation_id']) && $_POST['conversation_id'] !== ''
    ? (int) $_POST['conversation_id']
    : null;
$hasImageField = isset($_FILES['image']);
$hasUploadedImage = $hasImageField && (int) $_FILES['image']['error'] === UPLOAD_ERR_OK;

if ($hasImageField && !$hasUploadedImage) {
    $uploadError = (int) $_FILES['image']['error'];
    error_log(sprintf('Spirant upload rejected: upload_error=%d', $uploadError));
    if (in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        $sendChatError('image_too_large', 413);
    }
    $sendChatError('general', 400);
}

if ($messageText === '' && !$hasUploadedImage) {
    $sendChatError('general', 400);
}

if ($conversationId !== null && $conversationId > 0) {
    $conversationCheck = $pdo->prepare('SELECT id FROM conversations WHERE id = :id AND user_id = :user_id LIMIT 1');
    $conversationCheck->execute(['id' => $conversationId, 'user_id' => $user['id']]);
    if (!$conversationCheck->fetch()) {
        $sendChatError('general', 403);
    }
} else {
    $conversationId = null;
}

$storedImageName = null;
$storedImagePath = null;
$storedImageMime = null;
$storedImageSize = null;
$destinationPath = null;
$messageSaved = false;

if ($hasUploadedImage) {
    $file = $_FILES['image'];
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $fileSize = (int) ($file['size'] ?? 0);

    if (!in_array($mime, $allowedMimes, true)) {
        $sendChatError('unsupported_image', 400);
    }
    if ($fileSize <= 0 || $fileSize > 5 * 1024 * 1024) {
        $sendChatError('image_too_large', 413);
    }
    if (empty($geminiConfig['api_key'])) {
        $sendChatError('configuration', 503);
    }

    $extension = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    };
    $storedImageName = 'spirant_' . bin2hex(random_bytes(16)) . '.' . $extension;
    $destinationDir = dirname(__DIR__) . '/uploads/chat-images';
    if (!is_dir($destinationDir) && !mkdir($destinationDir, 0750, true) && !is_dir($destinationDir)) {
        error_log('Spirant upload failed: destination directory could not be created.');
        $sendChatError('general', 500);
    }

    $destinationPath = $destinationDir . DIRECTORY_SEPARATOR . $storedImageName;
    error_log(sprintf('Spirant upload: error_code=%d destination=%s size=%d', (int) $file['error'], $destinationPath, $fileSize));
    if (!move_uploaded_file($file['tmp_name'], $destinationPath)) {
        error_log(sprintf('Spirant upload failed: destination=%s', $destinationPath));
        $sendChatError('general', 500);
    }

    $storedImageMime = $mime;
    $storedImageSize = $fileSize;
    $storedImagePath = spirant_public_path('/uploads/chat-images/' . $storedImageName);
    error_log(sprintf('Spirant upload stored: filename=%s exists=%s', $storedImageName, file_exists($destinationPath) ? 'yes' : 'no'));
}

if (empty($geminiConfig['api_key'])) {
    $sendChatError('configuration', 503);
}

$loadImageContent = static function (string $storedName, string $mime, int $maximumBytes): ?array {
    $uploadsDirectory = realpath(dirname(__DIR__) . '/uploads/chat-images');
    $safeName = basename($storedName);
    if ($uploadsDirectory === false || $safeName !== $storedName) {
        return null;
    }

    $imagePath = realpath($uploadsDirectory . DIRECTORY_SEPARATOR . $safeName);
    if ($imagePath === false || !str_starts_with($imagePath, $uploadsDirectory . DIRECTORY_SEPARATOR)) {
        return null;
    }

    $imageSize = filesize($imagePath);
    if ($imageSize === false || $imageSize <= 0 || $imageSize > $maximumBytes) {
        return null;
    }

    $imageBytes = file_get_contents($imagePath);
    if ($imageBytes === false) {
        return null;
    }

    return [
        'type' => 'image',
        'data' => base64_encode($imageBytes),
        'mime_type' => $mime,
        'bytes' => $imageSize,
    ];
};

try {
    $previousInteractionId = null;
    $historyMessages = [];
    $currentStep = null;
    $buildHistoryInput = null;

    if ($conversationId !== null) {
        $stateQuery = $pdo->prepare('SELECT provider_interaction_id, model FROM conversation_ai_state WHERE conversation_id = :conversation_id AND user_id = :user_id AND provider = :provider LIMIT 1');
        $stateQuery->execute([
            'conversation_id' => $conversationId,
            'user_id' => $user['id'],
            'provider' => 'gemini',
        ]);
        $storedAiState = $stateQuery->fetch();
        if ($storedAiState && $storedAiState['model'] === $geminiConfig['model']) {
            $previousInteractionId = (string) $storedAiState['provider_interaction_id'];
        }

        if ($previousInteractionId === null) {
            $historyQuery = $pdo->prepare('SELECT m.role, m.message_type, m.message_content, ui.stored_name, ui.mime_type, ui.file_size FROM messages m LEFT JOIN uploaded_images ui ON ui.message_id = m.id AND ui.user_id = m.user_id AND ui.conversation_id = m.conversation_id WHERE m.conversation_id = :conversation_id AND m.user_id = :user_id ORDER BY m.id DESC LIMIT 30');
            $historyQuery->execute([
                'conversation_id' => $conversationId,
                'user_id' => $user['id'],
            ]);
            $historyMessages = array_reverse($historyQuery->fetchAll());
        }
    }

    $currentContent = [];
    if ($messageText !== '') {
        $currentContent[] = ['type' => 'text', 'text' => $messageText];
    } elseif ($hasUploadedImage) {
        $currentContent[] = ['type' => 'text', 'text' => 'Please analyze this image.'];
    }

    if ($hasUploadedImage) {
        $currentImage = $loadImageContent($storedImageName, $storedImageMime, 5 * 1024 * 1024);
        if ($currentImage === null) {
            throw new GeminiApiException('The saved image could not be read for analysis. Please upload it again.', 500, 'IMAGE_READ_FAILED');
        }
        unset($currentImage['bytes']);
        $currentContent[] = $currentImage;
    }

    $currentStep = ['type' => 'user_input', 'content' => $currentContent];
    $buildHistoryInput = static function (array $historyMessages) use ($currentStep, $hasUploadedImage, $storedImageSize, $loadImageContent): array {
        $input = [];
        $imageBytesIncluded = $hasUploadedImage ? $storedImageSize : 0;
        $maximumHistoryImageBytes = (10 * 1024 * 1024) - $imageBytesIncluded;

        foreach ($historyMessages as $historyMessage) {
            if ($historyMessage['role'] === 'user') {
                $content = [];
                if (trim((string) $historyMessage['message_content']) !== '') {
                    $content[] = ['type' => 'text', 'text' => (string) $historyMessage['message_content']];
                }

                if ($historyMessage['message_type'] === 'image' && !empty($historyMessage['stored_name'])) {
                    $historicalImage = $loadImageContent(
                        (string) $historyMessage['stored_name'],
                        (string) $historyMessage['mime_type'],
                        $maximumHistoryImageBytes
                    );
                    if ($historicalImage !== null) {
                        $imageBytesIncluded += $historicalImage['bytes'];
                        $maximumHistoryImageBytes -= $historicalImage['bytes'];
                        unset($historicalImage['bytes']);
                        $content[] = $historicalImage;
                    }
                }

                if ($content === []) {
                    $content[] = ['type' => 'text', 'text' => 'Image message.'];
                }
                $input[] = ['type' => 'user_input', 'content' => $content];
            } else {
                $input[] = [
                    'type' => 'model_output',
                    'content' => [[
                        'type' => 'text',
                        'text' => (string) $historyMessage['message_content'],
                    ]],
                ];
            }
        }

        $input[] = $currentStep;
        return $input;
    };

    if ($previousInteractionId !== null) {
        $input = $currentContent;
    } else {
        $input = $buildHistoryInput($historyMessages);
    }

    $gemini = new GeminiAIService($geminiConfig);
    try {
        $aiResult = $gemini->generateInteraction($input, $previousInteractionId);
    } catch (GeminiApiException $error) {
        if ($previousInteractionId === null || $error->apiStatus !== 'NOT_FOUND') {
            throw $error;
        }

        $historyQuery = $pdo->prepare('SELECT m.role, m.message_type, m.message_content, ui.stored_name, ui.mime_type, ui.file_size FROM messages m LEFT JOIN uploaded_images ui ON ui.message_id = m.id AND ui.user_id = m.user_id AND ui.conversation_id = m.conversation_id WHERE m.conversation_id = :conversation_id AND m.user_id = :user_id ORDER BY m.id DESC LIMIT 30');
        $historyQuery->execute(['conversation_id' => $conversationId, 'user_id' => $user['id']]);
        $historyMessages = array_reverse($historyQuery->fetchAll());
        $input = $buildHistoryInput($historyMessages);
        $aiResult = $gemini->generateInteraction($input);
    }

    $assistantReply = $aiResult['text'];
    $conversationTitle = generate_conversation_title($messageText !== '' ? $messageText : 'Uploaded image');

    $pdo->beginTransaction();
    if ($conversationId === null) {
        $insertConversation = $pdo->prepare('INSERT INTO conversations (user_id, title) VALUES (:user_id, :title)');
        $insertConversation->execute(['user_id' => $user['id'], 'title' => $conversationTitle]);
        $conversationId = (int) $pdo->lastInsertId();
    }

    $insertMessage = $pdo->prepare('INSERT INTO messages (conversation_id, user_id, role, message_type, message_content) VALUES (:conversation_id, :user_id, :role, :message_type, :message_content)');
    $insertMessage->execute([
        'conversation_id' => $conversationId,
        'user_id' => $user['id'],
        'role' => 'user',
        'message_type' => $hasUploadedImage ? 'image' : 'text',
        'message_content' => $messageText !== '' ? $messageText : 'Uploaded an image for analysis.',
    ]);
    $userMessageId = (int) $pdo->lastInsertId();

    if ($hasUploadedImage) {
        $imageInsert = $pdo->prepare('INSERT INTO uploaded_images (user_id, conversation_id, message_id, original_name, stored_name, file_path, mime_type, file_size) VALUES (:user_id, :conversation_id, :message_id, :original_name, :stored_name, :file_path, :mime_type, :file_size)');
        $imageInsert->execute([
            'user_id' => $user['id'],
            'conversation_id' => $conversationId,
            'message_id' => $userMessageId,
            'original_name' => basename((string) ($file['name'] ?? 'uploaded-image')),
            'stored_name' => $storedImageName,
            'file_path' => $storedImagePath,
            'mime_type' => $storedImageMime,
            'file_size' => $storedImageSize,
        ]);
    }

    $assistantInsert = $pdo->prepare('INSERT INTO messages (conversation_id, user_id, role, message_type, message_content) VALUES (:conversation_id, :user_id, :role, :message_type, :message_content)');
    $assistantInsert->execute([
        'conversation_id' => $conversationId,
        'user_id' => $user['id'],
        'role' => 'assistant',
        'message_type' => 'text',
        'message_content' => $assistantReply,
    ]);

    $aiStateCheck = $pdo->prepare('SELECT conversation_id FROM conversation_ai_state WHERE conversation_id = :conversation_id AND user_id = :user_id AND provider = :provider LIMIT 1');
    $aiStateCheck->execute([
        'conversation_id' => $conversationId,
        'user_id' => $user['id'],
        'provider' => 'gemini',
    ]);
    $aiState = $aiStateCheck->fetch();
    if ($aiState) {
        $aiStateUpdate = $pdo->prepare('UPDATE conversation_ai_state SET provider_interaction_id = :interaction_id, model = :model WHERE conversation_id = :conversation_id AND user_id = :user_id AND provider = :provider');
        $aiStateUpdate->execute([
            'interaction_id' => $aiResult['interaction_id'],
            'model' => $aiResult['model'],
            'conversation_id' => $conversationId,
            'user_id' => $user['id'],
            'provider' => 'gemini',
        ]);
    } else {
        $aiStateInsert = $pdo->prepare('INSERT INTO conversation_ai_state (conversation_id, user_id, provider, provider_interaction_id, model) VALUES (:conversation_id, :user_id, :provider, :interaction_id, :model)');
        $aiStateInsert->execute([
            'conversation_id' => $conversationId,
            'user_id' => $user['id'],
            'provider' => 'gemini',
            'interaction_id' => $aiResult['interaction_id'],
            'model' => $aiResult['model'],
        ]);
    }

    $titleUpdate = $pdo->prepare('UPDATE conversations SET updated_at = NOW(), title = CASE WHEN title = "New conversation" THEN :title ELSE title END WHERE id = :id AND user_id = :user_id');
    $titleUpdate->execute([
        'title' => $conversationTitle,
        'id' => $conversationId,
        'user_id' => $user['id'],
    ]);

    $pdo->commit();
    $messageSaved = true;

    error_log(sprintf('Spirant Gemini response saved: conversation_id=%d model=%s', $conversationId, $aiResult['model']));
    json_response(true, 'Message sent successfully.', [
        'conversation_id' => $conversationId,
        'assistant_reply' => $assistantReply,
        'image_path' => $storedImagePath,
        'user_message' => $messageText !== '' ? $messageText : 'Uploaded an image for analysis.',
    ]);
} catch (GeminiApiException $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($destinationPath !== null && !$messageSaved && is_file($destinationPath)) {
        unlink($destinationPath);
    }
    if ($error->httpStatus === 429 || $error->apiStatus === 'RESOURCE_EXHAUSTED') {
        $sendChatError('rate_limit', $error->httpStatus);
    }
    if (in_array($error->apiStatus, ['MISSING_API_KEY', 'CURL_UNAVAILABLE', 'UNAUTHENTICATED', 'PERMISSION_DENIED', 'INVALID_API_KEY', 'MODEL_UNAVAILABLE'], true)) {
        $sendChatError('configuration', $error->httpStatus);
    }
    if ($error->apiStatus === 'NETWORK_ERROR') {
        $sendChatError('network', $error->httpStatus);
    }
    if (in_array($error->httpStatus, [500, 502, 503, 504], true) || $error->apiStatus === 'SERVICE_UNAVAILABLE') {
        $sendChatError('temporary_server', $error->httpStatus);
    }
    if ($error->apiStatus === 'INVALID_IMAGE') {
        $sendChatError('unsupported_image', $error->httpStatus);
    }
    $sendChatError('general', $error->httpStatus);
} catch (PDOException $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($destinationPath !== null && !$messageSaved && is_file($destinationPath)) {
        unlink($destinationPath);
    }
    $sqlErrorCode = (int) ($error->errorInfo[1] ?? 0);
    if ($error->getCode() === '42S02' || $sqlErrorCode === 1146) {
        error_log('Spirant chat failed: required Gemini/knowledge-base migration has not been applied.');
        $sendChatError('configuration', 503);
    }
    error_log(sprintf('Spirant chat database failure: sql_state=%s vendor_code=%d', (string) $error->getCode(), $sqlErrorCode));
    $sendChatError('general', 500);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($destinationPath !== null && !$messageSaved && is_file($destinationPath)) {
        unlink($destinationPath);
    }
    error_log(sprintf('Spirant chat processing failure: type=%s', get_class($error)));
    $sendChatError('general', 500);
}
