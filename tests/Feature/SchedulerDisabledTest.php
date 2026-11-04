<?php

declare(strict_types=1);

namespace YourVendor\DbBackup\Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use YourVendor\DbBackup\Tests\TestCase;

/**
 * Если расписание отключено конфигурацией, задача не должна регистрироваться.
 *
 * Настройка задаётся до загрузки провайдеров, поэтому вынесена в отдельный
 * класс тестов.
 */
final class SchedulerDisabledTest extends TestCase
{
    /**
     * Настройки тестового приложения до загрузки провайдеров.
     */
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('db-backup.schedule.enabled', false);
    }

    public function test_backup_run_is_not_scheduled_when_disabled(): void
    {
        $this->assertFalse((bool) config('db-backup.schedule.enabled'));

        $events = array_filter(
            $this->app->make(Schedule::class)->events(),
            static fn (Event $event): bool => str_contains((string) $event->command, 'backup:run'),
        );

        $this->assertSame([], array_values($events), 'при schedule.enabled=false задача не регистрируется');
    }
}
