<?php

declare(strict_types=1);

namespace YourVendor\DbBackup\Support;

/**
 * Этапы резервного копирования.
 *
 * Этап используется в логах, уведомлениях и тексте ошибки: администратор
 * должен сразу видеть, на каком шаге всё сломалось.
 */
final class Stages
{
    /** Проверка конфигурации (URL, токен, имя проекта). */
    public const CONFIG = 'config';

    /** Проверка наличия mysqldump на сервере. */
    public const MYSQLDUMP = 'mysqldump';

    /** Создание дампа базы данных. */
    public const DUMP = 'dump';

    /** Сжатие дампа в gzip. */
    public const COMPRESS = 'compress';

    /** Вычисление SHA-256. */
    public const CHECKSUM = 'checksum';

    /** Отправка на удалённый приёмник. */
    public const UPLOAD = 'upload';

    /** Удаление временных файлов. */
    public const CLEANUP = 'cleanup';

    /**
     * Человекочитаемое название этапа для писем и вывода команды.
     */
    public static function label(string $stage): string
    {
        return match ($stage) {
            self::CONFIG => 'Проверка конфигурации',
            self::MYSQLDUMP => 'Поиск утилиты mysqldump',
            self::DUMP => 'Создание дампа базы данных',
            self::COMPRESS => 'Сжатие дампа (gzip)',
            self::CHECKSUM => 'Вычисление контрольной суммы SHA-256',
            self::UPLOAD => 'Отправка на удалённый сервер',
            self::CLEANUP => 'Удаление временных файлов',
            default => $stage,
        };
    }
}
