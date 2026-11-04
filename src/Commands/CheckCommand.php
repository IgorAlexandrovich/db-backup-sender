<?php

declare(strict_types=1);

namespace YourVendor\DbBackup\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use YourVendor\DbBackup\Exceptions\BackupException;
use YourVendor\DbBackup\Services\DumpCreator;
use YourVendor\DbBackup\Services\Uploader;

/**
 * Диагностика: php artisan backup:check
 *
 * Проверяет всё, что нужно для успешного резервного копирования, и подсказывает,
 * что именно исправить. Ничего не изменяет и не создаёт дамп.
 *
 * Полезно запускать после установки пакета и при разборе проблем.
 */
final class CheckCommand extends Command
{
    /** @var string */
    protected $signature = 'backup:check
        {--skip-remote : Не проверять доступность приёмника}';

    /** @var string */
    protected $description = 'Проверить готовность проекта к резервному копированию (диагностика)';

    /** Счётчик критичных проблем. */
    private int $failures = 0;

    /** Счётчик предупреждений. */
    private int $warnings = 0;

    public function handle(DumpCreator $dumpCreator, Uploader $uploader): int
    {
        $this->info('Диагностика резервного копирования (db-backup-sender)');
        $this->line(str_repeat('─', 72));

        $this->checkEnabled();
        $this->checkProjectName();
        $this->checkRemoteConfiguration($uploader);

        if (! $this->option('skip-remote')) {
            $this->checkRemoteHealth($uploader);
        }

        $this->checkMysqldump($dumpCreator);
        $this->checkTempPath();
        $this->checkDatabase();
        $this->checkTableEngines();
        $this->checkEmail();

        $this->line(str_repeat('─', 72));

        if ($this->failures > 0) {
            $this->error(sprintf('Найдено критичных проблем: %d. Резервное копирование не будет работать.', $this->failures));

            return self::FAILURE;
        }

        if ($this->warnings > 0) {
            $this->warn(sprintf('Предупреждений: %d. Резервное копирование работать будет, но обратите внимание.', $this->warnings));
        } else {
            $this->info('Все проверки пройдены.');
        }

        return self::SUCCESS;
    }

    /**
     * Включено ли резервное копирование.
     */
    private function checkEnabled(): void
    {
        $enabled = (bool) config('db-backup.enabled', true);

        $this->report(
            'Резервное копирование включено',
            $enabled,
            $enabled ? 'да (BACKUP_ENABLED=true)' : 'нет — команда backup:run ничего не делает',
            warningOnly: true,
        );
    }

    /**
     * Проверка имени проекта.
     */
    private function checkProjectName(): void
    {
        $project = trim((string) config('db-backup.project', ''));

        if ($project === '') {
            $this->report('Имя проекта', false, 'не задано BACKUP_PROJECT_NAME');

            return;
        }

        $valid = preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $project) === 1;

