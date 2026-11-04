<?php

declare(strict_types=1);

namespace YourVendor\DbBackup\Tests\Integration;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use YourVendor\DbBackup\Tests\Support\FakeMysqldump;
use YourVendor\DbBackup\Tests\Support\WorksWithTempFiles;
use YourVendor\DbBackup\Tests\TestCase;

/**
 * Интеграционный тест всей цепочки: Laravel-пакет → настоящий Go-приёмник.
 *
 * Проверяется по-настоящему:
 *   - запуск бинарника backup-receiver как отдельного процесса;
 *   - реальный HTTP-запрос на POST /api/upload (без Http::fake);
 *   - проверка токена, SHA-256 и сохранение файла приёмником;
 *   - отказ при неверном токене;
 *   - ротация файлов старше срока хранения;
 *   - корректное завершение приёмника по SIGTERM.
 *
 * Если бинарник не найден, тест пропускается: путь можно задать переменной
 * окружения BACKUP_RECEIVER_BIN.
 */
#[Group('integration')]
final class EndToEndTest extends TestCase
{
    use WorksWithTempFiles;

    private string $root;

    private string $receiverConfig;

    private int $port;

    private string $token = 'integration_token_0123456789abcdef0123456789abcdef';

    private ?Process $receiver = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = $this->tempDir('db-backup-e2e');
        $this->port = $this->freePort();

        mkdir($this->root . '/backups', 0o750, true);
        mkdir($this->root . '/receiver-logs', 0o750, true);

