<?php

declare(strict_types=1);

namespace YourVendor\DbBackup\Tests\Unit;

use YourVendor\DbBackup\Exceptions\BackupException;
use YourVendor\DbBackup\Services\Checksum;
use YourVendor\DbBackup\Tests\Support\WorksWithTempFiles;
use YourVendor\DbBackup\Tests\TestCase;

/**
 * Тесты вычисления SHA-256.
 */
final class ChecksumTest extends TestCase
{
    use WorksWithTempFiles;

    protected function tearDown(): void
    {
        $this->cleanupTempDirectories();
        parent::tearDown();
    }

    public function test_sha256_matches_known_vector(): void
    {
        $directory = $this->tempDir();
        $path = $directory . '/abc.txt';
        file_put_contents($path, 'abc');

        $checksum = (new Checksum())->sha256($path);

        // Общеизвестный тестовый вектор SHA-256("abc").
        $this->assertSame('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad', $checksum);
    }

    public function test_sha256_of_empty_file(): void
    {
        $directory = $this->tempDir();
        $path = $directory . '/empty.bin';
        file_put_contents($path, '');

        $this->assertSame(
            'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            (new Checksum())->sha256($path),
        );
    }

    public function test_sha256_matches_native_function(): void
    {
        $directory = $this->tempDir();
        $path = $directory . '/dump.sql.gz';
        file_put_contents($path, random_bytes(256 * 1024));

        $this->assertSame(hash_file('sha256', $path), (new Checksum())->sha256($path));
    }

    public function test_sha256_throws_for_missing_file(): void
    {
        $directory = $this->tempDir();

        $this->expectException(BackupException::class);

        (new Checksum())->sha256($directory . '/absent.gz');
    }

    public function test_matches_compares_case_insensitively(): void
    {
        $checksum = new Checksum();

        $this->assertTrue($checksum->matches(str_repeat('a', 64), str_repeat('A', 64)));
        $this->assertFalse($checksum->matches(str_repeat('a', 64), str_repeat('b', 64)));
    }
}
