<?php

declare(strict_types=1);

namespace YourVendor\DbBackup\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use YourVendor\DbBackup\BackupServiceProvider;
use YourVendor\DbBackup\Exceptions\BackupException;
use YourVendor\DbBackup\Exceptions\ConfigurationException;
use YourVendor\DbBackup\Exceptions\UploadException;
use YourVendor\DbBackup\Notifications\BackupFailedNotification;
use YourVendor\DbBackup\Services\Checksum;
use YourVendor\DbBackup\Services\Compressor;
use YourVendor\DbBackup\Services\DumpCreator;
use YourVendor\DbBackup\Services\UploadResult;
use YourVendor\DbBackup\Services\Uploader;
use YourVendor\DbBackup\Support\Stages;

/**
 * Команда резервного копирования: php artisan backup:run
 *
 * Последовательность:
 *   1. проверка конфигурации (имя проекта, URL, токен);
 *   2. проверка наличия mysqldump;
 *   3. дамп MySQL (--single-transaction);
 *   4. gzip-сжатие;
 *   5. SHA-256;
 *   6. отправка на приёмник (с повторами);
 *   7. удаление временных файлов.
 *
 * При ошибке:
 *   - сведения пишутся в отдельный лог и в вывод команды;
 *   - отправляется письмо (если включено);
 *   - временные файлы СОХРАНЯЮТСЯ: дамп нельзя удалять до подтверждённой
 *     отправки, иначе разбираться в причине будет нечем.
 */
final class RunBackup extends Command
{
    /** @var string */
    protected $signature = 'backup:run
        {--force : Выполнить, даже если резервное копирование отключено (BACKUP_ENABLED=false)}
        {--keep-temp : Не удалять временные файлы после успешной отправки}
        {--no-notify : Не отправлять письмо при ошибке}';

    /** @var string */
    protected $description = 'Создать дамп MySQL, сжать его в gzip и отправить на удалённый приёмник';

    /** Шаблон допустимого имени проекта (совпадает с приёмником). */
    private const PROJECT_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';

    private LoggerInterface $log;

    private string $stage = Stages::CONFIG;

    private string $project = '';

    /** Каталог текущего запуска с временными файлами. */
    private ?string $workspace = null;

    private ?string $databaseHost = null;

    private int $attempts = 0;

    private int $maxAttempts = 1;

    private float $startedAt = 0.0;

    /**
     * Выполнение команды.
     */
    public function handle(
        DumpCreator $dumpCreator,
        Compressor $compressor,
        Checksum $checksum,
        Uploader $uploader,
    ): int {
        $this->log = $this->laravel->make(BackupServiceProvider::LOGGER_KEY);
        $this->project = trim((string) config('db-backup.project'));
        $this->maxAttempts = max(1, (int) config('db-backup.retries', 3));
        $this->startedAt = microtime(true);

        // Полный «выключатель»: команда завершается успешно и ничего не делает.
        if (! (bool) config('db-backup.enabled') && ! $this->option('force')) {
            $this->log->info('Резервное копирование отключено конфигурацией', ['project' => $this->project]);
            $this->warn('Резервное копирование отключено (BACKUP_ENABLED=false). Ничего не делаю.');

            return self::SUCCESS;
        }

        $this->log->info('Старт резервного копирования', [
            'project' => $this->project,
            'pid' => getmypid(),
            'php' => PHP_VERSION,
        ]);

        $this->line(sprintf('<info>Проект:</info> %s', $this->project !== '' ? $this->project : '—'));

        try {
            // --- 1. Конфигурация -------------------------------------------
            $this->stage = Stages::CONFIG;
            $this->validateConfiguration($uploader);

            // --- 2. mysqldump ----------------------------------------------
            $this->stage = Stages::MYSQLDUMP;
            $binary = $dumpCreator->resolveBinary();
            $this->databaseHost = $dumpCreator->host();
            $this->line(sprintf('<info>mysqldump:</info> %s', $binary));

            if (($version = $dumpCreator->version()) !== null) {
                $this->line(sprintf('<info>Версия клиента:</info> %s', $version));
            }

            // --- 3. Дамп ---------------------------------------------------
            $this->stage = Stages::DUMP;
            $this->workspace = $this->createWorkspace();
            $sqlPath = $this->workspace . '/dump.sql';
            $archivePath = $this->workspace . '/dump.sql.gz';

            $dumpCreator->create($sqlPath);

            clearstatcache(true, $sqlPath);
            $dumpSize = (int) filesize($sqlPath);
            $this->line(sprintf('<info>Дамп создан:</info> %s', $this->formatBytes($dumpSize)));

            $this->warnAboutNonInnoDbTables();

            // --- 4. Сжатие -------------------------------------------------
            $this->stage = Stages::COMPRESS;
            $compressor->compress($sqlPath, $archivePath);

            clearstatcache(true, $archivePath);
            $archiveSize = (int) filesize($archivePath);
            $this->line(sprintf(
                '<info>Архив gzip:</info> %s (сжатие %s%% от исходного)',
                $this->formatBytes($archiveSize),
                $dumpSize > 0 ? (string) (int) round($archiveSize / $dumpSize * 100) : '0',
            ));

            // --- 5. Контрольная сумма --------------------------------------
            $this->stage = Stages::CHECKSUM;
            $sha256 = $checksum->sha256($archivePath);
            $this->line(sprintf('<info>SHA-256:</info> %s', $sha256));

            // --- 6. Отправка -----------------------------------------------
            $this->stage = Stages::UPLOAD;
            $result = $uploader->upload($archivePath, $this->project, $sha256);
            $this->attempts = $result->attempts;

            // --- 7. Очистка ------------------------------------------------
            $this->stage = Stages::CLEANUP;
            $this->cleanupWorkspace($sqlPath, $archivePath);

            $this->reportSuccess($result, $archiveSize, $sha256);

            return self::SUCCESS;
        } catch (BackupException $exception) {
            return $this->handleFailure($exception);
        } catch (\Throwable $exception) {
            // Непредвиденная ошибка: оборачиваем, чтобы сохранить этап и контекст.
            return $this->handleFailure(new BackupException(
                $exception->getMessage(),
                $this->stage,
                ['exception' => $exception::class],
                $exception,
            ));
        }
    }

