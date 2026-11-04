# Reference Documentation: Dynamic QR Code Architecture, Generators & Konva Canvas Integration

---

## 1. Overview & Ecosystem Standards

This document establishes grounded, authoritative API specifications and technical patterns for:
1. Server-side QR Code generation (PHP 8.3 / Laravel 13 using `endroid/qr-code` v6.0 + `bacon/bacon-qr-code` v3.1).
2. Client-side QR generation & Canvas rendering (React 18 / `qrcode` v1.5 / `react-konva` 18.2 / `konva` 10.7).
3. Low-latency HTTP Redirection (HTTP 302) and Privacy-Compliant Scan Telemetry (Salted SHA-256 IP Hashing via post-response terminating callback).

---

## 2. Server-Side QR Code Engine: `endroid/qr-code` (v6.0.x for PHP 8.3)

### 2.1 Package Metadata & Audit Note
- **Package:** `endroid/qr-code: ^6.0` (locks `6.0.9` on PHP 8.3; v6.1+ requires PHP 8.4)
- **Underlying Matrix Engine:** `bacon/bacon-qr-code: ^3.1`
- **Why `endroid/qr-code` instead of bare `bacon/bacon-qr-code`:** Bare `bacon/bacon-qr-code` v3 only ships `SvgImageBackEnd`, `EpsImageBackEnd`, and `ImagickImageBackEnd` (no `ext-gd` backend). Since this environment provides `ext-gd` and `ext-xmlwriter` without `ext-imagick`, `endroid/qr-code` v6 natively provides both `SvgWriter` (zero image extension needed) and `PngWriter` (native `ext-gd`), plus RGB color support.
- **Official Documentation:** https://github.com/endroid/qr-code

### 2.2 Canonical SVG & PNG Generation (PHP 8.3 / `endroid/qr-code` v6.0)
```php
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

        return (new SvgWriter())->write($qrCode)->getString();
    }

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

        return (new PngWriter())->write($qrCode)->getString();
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

        return new Color(
            hexdec(substr($clean, 0, 2)),
            hexdec(substr($clean, 2, 2)),
            hexdec(substr($clean, 4, 2)),
        );
    }
}
```

---

## 3. Client-Side QR Engine: `qrcode` (v1.5.x) & `react-konva`

### 3.1 Package Metadata
- **Package:** `qrcode: ^1.5.4`
- **Canvas Stack:** `react-konva: 18.2.16`, `konva: ^10.7.0`
- **Official Documentation:** https://github.com/soldair/node-qrcode

### 3.2 Taint-Free Canvas Insertion & Zero DB Bloat Pattern
- Generating the QR Code as an in-memory base64 `data:image/png;base64,...` URL prevents `SecurityError` (Tainted Canvas) when `stage.toDataURL()` exports the poster.
- **Zero DB Bloat Rule:** Do **NOT** persist the `data:image/png;base64,...` string inside `posts.image_editor_data` or `templates.image_editor_data`. Persist only lightweight metadata (`qrCodeId`, `code`, `scanUrl`, `styling`, geometry) and regenerate the Data URL in memory when loading `ImageEditorModal`.

```javascript
import QRCode from 'qrcode';

export async function generateQrDataUrl(url, options = {}) {
    const {
        size = 800,
        margin = 2,
        errorCorrectionLevel = 'H',
        darkColor = '#000000',
        lightColor = '#ffffff',
        transparentBg = false,
    } = options;

    return QRCode.toDataURL(url, {
        width: size,
        margin,
        errorCorrectionLevel,
        color: {
            dark: darkColor,
            light: transparentBg ? '#00000000' : lightColor,
        },
    });
}
```

### 3.3 Konva Transformer Strict 1:1 Lock
In `konva` 10.x, setting `keepRatio={true}` alone only locks corner anchors — side anchors (`middle-left`, `middle-right`, `top-center`, `bottom-center`) still stretch non-uniformly and distort QR finder patterns. Therefore, QR elements **MUST** restrict `enabledAnchors` to the four corners:

```jsx
<Transformer
    ref={transformerRef}
    name="editor-transformer"
    rotateEnabled
    flipEnabled={false}
    keepRatio={item.type === 'image' || item.type === 'qr'}
    enabledAnchors={
        item.type === 'qr'
            ? ['top-left', 'top-right', 'bottom-left', 'bottom-right']
            : undefined
    }
    boundBoxFunc={(oldBox, newBox) => {
        const minSize = item.type === 'qr' ? 64 : 20;
        return newBox.width < minSize || newBox.height < minSize ? oldBox : newBox;
    }}
/>
```

---

## 4. Redirection, Telemetry, Subdirectory Routing & Security

### 4.1 HTTP 302 + Strict Cache Invalidation
- Always return `302 Found` with `Cache-Control: no-store, no-cache, must-revalidate, max-age=0` and `Pragma: no-cache` so LINE/Facebook In-App Browsers never cache stale destinations.
- Whenever a `QrCode` model is updated or deleted (`saved` / `deleted` model events), immediately flush `Cache::forget("qr_code:{$code}")` (including `$qrCode->getOriginal('code')`).

### 4.2 Post-Response Telemetry (`app()->terminating`)
- Instead of dispatching onto the `database` queue (which stalls if no Supervisor worker is running or spawns costly OS processes on Windows), record scan telemetry inside `app()->terminating(...)` (or inline during `app()->runningUnitTests()`), wrapped in `try/catch` so telemetry errors can never break user redirection.

### 4.3 Subdirectory (`/poster`) & Localhost Protection
- Always resolve scan URLs via Laravel named route `route('qr.redirect', ['code' => $code])` (or configurable `config('app.qr_public_url')` base if set) so deployments under `/poster` work seamlessly.
- Warn admins in the UI when the resolved scan URL points to `localhost` or `127.0.0.1`.
