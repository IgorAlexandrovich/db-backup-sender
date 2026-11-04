<?php

declare(strict_types=1);

namespace YourVendor\DbBackup\Tests\Support;

/**
 * Поддельный mysqldump для тестов.
 *
 * Создаёт исполняемый shell-скрипт, который ведёт себя как mysqldump:
 *   - понимает --version;
 *   - пишет заданный SQL в файл из --result-file=;
 *   - сохраняет аргументы и содержимое --defaults-extra-file для проверок;
 *   - умеет завершаться с ошибкой и выводить текст в stderr.
 *
 * Это позволяет проверять DumpCreator без реального сервера MySQL и
 * одновременно контролировать, что пароль передаётся только через файл опций.
 */
final class FakeMysqldump
{
    private string $path;

    private string $argumentsFile;

    private string $clientConfigFile;

    private function __construct(string $path, string $argumentsFile, string $clientConfigFile)
    {
        $this->path = $path;
        $this->argumentsFile = $argumentsFile;
        $this->clientConfigFile = $clientConfigFile;
    }

    /**
     * Создаёт поддельный mysqldump в указанном каталоге.
     */
    public static function create(
        string $directory,
        string $sql = "CREATE TABLE `users` (`id` INT);\nINSERT INTO `users` VALUES (1);\n",
        int $exitCode = 0,
        string $stderr = '',
    ): self {
        if (! is_dir($directory) && ! mkdir($directory, 0o750, true) && ! is_dir($directory)) {
            throw new \RuntimeException(sprintf('не удалось создать каталог %s', $directory));
        }

        $path = $directory . '/fake-mysqldump';
        $argumentsFile = $directory . '/args.txt';
        $clientConfigFile = $directory . '/client-config.txt';

        $script = strtr(self::scriptTemplate(), [
            '__SQL__' => self::shellQuote($sql),
            '__EXIT_CODE__' => (string) $exitCode,
            '__STDERR__' => self::shellQuote($stderr),
            '__ARGUMENTS_FILE__' => self::shellQuote($argumentsFile),
            '__CLIENT_CONFIG_FILE__' => self::shellQuote($clientConfigFile),
        ]);

        file_put_contents($path, $script);
        chmod($path, 0o755);

        return new self($path, $argumentsFile, $clientConfigFile);
    }

    /**
     * Путь к исполняемому файлу.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Аргументы последнего запуска (по одному в строке).
     *
     * @return list<string>
     */
    public function arguments(): array
    {
        if (! is_file($this->argumentsFile)) {
            return [];
        }

        $lines = file($this->argumentsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return $lines === false ? [] : array_values($lines);
    }

    /**
     * Содержимое файла опций MySQL, переданного через --defaults-extra-file.
     */
    public function clientConfig(): string
    {
        if (! is_file($this->clientConfigFile)) {
            return '';
        }

        return (string) file_get_contents($this->clientConfigFile);
    }

    /**
     * Шаблон shell-скрипта.
     */
    private static function scriptTemplate(): string
    {
        return <<<'SH'
#!/bin/sh
# Поддельный mysqldump для автотестов пакета db-backup-sender.
RESULT=""
for arg in "$@"; do
    case "$arg" in
        --version)
            echo "mysqldump  Ver 8.0.36 for Linux on x86_64 (Fake)"
            exit 0
            ;;
        --result-file=*)
            RESULT="${arg#--result-file=}"
            ;;
    esac
done

# Сохраняем аргументы: тесты проверяют наличие --single-transaction и порядок.
printf '%s\n' "$@" > __ARGUMENTS_FILE__

# Сохраняем файл опций, чтобы тест убедился, что пароль передан именно через него.
FIRST="$1"
FIRST="${FIRST#--defaults-extra-file=}"
if [ -r "$FIRST" ]; then
    cat "$FIRST" > __CLIENT_CONFIG_FILE__
fi

if [ "__EXIT_CODE__" -ne 0 ]; then
    echo __STDERR__ >&2
    exit __EXIT_CODE__
fi

if [ -z "$RESULT" ]; then
    echo "mysqldump: не передан --result-file" >&2
    exit 2
fi

printf '%s' __SQL__ > "$RESULT"
exit 0
SH;
    }

    /**
     * Экранирует строку для вставки в одинарные кавычки shell.
     */
    private static function shellQuote(string $value): string
    {
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }
}
