<?php

namespace App\Services\Publishers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Throwable;

class PublishFailureClassifier
{
    /** @var array<int, int> */
    private const BACKOFF = [
        1 => 10,
        2 => 30,
        3 => 90,
        4 => 300,
        5 => 900,
    ];

    public static function classifyThrowable(Throwable $exception, string $channel, int $attempt = 1): ClassificationResult
    {
        $message = strtolower($exception->getMessage());
        $isConnection = $exception instanceof ConnectionException;
        $isTimeout = str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, 'curl error 28');
        $isDns = str_contains($message, 'could not resolve host')
            || str_contains($message, 'name resolution');

        if ($isConnection || $isTimeout || $isDns) {
            return new ClassificationResult(
                category: $isTimeout ? 'timeout' : 'network_error',
                isRetryable: true,
                retryDelaySeconds: self::backoff($attempt),
                messageTh: $isTimeout
                    ? 'การเชื่อมต่อใช้เวลานานเกินกำหนด ระบบจะลองใหม่อัตโนมัติ'
                    : 'ไม่สามารถเชื่อมต่อบริการภายนอกได้ชั่วคราว ระบบจะลองใหม่อัตโนมัติ',
            );
        }

        return new ClassificationResult(
            category: 'unknown',
            isRetryable: false,
            retryDelaySeconds: 0,
            messageTh: 'เกิดข้อผิดพลาดที่ไม่สามารถลองใหม่อัตโนมัติได้',
        );
    }

    public static function classifyHttpResponse(Response $response, string $channel, int $attempt = 1): ClassificationResult
    {
        $status = $response->status();
        $body = $response->body();
        $payload = $response->json();
        $errorCode = self::extractErrorCode($payload);
        $subcode = self::extractErrorSubcode($payload);

        if ($channel === 'facebook' && in_array($errorCode, ['4', '17', '32'], true)) {
            $retryAfter = (int) ($response->header('Retry-After') ?: 0);

            return new ClassificationResult(
                category: 'rate_limited',
                isRetryable: true,
                retryDelaySeconds: $retryAfter > 0 ? $retryAfter : self::backoff($attempt),
                messageTh: 'Facebook API จำกัดการเรียกใช้งานชั่วคราว ระบบจะลองใหม่อัตโนมัติ',
                errorCode: $errorCode,
                httpStatus: $status,
                externalSubcode: $subcode,
            );
        }

        if ($channel === 'facebook' && $errorCode === '190') {
            return new ClassificationResult(
                category: 'auth_required',
                isRetryable: false,
                retryDelaySeconds: 0,
                messageTh: 'สิทธิ์ Facebook หรือ Access Token หมดอายุ กรุณาเชื่อมต่อใหม่',
                errorCode: $errorCode,
                httpStatus: $status,
                externalSubcode: $subcode,
            );
        }

        if ($channel === 'facebook' && $errorCode === '200') {
            return new ClassificationResult(
                category: 'auth_required',
                isRetryable: false,
                retryDelaySeconds: 0,
                messageTh: 'สิทธิ์ Facebook ไม่เพียงพอ กรุณาตรวจสอบ Permissions ของแอป',
                errorCode: $errorCode,
                httpStatus: $status,
                externalSubcode: $subcode,
            );
        }

        if ($status === 429) {
            $retryAfter = (int) ($response->header('Retry-After') ?: 0);

            return new ClassificationResult(
                category: 'rate_limited',
                isRetryable: true,
                retryDelaySeconds: $retryAfter > 0 ? $retryAfter : self::backoff($attempt),
                messageTh: $channel === 'facebook'
                    ? 'Facebook API จำกัดการเรียกใช้งานชั่วคราว ระบบจะลองใหม่อัตโนมัติ'
                    : 'บริการปลายทางจำกัดการเรียกใช้งานชั่วคราว ระบบจะลองใหม่อัตโนมัติ',
                errorCode: $errorCode,
                httpStatus: $status,
                externalSubcode: $subcode,
            );
        }

        if (in_array($status, [408, 425, 500, 502, 503, 504], true)) {
            return new ClassificationResult(
                category: 'retryable',
                isRetryable: true,
                retryDelaySeconds: self::backoff($attempt),
                messageTh: 'บริการปลายทางไม่พร้อมใช้งานชั่วคราว ระบบจะลองใหม่อัตโนมัติ',
                errorCode: $errorCode,
                httpStatus: $status,
                externalSubcode: $subcode,
            );
        }

        if (in_array($status, [401, 403], true)) {
            return new ClassificationResult(
                category: 'auth_required',
                isRetryable: false,
                retryDelaySeconds: 0,
                messageTh: $channel === 'facebook'
                    ? 'สิทธิ์ Facebook ไม่เพียงพอหรือ Token ไม่ถูกต้อง'
                    : 'ข้อมูลยืนยันตัวตน WordPress ไม่ถูกต้องหรือไม่มีสิทธิ์เผยแพร่',
                errorCode: $errorCode,
                httpStatus: $status,
                externalSubcode: $subcode,
            );
        }

        if ($status === 400) {
            return new ClassificationResult(
                category: 'validation',
                isRetryable: false,
                retryDelaySeconds: 0,
                messageTh: 'ข้อมูลที่ส่งไปยังบริการปลายทางไม่ถูกต้อง กรุณาตรวจสอบเนื้อหาและการตั้งค่า',
                errorCode: $errorCode,
                httpStatus: $status,
                externalSubcode: $subcode,
            );
        }

        return self::classifyMessage($body, $channel, $attempt, $status, $errorCode, $subcode);
    }

    public static function classifyMessage(
        ?string $message,
        string $channel,
        int $attempt = 1,
        ?int $httpStatus = null,
        ?string $errorCode = null,
        ?string $externalSubcode = null,
    ): ClassificationResult {
        $text = strtolower((string) $message);

        if ($httpStatus === 429) {
            return new ClassificationResult(
                category: 'rate_limited',
                isRetryable: true,
                retryDelaySeconds: self::backoff($attempt),
                messageTh: $channel === 'facebook'
                    ? 'Facebook API จำกัดการเรียกใช้งานชั่วคราว ระบบจะลองใหม่อัตโนมัติ'
                    : 'บริการปลายทางจำกัดการเรียกใช้งานชั่วคราว ระบบจะลองใหม่อัตโนมัติ',
                errorCode: $errorCode,
                httpStatus: $httpStatus,
                externalSubcode: $externalSubcode,
            );
        }

        if (in_array($httpStatus, [408, 425, 500, 502, 503, 504], true)) {
            return new ClassificationResult(
                category: 'retryable',
                isRetryable: true,
                retryDelaySeconds: self::backoff($attempt),
                messageTh: 'บริการปลายทางไม่พร้อมใช้งานชั่วคราว ระบบจะลองใหม่อัตโนมัติ',
                errorCode: $errorCode,
                httpStatus: $httpStatus,
                externalSubcode: $externalSubcode,
            );
        }

        if ($channel === 'facebook' && in_array($errorCode, ['4', '17', '32'], true)) {
            return new ClassificationResult(
                category: 'rate_limited',
                isRetryable: true,
                retryDelaySeconds: self::backoff($attempt),
                messageTh: 'Facebook API จำกัดการเรียกใช้งานชั่วคราว ระบบจะลองใหม่อัตโนมัติ',
                errorCode: $errorCode,
                httpStatus: $httpStatus,
                externalSubcode: $externalSubcode,
            );
        }

        if ($channel === 'facebook' && (
            $errorCode === '190'
            || str_contains($text, '"code":190')
            || str_contains($text, 'code 190')
            || str_contains($text, 'session has expired')
            || str_contains($text, 'error validating access token')
        )) {
            return new ClassificationResult(
                category: 'auth_required',
                isRetryable: false,
                retryDelaySeconds: 0,
                messageTh: 'สิทธิ์ Facebook หรือ Access Token หมดอายุ กรุณาเชื่อมต่อใหม่',
                errorCode: '190',
                httpStatus: $httpStatus,
                externalSubcode: $externalSubcode,
            );
        }

        if ($channel === 'facebook' && (
            $errorCode === '200'
            || str_contains($text, '"code":200')
            || str_contains($text, 'permissions error')
            || str_contains($text, 'permission')
        )) {
            return new ClassificationResult(
                category: 'auth_required',
                isRetryable: false,
                retryDelaySeconds: 0,
                messageTh: 'สิทธิ์ Facebook ไม่เพียงพอ กรุณาตรวจสอบ Permissions ของแอป',
                errorCode: $errorCode ?: '200',
                httpStatus: $httpStatus,
                externalSubcode: $externalSubcode,
            );
        }

        if (str_contains($text, '429') || str_contains($text, 'rate limit')) {
            return new ClassificationResult(
                category: 'rate_limited',
                isRetryable: true,
                retryDelaySeconds: self::backoff($attempt),
                messageTh: $channel === 'facebook'
                    ? 'Facebook API จำกัดการเรียกใช้งานชั่วคราว ระบบจะลองใหม่อัตโนมัติ'
                    : 'บริการปลายทางจำกัดการเรียกใช้งานชั่วคราว ระบบจะลองใหม่อัตโนมัติ',
                errorCode: $errorCode,
                httpStatus: $httpStatus,
                externalSubcode: $externalSubcode,
            );
        }

        if (
            str_contains($text, 'timeout')
            || str_contains($text, 'timed out')
            || str_contains($text, 'connection')
            || str_contains($text, 'could not resolve')
            || str_contains($text, 'temporarily unavailable')
        ) {
            return new ClassificationResult(
                category: str_contains($text, 'timeout') || str_contains($text, 'timed out')
                    ? 'timeout'
                    : 'network_error',
                isRetryable: true,
                retryDelaySeconds: self::backoff($attempt),
                messageTh: 'ไม่สามารถเชื่อมต่อบริการภายนอกได้ชั่วคราว ระบบจะลองใหม่อัตโนมัติ',
                errorCode: $errorCode,
                httpStatus: $httpStatus,
                externalSubcode: $externalSubcode,
            );
        }

        return new ClassificationResult(
            category: 'non_retryable',
            isRetryable: false,
            retryDelaySeconds: 0,
            messageTh: 'เผยแพร่ไม่สำเร็จ กรุณาตรวจสอบรายละเอียดข้อผิดพลาด',
            errorCode: $errorCode,
            httpStatus: $httpStatus,
            externalSubcode: $externalSubcode,
        );
    }

    private static function backoff(int $attempt): int
    {
        $attempt = max(1, min(5, $attempt));

        return self::BACKOFF[$attempt];
    }

    private static function extractErrorCode(mixed $payload): ?string
    {
        if (! is_array($payload)) {
            return null;
        }

        $value = data_get($payload, 'error.code')
            ?? data_get($payload, 'code')
            ?? data_get($payload, 'data.status');

        return $value === null ? null : (string) $value;
    }

    private static function extractErrorSubcode(mixed $payload): ?string
    {
        if (! is_array($payload)) {
            return null;
        }

        $value = data_get($payload, 'error.error_subcode');

        return $value === null ? null : (string) $value;
    }
}
