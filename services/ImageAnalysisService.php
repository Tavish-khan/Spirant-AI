<?php

declare(strict_types=1);

require_once __DIR__ . '/AIService.php';

class ImageAnalysisService extends AIService
{
    public function generateTextResponse(string $prompt, ?array $context = null): string
    {
        return 'This service is reserved for real image-analysis integration in Phase 2.';
    }

    public function generateImageResponse(string $imagePath, string $question): string
    {
        $fileName = basename($imagePath);
        return "Vision model placeholder: The image {$fileName} has been received and queued for analysis. Once a real image-capable API is connected, this response will be replaced with actual content-based understanding of the uploaded file.";
    }
}
