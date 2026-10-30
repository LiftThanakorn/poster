<?php

namespace Tests\Unit;

use App\Services\GoogleDriveFilenameBuilder;
use PHPUnit\Framework\TestCase;

class GoogleDriveFilenameBuilderTest extends TestCase
{
    public function test_it_builds_standard_filename_formula_with_position(): void
    {
        $filename = GoogleDriveFilenameBuilder::buildFilename(
            category: 'รายวัน',
            stage: 'รับสมัคร',
            date: '2026-10-26',
            position: 'แม่บ้าน',
        );

        $this->assertSame('2026-10-26 รับสมัคร-รายวัน-แม่บ้าน.pdf', $filename);
    }

    public function test_it_builds_standard_filename_without_position(): void
    {
        $filename = GoogleDriveFilenameBuilder::buildFilename(
            category: 'พนักงานมหาวิทยาลัย',
            stage: 'ประกาศรายชื่อ',
            date: '2026-10-30',
            position: null,
        );

        $this->assertSame('2026-10-30 ประกาศรายชื่อ-พนักงานมหาวิทยาลัย.pdf', $filename);
    }

    public function test_it_sanitizes_illegal_filesystem_characters(): void
    {
        $filename = GoogleDriveFilenameBuilder::buildFilename(
            category: 'พนักงานประจำตามสัญญา',
            stage: 'ผลการคัดเลือก',
            date: '2026-11-01',
            position: 'เจ้าหน้าที่/การเงิน:ฝ่าย 1 * 2',
        );

        $this->assertStringNotContainsString('/', $filename);
        $this->assertStringNotContainsString(':', $filename);
        $this->assertStringNotContainsString('*', $filename);
        $this->assertStringEndsWith('.pdf', $filename);
    }

    public function test_it_normalizes_thai_folder_names_with_or_without_spaces(): void
    {
        $this->assertSame(
            'งบประเภทอื่น ๆ',
            GoogleDriveFilenameBuilder::normalizeThaiFolderName('งบประเภทอื่น ๆ')
        );

        $this->assertSame(
            'งบประเภทอื่น ๆ',
            GoogleDriveFilenameBuilder::normalizeThaiFolderName('  พนักงานอื่นๆ  ')
        );

        $this->assertSame(
            'พนักงานรายวัน',
            GoogleDriveFilenameBuilder::normalizeThaiFolderName('รายวัน')
        );

        $this->assertSame(
            'พนักงานรายวัน',
            GoogleDriveFilenameBuilder::normalizeThaiFolderName('พนักงานรายวัน')
        );
    }

    public function test_it_extracts_folder_id_from_full_urls(): void
    {
        $url1 = 'https://drive.google.com/drive/folders/1aBcDeFgHiJkLmNoP_123456';
        $this->assertSame('1aBcDeFgHiJkLmNoP_123456', GoogleDriveFilenameBuilder::extractFolderId($url1));

        $url2 = 'https://drive.google.com/drive/u/0/folders/1aBcDeFgHiJkLmNoP_123456?usp=sharing';
        $this->assertSame('1aBcDeFgHiJkLmNoP_123456', GoogleDriveFilenameBuilder::extractFolderId($url2));

        $rawId = '1aBcDeFgHiJkLmNoP_123456';
        $this->assertSame('1aBcDeFgHiJkLmNoP_123456', GoogleDriveFilenameBuilder::extractFolderId($rawId));
    }

    public function test_it_provides_valid_categories_and_stages(): void
    {
        $categories = GoogleDriveFilenameBuilder::categories();
        $this->assertContains('พนักงานรายวัน', $categories);
        $this->assertContains('พนักงานประจำตามสัญญา', $categories);
        $this->assertContains('พนักงานมหาวิทยาลัย', $categories);
        $this->assertContains('งบประเภทอื่น ๆ', $categories);

        $stages = GoogleDriveFilenameBuilder::stages();
        $this->assertContains('รับสมัคร', $stages);
        $this->assertContains('ประกาศรายชื่อ', $stages);
        $this->assertContains('ผลการคัดเลือก', $stages);
    }
}
