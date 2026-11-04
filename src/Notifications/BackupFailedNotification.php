<?php

declare(strict_types=1);

namespace YourVendor\DbBackup\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Письмо об ошибке резервного копирования.
 *
 * Содержит только безопасную информацию: имя проекта, дату, этап, причину и
 * число попыток. Пароль БД и токен в письмо не попадают.
 */
final class BackupFailedNotification extends Notification
{
    use Queueable;

    /**
     * @param array{
     *     project: string,
     *     date: string,
     *     stage: string,
     *     reason: string,
     *     attempts: string,
     *     temp_path: string|null,
     *     host: string|null
     * } $details
     */
    public function __construct(private readonly array $details)
    {
    }

    /**
     * Каналы доставки.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Текст письма.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $project = $this->details['project'] ?? 'неизвестный проект';

        $message = (new MailMessage())
            ->subject(mb_substr('Ошибка резервного копирования: ' . $project, 0, 120))
            ->greeting('Ошибка резервного копирования')
            ->line('**Проект:** ' . $project)
            ->line('**Дата:** ' . ($this->details['date'] ?? ''))
            ->line('**Этап:** ' . ($this->details['stage'] ?? ''))
            ->line('**Причина:** ' . ($this->details['reason'] ?? ''))
            ->line('**Попытки:** ' . ($this->details['attempts'] ?? '—'));

        if (! empty($this->details['host'])) {
            $message->line('**Сервер БД:** ' . $this->details['host']);
        }

        if (! empty($this->details['temp_path'])) {
            $message->line('**Временные файлы сохранены:** `' . $this->details['temp_path'] . '`');
        }

        $message->line('**Следующее действие:** повтор при следующем запуске по расписанию.');

        $from = config('db-backup.email.from');
        if (is_string($from) && trim($from) !== '') {
            $message->from(trim($from));
        }

        return $message->salutation('Система резервного копирования');
    }

    /**
     * Данные уведомления (используются в тестах и кастомных каналах).
     *
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }
}
