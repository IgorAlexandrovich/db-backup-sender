<?php

declare(strict_types=1);

namespace YourVendor\DbBackup\Services;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use YourVendor\DbBackup\Exceptions\ConfigurationException;
use YourVendor\DbBackup\Exceptions\DumpException;

/**
 * Создание дампа MySQL/MariaDB через mysqldump.
 *
 * Безопасность пароля:
 *   - пароль НЕ передаётся аргументом командной строки (иначе его видно в ps);
 *   - пароль НЕ передаётся через переменную окружения (её видно в /proc/PID/environ);
 *   - пароль пишется во временный файл конфигурации с правами 0600 и передаётся
 *     через --defaults-extra-file, после завершения файл удаляется;
 *   - пароль вырезается из текста ошибок mysqldump перед логированием.
 *
 * Согласованность дампа:
 *   --single-transaction даёт согласованный снимок только для таблиц InnoDB.
 *   Для MyISAM данные могут быть несогласованными — об этом предупреждает
 *   команда backup:check.
 */
final class DumpCreator
{
    /** Запас по умолчанию для чтения ошибок mysqldump. */
    private const MAX_ERROR_LENGTH = 2000;

    /** @var array<string, string>|null Кеш результата поиска mysqldump. */
    private ?array $resolved = null;

