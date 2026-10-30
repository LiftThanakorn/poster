import DOMPurify from 'dompurify';
import { WordPressIcon } from '@/Components/ChannelIcons';

const SANITIZE_CONFIG = {
    ALLOWED_TAGS: [
        'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'strong', 'b', 'em', 'i', 'u', 's',
        'ul', 'ol', 'li', 'blockquote', 'a',
        'br', 'hr', 'pre', 'code', 'span',
    ],
    ALLOWED_ATTR: ['href', 'title', 'target', 'rel', 'class'],
    FORBID_TAGS: ['script', 'iframe', 'object', 'embed', 'form', 'input'],
    FORBID_ATTR: ['style'],
};

export default function WordPressPostPreview({
    title,
    content,
    imageUrl,
    categoryName,
    viewport = 'desktop',
    actionButtons = [],
    pdfUrl = null,
    pdfName = null,
}) {
    const sanitizedHtml = DOMPurify.sanitize(content || '<p>ยังไม่มีเนื้อหาบทความ</p>', SANITIZE_CONFIG);
    const isMobile = viewport === 'mobile';
    const validButtons = (actionButtons || []).filter(btn => (btn.label || '').trim() || (btn.url || '').trim());

    return (
        <div className={`mx-auto w-full transition-all ${isMobile ? 'max-w-[390px]' : 'max-w-4xl'}`}>
            <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div className="border-b border-slate-100 px-5 py-3">
                    <div className="flex items-center justify-between gap-3">
                        <span className="inline-flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-[#21759B]">
                            <WordPressIcon className="h-4 w-4 text-[#21759B]" />
                            WordPress Preview
                        </span>
                        <span className="rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-medium text-amber-700">
                            ตัวอย่างเท่านั้น • ยังไม่ได้เผยแพร่
                        </span>
                    </div>
                </div>

                {imageUrl && (
                    <div className="bg-slate-100">
                        <img
                            src={imageUrl}
                            alt=""
                            className={`w-full object-cover ${isMobile ? 'max-h-64' : 'max-h-[420px]'}`}
                        />
                    </div>
                )}

                <article className={`px-5 py-6 ${isMobile ? '' : 'sm:px-8 sm:py-8'}`}>
                    {categoryName && (
                        <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-blue-700">
                            {categoryName}
                        </p>
                    )}
                    <h1 className={`font-bold leading-tight text-slate-950 ${isMobile ? 'text-2xl' : 'text-3xl sm:text-4xl'}`}>
                        {title?.trim() || 'หัวข้อข่าวจะแสดงที่นี่'}
                    </h1>
                    <div
                        className={`prose prose-slate mt-6 max-w-none ${isMobile ? 'prose-sm' : 'sm:prose-lg'}`}
                        dangerouslySetInnerHTML={{ __html: sanitizedHtml }}
                    />

                    {validButtons.length > 0 && (
                        <div className="mt-8 rounded-xl border border-slate-200 bg-slate-50 p-4 sm:p-5">
                            <div className="mb-3 flex items-center gap-2 text-xs font-semibold text-slate-700">
                                <svg className="h-4 w-4 text-[#000080]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                                <span>ลิงก์และเอกสารสำคัญ</span>
                            </div>
                            <div className="flex flex-wrap gap-3">
                                {validButtons.map((btn, idx) => {
                                    const targetUrl = btn.url === '{drive_url}' 
                                        ? 'https://drive.google.com/file/d/.../view' 
                                        : (btn.url === '{pdf_url}' ? (pdfUrl || '#') : (btn.url || '#'));
                                    return (
                                        <a
                                            key={idx}
                                            href={targetUrl}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="inline-flex items-center gap-2 rounded-lg bg-[#000080] px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-blue-900"
                                        >
                                            <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                            </svg>
                                            <span>{btn.label || 'ลิงก์ใบสมัคร / เอกสาร'}</span>
                                        </a>
                                    );
                                })}
                            </div>
                        </div>
                    )}

                    {pdfUrl && (
                        <div className="mt-8 overflow-hidden rounded-xl border border-slate-300 bg-white shadow-sm">
                            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-slate-100 px-4 py-3">
                                <div className="flex items-center gap-2 text-sm font-semibold text-slate-900">
                                    <svg className="h-5 w-5 text-red-600" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M20 2H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-8.5 7.5c0 .83-.67 1.5-1.5 1.5H9v2H7.5V7H10c.83 0 1.5.67 1.5 1.5v1zm5 2c0 .83-.67 1.5-1.5 1.5h-2.5V7H15c.83 0 1.5.67 1.5 1.5v2.5zm4-3H19v1h1.5V11H19v2h-1.5V7h3v1.5zM9 9.5h1v-1H9v1zm5.5 1.5H15v-2.5h-.5v2.5zM4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6z"/>
                                    </svg>
                                    <span>{pdfName || 'เอกสารแนบ PDF'}</span>
                                </div>
                                <a
                                    href={pdfUrl}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="inline-flex items-center gap-1.5 rounded bg-red-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-red-700"
                                >
                                    <svg className="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM17 13l-5 5-5-5h3V9h4v4h3z"/>
                                    </svg>
                                    ดาวน์โหลดไฟล์ PDF
                                </a>
                            </div>
                            <div className="relative h-[480px] w-full bg-slate-700">
                                <iframe
                                    src={`${pdfUrl}#toolbar=1&navpanes=0`}
                                    className="h-full w-full border-none"
                                    title="ตัวอย่างเอกสาร PDF"
                                />
                            </div>
                        </div>
                    )}
                </article>
            </div>
        </div>
    );
}
