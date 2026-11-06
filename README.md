# Spirant AI

Spirant AI is a PHP + MySQL chatbot platform inspired by modern AI chat experiences, designed for cPanel-friendly hosting and modular future AI integration.

## Tech stack

- PHP 8+
- MySQL / MariaDB
- PDO Prepared Statements
- Bootstrap 5 and custom CSS
- Vanilla JavaScript with Fetch API
- Secure file upload handling

## Project structure

- `config/database.php` – database configuration
- `config/gemini.php` – server-only Gemini configuration loader
- `includes/` – authentication, CSRF and utility helpers
- `services/` – AI provider and future knowledge-base service interfaces
- `api/` – conversation, chat, upload, and user endpoints
- `assets/` – styles and scripts
- `uploads/chat-images/` – stored uploaded files
- `database/spirant_ai.sql` – schema and seed-ready table definitions

## Quick setup

1. Create a database named `spirant_ai` in MySQL.
2. Import the SQL file from `database/spirant_ai.sql`.
3. Update database credentials in `config/database.php` if needed.
4. Configure the Gemini key outside the web root (see below).
5. Ensure PHP cURL and fileinfo extensions are enabled.
6. Start a local PHP server from the project folder.
7. Open the app in the browser and register a user.

## Features implemented

- User registration and login
- Session-based authentication
- Chat history by user
- Search and grouping of conversations
- Gemini text conversations with persisted multi-turn history
- Gemini image understanding for uploaded JPG, PNG, and WEBP images
- Image upload validation and storage
- Conversation rename and delete actions
- Clean dashboard UI with responsive layout
- CSRF protection and secure file handling

## Gemini API configuration

The key is never sent to the browser. The default local secret file is:

`C:\xampp\spirant-ai-secrets\gemini.php`

The file is outside `htdocs` and returns a PHP array:

```php
<?php
return [
	'api_key' => 'PASTE_YOUR_GEMINI_API_KEY_HERE',
	'model' => 'gemini-3.8-flash',
];
```

Replace the placeholder with your key. Alternatively, set `GEMINI_API_KEY` in the PHP process environment. `GEMINI_MODEL` may override the model. For cPanel, put `spirant-ai-secrets/gemini.php` one directory above `public_html`; set `GEMINI_CONFIG_FILE` if your hosting layout differs. Do not put a real key in this repository or in HTML/JavaScript.

An optional `fallback_model` value may be set in this external file, or with `GEMINI_FALLBACK_MODEL`. It is disabled by default. Before using it, the service checks that the name is in its documented Interactions API model list and that the model metadata endpoint makes it available to this key. It is only considered for a new conversation (no previous interaction ID), so an existing conversation is never silently switched between models.

The app uses Google's recommended Gemini Interactions REST API with `x-goog-api-key`, and stores the provider interaction ID per conversation so follow-up turns include server-managed Gemini context. The app's own user/assistant messages remain stored in MySQL.

For an existing database, import `database/migrations/2026_11_gemini_and_pdf_knowledge_base.sql` once. Fresh installs can import the updated `database/spirant_ai.sql`.

## PDF knowledge base status

The schema and `KnowledgeBaseService` contract reserve separate boundaries for administrator-owned PDF upload, page extraction, chunk indexing, source/page tracking, and relevance retrieval. No PDF upload, extraction, indexing, retrieval, or grounded-answer feature is active. The Gemini chat currently answers general prompts; do not treat it as restricted to company PDFs until those stages are implemented and tested.
