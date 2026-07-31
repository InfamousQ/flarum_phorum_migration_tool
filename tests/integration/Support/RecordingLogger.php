<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Support;

use Psr\Log\AbstractLogger;

/**
 * Minimal PSR-3 logger that records every call so tests can assert on
 * critical/error messages logged by the migration pipeline (e.g. unknown
 * Phorum user/forum ids) instead of only inspecting side effects.
 */
class RecordingLogger extends AbstractLogger
{
    /** @var array<int, array{level: string, message: string, context: array}> */
    public array $records = [];

    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];
    }

    public function hasRecordMatching(string $level, string $messageSubstring): bool
    {
        foreach ($this->records as $record) {
            if ($record['level'] === $level && str_contains($record['message'], $messageSubstring)) {
                return true;
            }
        }

        return false;
    }
}
