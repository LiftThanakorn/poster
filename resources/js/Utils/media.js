export function resolveStorageUrl(pathOrMedia) {
    if (!pathOrMedia) return '';
    if (typeof pathOrMedia === 'object') {
        if (pathOrMedia.url) return pathOrMedia.url;
        pathOrMedia = pathOrMedia.file_path;
    }
    if (!pathOrMedia || typeof pathOrMedia !== 'string') return '';
    if (
        pathOrMedia.startsWith('http://') ||
        pathOrMedia.startsWith('https://') ||
        pathOrMedia.startsWith('blob:') ||
        pathOrMedia.startsWith('data:')
    ) {
        return pathOrMedia;
    }

    const clean = pathOrMedia.replace(/^\/?(poster\/)?(storage\/)?/, '');
    const prefix = typeof window !== 'undefined' && window.location.pathname.startsWith('/poster')
        ? '/poster/storage/'
        : '/storage/';

    return `${prefix}${clean}`;
}
