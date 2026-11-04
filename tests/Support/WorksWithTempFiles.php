<?php

declare(strict_types=1);

namespace YourVendor\DbBackup\Tests\Support;

use Illuminate\Support\Facades\File;

/**
 * Вспомогательные методы для тестов: временные каталоги.
 */
trait WorksWithTempFiles
{
    /** @var list<string> Каталоги, которые нужно удалить после теста. */
    private array $tempDirectories = [];

    /**
     * Создаёт временный каталог и регистрирует его для удаления.
     */
    protected function tempDir(string $prefix = 'db-backup-test'): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . '-' . bin2hex(random_bytes(6));

        if (! mkdir($directory, 0o750, true) && ! is_dir($directory)) {
            throw new \RuntimeException(sprintf('не удалось создать временный каталог %s', $directory));
        }

        $this->tempDirectories[] = $directory;

        return $directory;
    }

    /**
     * Удаляет созданные тестом каталоги.
     */
    protected function cleanupTempDirectories(): void
    {
        foreach ($this->tempDirectories as $directory) {
            File::deleteDirectory($directory);
        }

        $this->tempDirectories = [];
    }

    /**
     * Рекурсивно возвращает список файлов каталога (только файлы).
     *
     * @return list<string>
     */
    protected function filesIn(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        foreach (File::allFiles($directory) as $file) {
            $files[] = $file->getPathname();
        }

        return $files;
    }
}