    /**
     * Проверяет конфигурацию до создания дампа.
     *
     * @throws ConfigurationException
     */
    private function validateConfiguration(Uploader $uploader): void
    {
        if ($this->project === '') {
            throw new ConfigurationException('не задано имя проекта BACKUP_PROJECT_NAME');
        }

        if (preg_match(self::PROJECT_PATTERN, $this->project) !== 1) {
            throw new ConfigurationException(sprintf(
                'недопустимое имя проекта "%s": разрешены латинские буквы, цифры, точка, дефис и подчёркивание '
                . '(до 64 символов, первым символом — буква или цифра)',
                $this->project,
            ));
        }

        // Проверяем URL и токен: бессмысленно делать дамп, если отправка
        // заведомо не настроена.
        $uploader->validate();
    }

    /**
     * Создаёт каталог запуска для временных файлов.
     *
     * @throws ConfigurationException
     */
    private function createWorkspace(): string
    {
        $base = (string) config('db-backup.temp_path', 'storage/app/backups');
        $base = $this->absolutePath($base);

        $workspace = rtrim($base, '/\\')
            . DIRECTORY_SEPARATOR . $this->project
            . DIRECTORY_SEPARATOR . date('Ymd-His') . '-' . Str::lower(Str::random(8));

        if (! is_dir($workspace) && ! @mkdir($workspace, 0750, true) && ! is_dir($workspace)) {
            throw new ConfigurationException(sprintf(
                'не удалось создать каталог для временных файлов "%s" — проверьте права на storage/app',
                $workspace,
            ));
        }

        $this->log->debug('Каталог временных файлов создан', ['path' => $workspace]);

        return $workspace;
    }

