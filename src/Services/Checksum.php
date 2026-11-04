<?php

declare(strict_types=1);

namespace YourVendor\DbBackup\Services;

use YourVendor\DbBackup\Exceptions\BackupException;
use YourVendor\DbBackup\Support\Stages;

/**
 * Вычисление контрольной суммы SHA-256.
 *
 * Используется hash_file: файл читается потоково, память не расходуется
 * пропорционально размеру дампа.
 */
final class Checksum
{
    /**
     * Возвращает SHA-256 файла в hex-формате (64 символа в нижнем регистре).
     *
     * @throws BackupException
     */
    public function sha256(string $path): string
    {
        if (! is_file($path)) {
            throw new BackupException(sprintf('файл "%s" не найден для расчёта SHA-256', $path), Stages::CHECKSUM);
        }

        $hash = @hash_file('sha256', $path);

        if ($hash === false) {
            throw new BackupException(sprintf('не удалось вычислить SHA-256 файла "%s"', $path), Stages::CHECKSUM);
        }

        $hash = strtolower($hash);

        // Защита от «странных» результатов: длина SHA-256 в hex строго 64 символа.
        if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
            throw new BackupException('получена некорректная контрольная сумма SHA-256', Stages::CHECKSUM);
        }

        return $hash;
    }

    /**
     * Сравнивает две контрольные суммы за постоянное время.
     */
    public function matches(string $expected, string $actual): bool
    {
        return hash_equals(strtolower($expected), strtolower($actual));
    }
}
