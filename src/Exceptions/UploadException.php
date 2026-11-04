<?php

declare(strict_types=1);

namespace YourVendor\DbBackup\Exceptions;

use Throwable;
use YourVendor\DbBackup\Support\Stages;

/**
 * Ошибка отправки резервной копии на приёмник.
 *
 * Хранит номер последней попытки и общее число попыток, чтобы уведомление
 * могло показать «Попытки: 3 из 3».
 */
final class UploadException extends BackupException
{
    /**
     * @param array<string, scalar|null> $context
     */
    public function __construct(
        string $message,
        private readonly int $attempts = 1,
        private readonly int $maxAttempts = 1,
        private readonly bool $retryable = false,
        array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, Stages::UPLOAD, $context, $previous);
    }

    /**
     * Сколько попыток уже сделано.
     */
    public function attempts(): int
    {
        return $this->attempts;
    }

    /**
     * Сколько попыток разрешено конфигурацией.
     */
    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    /**
     * Имеет ли смысл повторять отправку.
     */
    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    /**
     * Текст «Попытки: N из M» для писем и вывода команды.
     */
    public function attemptsLabel(): string
    {
        return sprintf('%d из %d', $this->attempts, $this->maxAttempts);
    }
}
