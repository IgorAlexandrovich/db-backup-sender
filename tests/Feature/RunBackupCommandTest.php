<?php

declare(strict_types=1);

namespace YourVendor\DbBackup\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use YourVendor\DbBackup\Notifications\BackupFailedNotification;
use YourVendor\DbBackup\Tests\Support\FakeMysqldump;
use YourVendor\DbBackup\Tests\Support\MultipartBodyParser;
use YourVendor\DbBackup\Tests\Support\WorksWithTempFiles;
use YourVendor\DbBackup\Tests\TestCase;

/**
 * Сквозные тесты команды backup:run с поддельным mysqldump и поддельным
 * HTTP-приёмником.
 */
final class RunBackupCommandTest extends TestCase
{
    use WorksWithTempFiles;

    private string $root;

    private FakeMysqldump $mysqldump;

    /** Токен проекта, который не должен попадать в логи. */
    private const TOKEN = 'kkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkk';

    /** Пароль БД, который не должен попадать в логи. */
    private const DB_PASSWORD = 'super-secret-db-password';

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = $this->tempDir('db-backup-run');
        $this->mysqldump = FakeMysqldump::create(
            $this->root . '/bin',
            "CREATE TABLE `users` (`id` INT);\nINSERT INTO `users` VALUES (1);\n",
        );

