<?php

declare(strict_types=1);

require_once __DIR__ . '/AIService.php';

final class GeminiApiException extends RuntimeException
{
    public int $httpStatus;
    public string $apiStatus;
    public string $diagnosticId;

    public function __construct(
        string $message,
        int $httpStatus = 502,
        string $apiStatus = '',
        string $diagnosticId = ''
    ) {
        $this->httpStatus = $httpStatus;
        $this->apiStatus = $apiStatus;
        $this->diagnosticId = $diagnosticId;
        parent::__construct($message);
    }
}

final class GeminiAIService extends AIService
{
    private const API_URL = 'https://generativelanguage.googleapis.com/v1beta/interactions';
    private const MAX_OUTPUT_TOKENS = 2048;
    private const MAX_ATTEMPTS = 3;
    private const RETRYABLE_HTTP_STATUSES = [429, 500, 502, 503, 504];
    private const RETRY_DELAYS_SECONDS = [1, 2];
    private const INTERACTIONS_API_MODELS = [
        'gemini-3.8-flash',
        'gemini-3.7-flash',
        'gemini-3.6-flash',
        'gemini-3.5-flash',
        'gemini-3.5-flash-lite',
        'gemini-3.1-flash-lite',
    ];
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function generateInteraction(array $input, ?string $previousInteractionId = null, ?string $systemInstruction = null): array
    {
        $apiKey = trim((string) ($this->config['api_key'] ?? ''));
        if ($apiKey === '') {
            throw new GeminiApiException('Gemini is not configured. Add GEMINI_API_KEY to the server-side secret configuration.', 503, 'MISSING_API_KEY');
        }

        if (!function_exists('curl_init')) {
            throw new GeminiApiException('The server is missing PHP cURL support. Enable the cURL extension to use Gemini.', 503, 'CURL_UNAVAILABLE');
        }

        $configuredModel = (string) ($this->config['model'] ?? 'gemini-3.8-flash');
        $fallbackModel = trim((string) ($this->config['fallback_model'] ?? ''));
        $fallbackChecked = false;
        $activeModel = $configuredModel;
        $requestHeaders = [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $apiKey,
            'Api-Revision: ' . ($this->config['api_revision'] ?? '2026-05-20'),
        ];

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            if ($attempt === self::MAX_ATTEMPTS && $previousInteractionId === null && $fallbackModel !== '' && $fallbackModel !== $configuredModel && !$fallbackChecked) {
                $fallbackChecked = true;
                if (self::isFallbackModelAvailable($fallbackModel, $apiKey, $this)) {
                    $activeModel = $fallbackModel;
                }
            }

            $payload = [
                'model' => $activeModel,
                'input' => $input,
                'store' => true,
                'generation_config' => [
                    'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
                ],
            ];
            if ($previousInteractionId !== null && $previousInteractionId !== '') {
                $payload['previous_interaction_id'] = $previousInteractionId;
            }
            if ($systemInstruction !== null && trim($systemInstruction) !== '') {
                $payload['system_instruction'] = $systemInstruction;
            }

            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($body === false) {
                throw new GeminiApiException('The chat request could not be prepared. Please try again.', 500, 'REQUEST_ENCODING_FAILED');
            }

            $diagnosticId = bin2hex(random_bytes(8));
            $handle = curl_init(self::API_URL);
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $requestHeaders,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 90,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $responseBody = curl_exec($handle);
            $curlErrorNumber = curl_errno($handle);
            $httpStatus = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            curl_close($handle);

            if ($responseBody === false) {
                $this->writeDiagnostic('request_attempt', [
                    'endpoint' => self::API_URL,
                    'attempt' => $attempt,
                    'max_attempts' => self::MAX_ATTEMPTS,
                    'http_status' => $httpStatus,
                    'error_code' => $curlErrorNumber === CURLE_OPERATION_TIMEDOUT ? 'TIMEOUT' : 'NETWORK_ERROR',
                    'curl_errno' => $curlErrorNumber,
                    'model' => $activeModel,
                    'input' => self::summarizeInput($input),
                ], $diagnosticId);
                $statusCode = $curlErrorNumber === CURLE_OPERATION_TIMEDOUT ? 504 : 502;
                $apiStatus = $curlErrorNumber === CURLE_OPERATION_TIMEDOUT ? 'TIMEOUT' : 'NETWORK_ERROR';
                $message = $statusCode === 504
                    ? 'Gemini took too long to respond. Please try again.'
                    : 'Could not reach Gemini. Check the server connection and try again.';
                throw new GeminiApiException($message, $statusCode, $apiStatus, $diagnosticId);
            }

            $decoded = json_decode($responseBody, true);
            if (!is_array($decoded)) {
                $this->writeDiagnostic('request_attempt', [
                    'endpoint' => self::API_URL,
                    'attempt' => $attempt,
                    'max_attempts' => self::MAX_ATTEMPTS,
                    'http_status' => $httpStatus,
                    'error_code' => 'INVALID_JSON',
                    'model' => $activeModel,
                    'response_body' => self::redactSecret($responseBody, $apiKey),
                    'input' => self::summarizeInput($input),
                ], $diagnosticId);
                throw new GeminiApiException('Gemini returned an unreadable response. Please try again.', 502, 'INVALID_RESPONSE', $diagnosticId);
            }

            if ($httpStatus < 200 || $httpStatus >= 300) {
                $googleErrorCode = strtoupper((string) ($decoded['error']['status'] ?? $decoded['error']['code'] ?? 'UNKNOWN'));
                $apiMessage = strtolower((string) ($decoded['error']['message'] ?? ''));
                $apiStatus = $googleErrorCode;
                if ($httpStatus === 401 || $apiStatus === 'UNAUTHENTICATED' || str_contains($apiMessage, 'api key')) {
                    $apiStatus = 'INVALID_API_KEY';
                } elseif ($httpStatus === 404 || str_contains($apiMessage, 'model not found') || str_contains($apiMessage, 'model is not found')) {
                    $apiStatus = 'MODEL_UNAVAILABLE';
                }
                $responseJson = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $this->writeDiagnostic('request_attempt', [
                    'endpoint' => self::API_URL,
                    'attempt' => $attempt,
                    'max_attempts' => self::MAX_ATTEMPTS,
                    'http_status' => $httpStatus,
                    'error_code' => $googleErrorCode,
                    'api_status' => $apiStatus,
                    'model' => $configuredModel,
                    'google_error_json' => self::redactSecret($responseJson !== false ? $responseJson : $responseBody, $apiKey),
                    'input' => self::summarizeInput($input),
                ], $diagnosticId);

                if (in_array($httpStatus, self::RETRYABLE_HTTP_STATUSES, true) && $attempt < self::MAX_ATTEMPTS) {
                    usleep(self::RETRY_DELAYS_SECONDS[$attempt - 1] * 1000000);
                    continue;
                }

                $message = in_array($httpStatus, self::RETRYABLE_HTTP_STATUSES, true)
                    ? 'Gemini is temporarily unavailable after several attempts. Please try again shortly.'
                    : self::userMessageForError($httpStatus, $apiStatus, $apiMessage);
                throw new GeminiApiException($message, $httpStatus, $apiStatus, $diagnosticId);
            }

            $this->writeDiagnostic('request_attempt', [
                'endpoint' => self::API_URL,
                'attempt' => $attempt,
                'max_attempts' => self::MAX_ATTEMPTS,
                'http_status' => $httpStatus,
                'error_code' => null,
                'model' => $activeModel,
                'input' => self::summarizeInput($input),
            ], $diagnosticId);

            $textParts = [];
            foreach (($decoded['steps'] ?? []) as $step) {
                if (($step['type'] ?? '') !== 'model_output') {
                    continue;
                }
                foreach (($step['content'] ?? []) as $part) {
                    if (($part['type'] ?? '') === 'text' && isset($part['text'])) {
                        $textParts[] = (string) $part['text'];
                    }
                }
            }

            $text = trim(implode('', $textParts));
            if ($text === '') {
                throw new GeminiApiException('Gemini did not return a text answer. Please rephrase your question and try again.', 502, 'EMPTY_RESPONSE', $diagnosticId);
            }

            $interactionId = trim((string) ($decoded['id'] ?? ''));
            if ($interactionId === '') {
                throw new GeminiApiException('Gemini returned an incomplete response. Please try again.', 502, 'MISSING_INTERACTION_ID', $diagnosticId);
            }

            return [
                'text' => $text,
                'interaction_id' => $interactionId,
                'model' => (string) ($decoded['model'] ?? $activeModel),
            ];
        }

