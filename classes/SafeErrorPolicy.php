<?php

declare(strict_types=1);

final class SafeErrorPolicy
{
    public static function report(Throwable $error, string $component): string
    {
        try {
            $reference = bin2hex(random_bytes(8));
        } catch (Throwable $ignored) {
            $reference = str_replace('.', '', uniqid('', true));
        }

        $message = preg_replace('/[\r\n\t]+/', ' ', $error->getMessage()) ?? 'Unavailable';
        $message = preg_replace(
            '/(password|passwd|pwd|secret|token|key)\s*[=:]\s*[^\s;,&]+/i',
            '$1=[redacted]',
            $message
        ) ?? 'Unavailable';

        error_log(json_encode([
            'event' => 'application_error',
            'reference' => $reference,
            'component' => $component,
            'exception' => get_class($error),
            'code' => (string) $error->getCode(),
            'message' => substr($message, 0, 1000),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $reference;
    }
}
