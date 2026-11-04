<?php

declare(strict_types=1);

namespace YourVendor\DbBackup\Tests\Feature;

use YourVendor\DbBackup\Tests\Support\FakeMysqldump;
use YourVendor\DbBackup\Tests\Support\WorksWithTempFiles;
use YourVendor\DbBackup\Tests\TestCase;

/**
 * Тесты команды диагностики backup:check.
 */
final class CheckCommandTest extends TestCase
{
    use WorksWithTempFiles;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = $this->tempDir('db-backup-check');
        $fake = FakeMysqldump::create($this->root . '/bin');

        config([
            'db-backup.enabled' => true,
            'db-backup.project' => 'project-one',
            'db-backup.temp_path' => $this->root . '/work',
            'db-backup.remote.url' => 'https://backup.example.test/api/upload',
            'db-backup.remote.token' => str_repeat('t', 64),
            'db-backup.mysqldump.binary' => $fake->path(),
            'db-backup.check_engines' => true,
            'db-backup.email.enabled' => true,
            'db-backup.email.to' => 'admin@example.test',
            'db-backup.logging' => [
                'channel' => 'db-backup',
                'driver' => 'single',
                'path' => $this->root . '/logs/db-backup.log',
                'level' => 'debug',
                'days' => 1,
            ],
            // SQLite в памяти: тесты не должны зависеть от наличия MySQL.
            'database.default' => 'testing',
            'database.connections.testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $this->cleanupTempDirectories();
        parent::tearDown();
    }

    public function test_all_checks_pass_with_valid_configuration(): void
    {
        $this->artisan('backup:check', ['--skip-remote' => true])
            ->assertExitCode(0);
    }

    public function test_missing_token_is_reported(): void
    {
        config(['db-backup.remote.token' => '']);

        $this->artisan('backup:check', ['--skip-remote' => true])
            ->expectsOutputToContain('BACKUP_TOKEN')
            ->assertExitCode(1);
    }

    public function test_invalid_project_name_is_reported(): void
    {
        config(['db-backup.project' => '../evil']);

        $this->artisan('backup:check', ['--skip-remote' => true])
            ->expectsOutputToContain('недопустимо')
            ->assertExitCode(1);
    }

    public function test_missing_mysqldump_is_reported(): void
    {
        config(['db-backup.mysqldump.binary' => '/nonexistent/mysqldump']);

        $this->artisan('backup:check', ['--skip-remote' => true])
            ->expectsOutputToContain('mysqldump')
            ->assertExitCode(1);
    }

    public function test_http_url_is_reported_as_error(): void
    {
        config(['db-backup.remote.url' => 'http://backup.example.test/api/upload']);

        $this->artisan('backup:check', ['--skip-remote' => true])
            ->expectsOutputToContain('https')
            ->assertExitCode(1);
    }

    public function test_disabled_backup_is_warning_not_error(): void
    {
        config(['db-backup.enabled' => false]);

        // Отключённое резервное копирование — предупреждение, а не отказ.
        $this->artisan('backup:check', ['--skip-remote' => true])
            ->assertExitCode(0);
    }

    public function test_missing_email_is_warning_not_error(): void
    {
        config(['db-backup.email.to' => '']);

        $this->artisan('backup:check', ['--skip-remote' => true])
            ->assertExitCode(0);
    }
}