        $this->report(
            'Имя проекта',
            $valid,
            $valid
                ? $project
                : sprintf('"%s" недопустимо: разрешены латиница, цифры, точка, дефис, подчёркивание', $project),
        );
    }

    /**
     * Проверка адреса и токена.
     */
    private function checkRemoteConfiguration(Uploader $uploader): void
    {
        $url = (string) config('db-backup.remote.url', '');
        $token = (string) config('db-backup.remote.token', '');

        try {
            $uploader->validate();
            $this->report('Адрес приёмника', true, $url);
        } catch (BackupException $exception) {
            $this->report('Адрес приёмника', false, $exception->getMessage());
        }

        $tokenLength = strlen(trim($token));

        if ($tokenLength === 0) {
            $this->report('Токен проекта', false, 'не задан BACKUP_TOKEN');
        } elseif ($tokenLength < 16) {
            $this->report('Токен проекта', false, 'слишком короткий токен — используйте не короче 32 символов');
        } elseif ($tokenLength < 32) {
            $this->report('Токен проекта', null, 'токен короче 32 символов — рекомендуется длиннее', warningOnly: true);
        } else {
            $this->report('Токен проекта', true, sprintf('задан, длина %d символов', $tokenLength));
        }

        $timeout = (int) config('db-backup.remote.timeout', 180);
        $this->report('Таймаут отправки', $timeout > 0, sprintf('%d с', $timeout));

        $retries = (int) config('db-backup.retries', 3);
        $this->report('Повторные попытки', $retries >= 1, sprintf('%d попыток, пауза %d с', $retries, (int) config('db-backup.retry_delay', 5)));
    }

    /**
     * Проверка доступности приёмника (GET /health).
     */
    private function checkRemoteHealth(Uploader $uploader): void
    {
        $healthUrl = $uploader->healthUrl();

        if ($healthUrl === '') {
            $this->report('Доступность приёмника', null, 'адрес не задан — проверка пропущена', warningOnly: true);

            return;
        }

        $result = $uploader->checkHealth();

        if ($result['ok']) {
            $this->report('Доступность приёмника', true, sprintf('%s — отвечает', $healthUrl));

            return;
        }

        $this->report(
            'Доступность приёмника',
            false,
            sprintf('%s — %s', $healthUrl, (string) ($result['error'] ?? 'нет ответа')),
        );
    }

    /**
     * Проверка mysqldump.
     */
    private function checkMysqldump(DumpCreator $dumpCreator): void
    {
        try {
            $binary = $dumpCreator->resolveBinary();
        } catch (BackupException $exception) {
            $this->report('Утилита mysqldump', false, $exception->getMessage());

            return;
        }

        $version = $dumpCreator->version();

        $this->report(
            'Утилита mysqldump',
            true,
            $version !== null ? $binary . ' — ' . $version : $binary,
        );
    }

    /**
     * Проверка каталога для временных файлов.
     */
    private function checkTempPath(): void
    {
        $path = (string) config('db-backup.temp_path', 'storage/app/backups');
        $absolute = str_starts_with($path, '/') ? $path : base_path($path);

        if (! is_dir($absolute) && ! @mkdir($absolute, 0750, true) && ! is_dir($absolute)) {
            $this->report('Каталог временных файлов', false, sprintf('не удалось создать "%s"', $absolute));

            return;
        }

        $probe = tempnam($absolute, 'db-backup-check-');

        if ($probe === false) {
            $this->report('Каталог временных файлов', false, sprintf('нет прав на запись в "%s"', $absolute));

            return;
        }

        @unlink($probe);
        $this->report('Каталог временных файлов', true, $absolute);
    }

    /**
     * Проверка подключения к базе данных.
     */
    private function checkDatabase(): void
    {
        $connectionName = config('db-backup.connection');

        try {
            $connection = DB::connection($connectionName);
            $driver = $connection->getDriverName();
        } catch (\Throwable $exception) {
            $this->report('Подключение к БД', false, 'не удалось получить подключение: ' . $exception->getMessage());

            return;
        }

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->report(
                'Подключение к БД',
                null,
                sprintf('драйвер "%s": пакет рассчитан на MySQL/MariaDB, проверка пропущена', $driver),
                warningOnly: true,
            );

            return;
        }

        try {
            $rows = $connection->select('SELECT VERSION() AS version');
            $version = (string) ($rows[0]->version ?? 'неизвестно');
            $database = (string) $connection->getDatabaseName();

            $this->report('Подключение к БД', true, sprintf('%s, база "%s", версия %s', $driver, $database, $version));
        } catch (\Throwable $exception) {
            $this->report('Подключение к БД', false, 'ошибка подключения: ' . $exception->getMessage());
        }
    }

    /**
     * Предупреждение о таблицах не на InnoDB.
     */
    private function checkTableEngines(): void
    {
        if (! (bool) config('db-backup.check_engines', true)) {
            return;
        }

        try {
            $connection = DB::connection(config('db-backup.connection'));

            if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
                return;
            }

            $rows = $connection->select(
                'SELECT TABLE_NAME AS name, ENGINE AS engine
                   FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND ENGINE IS NOT NULL
                    AND ENGINE <> ?
                     LIMIT 5',
                ['InnoDB'],
            );
        } catch (\Throwable) {
            // Проверка необязательная: молча пропускаем.
            return;
        }

        if ($rows === []) {
            $this->report('Движки таблиц', true, 'все таблицы на InnoDB — снимок согласован');

            return;
        }

        $tables = [];
        foreach ($rows as $row) {
            $tables[] = sprintf('%s (%s)', (string) ($row->name ?? ''), (string) ($row->engine ?? ''));
        }

        $this->report(
            'Движки таблиц',
            null,
            'не InnoDB: ' . implode(', ', $tables) . ' — --single-transaction не гарантирует согласованность',
            warningOnly: true,
        );
    }

    /**
     * Проверка настроек почты.
     */
    private function checkEmail(): void
    {
        $enabled = (bool) config('db-backup.email.enabled', true);
        $to = trim((string) config('db-backup.email.to', ''));

        if (! $enabled) {
            $this->report('Уведомления на email', null, 'отключены (BACKUP_EMAIL_ENABLED=false)', warningOnly: true);

            return;
        }

        if ($to === '') {
            $this->report('Уведомления на email', null, 'не задан BACKUP_EMAIL — письма об ошибках отправляться не будут', warningOnly: true);

            return;
        }

        $this->report('Уведомления на email', true, $to);
    }

    /**
     * Печатает результат одной проверки.
     *
     * @param bool|null $ok          true — успех, false — ошибка, null — предупреждение.
     * @param bool      $warningOnly Считать неуспех предупреждением, а не критичной ошибкой.
     */
    private function report(string $label, ?bool $ok, string $details, bool $warningOnly = false): void
    {
        if ($ok === true) {
            $mark = '<info> OK </info>';
        } elseif ($ok === false && ! $warningOnly) {
            $mark = '<error>FAIL</error>';
            $this->failures++;
        } else {
            $mark = '<comment>WARN</comment>';
            $this->warnings++;
        }

        $this->line(sprintf(' [%s] %-32s %s', $mark, $label, $details));
    }
}
