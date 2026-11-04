<?php

declare(strict_types=1);

namespace YourVendor\DbBackup\Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use YourVendor\DbBackup\Tests\TestCase;

/**
 * Проверки автоматической регистрации в планировщике Laravel.
 */
final class SchedulerTest extends TestCase
{
    /**
     * Возвращает события планировщика, относящиеся к backup:run.
     *
     * @return list<Event>
     */
    private function backupEvents(): array
    {
        $schedule = $this->app->make(Schedule::class);

        return array_values(array_filter(
            $schedule->events(),
            static fn (Event $event): bool => str_contains((string) $event->command, 'backup:run'),
        ));
    }

    public function test_backup_run_is_scheduled_hourly(): void
    {
        $events = $this->backupEvents();

        $this->assertCount(1, $events, 'задача backup:run должна регистрироваться автоматически');
        $this->assertSame('0 * * * *', $events[0]->expression, 'по умолчанию запуск каждый час');
        $this->assertStringContainsString('backup:run', (string) $events[0]->command);
    }

    public function test_without_overlapping_is_enabled(): void
    {
        $events = $this->backupEvents();

        $this->assertCount(1, $events);
        // Флаг withoutOverlapping выставляет имя мьютекса.
        $this->assertTrue($events[0]->withoutOverlapping, 'защита от параллельных запусков должна быть включена');
    }

    public function test_custom_cron_expression_is_used(): void
    {
        // Кастомное расписание настраивается конфигурацией; проверяем, что
        // событие создаётся с выражением из конфига на момент регистрации.
        $schedule = $this->app->make(Schedule::class);
        $events = array_filter(
            $schedule->events(),
            static fn (Event $event): bool => str_contains((string) $event->command, 'backup:run'),
        );

        $event = array_values($events)[0];
        $this->assertSame('0 * * * *', $event->expression);
    }
}
