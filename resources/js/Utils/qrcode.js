import QRCode from 'qrcode';

/**
 * Loads an HTML Image object from a source URL.
 *
 * @param {string} src
 * @returns {Promise<HTMLImageElement>}
 */
function loadImage(src) {
    return new Promise((resolve, reject) => {
        const img = new Image();
        if (/^https?:\/\//i.test(src)) {
            const parsed = new URL(src, window.location.href);
            if (parsed.origin !== window.location.origin) {
                img.crossOrigin = 'anonymous';
            }
        }
        img.onload = () => resolve(img);
        img.onerror = (err) => reject(err);
        img.src = src;
    });
}

/**
 * Generates high-resolution PNG Data URL for Konva canvas embedding, preview, and downloads,
 * with optional centered logo overlay (with rounded punchout background).
 *
 * @param {string} url
 * @param {object} options
 * @returns {Promise<string>}
 */
export async function generateQrDataUrl(url, options = {}) {
    const safeUrl = (typeof url === 'string' && url.trim() !== '')
        ? url.trim()
        : 'https://example.com';

    const {
        size = 800,
        margin = 2,
        errorCorrectionLevel = options.logoSrc ? 'H' : (options.errorCorrectionLevel || 'H'),
        darkColor = '#000000',
        lightColor = '#ffffff',
        transparentBg = false,
        logoSrc = null,
        logoSizeRatio = 0.22, // 22% of QR width is optimal for error recovery
    } = options;

    const baseDataUrl = await QRCode.toDataURL(safeUrl, {
        width: size,
        margin,
        errorCorrectionLevel,
        color: {
            dark: darkColor,
            light: transparentBg ? '#00000000' : lightColor,
        },
    });

    if (!logoSrc) {
        return baseDataUrl;
    }

    try {
        const [qrImg, logoImg] = await Promise.all([
            loadImage(baseDataUrl),
            loadImage(logoSrc),
        ]);

        const canvas = document.createElement('canvas');
        canvas.width = size;
        canvas.height = size;
        const ctx = canvas.getContext('2d');
        if (!ctx) return baseDataUrl;

        // Draw base QR code
        ctx.drawImage(qrImg, 0, 0, size, size);

        // Calculate Logo Dimensions
        const logoWidth = size * logoSizeRatio;
        const logoAspect = (logoImg.naturalWidth && logoImg.naturalHeight)
            ? (logoImg.naturalWidth / logoImg.naturalHeight)
            : 1;

        let drawW = logoWidth;
        let drawH = logoWidth / logoAspect;
        if (drawH > logoWidth) {
            drawH = logoWidth;
            drawW = logoWidth * logoAspect;
        }

        const centerX = size / 2;
        const centerY = size / 2;
        const padding = Math.max(8, Math.round(size * 0.025));

        // Draw white punchout background badge
        const badgeW = drawW + padding * 2;
        const badgeH = drawH + padding * 2;
        const badgeX = centerX - badgeW / 2;
        const badgeY = centerY - badgeH / 2;
        const radius = Math.min(badgeW, badgeH) * 0.22;

        ctx.save();
        ctx.fillStyle = '#ffffff';
        ctx.shadowColor = 'rgba(0, 0, 0, 0.15)';
        ctx.shadowBlur = Math.round(size * 0.015);
        ctx.shadowOffsetX = 0;
        ctx.shadowOffsetY = Math.round(size * 0.005);

        ctx.beginPath();
        if (ctx.roundRect) {
            ctx.roundRect(badgeX, badgeY, badgeW, badgeH, radius);
        } else {
            ctx.rect(badgeX, badgeY, badgeW, badgeH);
        }
        ctx.fill();
        ctx.restore();

        // Draw punchout border
        ctx.save();
        ctx.lineWidth = Math.max(2, Math.round(size * 0.004));
        ctx.strokeStyle = '#f1f5f9';
        ctx.beginPath();
        if (ctx.roundRect) {
            ctx.roundRect(badgeX, badgeY, badgeW, badgeH, radius);
        } else {
            ctx.rect(badgeX, badgeY, badgeW, badgeH);
        }
        ctx.stroke();
        ctx.restore();

        // Draw centered logo
        const logoX = centerX - drawW / 2;
        const logoY = centerY - drawH / 2;
        ctx.drawImage(logoImg, logoX, logoY, drawW, drawH);

        return canvas.toDataURL('image/png', 1.0);
    } catch {
        // Fallback to base QR without logo if logo fails to load (e.g. invalid image)
        return baseDataUrl;
    }
}

/**
 * Checks if a URL points to localhost or private loopback addresses.
 *
 * @param {string} urlString
 * @returns {boolean}
 */
export function isLocalhostUrl(urlString) {
    if (!urlString || typeof urlString !== 'string') return false;
    try {
        const parsed = new URL(urlString, window.location.href);
        const host = parsed.hostname.toLowerCase();
        return (
            host === 'localhost' ||
            host === '127.0.0.1' ||
            host === '[::1]' ||
            host.endsWith('.test') ||
            host.endsWith('.local')
        );
    } catch {
        return false;
    }
}
