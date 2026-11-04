<?php

declare(strict_types=1);

namespace YourVendor\DbBackup\Tests\Unit;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use YourVendor\DbBackup\Exceptions\ConfigurationException;
use YourVendor\DbBackup\Exceptions\UploadException;
use YourVendor\DbBackup\Services\Uploader;
use YourVendor\DbBackup\Tests\Support\WorksWithTempFiles;
use YourVendor\DbBackup\Tests\TestCase;

/**
 * Тесты отправки на приёмник: заголовки, повторы, разбор ошибок.
 */
final class UploaderTest extends TestCase
{
    use WorksWithTempFiles;

    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const CHECKSUM = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    protected function tearDown(): void
    {
        $this->cleanupTempDirectories();
        parent::tearDown();
    }

    /**
     * Создаёт файл архива для отправки.
     */
    private function archiveFile(): string
    {
        $directory = $this->tempDir();
        $path = $directory . '/dump.sql.gz';
        file_put_contents($path, "\x1f\x8bданные архива");

        return $path;
    }

    /**
     * Создаёт Uploader с тестовыми настройками.
     */
    private function uploader(array $overrides = []): Uploader
    {
        $defaults = [
            'url' => 'https://backup.example.test/api/upload',
            'token' => self::TOKEN,
            'header' => 'X-Backup-Token',
            'timeout' => 30,
            'retries' => 3,
            'retryDelay' => 0,
            'verifySsl' => true,
            'allowInsecureHttp' => false,
        ];

        $config = array_merge($defaults, $overrides);

        return new Uploader(
            url: (string) $config['url'],
            token: (string) $config['token'],
            header: (string) $config['header'],
            timeout: (int) $config['timeout'],
            retries: (int) $config['retries'],
            retryDelay: (int) $config['retryDelay'],
            verifySsl: (bool) $config['verifySsl'],
            allowInsecureHttp: (bool) $config['allowInsecureHttp'],
            logger: null,
        );
    }

    public function test_upload_sends_token_project_and_checksum(): void
    {
        Http::fake([
            'https://backup.example.test/api/upload' => Http::response([
                'status' => 'ok',
                'project' => 'project-one',
                'file' => 'project-one/2026-11-04_17-00-00.sql.gz',
                'size' => 16,
                'sha256' => self::CHECKSUM,
                'request_id' => 'abc',
            ], 201),
        ]);

        $result = $this->uploader()->upload($this->archiveFile(), 'project-one', self::CHECKSUM);

        $this->assertSame('project-one', $result->project);
        $this->assertSame('2026-11-04_17-00-00.sql.gz', $result->baseName());
        $this->assertSame(1, $result->attempts);

        Http::assertSent(static function (Request $request): bool {
            // Поля multipart лежат в теле запроса: data() для вложений не заполняется.
            $body = $request->body();

            return $request->url() === 'https://backup.example.test/api/upload'
                && $request->method() === 'POST'
                && $request->hasHeader('X-Backup-Token', self::TOKEN)
                && $request->isMultipart()
                && $request->hasFile('file')
                && str_contains($body, 'name="project"')
                && str_contains($body, 'project-one')
                && str_contains($body, 'name="sha256"')
                && str_contains($body, self::CHECKSUM);
        });
    }

    public function test_upload_retries_on_server_error_and_succeeds(): void
    {
        $calls = 0;

        Http::fake(static function () use (&$calls) {
            $calls++;

            if ($calls === 1) {
                return Http::response(['status' => 'error', 'error_code' => 'internal_error', 'message' => 'временно'], 500);
            }

            return Http::response([
                'status' => 'ok',
                'project' => 'project-one',
                'file' => 'project-one/2026-11-04_17-00-00.sql.gz',
                'size' => 16,
                'sha256' => self::CHECKSUM,
            ], 201);
        });

        $result = $this->uploader(['retries' => 3])->upload($this->archiveFile(), 'project-one', self::CHECKSUM);

        $this->assertSame(2, $result->attempts);
        Http::assertSentCount(2);
    }