    /**
     * Приводит путь к абсолютному: относительные пути считаются от base_path.
     */
    private function absolutePath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return base_path('storage/app/backups');
        }

        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }

    /**
     * Удаляет временные файлы после успешной отправки.
     */
    private function cleanupWorkspace(string $sqlPath, string $archivePath): void
    {
        if ($this->option('keep-temp')) {
            $this->warn(sprintf('Временные файлы оставлены по флагу --keep-temp: %s', (string) $this->workspace));
            $this->log->info('Временные файлы сохранены по флагу --keep-temp', ['path' => $this->workspace]);

            return;
        }

        foreach ([$sqlPath, $archivePath] as $file) {
            if (is_file($file) && ! @unlink($file)) {
                $this->log->warning('Не удалось удалить временный файл', ['file' => $file]);
            }
        }

        if ($this->workspace !== null && is_dir($this->workspace)) {
            // Каталог должен быть пустым; непустой (из-за прав) не удаляем молча.
            @rmdir($this->workspace);
        }

        $this->log->debug('Временные файлы удалены', ['path' => $this->workspace]);
        $this->workspace = null;
    }

    /**
     * Выводит и логирует информацию об успешной отправке.
     */
    private function reportSuccess(UploadResult $result, int $archiveSize, string $sha256): void
    {
        $duration = round(microtime(true) - $this->startedAt, 2);

        $this->newLine();
        $this->info('Резервная копия отправлена успешно');
        $this->line(sprintf('   Файл на приёмнике: %s', $result->file !== '' ? $result->file : '—'));
        $this->line(sprintf('   Размер архива:     %s', $this->formatBytes($archiveSize)));
        $this->line(sprintf('   SHA-256:           %s', $sha256));
        $this->line(sprintf('   Попыток отправки:  %d', $result->attempts));
        $this->line(sprintf('   Время выполнения:  %s с', (string) $duration));

        $this->log->info('Резервное копирование завершено успешно', [
            'project' => $this->project,
            'file' => $result->file,
            'size' => $archiveSize,
            'sha256' => $sha256,
            'attempts' => $result->attempts,
            'duration_seconds' => $duration,
        ]);
    }

    /**
     * Обработка ошибки: лог, вывод, уведомление.
     */
    private function handleFailure(BackupException $exception): int
    {
        $attemptsLabel = $exception instanceof UploadException
            ? $exception->attemptsLabel()
            : '—';

        if ($exception instanceof UploadException) {
            $this->attempts = $exception->attempts();
        }

        $this->log->error('Ошибка резервного копирования', [
            'project' => $this->project,
            'stage' => $exception->stage(),
            'stage_label' => $exception->stageLabel(),
            'reason' => $exception->getMessage(),
            'attempts' => $attemptsLabel,
            'temp_path' => $this->workspace,
            'database_host' => $this->databaseHost,
            'exception' => $exception::class,
            'context' => $exception->context(),
        ]);

        $this->newLine();
        $this->error('Ошибка резервного копирования');
        $this->line(sprintf('   Проект:  %s', $this->project !== '' ? $this->project : '—'));
        $this->line(sprintf('   Дата:    %s', now()->format('d.m.Y H:i')));
        $this->line(sprintf('   Этап:    %s', $exception->stageLabel()));
        $this->line(sprintf('   Причина: %s', $exception->getMessage()));
        $this->line(sprintf('   Попытки: %s', $attemptsLabel));
        $this->line('   Следующее действие: повтор при следующем запуске по расписанию.');

        if ($this->workspace !== null && is_dir($this->workspace)) {
            $this->warn(sprintf('Временные файлы сохранены для диагностики: %s', $this->workspace));
        }

        $this->notifyFailure($exception, $attemptsLabel);

        return self::FAILURE;
    }

    /**
     * Отправляет письмо об ошибке. Сбой отправки письма не меняет код возврата:
     * главное — что резервная копия не создана, и это уже зафиксировано.
     */
    private function notifyFailure(BackupException $exception, string $attemptsLabel): void
    {
        if ($this->option('no-notify')) {
            $this->warn('Письмо не отправлено: указан флаг --no-notify.');

            return;
        }

        if (! (bool) config('db-backup.email.enabled', true)) {
            return;
        }

        $to = trim((string) config('db-backup.email.to', ''));

        if ($to === '') {
            $this->log->warning('Уведомление не отправлено: не задан BACKUP_EMAIL');
            $this->warn('Уведомление не отправлено: не задан адрес BACKUP_EMAIL.');

            return;
        }

        $notification = new BackupFailedNotification([
            'project' => $this->project,
            'date' => now()->format('d.m.Y H:i'),
            'stage' => $exception->stageLabel(),
            'reason' => $exception->getMessage(),
            'attempts' => $attemptsLabel,
            'temp_path' => $this->workspace,
            'host' => $this->databaseHost,
        ]);

        try {
            Notification::route('mail', $to)->notify($notification);
            $this->log->info('Уведомление об ошибке отправлено', ['to' => $to]);
        } catch (\Throwable $error) {
            // Не раскрываем содержимое письма в логе, только причину сбоя.
            $this->log->error('Не удалось отправить уведомление об ошибке', [
                'reason' => $error->getMessage(),
                'exception' => $error::class,
            ]);
            $this->error('Не удалось отправить письмо об ошибке: ' . $error->getMessage());
        }
    }

    /**
     * Предупреждает о таблицах не на InnoDB: для них --single-transaction
     * не даёт согласованного снимка.
     */
    private function warnAboutNonInnoDbTables(): void
    {
        if (! (bool) config('db-backup.check_engines', true)) {
            return;
        }

        try {
            $connection = DB::connection(config('db-backup.connection'));
            $rows = $connection->select(
                'SELECT TABLE_NAME AS name, ENGINE AS engine
                   FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND ENGINE IS NOT NULL
                    AND ENGINE <> ?',
                ['InnoDB'],
            );
        } catch (\Throwable $error) {
            // Нет прав на information_schema — не повод прерывать резервное копирование.
            $this->log->debug('Проверка движков таблиц пропущена', ['reason' => $error->getMessage()]);

            return;
        }

        if ($rows === []) {
            return;
        }

        $tables = [];
        foreach (array_slice($rows, 0, 10) as $row) {
            $name = (string) ($row->name ?? '');
            $engine = (string) ($row->engine ?? '');
            $tables[] = sprintf('%s (%s)', $name, $engine);
        }

        $suffix = count($rows) > 10 ? sprintf(' и ещё %d', count($rows) - 10) : '';
        $message = 'таблицы не на InnoDB — согласованность снимка не гарантируется: '
            . implode(', ', $tables) . $suffix;

        $this->warn('ВНИМАНИЕ: ' . $message);
        $this->log->warning('Найдены таблицы не на InnoDB', [
            'tables' => $tables,
            'total' => count($rows),
        ]);
    }

    /**
     * Форматирует размер в человекочитаемый вид.
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
        $size = (float) $bytes;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return $unit === 0
            ? sprintf('%d %s', (int) $size, $units[$unit])
            : sprintf('%.1f %s', $size, $units[$unit]);
    }
}
