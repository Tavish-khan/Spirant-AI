<?php

declare(strict_types=1);

abstract class AIService
{
    abstract public function generateTextResponse(string $prompt, ?array $context = null): string;

    abstract public function generateImageResponse(string $imagePath, string $question): string;
}
