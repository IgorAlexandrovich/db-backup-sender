<?php

declare(strict_types=1);

namespace YourVendor\DbBackup\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;
use YourVendor\DbBackup\Exceptions\BackupException;
use YourVendor\DbBackup\Exceptions\ConfigurationException;
use YourVendor\DbBackup\Exceptions\UploadException;

/**
 * Отправка резервной копии на удалённый приёмник.
 *
 * Правила повторных попыток:
 *   - повторяются сетевые ошибки, таймауты, ответы 5xx, 408 и 429;
 *   - НЕ повторяются 400/401/413/422: это ошибки конфигурации или данных,
 *     повтор ничего не изменит и лишь нагружает сервер;
 *   - между попытками выдерживается пауза BACKUP_RETRY_DELAY.
 *
 * Безопасность:
 *   - токен передаётся только в HTTP-заголовке и никогда не пишется в логи
 *     и в текст исключений;
 *   - текст ошибок от сервера вырезается до 500 символов и очищается от HTML.
 */
final class Uploader
{
    /** Максимальная длина сообщения сервера в тексте ошибки. */
    private const MAX_MESSAGE_LENGTH = 500;

    public function __construct(
        private string $url,
        private string $token,
        private string $header = 'X-Backup-Token',
        private int $timeout = 180,
        private int $retries = 3,
        private int $retryDelay = 5,
        private bool $verifySsl = true,
        private bool $allowInsecureHttp = false,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Проверяет настройки отправки. Вызывается до создания дампа, чтобы не
     * тратить время на дамп при заведомо неверной конфигурации.
     *
     * @throws ConfigurationException
     */
    public function validate(): void
    {
        if (trim($this->url) === '') {
            throw new ConfigurationException(
                'не задан адрес приёмника BACKUP_REMOTE_URL',
            );
        }

        $scheme = strtolower((string) parse_url($this->url, PHP_URL_SCHEME));

        if ($scheme === 'https') {
            // Штатный и единственный рекомендуемый вариант.
        } elseif ($scheme === 'http') {
            if (! $this->allowInsecureHttp) {
                throw new ConfigurationException(
                    'адрес приёмника должен начинаться с https:// — передача резервных копий по http запрещена '
                    . '(для локальной проверки можно временно включить BACKUP_ALLOW_INSECURE_HTTP=true)',
                );
            }

            $this->logger?->warning('Внимание: резервные копии отправляются по HTTP без шифрования', [
                'url' => $this->url,
            ]);
        } else {
            throw new ConfigurationException(
                sprintf('некорректный адрес приёмника "%s": ожидается https://host/api/upload', $this->url),
            );
        }

        if (! filter_var($this->url, FILTER_VALIDATE_URL)) {
            throw new ConfigurationException(sprintf('некорректный URL приёмника: "%s"', $this->url));
        }

        if (trim($this->token) === '') {
            throw new ConfigurationException(
                'не задан токен проекта BACKUP_TOKEN — его выдаёт установщик приёмника',
            );
        }

        if (trim($this->header) === '') {
            throw new ConfigurationException('не задано имя заголовка авторизации BACKUP_HEADER_NAME');
        }
    }

    /**
     * Адрес health-check приёмника, полученный из адреса загрузки.
     */
    public function healthUrl(): string
    {
        if (! filter_var($this->url, FILTER_VALIDATE_URL)) {
            return '';
        }

        $parts = parse_url($this->url);
        $host = (string) ($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $scheme = (string) ($parts['scheme'] ?? 'https');

        return sprintf('%s://%s%s/health', $scheme, $host, $port);
    }

    /**
     * Проверяет доступность приёмника (GET /health).
     *
     * @return array{ok: bool, status: int, body: string, error: string|null}
     */
    public function checkHealth(): array
    {
        $result = ['ok' => false, 'status' => 0, 'body' => '', 'error' => null];
        $url = $this->healthUrl();

        if ($url === '') {
            $result['error'] = 'не удалось определить адрес health-check из BACKUP_REMOTE_URL';

            return $result;
        }

        try {
            $response = Http::withOptions(['verify' => $this->verifySsl])
                ->timeout(min(15, max(5, $this->timeout)))
                ->acceptJson()
                ->get($url);

            $result['status'] = $response->status();
            $result['body'] = $this->limit($response->body());
            $result['ok'] = $response->successful()
                && (($response->json('status') ?? null) === 'ok');

            if (! $result['ok']) {
                $result['error'] = sprintf('приёмник ответил HTTP %d', $response->status());
            }
        } catch (ConnectionException $exception) {
            $result['error'] = 'приёмник недоступен: ' . $this->sanitize($exception->getMessage());
        } catch (\Throwable $exception) {
            $result['error'] = $this->sanitize($exception->getMessage());
        }

        return $result;
    }

    /**
     * Отправляет файл на приёмник, соблюдая политику повторных попыток.
     *
     * @throws UploadException
     * @throws BackupException
     */
    public function upload(string $filePath, string $project, string $checksum): UploadResult
    {
        $this->validate();

        if (! is_file($filePath)) {
            throw new BackupException(
                sprintf('файл для отправки "%s" не найден', $filePath),
            );
        }

        $size = (int) filesize($filePath);
        $maxAttempts = max(1, $this->retries);
        $delay = max(0, $this->retryDelay);
        $attempt = 0;
        $lastMessage = 'неизвестная ошибка отправки';

        while ($attempt < $maxAttempts) {
            $attempt++;

            $this->logger?->info('Отправка резервной копии', [
                'url' => $this->url,
                'project' => $project,
                'attempt' => $attempt,
                'attempts' => $maxAttempts,
                'size' => $size,
                'sha256' => $checksum,
            ]);

            try {
                $response = $this->send($filePath, $project, $checksum);
            } catch (ConnectionException $exception) {
                $lastMessage = 'сетевая ошибка: ' . $this->sanitize($exception->getMessage());

                $this->logger?->warning('Попытка отправки не удалась', [
                    'attempt' => $attempt,
                    'attempts' => $maxAttempts,
                    'reason' => $lastMessage,
                ]);

                if ($attempt < $maxAttempts) {
                    sleep($delay);

                    continue;
                }

                throw new UploadException(
                    $lastMessage,
                    $attempt,
                    $maxAttempts,
                    true,
                    ['url' => $this->url],
                    $exception,
                );
            }

            $status = $response->status();
            $payload = $this->decode($response->body());

            // Успех: приёмник подтвердил сохранение файла.
            if ($response->successful() && ($payload['status'] ?? null) === 'ok') {
                $savedFile = (string) ($payload['file'] ?? '');
                $savedSize = (int) ($payload['size'] ?? $size);
                $savedSha = (string) ($payload['sha256'] ?? $checksum);

                $this->logger?->info('Резервная копия принята приёмником', [
                    'project' => $project,
                    'file' => $savedFile,
                    'size' => $savedSize,
                    'attempt' => $attempt,
                ]);

                return new UploadResult(
                    project: (string) ($payload['project'] ?? $project),
                    file: $savedFile,
                    size: $savedSize,
                    sha256: $savedSha,
                    attempts: $attempt,
                    response: $payload,
                );
            }

            $errorCode = (string) ($payload['error_code'] ?? 'unknown');
            $serverMessage = trim((string) ($payload['message'] ?? ''));

            if ($serverMessage === '') {
                $serverMessage = $this->limit($response->body());
            }

            $lastMessage = sprintf(
                'приёмник вернул HTTP %d (%s): %s%s',
                $status,
                $errorCode,
                $this->sanitize($serverMessage),
                $this->hintForStatus($status, $errorCode),
            );

            $retryable = $this->isRetryableStatus($status);

            $this->logger?->{$retryable && $attempt < $maxAttempts ? 'warning' : 'error'}(
                'Приёмник отклонил резервную копию',
                [
                    'attempt' => $attempt,
                    'attempts' => $maxAttempts,
                    'status' => $status,
                    'error_code' => $errorCode,
                    'reason' => $lastMessage,
                    'retryable' => $retryable,
                ],
            );

            if (! $retryable) {
                throw new UploadException($lastMessage, $attempt, $maxAttempts, false, [
                    'status' => $status,
                    'error_code' => $errorCode,
                ]);
            }

            if ($attempt < $maxAttempts) {
                sleep($delay);
            }
        }

        // Все попытки исчерпаны на временных ошибках.
        throw new UploadException($lastMessage, $attempt, $maxAttempts, true, ['url' => $this->url]);
    }

    /**
     * Выполняет одну попытку HTTP-запроса.
     *
     * @throws ConnectionException
     */
    private function send(string $filePath, string $project, string $checksum): \Illuminate\Http\Client\Response
    {
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new BackupException(sprintf('не удалось открыть архив "%s" для отправки', $filePath));
        }

        // Поток НЕ закрываем вручную: Guzzle читает его во время отправки,
        // а HTTP-клиент сохраняет ссылку на него в объекте запроса (в том числе
        // при Http::fake в тестах). Дескриптор освобождается сборщиком мусора
        // сразу после завершения попытки, поэтому утечки нет.
        return Http::withHeaders([$this->header => $this->token])
            ->withOptions(['verify' => $this->verifySsl])
            ->timeout($this->timeout)
            ->acceptJson()
            ->attach('file', $handle, basename($filePath))
            ->post($this->url, [
                'project' => $project,
                'sha256' => $checksum,
            ]);
    }

    /**
     * Разбирает JSON-ответ приёмника, не падая на некорректном ответе.
     *
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        if ($body === '') {
            return [];
        }

        try {
            $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Имеет ли смысл повторять запрос при таком HTTP-статусе.
     */
    private function isRetryableStatus(int $status): bool
    {
        return $status >= 500 || in_array($status, [408, 425, 429], true);
    }

    /**
     * Подсказка администратору по коду ответа.
     */
    private function hintForStatus(int $status, string $errorCode): string
    {
        return match (true) {
            $status === 401 && $errorCode === 'project_mismatch' => ' — имя проекта не соответствует токену',
            $status === 401 => ' — проверьте BACKUP_TOKEN: токен не совпадает с токеном проекта на приёмнике',
            $status === 413 => ' — файл больше лимита приёмника (max_upload_size / client_max_body_size)',
            $status === 422 && $errorCode === 'checksum_mismatch' => ' — файл повреждён при передаче',
            $status === 422 && $errorCode === 'bad_format' => ' — приёмник ожидает gzip-архив',
            $status === 502 || $status === 504 => ' — Nginx не смог связаться с приёмником (служба запущена?)',
            default => '',
        };
    }

    /**
     * Убирает секреты и HTML из текста ошибки.
     */
    private function sanitize(string $message): string
    {
        if ($this->token !== '') {
            $message = str_replace($this->token, '***', $message);
        }

        // Nginx отдаёт HTML-страницы ошибок: оставляем только текст.
        $message = strip_tags($message);
        $message = (string) preg_replace('/\s+/u', ' ', $message);

        return $this->limit(trim($message));
    }

    /**
     * Ограничивает длину сообщения.
     */
    private function limit(string $message): string
    {
        if (mb_strlen($message) <= self::MAX_MESSAGE_LENGTH) {
            return $message;
        }

        return mb_substr($message, 0, self::MAX_MESSAGE_LENGTH) . '…';
    }
}
