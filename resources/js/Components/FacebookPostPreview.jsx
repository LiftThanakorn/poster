import { FacebookIcon } from '@/Components/ChannelIcons';

const WORDPRESS_URL_PLACEHOLDER = 'ลิงก์บทความ WordPress จะถูกใส่อัตโนมัติหลังเผยแพร่';

function replacePlaceholders(value = '', { actionButtons = [], pdfUrl = '' } = {}) {
    let result = (value || '')
        .replaceAll('{wordpress_url}', `[${WORDPRESS_URL_PLACEHOLDER}]`)
        .replaceAll('{{wordpress_url}}', `[${WORDPRESS_URL_PLACEHOLDER}]`);

    if (pdfUrl) {
        result = result
            .replaceAll('{pdf_url}', `[ลิงก์ไฟล์ PDF]`)
            .replaceAll('{{pdf_url}}', `[ลิงก์ไฟล์ PDF]`);
    } else {
        result = result
            .replaceAll('{pdf_url}', `[ยังไม่ได้แนบไฟล์ PDF]`)
            .replaceAll('{{pdf_url}}', `[ยังไม่ได้แนบไฟล์ PDF]`);
    }

    result = result
        .replaceAll('{drive_url}', `[https://drive.google.com/file/d/.../view]`)
        .replaceAll('{{drive_url}}', `[https://drive.google.com/file/d/.../view]`);

    const appLinks = (actionButtons || [])
        .filter(btn => (btn.label || '').trim() || (btn.url || '').trim())
        .map((btn, i) => `${i + 1}. ${btn.label || 'ใบสมัคร'}: ${btn.url || '#'}`)
        .join('\n');

    result = result
        .replaceAll('{application_links}', appLinks || '[รายการลิงก์ใบสมัคร]')
        .replaceAll('{{application_links}}', appLinks || '[รายการลิงก์ใบสมัคร]');

    return result;
}

export default function FacebookPostPreview({
    caption,
    firstComment,
    secondComment,
    imageUrl,
    viewport = 'desktop',
    actionButtons = [],
    pdfUrl = null,
}) {
    const previewCaption = replacePlaceholders(caption, { actionButtons, pdfUrl });
    const previewFirstComment = replacePlaceholders(firstComment, { actionButtons, pdfUrl });
    const previewSecondComment = replacePlaceholders(secondComment, { actionButtons, pdfUrl });
    const isMobile = viewport === 'mobile';

    return (
        <div className={`mx-auto w-full transition-all ${isMobile ? 'max-w-[390px]' : 'max-w-xl'}`}>
            <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div className="border-b border-slate-100 px-4 py-3">
                    <div className="flex items-center justify-between gap-3">
                        <span className="inline-flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-[#1877F2]">
                            <FacebookIcon className="h-4 w-4 text-[#1877F2]" />
                            Facebook Preview
                        </span>
                        <span className="rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-medium text-amber-700">
                            ตัวอย่างเท่านั้น • ยังไม่ได้โพสต์
                        </span>
                    </div>
                </div>

                <div className="p-4">
                    <div className="flex items-center gap-3">
                        <FacebookIcon className="h-10 w-10 text-[#1877F2] shrink-0" />
                        <div>
                            <p className="text-sm font-semibold text-slate-900">Facebook Page</p>
                            <p className="text-xs text-slate-500">ตัวอย่างก่อนเผยแพร่</p>
                        </div>
                    </div>

                    <p className="mt-4 whitespace-pre-wrap break-words text-sm leading-6 text-slate-800">
                        {previewCaption || 'ข้อความโพสต์ Facebook จะแสดงที่นี่'}
                    </p>
                </div>

                {imageUrl && (
                    <div className="border-y border-slate-100 bg-slate-100">
                        <img src={imageUrl} alt="" className="max-h-[520px] w-full object-contain" />
                    </div>
                )}

                <div className="px-4 py-3 text-xs text-slate-400">
                    การกดดูตัวอย่างไม่ส่งข้อมูลไปยัง Facebook
                </div>
            </div>

            {previewFirstComment && (
                <div className="mt-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p className="text-xs font-semibold text-slate-500">คอมเมนต์แรก (Comment #1)</p>
                    <div className="mt-3 flex gap-2">
                        <FacebookIcon className="h-8 w-8 text-[#1877F2] shrink-0" />
                        <div className="min-w-0 rounded-2xl bg-slate-100 px-3 py-2">
                            <p className="text-xs font-semibold text-slate-800">Facebook Page</p>
                            <p className="mt-1 whitespace-pre-wrap break-words text-sm leading-5 text-slate-700">
                                {previewFirstComment}
                            </p>
                        </div>
                    </div>
                </div>
            )}

            {previewSecondComment && (
                <div className="mt-3 rounded-2xl border border-blue-200 bg-blue-50/50 p-4 shadow-sm">
                    <p className="text-xs font-semibold text-blue-700">คอมเมนต์ที่ 2 (Comment #2 - ใบสมัคร / รายละเอียด)</p>
                    <div className="mt-3 flex gap-2">
                        <FacebookIcon className="h-8 w-8 text-[#1877F2] shrink-0" />
                        <div className="min-w-0 rounded-2xl bg-white border border-blue-100 px-3 py-2 shadow-xs">
                            <p className="text-xs font-semibold text-slate-800">Facebook Page</p>
                            <p className="mt-1 whitespace-pre-wrap break-words text-sm leading-5 text-slate-700">
                                {previewSecondComment}
                            </p>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
