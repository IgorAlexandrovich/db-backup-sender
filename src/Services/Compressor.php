<?php

declare(strict_types=1);

namespace YourVendor\DbBackup\Services;

use YourVendor\DbBackup\Exceptions\BackupException;
use YourVendor\DbBackup\Support\Stages;

/**
 * Потоковое сжатие дампа в gzip.
 *
 * Файл читается и сжимается блоками по 1 МБ: дамп на 100 МБ не занимает
 * 100 МБ памяти PHP (важно при memory_limit 128M на типичном хостинге).
 */
final class Compressor
{
    /** Размер блока чтения. */
    private const CHUNK_SIZE = 1048576;

    public function __construct(private int $level = 6)
    {
    }

    /**
     * Уровень сжатия gzip (1..9).
     */
    public function level(): int
    {
        return $this->level;
    }

    /**
     * Сжимает $sourcePath в gzip-файл $targetPath.
     *
     * @throws BackupException
     */
    public function compress(string $sourcePath, string $targetPath): void
    {
        if (! is_file($sourcePath)) {
            throw new BackupException(
                sprintf('файл дампа "%s" не найден — сжимать нечего', $sourcePath),
                Stages::COMPRESS,
            );
        }

        $level = min(9, max(1, $this->level));

        $context = deflate_init(ZLIB_ENCODING_GZIP, ['level' => $level]);
        if ($context === false) {
            throw new BackupException('не удалось инициализировать gzip-сжатие', Stages::COMPRESS);
        }

        $source = fopen($sourcePath, 'rb');
        if ($source === false) {
            throw new BackupException(sprintf('не удалось открыть дамп "%s" для чтения', $sourcePath), Stages::COMPRESS);
        }

        $target = fopen($targetPath, 'wb');
        if ($target === false) {
            fclose($source);
            throw new BackupException(
                sprintf('не удалось открыть файл "%s" для записи архива', $targetPath),
                Stages::COMPRESS,
            );
        }

        try {
            while (true) {
                $chunk = fread($source, self::CHUNK_SIZE);
                if ($chunk === false) {
                    throw new BackupException('ошибка чтения дампа при сжатии', Stages::COMPRESS);
                }
                if ($chunk === '') {
                    break;
                }

                $compressed = deflate_add($context, $chunk, ZLIB_NO_FLUSH);
                if ($compressed === false) {
                    throw new BackupException('ошибка gzip-сжатия блока данных', Stages::COMPRESS);
                }

                $this->writeAll($target, $compressed, $targetPath);
            }

            // Закрываем gzip-поток: дописываем контрольную сумму и длину.
            $tail = deflate_add($context, '', ZLIB_FINISH);
            if ($tail === false) {
                throw new BackupException('ошибка завершения gzip-потока', Stages::COMPRESS);
            }
            $this->writeAll($target, $tail, $targetPath);

            if (! fflush($target)) {
                throw new BackupException('не удалось сбросить буферы файла архива на диск', Stages::COMPRESS);
            }
        } finally {
            fclose($source);
            fclose($target);
        }

        clearstatcache(true, $targetPath);

        if (! is_file($targetPath) || (int) filesize($targetPath) === 0) {
            throw new BackupException('архив gzip создан пустым', Stages::COMPRESS);
        }

        // Проверяем сигнатуру: если её нет, файл бесполезен как .sql.gz.
        if (! self::isGzip($targetPath)) {
            @unlink($targetPath);
            throw new BackupException('полученный файл не является gzip-архивом', Stages::COMPRESS);
        }
    }

    /**
     * Проверяет сигнатуру gzip (1F 8B) у файла.
     */
    public static function isGzip(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $header = fread($handle, 2);
        fclose($handle);

        return $header === "\x1f\x8b";
    }

    /**
     * Записывает данные в файл, контролируя результат записи.
     *
     * @param resource $handle
     *
     * @throws BackupException
     */
    private function writeAll($handle, string $data, string $path): void
    {
        if ($data === '') {
            return;
        }

        $written = fwrite($handle, $data);
        if ($written === false || $written !== strlen($data)) {
            throw new BackupException(
                sprintf('не удалось записать данные в архив "%s" (диск переполнен?)', $path),
                Stages::COMPRESS,
            );
        }
    }
}
