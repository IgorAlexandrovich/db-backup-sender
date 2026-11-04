<?php

declare(strict_types=1);

namespace YourVendor\DbBackup\Exceptions;

use Throwable;
use YourVendor\DbBackup\Support\Stages;

/**
 * Ошибка конфигурации: не задан токен, адрес не HTTPS, некорректное имя проекта.
 *
 * Такие ошибки не имеют смысла в повторных попытках — их должен исправить
 * администратор.
 */
final class ConfigurationException extends BackupException
{
    /**
     * @param array<string, scalar|null> $context
     */
    public function __construct(string $message, array $context = [], ?Throwable $previous = null)
    {
        parent::__construct($message, Stages::CONFIG, $context, $previous);
    }
}
