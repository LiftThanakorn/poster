<?php

namespace App\Services;

use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode as EndroidQrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;

class QrCodeGeneratorService
{
    /**
     * Generate raw SVG markup for a given text/URL.
     */
    public function generateSvg(
        string $content,
        int $size = 400,
        int $margin = 16,
        string $errorCorrection = 'H',
        string $darkHex = '#000000',
        string $lightHex = '#ffffff',
        bool $transparentBg = false,
    ): string {
        $qrCode = $this->buildQrCode($content, $size, $margin, $errorCorrection, $darkHex, $lightHex, $transparentBg);

        return (new SvgWriter)->write($qrCode)->getString();
    }

    /**
     * Generate binary PNG string for a given text/URL.
     */
    public function generatePng(
        string $content,
        int $size = 400,
        int $margin = 16,
        string $errorCorrection = 'H',
        string $darkHex = '#000000',
        string $lightHex = '#ffffff',
        bool $transparentBg = false,
    ): string {
        $qrCode = $this->buildQrCode($content, $size, $margin, $errorCorrection, $darkHex, $lightHex, $transparentBg);

        return (new PngWriter)->write($qrCode)->getString();
    }

    private function buildQrCode(
        string $content,
        int $size,
        int $margin,
        string $errorCorrection,
        string $darkHex,
        string $lightHex,
        bool $transparentBg,
    ): EndroidQrCode {
        $ecc = match (strtoupper($errorCorrection)) {
            'L' => ErrorCorrectionLevel::Low,
            'M' => ErrorCorrectionLevel::Medium,
            'Q' => ErrorCorrectionLevel::Quartile,
            default => ErrorCorrectionLevel::High,
        };

        return new EndroidQrCode(
            data: $content,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: $ecc,
            size: max(100, min(2000, $size)),
            margin: max(0, min(100, $margin)),
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
            foregroundColor: $this->hexToColor($darkHex),
            backgroundColor: $transparentBg ? new Color(255, 255, 255, 127) : $this->hexToColor($lightHex),
        );
    }

    private function hexToColor(string $hex): Color
    {
        $clean = ltrim($hex, '#');
        if (strlen($clean) === 3) {
            $clean = $clean[0].$clean[0].$clean[1].$clean[1].$clean[2].$clean[2];
        }

        if (strlen($clean) !== 6) {
            return new Color(0, 0, 0);
        }

        return new Color(
            (int) hexdec(substr($clean, 0, 2)),
            (int) hexdec(substr($clean, 2, 2)),
            (int) hexdec(substr($clean, 4, 2)),
        );
    }
}
