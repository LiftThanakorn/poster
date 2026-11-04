import QRCode from 'qrcode';

/**
 * Generates high-resolution PNG Data URL for Konva canvas embedding and preview.
 *
 * @param {string} url
 * @param {object} options
 * @returns {Promise<string>}
 */
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
