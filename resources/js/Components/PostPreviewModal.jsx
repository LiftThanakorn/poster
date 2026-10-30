import { Monitor, Smartphone, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import FacebookPostPreview from '@/Components/FacebookPostPreview';
import WordPressPostPreview from '@/Components/WordPressPostPreview';
import { FacebookIcon, WordPressIcon } from '@/Components/ChannelIcons';

const TAB_LABELS = {
    wordpress: 'WordPress',
    facebook: 'Facebook',
};

export default function PostPreviewModal({
    open,
    onClose,
    channels = [],
    title,
    wpContent,
    fbCaption,
    fbComment,
    fbComment2,
    actionButtons = [],
    pdfUrl = null,
    pdfName = null,
    imageUrl,
    categoryName,
}) {
    const availableTabs = ['wordpress', 'facebook'].filter((channel) => channels.includes(channel));
    const [activeTab, setActiveTab] = useState(availableTabs[0] || 'wordpress');
    const [viewport, setViewport] = useState('desktop');
    const closeButtonRef = useRef(null);

    useEffect(() => {
        if (!open) return undefined;

        setActiveTab(availableTabs[0] || 'wordpress');        setViewport('desktop');
        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        const handleKeyDown = (event) => {
            if (event.key === 'Escape') {
                onClose();
            }
        };

        window.addEventListener('keydown', handleKeyDown);
        window.setTimeout(() => closeButtonRef.current?.focus(), 0);

        return () => {
            document.body.style.overflow = previousOverflow;
            window.removeEventListener('keydown', handleKeyDown);
        };
    }, [open]);

    if (!open) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-slate-950/60 p-0 sm:items-center sm:p-6">
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="post-preview-title"
                className="flex max-h-[96vh] w-full max-w-6xl flex-col overflow-hidden rounded-t-2xl bg-slate-50 shadow-2xl sm:max-h-[90vh] sm:rounded-2xl"
            >                <div className="flex items-start justify-between gap-4 border-b border-slate-200 bg-white px-4 py-4 sm:px-6">
                    <div>
                        <h2 id="post-preview-title" className="text-lg font-bold text-slate-900">
                            ดูตัวอย่างก่อนเผยแพร่
                        </h2>
                        <p className="mt-1 text-xs text-slate-500">
                            หน้านี้เป็นเพียงตัวอย่างและจะไม่ส่งโพสต์ไปยังปลายทาง
                        </p>
                    </div>
                    <button
                        ref={closeButtonRef}
                        type="button"
                        onClick={onClose}
                        className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500"
                        aria-label="ปิดหน้าดูตัวอย่าง"
                    >
                        <X className="h-5 w-5" />
                    </button>
                </div>

                <div className="flex flex-col gap-3 border-b border-slate-200 bg-white px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                    {availableTabs.length > 1 ? (
                        <div className="flex gap-1 rounded-xl bg-slate-100 p-1" role="tablist" aria-label="ช่องทางตัวอย่าง">
                            {availableTabs.map((channel) => (
                                <button
                                    key={channel}
                                    type="button"
                                    role="tab"
                                    aria-selected={activeTab === channel}
                                    onClick={() => setActiveTab(channel)}
                                    className={`inline-flex items-center gap-1.5 rounded-lg px-4 py-2 text-sm font-semibold transition-colors ${
                                        activeTab === channel
                                            ? 'bg-white text-[#000080] shadow-sm'
                                            : 'text-slate-600 hover:text-slate-900'
                                    }`}
                                >
                                    {channel === 'facebook' ? (
                                        <FacebookIcon className="h-4 w-4 text-[#1877F2]" />
                                    ) : (
                                        <WordPressIcon className="h-4 w-4 text-[#21759B]" />
                                    )}
                                    {TAB_LABELS[channel]}
                                </button>
                            ))}
                        </div>
                    ) : (
                        <div className="flex items-center gap-1.5 text-sm font-semibold text-slate-700">
                            {availableTabs[0] === 'facebook' ? (
                                <FacebookIcon className="h-4 w-4 text-[#1877F2]" />
                            ) : (
                                <WordPressIcon className="h-4 w-4 text-[#21759B]" />
                            )}
                            <span>{TAB_LABELS[availableTabs[0]] || 'ยังไม่ได้เลือกช่องทาง'}</span>
                        </div>
                    )}

                    <div className="flex gap-1 rounded-xl bg-slate-100 p-1" aria-label="ขนาดหน้าจอตัวอย่าง">
                        <button
                            type="button"
                            onClick={() => setViewport('desktop')}
                            className={`inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-xs font-semibold ${
                                viewport === 'desktop' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500'
                            }`}
                        >
                            <Monitor className="h-4 w-4" />
                            เดสก์ท็อป
                        </button>                        <button
                            type="button"
                            onClick={() => setViewport('mobile')}
                            className={`inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-xs font-semibold ${
                                viewport === 'mobile' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500'
                            }`}
                        >
                            <Smartphone className="h-4 w-4" />
                            มือถือ
                        </button>
                    </div>
                </div>

                <div className="flex-1 overflow-y-auto p-4 sm:p-6">
                    {activeTab === 'wordpress' && availableTabs.includes('wordpress') && (
                        <WordPressPostPreview
                            title={title}
                            content={wpContent}
                            imageUrl={imageUrl}
                            categoryName={categoryName}
                            viewport={viewport}
                            actionButtons={actionButtons}
                            pdfUrl={pdfUrl}
                            pdfName={pdfName}
                        />
                    )}

                    {activeTab === 'facebook' && availableTabs.includes('facebook') && (
                        <FacebookPostPreview
                            caption={fbCaption}
                            firstComment={fbComment}
                            secondComment={fbComment2}
                            imageUrl={imageUrl}
                            viewport={viewport}
                            actionButtons={actionButtons}
                            pdfUrl={pdfUrl}
                        />
                    )}

                    {availableTabs.length === 0 && (                        <div className="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center text-sm text-slate-500">
                            กรุณาเลือก WordPress หรือ Facebook อย่างน้อย 1 ช่องทางก่อนดูตัวอย่าง
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
