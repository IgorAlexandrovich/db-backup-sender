<?php

declare(strict_types=1);

namespace YourVendor\DbBackup\Tests\Support;

/**
 * Разбор multipart-тела запроса в тестах.
 *
 * Нужен, чтобы проверить, что пакет действительно отправил архив и что
 * переданная контрольная сумма совпадает с содержимым файла.
 */
final class MultipartBodyParser
{
    /**
     * @return array{fields: array<string, string>, file: array{field: string, name: string, content: string}|null}
     */
    public static function parse(string $body, string $contentType): array
    {
        $result = ['fields' => [], 'file' => null];

        if (preg_match('/boundary="?([^";]+)"?/i', $contentType, $matches) !== 1) {
            return $result;
        }

        $boundary = $matches[1];
        $chunks = explode('--' . $boundary, $body);

        foreach ($chunks as $chunk) {
            // Служебные границы: пустой кусок и завершающая граница "--".
            $chunk = ltrim($chunk, "\r\n");
            if ($chunk === '' || str_starts_with($chunk, '--')) {
                continue;
            }

            $separator = strpos($chunk, "\r\n\r\n");
            if ($separator === false) {
                continue;
            }

            $headers = substr($chunk, 0, $separator);
            $content = substr($chunk, $separator + 4);
            $content = (string) preg_replace('/\r\n$/', '', $content);

            $fieldName = preg_match('/name="([^"]*)"/i', $headers, $nameMatch) === 1
                ? $nameMatch[1]
                : '';

            if (str_contains($headers, 'filename=')) {
                $fileName = preg_match('/filename="([^"]*)"/i', $headers, $fileMatch) === 1
                    ? $fileMatch[1]
                    : '';

                $result['file'] = [
                    'field' => $fieldName,
                    'name' => $fileName,
                    'content' => $content,
                ];

                continue;
            }

            $result['fields'][$fieldName] = $content;
        }

        return $result;
    }
}
