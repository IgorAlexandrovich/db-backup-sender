<?php

declare(strict_types=1);

namespace YourVendor\DbBackup\Tests\Unit;

use Illuminate\Notifications\AnonymousNotifiable;
use YourVendor\DbBackup\Notifications\BackupFailedNotification;
use YourVendor\DbBackup\Tests\TestCase;

/**
 * Тесты письма об ошибке резервного копирования.
 */
final class NotificationTest extends TestCase
{
    /**
     * Данные уведомления, как их формирует команда.
     *
     * @return array<string, string|null>
     */
    private function details(): array
    {
        return [
            'project' => 'my-project',
            'date' => '04.11.2026 17:00',
            'stage' => 'Отправка на удалённый сервер',
            'reason' => 'Connection timeout',
            'attempts' => '3 из 3',
            'temp_path' => '/var/www/project/storage/app/backups/my-project/20261104-170000',
            'host' => '127.0.0.1:3306',
        ];
    }

    public function test_via_returns_mail_channel(): void
    {
        $notification = new BackupFailedNotification($this->details());

        $this->assertSame(['mail'], $notification->via(new AnonymousNotifiable()));
    }

    public function test_mail_contains_required_information(): void
    {
        $notification = new BackupFailedNotification($this->details());
        $mail = $notification->toMail(new AnonymousNotifiable());

        $this->assertStringContainsString('my-project', (string) $mail->subject);

        $body = implode("\n", $mail->introLines);

        $this->assertStringContainsString('my-project', $body);
        $this->assertStringContainsString('04.11.2026 17:00', $body);
        $this->assertStringContainsString('Отправка на удалённый сервер', $body);
        $this->assertStringContainsString('Connection timeout', $body);
        $this->assertStringContainsString('3 из 3', $body);
        $this->assertStringContainsString('повтор при следующем запуске', mb_strtolower($body));
    }

    public function test_mail_does_not_contain_secrets(): void
    {
        $token = 'super-secret-token-value';
        $password = 'database-password';

        $mail = (new BackupFailedNotification($this->details()))->toMail(new AnonymousNotifiable());
        $body = implode("\n", $mail->introLines);

        $this->assertStringNotContainsString($token, $body);
        $this->assertStringNotContainsString($password, $body);
    }

    public function test_from_address_is_taken_from_config(): void
    {
        config(['db-backup.email.from' => 'backup@example.test']);

        $mail = (new BackupFailedNotification($this->details()))->toMail(new AnonymousNotifiable());

        $this->assertContains('backup@example.test', $mail->from);
    }

    public function test_details_are_exposed_for_custom_channels(): void
    {
        $notification = new BackupFailedNotification($this->details());

        $this->assertSame('my-project', $notification->details()['project']);
    }

    public function test_subject_is_limited(): void
    {
        $details = $this->details();
        $details['project'] = str_repeat('очень-длинное-имя-', 20);

        $mail = (new BackupFailedNotification($details))->toMail(new AnonymousNotifiable());

        $this->assertLessThanOrEqual(120, mb_strlen((string) $mail->subject));
    }
}