    public function test_upload_does_not_retry_authorization_error(): void
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'error',
                'error_code' => 'invalid_token',
                'message' => 'неверный токен проекта',
            ], 401),
        ]);

        try {
            $this->uploader(['retries' => 5])->upload($this->archiveFile(), 'project-one', self::CHECKSUM);
            $this->fail('ожидалось исключение UploadException');
        } catch (UploadException $exception) {
            $this->assertFalse($exception->isRetryable());
            $this->assertSame(1, $exception->attempts());
            $this->assertStringContainsString('HTTP 401', $exception->getMessage());
            $this->assertStringContainsString('BACKUP_TOKEN', $exception->getMessage());
            $this->assertSame('upload', $exception->stage());
        }

        Http::assertSentCount(1);
    }

    public function test_upload_does_not_retry_checksum_mismatch(): void
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'error',
                'error_code' => 'checksum_mismatch',
                'message' => 'контрольная сумма не совпадает',
            ], 422),
        ]);

        try {
            $this->uploader(['retries' => 4])->upload($this->archiveFile(), 'project-one', self::CHECKSUM);
            $this->fail('ожидалось исключение');
        } catch (UploadException $exception) {
            $this->assertFalse($exception->isRetryable());
            $this->assertStringContainsString('повреждён', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_upload_retries_connection_errors_and_reports_attempts(): void
    {
        $calls = 0;

        Http::fake(static function () use (&$calls): void {
            $calls++;

            throw new ConnectionException('cURL error 7: Failed to connect to backup.example.test');
        });

        try {
            $this->uploader(['retries' => 3])->upload($this->archiveFile(), 'project-one', self::CHECKSUM);
            $this->fail('ожидалось исключение');
        } catch (UploadException $exception) {
            $this->assertTrue($exception->isRetryable());
            $this->assertSame(3, $exception->attempts());
            $this->assertSame(3, $exception->maxAttempts());
            $this->assertSame('3 из 3', $exception->attemptsLabel());
            $this->assertStringContainsString('сетевая ошибка', $exception->getMessage());
        }

        // При исключении соединения Laravel не записывает запрос, поэтому
        // считаем вызовы самостоятельно.
        $this->assertSame(3, $calls);
    }

    public function test_upload_hides_token_in_error_message(): void
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'error',
                'error_code' => 'invalid_token',
                'message' => 'токен ' . self::TOKEN . ' отклонён',
            ], 401),
        ]);

        try {
            $this->uploader()->upload($this->archiveFile(), 'project-one', self::CHECKSUM);
            $this->fail('ожидалось исключение');
        } catch (UploadException $exception) {
            $this->assertStringNotContainsString(self::TOKEN, $exception->getMessage());
            $this->assertStringContainsString('***', $exception->getMessage());
        }
    }

    public function test_upload_rejects_html_error_page_from_nginx(): void
    {
        Http::fake([
            '*' => Http::response('<html><body><h1>413 Request Entity Too Large</h1></body></html>', 413),
        ]);

        try {
            $this->uploader()->upload($this->archiveFile(), 'project-one', self::CHECKSUM);
            $this->fail('ожидалось исключение');
        } catch (UploadException $exception) {
            $this->assertStringNotContainsString('<html>', $exception->getMessage());
            $this->assertStringContainsString('413', $exception->getMessage());
            $this->assertStringContainsString('лимита', $exception->getMessage());
        }
    }

    public function test_upload_rejects_missing_file(): void
    {
        $this->expectException(\YourVendor\DbBackup\Exceptions\BackupException::class);

        $this->uploader()->upload('/nonexistent/dump.sql.gz', 'project-one', self::CHECKSUM);
    }

    public function test_validate_allows_https(): void
    {
        $this->uploader()->validate();
        $this->addToAssertionCount(1);
    }

    public function test_validate_rejects_http_without_flag(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/https/');

        $this->uploader(['url' => 'http://backup.example.test/api/upload'])->validate();
    }

    public function test_validate_allows_http_with_flag(): void
    {
        $this->uploader([
            'url' => 'http://127.0.0.1:8095/api/upload',
            'allowInsecureHttp' => true,
        ])->validate();

        $this->addToAssertionCount(1);
    }

    public function test_validate_requires_url_and_token(): void
    {
        foreach ([['url' => ''], ['token' => ''], ['url' => 'not-a-url']] as $overrides) {
            try {
                $this->uploader($overrides)->validate();
                $this->fail('ожидалось исключение для ' . json_encode($overrides));
            } catch (ConfigurationException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function test_health_url_is_derived_from_upload_url(): void
    {
        $this->assertSame(
            'https://backup.example.test/health',
            $this->uploader()->healthUrl(),
        );

        $this->assertSame(
            'https://backup.example.test:8443/health',
            $this->uploader(['url' => 'https://backup.example.test:8443/api/upload'])->healthUrl(),
        );
    }

    public function test_check_health_reports_ok(): void
    {
        Http::fake([
            'https://backup.example.test/health' => Http::response(['status' => 'ok', 'version' => 'test'], 200),
        ]);

        $result = $this->uploader()->checkHealth();

        $this->assertTrue($result['ok']);
        $this->assertSame(200, $result['status']);
    }

    public function test_check_health_reports_failure(): void
    {
        Http::fake([
            'https://backup.example.test/health' => Http::response(['status' => 'degraded'], 503),
        ]);

        $result = $this->uploader()->checkHealth();

        $this->assertFalse($result['ok']);
        $this->assertSame(503, $result['status']);
        $this->assertNotNull($result['error']);
    }

    public function test_custom_header_name_is_used(): void
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'ok',
                'project' => 'project-one',
                'file' => 'project-one/x.sql.gz',
                'size' => 1,
                'sha256' => self::CHECKSUM,
            ], 201),
        ]);

        $this->uploader(['header' => 'X-Custom-Backup'])->upload($this->archiveFile(), 'project-one', self::CHECKSUM);

        Http::assertSent(static fn (Request $request): bool => $request->hasHeader('X-Custom-Backup', self::TOKEN));
    }
}
