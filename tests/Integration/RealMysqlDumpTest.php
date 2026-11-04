<?php

declare(strict_types=1);

namespace YourVendor\DbBackup\Tests\Integration;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use YourVendor\DbBackup\Tests\Support\MultipartBodyParser;
use YourVendor\DbBackup\Tests\Support\WorksWithTempFiles;
use YourVendor\DbBackup\Tests\TestCase;

/**
 * Проверка на реальном сервере MySQL/MariaDB с настоящим mysqldump.
 *
 * Тест пропускается, если не задана переменная окружения BACKUP_TEST_MYSQL_URL
 * в формате:
 *
 *   BACKUP_TEST_MYSQL_URL='mysql://user:password@127.0.0.1:3306/database'
 *
 * Запуск только этого теста:
 *   BACKUP_TEST_MYSQL_URL='...' vendor/bin/phpunit --group integration --filter RealMysql
 *
 * Зачем нужен: поддельный mysqldump проверяет аргументы, но только настоящий
 * клиент подтверждает, что пароль из файла опций принимается сервером, а дамп
 * содержит данные и может быть распакован.
 */
#[Group('integration')]
final class RealMysqlDumpTest extends TestCase
{
    use WorksWithTempFiles;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $url = (string) getenv('BACKUP_TEST_MYSQL_URL');

        if ($url === '') {
            $this->markTestSkipped('не задана переменная BACKUP_TEST_MYSQL_URL — тест с реальным MySQL пропущен');
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'], $parts['user'], $parts['scheme'])) {
            $this->markTestSkipped('некорректный BACKUP_TEST_MYSQL_URL: ожидается mysql://user:pass@host:port/database');
        }

        $this->root = $this->tempDir('db-backup-real-mysql');

        config([
            'db-backup.enabled' => true,
            'db-backup.project' => 'real-mysql',
            'db-backup.temp_path' => $this->root . '/work',
            'db-backup.remote.url' => 'https://backup.example.test/api/upload',
            'db-backup.remote.token' => str_repeat('r', 64),
            'db-backup.retries' => 1,
            'db-backup.retry_delay' => 0,
            // Пустое значение: ищем mysqldump в PATH, как на боевом сервере.
            'db-backup.mysqldump.binary' => (string) (getenv('BACKUP_TEST_MYSQLDUMP') ?: ''),
            'db-backup.check_engines' => true,
            'db-backup.email.enabled' => false,
            'db-backup.logging' => [
                'channel' => 'db-backup',
                'driver' => 'single',
                'path' => $this->root . '/logs/db-backup.log',
                'level' => 'debug',
                'days' => 1,
            ],
            'database.default' => 'mysql',
            'database.connections.mysql' => [
                'driver' => 'mysql',
                'host' => (string) ($parts['host'] ?? '127.0.0.1'),
                'port' => (int) ($parts['port'] ?? 3306),
                'database' => ltrim((string) ($parts['path'] ?? ''), '/'),
                'username' => urldecode((string) $parts['user']),
                'password' => urldecode((string) ($parts['pass'] ?? '')),
                'charset' => 'utf8mb4',
                'unix_socket' => '',
            ],
        ]);

        try {
            DB::connection('mysql')->select('SELECT 1');
        } catch (\Throwable $exception) {
            $this->markTestSkipped('нет подключения к MySQL: ' . $exception->getMessage());
        }
    }

    protected function tearDown(): void
    {
        $this->cleanupTempDirectories();
        parent::tearDown();
    }

    public function test_real_mysqldump_produces_valid_archive(): void
    {
        $captured = null;

        Http::fake(function (Request $request) use (&$captured) {
            $captured = MultipartBodyParser::parse(
                $request->body(),
                $request->header('Content-Type')[0] ?? '',
            );

            return Http::response([
                'status' => 'ok',
                'project' => 'real-mysql',
                'file' => 'real-mysql/2026-11-04_17-00-00.sql.gz',
                'size' => strlen((string) ($captured['file']['content'] ?? '')),
                'sha256' => (string) ($captured['fields']['sha256'] ?? ''),
            ], 201);
        });

        $this->artisan('backup:run')->assertExitCode(0);

        $this->assertNotNull($captured, 'файл не был отправлен');
        $this->assertSame('real-mysql', $captured['fields']['project'] ?? null);

        $content = (string) ($captured['file']['content'] ?? '');
        $this->assertSame("\x1f\x8b", substr($content, 0, 2));

        $sql = gzdecode($content);
        $this->assertIsString($sql, 'архив должен распаковываться');
        $this->assertNotSame('', $sql);

        // Настоящий дамп содержит служебный заголовок и данные таблиц.
        $this->assertStringContainsString('MySQL dump', $sql);
        $this->assertStringContainsString('CREATE TABLE', $sql);

        $this->assertSame(hash('sha256', $content), $captured['fields']['sha256'] ?? null);

        // Временные файлы удалены после успеха.
        $this->assertSame([], $this->filesIn($this->root . '/work'));

        // Лог не должен содержать пароль БД.
        $log = (string) @file_get_contents($this->root . '/logs/db-backup.log');
        $password = urldecode((string) (parse_url((string) getenv('BACKUP_TEST_MYSQL_URL'), PHP_URL_PASS) ?? ''));
        if ($password !== '') {
            $this->assertStringNotContainsString($password, $log);
        }

        // Если в базе есть таблицы не на InnoDB, команда обязана предупредить.
        $nonInnoDb = DB::connection('mysql')->select(
            "SELECT TABLE_NAME AS name FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND ENGINE IS NOT NULL AND ENGINE <> 'InnoDB' LIMIT 1",
        );

        if ($nonInnoDb !== []) {
            $this->assertStringContainsString('не на InnoDB', $log);
        }
    }
}
