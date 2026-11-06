<?php

declare(strict_types=1);

$defaultSecretsFile = dirname(__DIR__, 3)
    . DIRECTORY_SEPARATOR . 'spirant-ai-secrets'
    . DIRECTORY_SEPARATOR . 'gemini.php';
$configPath = getenv('GEMINI_CONFIG_FILE');
$secretsFile = is_string($configPath) && trim($configPath) !== ''
    ? $configPath
    : $defaultSecretsFile;
$secrets = is_file($secretsFile) && is_readable($secretsFile) ? require $secretsFile : [];
$secrets = is_array($secrets) ? $secrets : [];

$environmentKey = getenv('GEMINI_API_KEY');
$environmentKey = is_string($environmentKey) ? trim($environmentKey) : '';
$secretKey = is_string($secrets['api_key'] ?? null) ? trim($secrets['api_key']) : '';
$apiKey = $environmentKey !== '' ? $environmentKey : $secretKey;

if (in_array($apiKey, ['GEMINI_API_KEY', 'PASTE_YOUR_GEMINI_API_KEY_HERE'], true)) {
    $apiKey = '';
}

$modelEnvironment = getenv('GEMINI_MODEL');
$modelEnvironment = is_string($modelEnvironment) ? trim($modelEnvironment) : '';
$secretModel = is_string($secrets['model'] ?? null) ? trim($secrets['model']) : '';
$model = $modelEnvironment !== '' ? $modelEnvironment : $secretModel;
$fallbackEnvironment = getenv('GEMINI_FALLBACK_MODEL');
$fallbackEnvironment = is_string($fallbackEnvironment) ? trim($fallbackEnvironment) : '';
$secretFallbackModel = is_string($secrets['fallback_model'] ?? null) ? trim($secrets['fallback_model']) : '';
$fallbackModel = $fallbackEnvironment !== '' ? $fallbackEnvironment : $secretFallbackModel;

return [
    'api_key' => $apiKey,
    'model' => $model !== '' ? $model : 'gemini-3.8-flash',
    'fallback_model' => $fallbackModel,
    'api_revision' => '2026-05-20',
    'log_file' => dirname($secretsFile) . DIRECTORY_SEPARATOR . 'gemini-api.log',
];