        $this->receiverConfig = $this->root . '/config.json';
        file_put_contents($this->receiverConfig, json_encode([
            'listen' => '127.0.0.1:' . $this->port,
            'storage_path' => $this->root . '/backups',
            'max_upload_size' => 157286400,
            'retention_days' => 30,
            'timezone' => 'UTC',
            'log_level' => 'debug',
            'log_format' => 'json',
            'log_file' => $this->root . '/receiver-logs/receiver.log',
            'max_concurrent_uploads' => 8,
            'trust_proxy_ip' => false,
            'verify_gzip' => true,
            'projects' => [
                'it-project' => ['token' => $this->token],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    protected function tearDown(): void
    {
        $this->stopReceiver();
        $this->cleanupTempDirectories();
        parent::tearDown();
    }

    /**
     * Ищет бинарник приёмника.
     */
    private function receiverBinary(): string
    {
        $packageRoot = dirname(__DIR__, 2);
        $systemRoot = dirname($packageRoot);
        $machine = php_uname('m');

        $candidates = array_filter([
            getenv('BACKUP_RECEIVER_BIN') ?: null,
            $systemRoot . '/go-backup-receiver/.local/backup-receiver-darwin-' . $machine,
            $systemRoot . '/go-backup-receiver/bin/backup-receiver-linux-' . ($machine === 'arm64' || $machine === 'aarch64' ? 'arm64' : 'amd64'),
            '/usr/local/bin/backup-receiver',
        ]);

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        $this->markTestSkipped(
            'Бинарник приёмника не найден. Соберите его (scripts/build-all.sh) '
            . 'или укажите путь в BACKUP_RECEIVER_BIN.',
        );
    }

    /**
     * Находит свободный локальный порт.
     */
    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

        if ($socket === false) {
            $this->markTestSkipped('не удалось найти свободный порт: ' . (string) $errorMessage);
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        $port = (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1);

        return $port > 0 ? $port : 18095;
    }

    /**
     * Запускает приёмник и ждёт готовности /health.
     */
    private function startReceiver(): void
    {
        $binary = $this->receiverBinary();

        $this->receiver = new Process([$binary, 'serve', '-config', $this->receiverConfig]);
        $this->receiver->setTimeout(null);
        $this->receiver->start();

        $healthUrl = 'http://127.0.0.1:' . $this->port . '/health';

        for ($attempt = 0; $attempt < 100; $attempt++) {
            if (! $this->receiver->isRunning()) {
                $this->fail('приёмник завершился при старте: ' . $this->receiver->getErrorOutput());
            }

            try {
                $response = Http::timeout(1)->get($healthUrl);
                if ($response->status() === 200 && $response->json('status') === 'ok') {
                    return;
                }
            } catch (\Throwable) {
                // Приёмник ещё поднимается.
            }

            usleep(100_000);
        }

        $this->fail('приёмник не ответил на /health за 10 секунд');
    }

    /**
     * Останавливает приёмник по SIGTERM и проверяет корректное завершение.
     */
    private function stopReceiver(): void
    {
        if ($this->receiver === null) {
            return;
        }

        $process = $this->receiver;
        $this->receiver = null;

        if ($process->isRunning()) {
            $process->signal(SIGTERM);
            $process->setTimeout(20);
            $process->wait();
        }

        if ($process->getExitCode() !== 0) {
            $this->fail(sprintf(
                'приёмник завершился с кодом %s вместо 0 (stderr: %s)',
                (string) $process->getExitCode(),
                $process->getErrorOutput(),
            ));
        }
    }

    /**
     * Настройки пакета для реального приёмника.
     */
    private function configureSender(string $mysqldumpBinary): void
    {
        config([
            'db-backup.enabled' => true,
            'db-backup.project' => 'it-project',
            'db-backup.temp_path' => $this->root . '/work',
            'db-backup.remote.url' => 'http://127.0.0.1:' . $this->port . '/api/upload',
            'db-backup.remote.token' => $this->token,
            'db-backup.remote.header' => 'X-Backup-Token',
            'db-backup.remote.timeout' => 30,
            'db-backup.remote.allow_insecure_http' => true,
            'db-backup.retries' => 2,
            'db-backup.retry_delay' => 0,
            'db-backup.mysqldump.binary' => $mysqldumpBinary,
            'db-backup.check_engines' => false,
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
                'host' => '127.0.0.1',
                'port' => 3306,
                'database' => 'integration_db',
                'username' => 'tester',
                'password' => 'secret',
                'charset' => 'utf8mb4',
            ],
        ]);
    }

    public function test_full_backup_chain_with_real_receiver(): void
    {
        $this->startReceiver();

        // Реалистичный дамп: несколько таблиц и русский текст.
        $sql = "-- MySQL dump\nCREATE TABLE `users` (`id` INT, `name` VARCHAR(255));\n";
        foreach (range(1, 500) as $i) {
            $sql .= sprintf("INSERT INTO `users` VALUES (%d, 'Пользователь %d');\n", $i, $i);
        }

        $mysqldump = FakeMysqldump::create($this->root . '/bin', $sql);
        $this->configureSender($mysqldump->path());

        $this->artisan('backup:run')->assertExitCode(0);

        // Приёмник сохранил файл в каталог проекта.
        $projectDirectory = $this->root . '/backups/it-project';
        $this->assertDirectoryExists($projectDirectory);

        $files = glob($projectDirectory . '/*.sql.gz') ?: [];
        $this->assertCount(1, $files, 'приёмник должен сохранить ровно один архив');

        $stored = $files[0];
        $this->assertMatchesRegularExpression(
            '/\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.sql\.gz$/',
            $stored,
            'имя файла генерирует приёмник по формату 2026-11-04_17-00-00.sql.gz',
        );

        // Содержимое архива совпадает с дампом.
        $decoded = gzdecode((string) file_get_contents($stored));
        $this->assertSame($sql, $decoded);
        $this->assertSame(0o640, fileperms($stored) & 0o777, 'права файла должны быть 0640');

        // Временные файлы отправителя удалены.
        $this->assertSame([], $this->filesIn($this->root . '/work'));

        // Ротация: свежий файл сохраняется, старый удаляется.
        $oldFile = $projectDirectory . '/2025-09-01_10-00-00.sql.gz';
        file_put_contents($oldFile, 'old');
        touch($oldFile, time() - 40 * 86400);

        $rotate = new Process([$this->receiverBinary(), 'rotate', '-config', $this->receiverConfig]);
        $rotate->run();

        $this->assertTrue($rotate->isSuccessful(), 'команда rotate должна завершиться успешно: ' . $rotate->getErrorOutput());
        $this->assertStringContainsString('удалено=1', $rotate->getOutput());
        $this->assertFileDoesNotExist($oldFile);
        $this->assertFileExists($stored);
    }

    public function test_receiver_rejects_wrong_token(): void
    {
        $this->startReceiver();

        $archive = $this->root . '/upload.sql.gz';
        file_put_contents($archive, gzencode('данные'));

        $response = Http::withHeaders(['X-Backup-Token' => 'wrong-token-value-123456'])
            ->attach('file', fopen($archive, 'rb'), 'upload.sql.gz')
            ->post('http://127.0.0.1:' . $this->port . '/api/upload', [
                'project' => 'it-project',
                'sha256' => hash_file('sha256', $archive),
            ]);

        $this->assertSame(401, $response->status());
        $this->assertSame('invalid_token', $response->json('error_code'));

        $files = glob($this->root . '/backups/it-project/*.sql.gz') ?: [];
        $this->assertSame([], $files, 'при неверном токене файл сохраняться не должен');
    }

    public function test_receiver_rejects_checksum_mismatch(): void
    {
        $this->startReceiver();

        $archive = $this->root . '/upload.sql.gz';
        file_put_contents($archive, gzencode('данные'));

        $response = Http::withHeaders(['X-Backup-Token' => $this->token])
            ->attach('file', fopen($archive, 'rb'), 'upload.sql.gz')
            ->post('http://127.0.0.1:' . $this->port . '/api/upload', [
                'project' => 'it-project',
                'sha256' => str_repeat('0', 64),
            ]);

        $this->assertSame(422, $response->status());
        $this->assertSame('checksum_mismatch', $response->json('error_code'));

        $files = glob($this->root . '/backups/it-project/*' ) ?: [];
        $this->assertSame([], $files, 'при неверной сумме файл сохраняться не должен');
    }
}
