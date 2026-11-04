<?php

declare(strict_types=1);

namespace YourVendor\DbBackup\Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use YourVendor\DbBackup\BackupServiceProvider;
use YourVendor\DbBackup\Tests\TestCase;

/**
 * Проверки регистрации пакета: команды, конфигурация, публикация.
 */
final class ServiceProviderTest extends TestCase
{
    public function test_commands_are_registered(): void
    {
        $commands = array_keys($this->app->make(Kernel::class)->all());

        $this->assertContains('backup:run', $commands);
        $this->assertContains('backup:check', $commands);
    }

    public function test_configuration_is_merged_with_defaults(): void
    {
        $this->assertTrue((bool) config('db-backup.enabled'));
        $this->assertSame('X-Backup-Token', config('db-backup.remote.header'));
        $this->assertSame(3, (int) config('db-backup.retries'));
        $this->assertSame(5, (int) config('db-backup.retry_delay'));
        $this->assertSame(180, (int) config('db-backup.remote.timeout'));
        $this->assertSame('0 * * * *', config('db-backup.schedule.cron'));
        $this->assertTrue((bool) config('db-backup.check_engines'));
    }

    public function test_configuration_can_be_published(): void
    {
        $paths = BackupServiceProvider::pathsToPublish(BackupServiceProvider::class, BackupServiceProvider::CONFIG_TAG);

        $this->assertNotEmpty($paths, 'тег публикации db-backup-config должен быть зарегистрирован');

        $source = array_key_first($paths);
        $this->assertIsString($source);
        $this->assertFileExists($source);
        $this->assertStringEndsWith('config/db-backup.php', str_replace('\\', '/', $source));
        $this->assertSame(config_path('db-backup.php'), $paths[$source]);
    }

    public function test_services_are_resolved_from_container(): void
    {
        $this->app['config']->set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'example',
            'username' => 'user',
            'password' => 'secret',
        ]);
        $this->app['config']->set('database.default', 'mysql');

        $creator = $this->app->make(\YourVendor\DbBackup\Services\DumpCreator::class);

        $this->assertSame('example', $creator->database());
        $this->assertInstanceOf(\Psr\Log\LoggerInterface::class, $this->app->make(BackupServiceProvider::LOGGER_KEY));
    }
}
