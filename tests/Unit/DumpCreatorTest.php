<?php

declare(strict_types=1);

namespace YourVendor\DbBackup\Tests\Unit;

use YourVendor\DbBackup\Exceptions\ConfigurationException;
use YourVendor\DbBackup\Exceptions\DumpException;
use YourVendor\DbBackup\Services\DumpCreator;
use YourVendor\DbBackup\Tests\Support\FakeMysqldump;
use YourVendor\DbBackup\Tests\Support\WorksWithTempFiles;
use YourVendor\DbBackup\Tests\TestCase;

/**
 * Тесты создания дампа: формирование файла опций, аргументы, обработка ошибок.
 */
final class DumpCreatorTest extends TestCase
{
    use WorksWithTempFiles;

    protected function tearDown(): void
    {
        $this->cleanupTempDirectories();
        parent::tearDown();
    }

    /**
     * Типичное подключение MySQL из config/database.php.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function connection(array $overrides = []): array
    {
        return array_merge([
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'example_db',
            'username' => 'app_user',
            'password' => 'p@ss "word"',
            'unix_socket' => '',
            'charset' => 'utf8mb4',
        ], $overrides);
    }

    public function test_returns_database_name_or_throws(): void
    {
        $creator = new DumpCreator($this->connection());
        $this->assertSame('example_db', $creator->database());
        $this->assertSame('127.0.0.1:3306', $creator->host());

        $this->expectException(ConfigurationException::class);
        (new DumpCreator($this->connection(['database' => ''])))->database();
    }

    public function test_client_config_escapes_special_characters(): void
    {
        $contents = (new DumpCreator($this->connection()))->clientConfigContents();

        $this->assertStringContainsString("[client]\n", $contents);
        $this->assertStringContainsString('user=app_user', $contents);
        $this->assertStringContainsString('host=127.0.0.1', $contents);
        $this->assertStringContainsString('port=3306', $contents);
        $this->assertStringContainsString('password="p@ss \\"word\\""', $contents);
        $this->assertStringNotContainsString('unix_socket', $contents);
    }

    public function test_client_config_prefers_unix_socket(): void
    {
        $contents = (new DumpCreator($this->connection([
            'unix_socket' => '/var/run/mysqld/mysqld.sock',
        ])))->clientConfigContents();

        $this->assertStringContainsString('socket=/var/run/mysqld/mysqld.sock', $contents);
        $this->assertStringNotContainsString('host=127.0.0.1', $contents);
        $this->assertStringNotContainsString('port=3306', $contents);
    }

    public function test_missing_binary_raises_exception(): void
    {
        $creator = new DumpCreator($this->connection(), '/nonexistent/path/mysqldump');

        $this->assertFalse($creator->isAvailable());
        $this->expectException(DumpException::class);
        $this->expectExceptionMessageMatches('/не найден/');
        $creator->resolveBinary();
    }

    public function test_create_runs_mysqldump_with_safe_arguments(): void
    {
        $directory = $this->tempDir();
        $fake = FakeMysqldump::create($directory . '/bin', "CREATE TABLE `t` (`id` INT);\n");
        $target = $directory . '/dump.sql';

        $creator = new DumpCreator($this->connection(), $fake->path());
        $creator->create($target);

        $this->assertFileExists($target);
        $this->assertSame("CREATE TABLE `t` (`id` INT);\n", file_get_contents($target));

        $arguments = $fake->arguments();

        $this->assertStringStartsWith('--defaults-extra-file=', $arguments[0], 'файл опций должен идти первым аргументом');
        $this->assertContains('--single-transaction', $arguments);
        $this->assertContains('--skip-lock-tables', $arguments);
        $this->assertSame('example_db', $arguments[count($arguments) - 1], 'имя базы — последний аргумент');

        // Пароль не должен быть виден в аргументах процесса.
        foreach ($arguments as $argument) {
            $this->assertStringNotContainsString('p@ss', $argument);
        }

        // Файл с паролем должен быть передан mysqldump...
        $this->assertStringContainsString('password=', $fake->clientConfig());

        // ...и удалён после завершения процесса.
        $leftovers = glob($directory . '/.mysql-client-*') ?: [];
        $this->assertSame([], $leftovers, 'временный файл с паролем должен быть удалён');
    }

    public function test_create_reports_mysqldump_failure_without_password(): void
    {
        $directory = $this->tempDir();
        $stderr = "mysqldump: Got error: 1045: Access denied for user 'app_user'@'localhost' (using password: YES)";
        $fake = FakeMysqldump::create($directory . '/bin', exitCode: 2, stderr: $stderr);

        $creator = new DumpCreator($this->connection(), $fake->path());

        try {
            $creator->create($directory . '/dump.sql');
            $this->fail('ожидалось исключение DumpException');
        } catch (DumpException $exception) {
            $this->assertStringContainsString('Access denied', $exception->getMessage());
            $this->assertStringContainsString('проверьте логин, пароль', $exception->getMessage());
            $this->assertStringNotContainsString('p@ss "word"', $exception->getMessage());
            $this->assertSame('dump', $exception->stage());
        }

        // Файл опций не должен остаться даже при ошибке.
        $this->assertSame([], glob($directory . '/.mysql-client-*') ?: []);
    }

    public function test_failure_message_hides_password_even_if_it_leaks_to_stderr(): void
    {
        $directory = $this->tempDir();
        $fake = FakeMysqldump::create(
            $directory . '/bin',
            exitCode: 1,
            stderr: 'ошибка с паролем p@ss "word" в тексте',
        );

        try {
            (new DumpCreator($this->connection(), $fake->path()))->create($directory . '/dump.sql');
            $this->fail('ожидалось исключение');
        } catch (DumpException $exception) {
            $this->assertStringNotContainsString('p@ss "word"', $exception->getMessage());
            $this->assertStringContainsString('***', $exception->getMessage());
        }
    }

    public function test_create_rejects_empty_result(): void
    {
        $directory = $this->tempDir();
        $fake = FakeMysqldump::create($directory . '/bin', sql: '');

        $this->expectException(DumpException::class);
        $this->expectExceptionMessageMatches('/пустой дамп/');

        (new DumpCreator($this->connection(), $fake->path()))->create($directory . '/dump.sql');
    }

    public function test_extra_arguments_are_passed_to_mysqldump(): void
    {
        $directory = $this->tempDir();
        $fake = FakeMysqldump::create($directory . '/bin');

        (new DumpCreator(
            connection: $this->connection(),
            binary: $fake->path(),
            extraArguments: ['--set-gtid-purged=OFF'],
        ))->create($directory . '/dump.sql');

        $this->assertContains('--set-gtid-purged=OFF', $fake->arguments());
    }

    public function test_binary_is_searched_in_path(): void
    {
        // Проверяем поиск по PATH на примере стандартной утилиты sh.
        $creator = new DumpCreator($this->connection(), 'sh');

        $this->assertStringEndsWith('/sh', $creator->resolveBinary());
        $this->assertTrue($creator->isAvailable());
    }
}
