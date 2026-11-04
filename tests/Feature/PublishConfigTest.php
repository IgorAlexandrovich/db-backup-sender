<?php

declare(strict_types=1);

namespace YourVendor\DbBackup\Tests\Feature;

use Illuminate\Support\Facades\File;
use YourVendor\DbBackup\Tests\TestCase;

/**
 * Проверка шага установки из документации:
 *   php artisan vendor:publish --tag=db-backup-config
 *
 * Каталог конфигурации подменяется до загрузки провайдеров, чтобы публикация
 * не затрагивала конфигурацию Testbench.
 */
final class PublishConfigTest extends TestCase
{
    /** Каталог, в который «публикуется» конфигурация. */
    private string $configPath = '';

    /**
     * Настройка приложения до регистрации провайдеров.
     */
    protected function getEnvironmentSetUp($app): void
    {
        $this->configPath = sys_get_temp_dir() . '/db-backup-publish-' . bin2hex(random_bytes(6));

        if (! is_dir($this->configPath)) {
            mkdir($this->configPath, 0o750, true);
        }

        $app->useConfigPath($this->configPath);
    }

    protected function tearDown(): void
    {
        if ($this->configPath !== '' && is_dir($this->configPath)) {
            File::deleteDirectory($this->configPath);
        }

        parent::tearDown();
    }

    public function test_publish_command_creates_config_file(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'db-backup-config', '--force' => true])
            ->assertExitCode(0);

        $published = $this->configPath . '/db-backup.php';

        $this->assertFileExists($published);

        /** @var array<string, mixed> $config */
        $config = require $published;

        $this->assertIsArray($config);
        $this->assertArrayHasKey('enabled', $config);
        $this->assertArrayHasKey('project', $config);
        $this->assertArrayHasKey('remote', $config);
        $this->assertArrayHasKey('mysqldump', $config);
        $this->assertArrayHasKey('schedule', $config);
        $this->assertArrayHasKey('email', $config);
    }
}
