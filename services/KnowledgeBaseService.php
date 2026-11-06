<?php

declare(strict_types=1);

interface KnowledgeBaseService
{
    public function retrieveRelevantContent(int $userId, string $question): array;
}