        throw new GeminiApiException('Gemini is temporarily unavailable after several attempts. Please try again shortly.', 503, 'RETRIES_EXHAUSTED');
    }

    public function generateTextResponse(string $prompt, ?array $context = null): string
    {
        $input = [[
            'type' => 'user_input',
            'content' => [['type' => 'text', 'text' => $prompt]],
        ]];
        return $this->generateInteraction($input)['text'];
    }

    public function generateImageResponse(string $imagePath, string $question): string
    {
        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($imagePath);
        $imageBytes = file_get_contents($imagePath);
        if ($imageBytes === false || !in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new GeminiApiException('The uploaded image could not be prepared for analysis.', 400, 'INVALID_IMAGE');
        }

        $content = [];
        if (trim($question) !== '') {
            $content[] = ['type' => 'text', 'text' => $question];
        } else {
            $content[] = ['type' => 'text', 'text' => 'Describe this image.'];
        }
        $content[] = ['type' => 'image', 'data' => base64_encode($imageBytes), 'mime_type' => $mimeType];

        $input = [['type' => 'user_input', 'content' => $content]];
        return $this->generateInteraction($input)['text'];
    }

    private static function userMessageForError(int $httpStatus, string $apiStatus, string $apiMessage): string
    {
        if ($httpStatus === 402) {
            return 'Gemini billing needs attention. Check the quota and billing status for the Google project.';
        }
        if ($httpStatus === 429 || $apiStatus === 'RESOURCE_EXHAUSTED') {
            return 'Gemini quota is temporarily unavailable. Please try again later.';
        }
        if ($httpStatus === 401 || $apiStatus === 'UNAUTHENTICATED' || str_contains($apiMessage, 'api key not valid')) {
            return 'Gemini rejected the server API key. Check GEMINI_API_KEY in the server configuration.';
        }
        if ($httpStatus === 403 || $apiStatus === 'PERMISSION_DENIED') {
            return 'Gemini denied access to this model. Check the key, model access, and billing for its Google project.';
        }
        if ($httpStatus >= 500 || $apiStatus === 'UNAVAILABLE') {
            return 'Gemini is temporarily unavailable. Please try again shortly.';
        }
        if ($httpStatus === 400 || $apiStatus === 'INVALID_ARGUMENT') {
            return 'Gemini could not accept this request. Check the model configuration and uploaded image, then try again.';
        }
        return 'Gemini could not complete the request. Please try again later.';
    }

    private static function isFallbackModelAvailable(string $model, string $apiKey, self $service): bool
    {
        $diagnosticId = bin2hex(random_bytes(8));
        if (!in_array($model, self::INTERACTIONS_API_MODELS, true)) {
            $service->writeDiagnostic('fallback_model_verification', [
                'model' => $model,
                'supported_by_interactions_docs' => false,
                'available_to_api_key' => false,
                'error_code' => 'NOT_IN_INTERACTIONS_MODEL_CATALOG',
            ], $diagnosticId);
            return false;
        }

        if (!function_exists('curl_init')) {
            return false;
        }

        $handle = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model));
        curl_setopt_array($handle, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => ['x-goog-api-key: ' . $apiKey],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $responseBody = curl_exec($handle);
        $httpStatus = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $curlErrorNumber = curl_errno($handle);
        curl_close($handle);

        $modelInfo = is_string($responseBody) ? json_decode($responseBody, true) : null;
        $resourceName = is_array($modelInfo) ? (string) ($modelInfo['name'] ?? '') : '';
        $baseModelId = is_array($modelInfo) ? (string) ($modelInfo['baseModelId'] ?? '') : '';
        $available = $responseBody !== false
            && $httpStatus === 200
            && ($resourceName === 'models/' . $model || $baseModelId === $model);

        $service->writeDiagnostic('fallback_model_verification', [
            'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model),
            'model' => $model,
            'http_status' => $httpStatus,
            'error_code' => $available ? null : ($curlErrorNumber !== 0 ? 'NETWORK_ERROR' : 'MODEL_NOT_AVAILABLE'),
            'supported_by_interactions_docs' => true,
            'available_to_api_key' => $available,
        ], $diagnosticId);

        return $available;
    }

    private function writeDiagnostic(string $event, array $details, ?string $diagnosticId = null): string
    {
        $diagnosticId ??= bin2hex(random_bytes(8));
        $entry = [
            'timestamp' => gmdate('c'),
            'diagnostic_id' => $diagnosticId,
            'event' => $event,
        ] + $details;
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            error_log('Spirant Gemini diagnostic could not be encoded. diagnostic_id=' . $diagnosticId);
            return $diagnosticId;
        }

        $logFile = (string) ($this->config['log_file'] ?? '');
        if ($logFile !== '' && self::isOutsideDocumentRoot($logFile)) {
            if (@file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX) !== false) {
                @chmod($logFile, 0600);
                return $diagnosticId;
            }
        }

        error_log($line);
        return $diagnosticId;
    }

    private static function isOutsideDocumentRoot(string $path): bool
    {
        $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $logDirectory = realpath(dirname($path));
        if ($documentRoot === false || $logDirectory === false) {
            return true;
        }

        $documentRoot = rtrim(strtolower($documentRoot), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $logDirectory = rtrim(strtolower($logDirectory), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return !str_starts_with($logDirectory, $documentRoot);
    }

    private static function redactSecret(string $value, string $apiKey): string
    {
        return $apiKey === '' ? $value : str_replace($apiKey, '[REDACTED]', $value);
    }

    private static function summarizeInput(array $input): array
    {
        $summary = [
            'text_parts' => 0,
            'image_parts' => [],
        ];
        $parts = [];
        foreach ($input as $item) {
            if (($item['type'] ?? '') === 'user_input') {
                $parts = array_merge($parts, $item['content'] ?? []);
            } elseif (isset($item['type'])) {
                $parts[] = $item;
            }
        }

        foreach ($parts as $part) {
            if (($part['type'] ?? '') === 'text') {
                $summary['text_parts']++;
            } elseif (($part['type'] ?? '') === 'image') {
                $summary['image_parts'][] = [
                    'mime_type' => $part['mime_type'] ?? 'unknown',
                    'base64_length' => strlen((string) ($part['data'] ?? '')),
                ];
            }
        }

        return $summary;
    }
}
