<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class SafeRedirectUrl implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail('กรุณาระบุ URL ปลายทาง');

            return;
        }

        if (strlen($value) > 2048) {
            $fail('URL ปลายทางมีความยาวเกิน 2,048 ตัวอักษร');

            return;
        }

        $trimmed = trim($value);
        if (in_array($trimmed, ['{drive_url}', '{{drive_url}}', '{wordpress_url}', '{{wordpress_url}}'], true)) {
            return;
        }

        $parts = parse_url($value);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            $fail('รูปแบบ URL ปลายทางไม่ถูกต้อง');

            return;
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            $fail('URL ปลายทางต้องขึ้นต้นด้วย http:// หรือ https:// เท่านั้น');

            return;
        }

        $host = strtolower($parts['host']);

        // Block localhost, .local, .internal, .test domains in production
        if (! app()->environment('local', 'testing')) {
            if (
                $host === 'localhost' ||
                str_ends_with($host, '.local') ||
                str_ends_with($host, '.internal') ||
                str_ends_with($host, '.test')
            ) {
                $fail('ไม่อนุญาตให้ใช้ที่อยู่เครื่องภายใน (Localhost / Internal domain)');

                return;
            }
        }

        // If host is an IP literal, validate that it is not private / reserved / loopback
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $isPublic = filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );

            if ($isPublic === false) {
                $fail('ไม่อนุญาตให้ใช้ IP Address เครือข่ายภายใน (Private / Loopback IP)');

                return;
            }
        }
    }
}
