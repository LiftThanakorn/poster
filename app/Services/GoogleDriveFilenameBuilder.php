<?php

namespace App\Services;

class GoogleDriveFilenameBuilder
{
    public const CATEGORY_DAILY = 'รายวัน';

    public const CATEGORY_CONTRACT = 'พนักงานประจำตามสัญญา';

    public const CATEGORY_UNIVERSITY = 'พนักงานมหาวิทยาลัย';

    public const CATEGORY_OTHER = 'พนักงานอื่นๆ';

    public const STAGE_ANNOUNCEMENT = 'รับสมัคร';

    public const STAGE_CANDIDATES = 'ประกาศรายชื่อ';

    public const STAGE_RESULTS = 'ผลการคัดเลือก';

    /**
     * @return array<int, string>
     */
    public static function categories(): array
    {
        return [
            self::CATEGORY_DAILY,
            self::CATEGORY_CONTRACT,
            self::CATEGORY_UNIVERSITY,
            self::CATEGORY_OTHER,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function stages(): array
    {
        return [
            self::STAGE_ANNOUNCEMENT,
            self::STAGE_CANDIDATES,
            self::STAGE_RESULTS,
        ];
    }

    /**
     * Build standard announcement filename according to user specification:
     * "YYYY-MM-DD {stage}-{employeeCategory}-{positionTitle}.pdf"
     * Example: "2026-10-26 รับสมัคร-รายวัน-แม่บ้าน.pdf"
     */
    public static function build(
        string $dateYmd,
        string $stage,
        string $employeeCategory,
        ?string $positionTitle = null,
        ?string $customFilename = null,
    ): string {
        $custom = trim((string) $customFilename);
        if ($custom !== '') {
            return self::sanitizeFilename($custom);
        }

        $date = trim($dateYmd);
        if ($date === '') {
            $date = date('Y-m-d');
        }

        $cleanStage = trim($stage);
        $cleanCategory = trim($employeeCategory);
        $cleanPosition = trim((string) $positionTitle);

        $parts = [];
        if ($cleanStage !== '') {
            $parts[] = $cleanStage;
        }
        if ($cleanCategory !== '') {
            $parts[] = $cleanCategory;
        }
        if ($cleanPosition !== '') {
            $parts[] = $cleanPosition;
        }

        $body = implode('-', $parts);
        $name = trim("{$date} {$body}");

        return self::sanitizeFilename($name);
    }

    public static function buildFilename(
        string $category,
        string $stage,
        string $date,
        ?string $position = null,
        ?string $custom = null,
    ): string {
        return self::build($date, $stage, $category, $position, $custom);
    }

    /**
     * Sanitize filename by stripping invalid filesystem and path traversal characters,
     * ensuring proper .pdf extension.
     */
    public static function sanitizeFilename(string $filename): string
    {
        $name = trim($filename);
        $name = (string) preg_replace('/[\/\\\\:*?"<>|]/u', '-', $name);
        $name = (string) preg_replace('/\.pdf$/i', '', $name);
        $name = (string) preg_replace('/\s+/u', ' ', $name);
        $name = trim($name, " \t\n\r\0\x0B-.");

        if ($name === '') {
            $name = 'announcement_'.date('Y-m-d');
        }

        return "{$name}.pdf";
    }

    /**
     * Normalize Thai folder names for comparison, especially handling spaces around maiyamok (ๆ).
     * e.g. "พนักงานอื่น ๆ" and "พนักงานอื่นๆ" both become "พนักงานอื่นๆ"
     */
    public static function normalizeThaiFolderName(string $name): string
    {
        $normalized = trim($name);
        $normalized = (string) preg_replace('/\s+ๆ/u', 'ๆ', $normalized);
        $normalized = (string) preg_replace('/ๆ\s+/u', 'ๆ ', $normalized);
        $normalized = (string) preg_replace('/\s+/u', ' ', $normalized);

        return mb_strtolower($normalized, 'UTF-8');
    }

    /**
     * Extract Google Drive folder ID from either a raw folder ID or a full Google Drive URL.
     */
    public static function extractFolderId(?string $urlOrId): string
    {
        $input = trim((string) $urlOrId);
        if ($input === '') {
            return '';
        }

        if (preg_match('/folders\/([a-zA-Z0-9_-]+)/i', $input, $matches)) {
            return $matches[1];
        }

        if (preg_match('/[?&]id=([a-zA-Z0-9_-]+)/i', $input, $matches)) {
            return $matches[1];
        }

        return $input;
    }
}
