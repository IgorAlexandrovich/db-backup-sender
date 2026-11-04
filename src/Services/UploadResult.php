<?php

declare(strict_types=1);

namespace YourVendor\DbBackup\Services;

/**
 * Результат успешной отправки резервной копии.
 *
 * Возвращается приёмником (см. POST /api/upload) и используется для
 * логирования и вывода команды.
 */
final class UploadResult
{
    /**
     * @param array<string, mixed> $response Полный ответ приёмника.
     */
    public function __construct(
        public readonly string $project,
        public readonly string $file,
        public readonly int $size,
        public readonly string $sha256,
        public readonly int $attempts,
        public readonly array $response = [],
    ) {
    }

    /**
     * Имя файла без пути (приёмник возвращает путь вида project/2026-11-04_17-00-00.sql.gz).
     */
    public function baseName(): string
    {
        $position = strrpos($this->file, '/');

        return $position === false ? $this->file : substr($this->file, $position + 1);
    }
}
