<?php

declare(strict_types=1);

namespace YourVendor\DbBackup\Exceptions;

use RuntimeException;
use Throwable;
use YourVendor\DbBackup\Support\Stages;

/**
 * Базовая ошибка резервного копирования.
 *
 * Всегда содержит этап, на котором произошёл сбой, и безопасный контекст
 * для логов и уведомлений. Секреты (пароль БД, токен) в сообщения не попадают.
 */
class BackupException extends RuntimeException
{
    /**
     * @param array<string, scalar|null> $context Безопасный для логов контекст.
     */
    public function __construct(
        string $message,
        private readonly string $stage = Stages::CONFIG,
        private readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Ключ этапа (см. Support\Stages).
     */
    public function stage(): string
    {
        return $this->stage;
    }

    /**
     * Название этапа для человека.
     */
    public function stageLabel(): string
    {
        return Stages::label($this->stage);
    }

    /**
     * Безопасный контекст для логов.
     *
     * @return array<string, scalar|null>
     */
    public function context(): array
    {
        return $this->context;
    }
}
