<?php

declare(strict_types=1);

namespace YourVendor\DbBackup\Exceptions;

use Throwable;
use YourVendor\DbBackup\Support\Stages;

/**
 * Ошибка на этапе создания дампа: mysqldump не найден, упал или не создал файл.
 */
final class DumpException extends BackupException
{
    /**
     * @param array<string, scalar|null> $context
     */
    public function __construct(string $message, array $context = [], ?Throwable $previous = null)
    {
        parent::__construct($message, Stages::DUMP, $context, $previous);
    }

    /**
     * Ошибка проверки наличия mysqldump — отдельный подэтап.
     *
     * @param array<string, scalar|null> $context
     */
    public static function binaryMissing(string $message, array $context = []): self
    {
        return new self($message, $context);
    }
}
