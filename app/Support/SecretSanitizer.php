<?php

namespace App\Support;

class SecretSanitizer
{
    public const REDACTED = '[REDACTED]';

    /**
     * Case-insensitive list of array keys containing sensitive credentials.
     *
     * @var list<string>
     */
    protected const SENSITIVE_KEYS = [
        'password',
        'wordpress_password',
        'app_password',
        'application_password',
        'access_token',
        'page_access_token',
        'user_access_token',
        'refresh_token',
        'google_refresh_token',
        'google_client_secret',
        'token',
        'secret',
        'client_secret',
        'app_secret',
        'api_key',
        'apikey',
        'authorization',
        'cookie',
        'encrypted_credentials',
    ];

    /**
     * Sanitize a text string by redacting tokens, passwords, and authorization credentials.
     */
    public static function sanitize(?string $text): string
    {
        if ($text === null || $text === '') {
            return (string) $text;
        }

        // 1. Redact access_token=... and refresh_token=... in URLs, query strings, or urlencoded payloads
        $text = (string) preg_replace('/((?:access_token|refresh_token)=)[^&\s"\'\r\n]+/iu', '$1'.self::REDACTED, $text);

        // 2. Redact Facebook tokens starting with EAA (15+ alphanumeric chars)
        $text = (string) preg_replace('/\b(EAA[A-Za-z0-9]{15,})\b/u', self::REDACTED, $text);

        // 2.1 Redact Google OAuth tokens (ya29.* and 1//*)
        $text = (string) preg_replace('/\b(ya29\.[A-Za-z0-9_\-\.]+)\b/u', self::REDACTED, $text);
        $text = (string) preg_replace('/\b(1\/\/[A-Za-z0-9_\-\.]+)\b/u', self::REDACTED, $text);

        // 3. Redact Bearer tokens
        $text = (string) preg_replace('/(bearer\s+)[A-Za-z0-9_\-\.+=]+/iu', '$1'.self::REDACTED, $text);

        // 4. Redact Basic auth tokens in Authorization headers
        $text = (string) preg_replace('/(basic\s+)[A-Za-z0-9+\/=_]+/iu', '$1'.self::REDACTED, $text);

        // 5. Redact user:password in URLs
        $text = (string) preg_replace('/([a-zA-Z][a-zA-Z0-9+.-]*:\/\/)([^:\/\s@]+):([^@\/\s]+)@/u', '$1$2:'.self::REDACTED.'@', $text);

        // 6. Redact password, access_token, etc. in JSON keys
        $text = (string) preg_replace('/("?(?:password|access_token|page_access_token|user_access_token|refresh_token|token|secret|client_secret|app_secret|app_password|application_password)"?\s*:\s*")[^"\\\\]*(?:\\\\.[^"\\\\]*)*(")/iu', '$1'.self::REDACTED.'$2', $text);

        // 7. Redact password=..., app_password=... in query/form strings
        $text = (string) preg_replace('/((?:password|app_password|application_password|client_secret|secret|refresh_token)=)[^&\s"\'\r\n]+/iu', '$1'.self::REDACTED, $text);

        return $text;
    }

    /**
     * Recursively sanitize an array by redacting sensitive keys and scrubbing string values.
     *
     * @param  array<string|int, mixed>  $data
     * @return array<string|int, mixed>
     */
    public static function sanitizeArray(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = self::sanitizeArray($value);
            } elseif (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                $sanitized[$key] = self::REDACTED;
            } elseif (is_string($value)) {
                $sanitized[$key] = self::sanitize($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Helper to sanitize throwable exception messages.
     */
    public static function sanitizeThrowable(\Throwable $e): string
    {
        return self::sanitize($e->getMessage());
    }
}
