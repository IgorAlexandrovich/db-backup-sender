<?php

declare(strict_types=1);

namespace YourVendor\DbBackup\Tests\Unit;

use YourVendor\DbBackup\Exceptions\BackupException;
use YourVendor\DbBackup\Services\Compressor;
use YourVendor\DbBackup\Tests\Support\WorksWithTempFiles;
use YourVendor\DbBackup\Tests\TestCase;

/**
 * Тесты gzip-сжатия.
 */
final class CompressorTest extends TestCase
{
    use WorksWithTempFiles;

    protected function tearDown(): void
    {
        $this->cleanupTempDirectories();
        parent::tearDown();
    }

    public function test_compress_creates_valid_gzip_archive(): void
    {
        $directory = $this->tempDir();
        $source = $directory . '/dump.sql';
        $target = $directory . '/dump.sql.gz';

        $sql = str_repeat("INSERT INTO `users` (`id`, `name`) VALUES (1, 'Иван');\n", 2000);
        file_put_contents($source, $sql);

        $compressor = new Compressor(6);
        $compressor->compress($source, $target);

        $this->assertFileExists($target);
        $this->assertTrue(Compressor::isGzip($target), 'архив должен иметь сигнатуру gzip 1F 8B');

        $decoded = gzdecode((string) file_get_contents($target));
        $this->assertSame($sql, $decoded, 'содержимое архива должно совпадать с исходным дампом');
        $this->assertLessThan(strlen($sql), (int) filesize($target), 'архив должен быть меньше исходного файла');
    }

    public function test_compress_handles_empty_dump(): void
    {
        $directory = $this->tempDir();
        $source = $directory . '/empty.sql';
        $target = $directory . '/empty.sql.gz';

        file_put_contents($source, '');

        (new Compressor())->compress($source, $target);

        $this->assertTrue(Compressor::isGzip($target));
        $this->assertSame('', gzdecode((string) file_get_contents($target)));
    }

    public function test_compress_handles_data_larger_than_internal_chunk(): void
    {
        $directory = $this->tempDir();
        $source = $directory . '/big.sql';
        $target = $directory . '/big.sql.gz';

        // Больше внутреннего блока в 1 МБ, чтобы проверить потоковую обработку.
        $sql = str_repeat('ABCDEFGHIJ', 400_000); // 4 МБ
        file_put_contents($source, $sql);

        (new Compressor(1))->compress($source, $target);

        $this->assertSame($sql, gzdecode((string) file_get_contents($target)));
    }

    public function test_compress_throws_when_source_missing(): void
    {
        $directory = $this->tempDir();

        $this->expectException(BackupException::class);
        $this->expectExceptionMessageMatches('/не найден/');

        (new Compressor())->compress($directory . '/missing.sql', $directory . '/missing.sql.gz');
    }

    public function test_is_gzip_rejects_plain_text(): void
    {
        $directory = $this->tempDir();
        $path = $directory . '/plain.txt';
        file_put_contents($path, 'обычный текст');

        $this->assertFalse(Compressor::isGzip($path));
    }

    public function test_level_is_limited_to_valid_range(): void
    {
        $compressor = new Compressor(99);

        $this->assertSame(99, $compressor->level());
    }
}
