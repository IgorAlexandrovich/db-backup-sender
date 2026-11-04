<?php

declare(strict_types=1);

namespace YourVendor\DbBackup;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use YourVendor\DbBackup\Commands\CheckCommand;
use YourVendor\DbBackup\Commands\RunBackup;
use YourVendor\DbBackup\Services\Checksum;
use YourVendor\DbBackup\Services\Compressor;
use YourVendor\DbBackup\Services\DumpCreator;
use YourVendor\DbBackup\Services\Uploader;

/**
 * Сервис-провайдер пакета.
 *
 * Регистрирует:
 *   - публикацию конфигурации (tag: db-backup-config);
 *   - команды backup:run и backup:check;
 *   - сервисы создания/сжатия/проверки/отправки дампа;
 *   - отдельный лог-канал резервного копирования;
 *   - ежечасную задачу в Laravel Scheduler.
 *
 * Тег ресурсов (config) не переопределяет уже опубликованный config/db-backup.php:
 * mergeConfigFrom добавляет значения пакета только как значения по умолчанию.
 */
final class BackupServiceProvider extends ServiceProvider
{
    /** Имя тега публикации конфигурации. */
    public const CONFIG_TAG = 'db-backup-config';

    /** Ключ логгера в контейнере. */
    public const LOGGER_KEY = 'db-backup.logger';

    /**
     * Регистрация привязок контейнера.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/db-backup.php', 'db-backup');

        $this->registerLogger();

        $this->app->singleton(Checksum::class, static fn (): Checksum => new Checksum());

        $this->app->singleton(Compressor::class, function ($app): Compressor {
            /** @var ConfigRepository $config */
            $config = $app['config'];

            return new Compressor((int) $config->get('db-backup.compression.level', 6));
        });

        $this->app->singleton(DumpCreator::class, function ($app): DumpCreator {
            /** @var ConfigRepository $config */
            $config = $app['config'];

            // Подключение берём из config/database.php: пользователь не должен
            // дублировать логин/пароль в конфигурации пакета.
            $connectionName = $config->get('db-backup.connection') ?: $config->get('database.default');
            $connection = (array) $config->get('database.connections.' . $connectionName, []);

            return new DumpCreator(
                connection: $connection,
                binary: (string) $config->get('db-backup.mysqldump.binary', ''),
                timeoutSeconds: (int) $config->get('db-backup.mysqldump.timeout_seconds', 0),
                extraArguments: array_values((array) $config->get('db-backup.mysqldump.extra_arguments', [])),
                logger: $app->bound(self::LOGGER_KEY) ? $app->make(self::LOGGER_KEY) : null,
            );
        });

        $this->app->singleton(Uploader::class, function ($app): Uploader {
            /** @var ConfigRepository $config */
            $config = $app['config'];

            return new Uploader(
                url: (string) $config->get('db-backup.remote.url', ''),
                token: (string) $config->get('db-backup.remote.token', ''),
                header: (string) $config->get('db-backup.remote.header', 'X-Backup-Token'),
                timeout: (int) $config->get('db-backup.remote.timeout', 180),
                retries: (int) $config->get('db-backup.retries', 3),
                retryDelay: (int) $config->get('db-backup.retry_delay', 5),
                verifySsl: (bool) $config->get('db-backup.remote.verify_ssl', true),
                allowInsecureHttp: (bool) $config->get('db-backup.remote.allow_insecure_http', false),
                logger: $app->bound(self::LOGGER_KEY) ? $app->make(self::LOGGER_KEY) : null,
            );
        });
    }

    /**
     * Публикация ресурсов, регистрация команд и расписания.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/db-backup.php' => config_path('db-backup.php'),
            ], self::CONFIG_TAG);

            $this->commands([
                RunBackup::class,
                CheckCommand::class,
            ]);
        }

        $this->registerSchedule();
    }

    /**
     * Отдельный логгер для событий резервного копирования.
     *
     * Если в config/logging.php уже объявлен канал с таким именем —
     * используется он (полный контроль над форматом и местом хранения).
     * Иначе логгер собирается средствами LogManager::build().
     */
    private function registerLogger(): void
    {
        $this->app->singleton(self::LOGGER_KEY, function ($app): LoggerInterface {
            /** @var ConfigRepository $config */
            $config = $app['config'];
            $settings = (array) $config->get('db-backup.logging', []);
            $channel = (string) ($settings['channel'] ?? 'db-backup');
            $manager = $app['log'];

            // Пользовательский канал из config/logging.php имеет приоритет.
            if ($config->get('logging.channels.' . $channel)) {
                return $manager->channel($channel);
            }

            $path = (string) ($settings['path'] ?? storage_path('logs/db-backup.log'));
            $directory = dirname($path);
            if (! is_dir($directory)) {
                @mkdir($directory, 0o775, true);
            }

            return $manager->build([
                'name' => $channel,
                'driver' => (string) ($settings['driver'] ?? 'daily'),
                'path' => $path,
                'level' => (string) ($settings['level'] ?? 'debug'),
                'days' => (int) ($settings['days'] ?? 14),
                'replace_placeholders' => true,
            ]);
        });
    }

    /**
     * Автоматическая регистрация задачи в планировщике Laravel.
     *
     * Пользователю достаточно добавить стандартный cron:
     *   * * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
     */
    private function registerSchedule(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            /** @var ConfigRepository $config */
            $config = $this->app['config'];

            if (! (bool) $config->get('db-backup.schedule.enabled', true)) {
                return;
            }

            $cron = trim((string) $config->get('db-backup.schedule.cron', '0 * * * *'));
            $lockMinutes = max(1, (int) $config->get('db-backup.schedule.without_overlapping_minutes', 90));

            $event = $schedule->command('backup:run')
                // Защита от наложения: если предыдущий дамп ещё идёт, запуск пропускается.
                ->withoutOverlapping($lockMinutes)
                ->description('Резервное копирование БД (db-backup-sender)');

            if ($cron !== '') {
                $event->cron($cron);
            }
        });
    }
}