    /**
     * @param array<string, mixed> $connection     Подключение из config/database.php.
     * @param list<string>         $extraArguments Дополнительные аргументы mysqldump.
     */
    public function __construct(
        private readonly array $connection,
        private readonly string $binary = '',
        private readonly int $timeoutSeconds = 0,
        private readonly array $extraArguments = [],
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Имя базы данных из настроек подключения.
     *
     * @throws ConfigurationException
     */
    public function database(): string
    {
        $database = trim((string) ($this->connection['database'] ?? ''));

        if ($database === '') {
            throw new ConfigurationException(
                'в конфигурации подключения не указано имя базы данных (database)',
                ['connection_driver' => (string) ($this->connection['driver'] ?? '')],
            );
        }

        return $database;
    }

    /**
     * Хост базы данных (для диагностики; пароль и логин не раскрываются).
     */
    public function host(): string
    {
        $socket = trim((string) ($this->connection['unix_socket'] ?? ''));
        if ($socket !== '') {
            return 'socket:' . $socket;
        }

        $host = trim((string) ($this->connection['host'] ?? '127.0.0.1'));
        $port = (string) ($this->connection['port'] ?? '');

        return $port !== '' ? $host . ':' . $port : $host;
    }

    /**
     * Проверяет, что mysqldump доступен, и возвращает путь к нему.
     *
     * @throws DumpException
     */
    public function resolveBinary(): string
    {
        if ($this->resolved !== null) {
            return $this->resolved['path'];
        }

        $configured = trim($this->binary);

        if ($configured !== '') {
            // Явно заданный путь: проверяем файл напрямую.
            if (str_contains($configured, '/') || str_contains($configured, '\\')) {
                if (! is_file($configured)) {
                    throw new DumpException(sprintf(
                        'утилита mysqldump не найдена по пути "%s". Проверьте BACKUP_MYSQLDUMP_PATH.',
                        $configured,
                    ));
                }
                if (! is_executable($configured)) {
                    throw new DumpException(sprintf('файл "%s" не является исполняемым', $configured));
                }

                $this->resolved = ['path' => $configured, 'source' => 'config'];

                return $configured;
            }

            // Имя без пути: ищем в PATH.
            $found = $this->searchPath($configured);
            if ($found === null) {
                throw new DumpException(sprintf(
                    'утилита "%s" не найдена в PATH. Установите пакет mysql-client/mariadb-client '
                    . 'или укажите полный путь в BACKUP_MYSQLDUMP_PATH.',
                    $configured,
                ));
            }

            $this->resolved = ['path' => $found, 'source' => 'config'];

            return $found;
        }

        $found = $this->searchPath('mysqldump');
        if ($found === null) {
            throw new DumpException(
                'утилита mysqldump не найдена в PATH. На Debian/Ubuntu установите её командой '
                . '"apt install default-mysql-client" (для MariaDB — "apt install mariadb-client") '
                . 'или укажите полный путь в BACKUP_MYSQLDUMP_PATH.',
            );
        }

        $this->resolved = ['path' => $found, 'source' => 'PATH'];

        return $found;
    }

    /**
     * Доступен ли mysqldump (без выбрасывания исключения).
     */
    public function isAvailable(): bool
    {
        try {
            $this->resolveBinary();

            return true;
        } catch (DumpException) {
            return false;
        }
    }

    /**
     * Версия клиента mysqldump (для диагностики). null, если получить не удалось.
     */
    public function version(): ?string
    {
        try {
            $process = new Process([$this->resolveBinary(), '--version'], null, null, null, 15);
            $process->run();

            if (! $process->isSuccessful()) {
                return null;
            }

            return trim($process->getOutput()) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Содержимое временного файла конфигурации MySQL-клиента.
     *
     * Отдельный метод (public) существует для тестов и диагностики:
     * он позволяет проверить экранирование пароля, не запуская mysqldump.
     */
    public function clientConfigContents(): string
    {
        $lines = ['[client]'];

        $user = (string) ($this->connection['username'] ?? '');
        if ($user !== '') {
            $lines[] = 'user=' . $this->quote($user);
        }

        $password = (string) ($this->connection['password'] ?? '');
        if ($password !== '') {
            $lines[] = 'password=' . $this->quote($password);
        }

        $socket = (string) ($this->connection['unix_socket'] ?? '');
        if ($socket !== '') {
            // Если задан unix_socket, подключаемся через сокет, а не по TCP.
            $lines[] = 'socket=' . $this->quote($socket);
        } else {
            $host = (string) ($this->connection['host'] ?? '');
            if ($host !== '') {
                $lines[] = 'host=' . $this->quote($host);
            }

            $port = (string) ($this->connection['port'] ?? '');
            if ($port !== '') {
                $lines[] = 'port=' . $this->quote($port);
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Создаёт дамп базы в указанный файл.
     *
     * @throws DumpException
     */
    public function create(string $targetPath): void
    {
        $binary = $this->resolveBinary();
        $database = $this->database();

        $directory = dirname($targetPath);
        if (! is_dir($directory) && ! @mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new DumpException(sprintf('не удалось создать каталог для дампа "%s"', $directory));
        }

        // Временный файл с паролем: 0600, удаляется в finally.
        $configFile = tempnam($directory, '.mysql-client-');
        if ($configFile === false) {
            throw new DumpException(sprintf('не удалось создать временный файл конфигурации MySQL в "%s"', $directory));
        }
        @chmod($configFile, 0600);

        $written = file_put_contents($configFile, $this->clientConfigContents());
        if ($written === false) {
            @unlink($configFile);
            throw new DumpException('не удалось записать временный файл конфигурации MySQL');
        }

        // --defaults-extra-file обязан идти первым аргументом.
        $command = array_merge(
            [$binary, '--defaults-extra-file=' . $configFile],
            [
                // Согласованный снимок без блокировки таблиц (для InnoDB).
                '--single-transaction',
                // Читаем построчно, не загружая таблицу в память.
                '--quick',
                '--skip-lock-tables',
                '--default-character-set=utf8mb4',
                // Пишем сразу в файл: PHP не участвует в перекачке данных.
                '--result-file=' . $targetPath,
            ],
            array_values($this->extraArguments),
            [$database],
        );

        $this->logger?->info('Запуск mysqldump', [
            'database' => $database,
            'host' => $this->host(),
            'binary' => $binary,
            'single_transaction' => true,
        ]);

        $process = new Process(
            $command,
            null,
            null,
            null,
            $this->timeoutSeconds > 0 ? $this->timeoutSeconds : null,
        );

        try {
            $process->run();
        } catch (ProcessTimedOutException $exception) {
            $process->stop(1);
            throw new DumpException(
                sprintf('mysqldump не завершился за %d секунд — дамп прерван', $this->timeoutSeconds),
                ['timeout_seconds' => $this->timeoutSeconds],
                $exception,
            );
        } finally {
            // Файл с паролем удаляется всегда, даже при исключении.
            @unlink($configFile);
        }

        if (! $process->isSuccessful()) {
            throw new DumpException(
                'mysqldump завершился с ошибкой: ' . $this->describeFailure($process),
                ['exit_code' => $process->getExitCode()],
            );
        }

        clearstatcache(true, $targetPath);

        if (! is_file($targetPath)) {
            throw new DumpException('mysqldump сообщил об успехе, но файл дампа не создан');
        }

        $size = (int) filesize($targetPath);
        if ($size <= 0) {
            @unlink($targetPath);
            throw new DumpException('mysqldump создал пустой дамп — проверьте права пользователя БД');
        }

        $this->logger?->info('Дамп создан', ['database' => $database, 'size' => $size]);
    }

    /**
     * Ищет исполняемый файл в PATH.
     */
    private function searchPath(string $program): ?string
    {
        $path = (string) getenv('PATH');
        if ($path === '') {
            $path = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
        }

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            if ($directory === '') {
                continue;
            }

            $candidate = rtrim($directory, '/') . '/' . $program;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Экранирует значение для файла опций MySQL.
     */
    private function quote(string $value): string
    {
        if ($value !== '' && preg_match('~^[A-Za-z0-9._:/=@.-]+$~', $value) === 1) {
            return $value;
        }

        $escaped = str_replace(
            ['\\', '"', "\n", "\r"],
            ['\\\\', '\\"', '', ''],
            $value,
        );

        return '"' . $escaped . '"';
    }

    /**
     * Готовит безопасное описание ошибки mysqldump: вырезает пароль,
     * ограничивает длину и добавляет подсказку по частым причинам.
     */
    private function describeFailure(Process $process): string
    {
        $error = trim($process->getErrorOutput());
        if ($error === '') {
            $error = trim($process->getOutput());
        }
        if ($error === '') {
            $error = 'нет вывода об ошибке, код выхода ' . (string) $process->getExitCode();
        }

        $password = (string) ($this->connection['password'] ?? '');
        if ($password !== '') {
            $error = str_replace($password, '***', $error);
        }

        $error = $this->limit($error, self::MAX_ERROR_LENGTH);

        $hint = $this->hint($error);

        return $hint === null ? $error : $error . ' (' . $hint . ')';
    }

    /**
     * Подсказки по типичным ошибкам mysqldump.
     */
    private function hint(string $error): ?string
    {
        $lower = mb_strtolower($error);

        return match (true) {
            str_contains($lower, 'access denied') => 'проверьте логин, пароль и права пользователя БД в config/database.php',
            str_contains($lower, 'unknown database') => 'проверьте имя базы данных в config/database.php',
            str_contains($lower, 'unknown option') => 'уберите неподдерживаемый аргумент из mysqldump.extra_arguments',
            str_contains($lower, 'can\'t connect') || str_contains($lower, 'connection refused') => 'сервер БД недоступен по указанному host/port или socket',
            str_contains($lower, 'no space left') => 'на сервере закончилось место на диске',
            default => null,
        };
    }

    /**
     * Обрезает строку до указанной длины, сохраняя корректный UTF-8.
     */
    private function limit(string $value, int $length): string
    {
        if (mb_strlen($value) <= $length) {
            return $value;
        }

        return mb_substr($value, 0, $length) . '…';
    }
}
