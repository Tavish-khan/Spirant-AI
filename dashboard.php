<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$user = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Spirant AI</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="app-body">
    <div class="app-shell">
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="brand-block">
                    <div class="brand-mark">S</div>
                    <div>
                        <h2>Spirant AI</h2>
                    </div>
                </div>
                <button class="icon-button sidebar-close" id="sidebarClose" aria-label="Close sidebar">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <button class="new-chat-btn" id="newChatBtn">
                <i class="fa-solid fa-plus"></i>
                <span>New Chat</span>
            </button>

            <div class="search-box">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="conversationSearch" placeholder="Search conversations">
            </div>

            <div class="conversation-groups" id="conversationGroups"></div>

            <div class="sidebar-footer">
                <div class="profile-card">
                    <div class="profile-avatar"><?= htmlspecialchars(substr($user['name'], 0, 1), ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="profile-meta">
                        <strong><?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        <span><?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                </div>
                <div class="profile-actions">
                    <button class="text-button" type="button">Settings</button>
                    <a class="text-button danger" href="logout.php">Logout</a>
                </div>
            </div>
        </aside>

        <main class="chat-panel">
            <header class="topbar">
                <button class="icon-button sidebar-toggle" id="sidebarToggle" aria-label="Open sidebar">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="topbar-brand">
                    <div class="brand-dot"></div>
                    <span>Spirant AI</span>
                </div>
                <div class="topbar-actions">
                    <button class="icon-button" id="themeToggle" aria-label="Toggle theme">
                        <i class="fa-solid fa-moon"></i>
                    </button>
                </div>
            </header>

            <div class="chat-page">
                <div class="welcome-card" id="welcomeCard">
                    <div class="welcome-logo">S</div>
                    <h1>Hello! Welcome to Spirant AI.</h1>
                    <p>Your intelligent assistant is ready to help you. Ask questions, explore ideas, or upload an image for analysis.</p>
                    <div class="suggested-prompts">
                        <button class="prompt-chip" data-prompt="Explain something to me.">Explain something to me.</button>
                        <button class="prompt-chip" data-prompt="Help me write professional emails.">Help me write professional emails.</button>
                        <button class="prompt-chip" data-prompt="Analyze an uploaded image.">Analyze an uploaded image.</button>
                        <button class="prompt-chip" data-prompt="Help me with programming.">Help me with programming.</button>
                        <button class="prompt-chip" data-prompt="Generate creative ideas.">Generate creative ideas.</button>
                    </div>
                </div>

                <div class="message-list" id="chatMessages"></div>
            </div>

            <div class="composer-shell">
                <div class="chat-error hidden" id="chatError" role="status" aria-live="polite">
                    <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                    <span id="chatErrorText"></span>
                    <button type="button" id="dismissChatError" aria-label="Dismiss error">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </div>
                <form id="chatForm" class="composer-form">
                    <input type="hidden" id="conversationId" value="">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="selected-image-box hidden" id="selectedImageBox">
                        <div class="image-thumbnail-wrap">
                            <img id="selectedImagePreview" src="" alt="Selected upload preview">
                        </div>
                        <button type="button" class="remove-image-btn" id="removeImageBtn">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>

                    <div class="composer-bar">
                        <label class="upload-button" for="imageInput" title="Upload image">
                            <i class="fa-regular fa-image"></i>
                            <input type="file" id="imageInput" accept="image/jpeg,image/jpg,image/png,image/webp">
                        </label>

                        <textarea id="messageInput" rows="1" placeholder="Message Spirant AI..."></textarea>

                        <button type="submit" class="primary-btn send-btn" id="sendBtn">
                            <i class="fa-solid fa-paper-plane"></i>
                            <span>Send</span>
                        </button>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script src="assets/js/app.js"></script>
    <script src="assets/js/chat.js"></script>
    <script src="assets/js/sidebar.js"></script>
</body>
</html>