        $this->configureBackup();
    }

    protected function tearDown(): void
    {
        $this->cleanupTempDirectories();
        parent::tearDown();
    }

    /**
     * Настройки пакета для теста.
     *
     * @param array<string, mixed> $overrides
     */
    private function configureBackup(array $overrides = []): void
    {
        config(array_merge([
            'db-backup.enabled' => true,
            'db-backup.project' => 'project-one',
            'db-backup.temp_path' => $this->root . '/work',
            'db-backup.remote.url' => 'https://backup.example.test/api/upload',
            'db-backup.remote.token' => self::TOKEN,
            'db-backup.remote.header' => 'X-Backup-Token',
            'db-backup.remote.timeout' => 30,
            'db-backup.remote.verify_ssl' => true,
            'db-backup.remote.allow_insecure_http' => false,
            'db-backup.retries' => 2,
            'db-backup.retry_delay' => 0,
            'db-backup.mysqldump.binary' => $this->mysqldump->path(),
            'db-backup.mysqldump.timeout_seconds' => 0,
            'db-backup.mysqldump.extra_arguments' => [],
            'db-backup.check_engines' => false,
            'db-backup.email.enabled' => true,
            'db-backup.email.to' => 'admin@example.test',
            'db-backup.email.from' => 'backup@example.test',
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
                'host' => '127.0.0.1',
                'port' => 3306,
                'database' => 'test_db',
                'username' => 'tester',
                'password' => self::DB_PASSWORD,
                'charset' => 'utf8mb4',
            ],
        ], $overrides));
    }

    /**
     * Ответ приёмника об успешном сохранении.
     */
    private function successResponse(): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response([
            'status' => 'ok',
            'project' => 'project-one',
            'file' => 'project-one/2026-11-04_17-00-00.sql.gz',
            'size' => 100,
            'sha256' => str_repeat('a', 64),
            'request_id' => 'test',
        ], 201);
    }

    /**
     * Содержимое лога резервного копирования.
     */
    private function logContents(): string
    {
        $path = $this->root . '/logs/db-backup.log';

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * Файлы, созданные в каталоге временных файлов.
     *
     * @return list<string>
     */
    private function tempFiles(): array
    {
        return $this->filesIn($this->root . '/work');
    }

    public function test_successful_backup_sends_archive_and_cleans_up(): void
    {
        $captured = null;

        Http::fake(function (Request $request) use (&$captured) {
            $contentType = $request->header('Content-Type')[0] ?? '';
            $captured = MultipartBodyParser::parse($request->body(), $contentType);

            return $this->successResponse();
        });

        $this->artisan('backup:run')->assertExitCode(0);

        $this->assertNotNull($captured, 'запрос на приёмник не был отправлен');

        // Поля протокола.
        $this->assertSame('project-one', $captured['fields']['project'] ?? null);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $captured['fields']['sha256'] ?? '');

        // Файл действительно gzip и его сумма совпадает с заявленной.
        $this->assertNotNull($captured['file'], 'в запросе нет файла');
        $content = $captured['file']['content'];

        $this->assertSame("\x1f\x8b", substr($content, 0, 2), 'переданный файл должен быть gzip');
        $this->assertSame(
            $captured['fields']['sha256'],
            hash('sha256', $content),
            'SHA-256 должен соответствовать отправленному файлу',
        );

        $decoded = gzdecode($content);
        $this->assertIsString($decoded);
        $this->assertStringContainsString('CREATE TABLE `users`', $decoded);

        // После успешной отправки временные файлы удаляются.
        $this->assertSame([], $this->tempFiles(), 'временные файлы должны быть удалены после успеха');

        // Лог содержит итог и не содержит секретов.
        $log = $this->logContents();
        $this->assertStringContainsString('Резервное копирование завершено успешно', $log);
        $this->assertStringContainsString('project-one', $log);
        $this->assertStringNotContainsString(self::TOKEN, $log);
        $this->assertStringNotContainsString(self::DB_PASSWORD, $log);
    }

    public function test_disabled_backup_does_nothing(): void
    {
        $this->configureBackup(['db-backup.enabled' => false]);
        Http::fake();

        $this->artisan('backup:run')->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame([], $this->tempFiles());
        $this->assertStringContainsString('отключено', $this->logContents());
    }

    public function test_force_option_overrides_disabled_flag(): void
    {
        $this->configureBackup(['db-backup.enabled' => false]);
        Http::fake(fn () => $this->successResponse());

        $this->artisan('backup:run', ['--force' => true])->assertExitCode(0);

        Http::assertSentCount(1);
    }

    public function test_upload_failure_sends_notification_and_keeps_temp_files(): void
    {
        Notification::fake();

        Http::fake([
            '*' => Http::response([
                'status' => 'error',
                'error_code' => 'checksum_mismatch',
                'message' => 'контрольная сумма не совпадает с переданным файлом',
            ], 422),
        ]);

        $this->artisan('backup:run')->assertExitCode(1);

        // Необработанная ошибка данных не повторяется.
        Http::assertSentCount(1);

        Notification::assertSentOnDemand(
            BackupFailedNotification::class,
            function (BackupFailedNotification $notification) {
                $details = $notification->details();

                return $details['project'] === 'project-one'
                    && $details['stage'] === 'Отправка на удалённый сервер'
                    && str_contains($details['reason'], 'контрольная сумма')
                    && $details['attempts'] === '1 из 2';
            },
        );

        // Дамп нельзя удалять до подтверждённой отправки: файлы остаются для разбора.
        $files = $this->tempFiles();
        $this->assertNotEmpty($files, 'при ошибке временные файлы должны сохраняться');

        $hasDump = false;
        $hasArchive = false;
        foreach ($files as $file) {
            $hasDump = $hasDump || str_ends_with($file, '.sql');
            $hasArchive = $hasArchive || str_ends_with($file, '.sql.gz');
        }
        $this->assertTrue($hasDump, 'исходный дамп должен сохраниться');
        $this->assertTrue($hasArchive, 'gzip-архив должен сохраниться');

        $log = $this->logContents();
        $this->assertStringContainsString('Ошибка резервного копирования', $log);
        $this->assertStringContainsString('upload', $log);
        $this->assertStringNotContainsString(self::TOKEN, $log);
    }

    public function test_retries_server_error_and_succeeds(): void
    {
        $calls = 0;

        Http::fake(function () use (&$calls) {
            $calls++;

            if ($calls === 1) {
                return Http::response(['status' => 'error', 'error_code' => 'internal_error', 'message' => 'сбой'], 503);
            }

            return $this->successResponse();
        });

        $this->artisan('backup:run')->assertExitCode(0);

        $this->assertSame(2, $calls, 'временная ошибка должна повторяться');
    }

    public function test_missing_mysqldump_fails_with_notification(): void
    {
        Notification::fake();
        $this->configureBackup(['db-backup.mysqldump.binary' => '/nonexistent/mysqldump']);
        Http::fake();

        $this->artisan('backup:run')->assertExitCode(1);

        Http::assertNothingSent();
        Notification::assertSentOnDemand(BackupFailedNotification::class);

        $this->assertStringContainsString('mysqldump', $this->logContents());
    }

    public function test_invalid_project_name_is_rejected(): void
    {
        Notification::fake();
        $this->configureBackup(['db-backup.project' => '../etc']);
        Http::fake();

        $this->artisan('backup:run')->assertExitCode(1);

        Http::assertNothingSent();
        Notification::assertSentOnDemand(BackupFailedNotification::class);
        $this->assertStringContainsString('недопустимое имя проекта', $this->logContents());
    }

    public function test_insecure_http_is_rejected_without_flag(): void
    {
        Notification::fake();
        $this->configureBackup(['db-backup.remote.url' => 'http://backup.example.test/api/upload']);
        Http::fake();

        $this->artisan('backup:run')->assertExitCode(1);

        Http::assertNothingSent();
        $this->assertStringContainsString('https', $this->logContents());
        // Дамп даже не должен начинать создаваться.
        $this->assertSame([], $this->tempFiles());
    }

    public function test_keep_temp_option_preserves_files(): void
    {
        Http::fake(fn () => $this->successResponse());

        $this->artisan('backup:run', ['--keep-temp' => true])->assertExitCode(0);

        $this->assertNotEmpty($this->tempFiles(), 'с флагом --keep-temp файлы должны сохраняться');
    }

    public function test_no_notify_option_disables_email(): void
    {
        Notification::fake();

        Http::fake([
            '*' => Http::response(['status' => 'error', 'error_code' => 'invalid_token', 'message' => 'неверный токен'], 401),
        ]);

        $this->artisan('backup:run', ['--no-notify' => true])->assertExitCode(1);

        Notification::assertNothingSent();
    }

    public function test_notification_is_not_sent_when_email_disabled(): void
    {
        Notification::fake();
        $this->configureBackup(['db-backup.email.enabled' => false]);

        Http::fake([
            '*' => Http::response(['status' => 'error', 'error_code' => 'invalid_token', 'message' => 'неверный токен'], 401),
        ]);

        $this->artisan('backup:run')->assertExitCode(1);

        Notification::assertNothingSent();
    }

    public function test_dump_failure_keeps_no_upload_request(): void
    {
        Notification::fake();

        $failing = FakeMysqldump::create($this->root . '/bin-fail', exitCode: 2, stderr: 'mysqldump: Got error: 1044: Access denied for user');
        $this->configureBackup(['db-backup.mysqldump.binary' => $failing->path()]);
        Http::fake();

        $this->artisan('backup:run')->assertExitCode(1);

        Http::assertNothingSent();
        $this->assertStringContainsString('Создание дампа базы данных', $this->logContents());
    }
}
