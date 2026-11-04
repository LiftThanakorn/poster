import React, { useState } from 'react';
import { X, Download, FileCode, Image as ImageIcon } from 'lucide-react';

export default function QrCodeDownloadModal({
    open,
    onClose,
    qrCode,
}) {
    const [format, setFormat] = useState('png');
    const [size, setSize] = useState(600);

    if (!open || !qrCode) return null;

    const handleDownload = () => {
        const url = route('qr.image', {
            code: qrCode.code,
            format,
            size,
            download: 1,
        });
        window.open(url, '_blank');
        onClose();
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 p-4">
            <div className="flex w-full max-w-md flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
                <header className="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                    <div className="flex items-center gap-2.5">
                        <div className="rounded-lg bg-emerald-50 p-2 text-emerald-700">
                            <Download className="h-5 w-5" />
                        </div>
                        <div>
                            <h3 className="font-bold text-slate-900 text-base">
                                ดาวน์โหลด QR Code
                            </h3>
                            <p className="text-xs text-slate-500 font-mono">
                                /qr/{qrCode.code}
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                    >
                        <X className="h-5 w-5" />
                    </button>
                </header>

                <div className="p-6 space-y-4">
                    <div>
                        <label className="block text-xs font-semibold text-slate-700 mb-2">
                            รูปแบบไฟล์ (Format)
                        </label>
                        <div className="grid grid-cols-2 gap-3">
                            <button
                                type="button"
                                onClick={() => setFormat('png')}
                                className={`flex items-center gap-2 p-3 rounded-xl border text-left transition-all ${
                                    format === 'png'
                                        ? 'border-blue-500 bg-blue-50 text-blue-900 font-bold'
                                        : 'border-slate-200 hover:bg-slate-50 text-slate-700'
                                }`}
                            >
                                <ImageIcon className="h-5 w-5 text-blue-600" />
                                <div>
                                    <div className="text-xs">PNG (Bitmap)</div>
                                    <div className="text-[10px] text-slate-500 font-normal">เหมาะสำหรับใส่รูป/เว็บ</div>
                                </div>
                            </button>
                            <button
                                type="button"
                                onClick={() => setFormat('svg')}
                                className={`flex items-center gap-2 p-3 rounded-xl border text-left transition-all ${
                                    format === 'svg'
                                        ? 'border-emerald-500 bg-emerald-50 text-emerald-900 font-bold'
                                        : 'border-slate-200 hover:bg-slate-50 text-slate-700'
                                }`}
                            >
                                <FileCode className="h-5 w-5 text-emerald-600" />
                                <div>
                                    <div className="text-xs">SVG (Vector)</div>
                                    <div className="text-[10px] text-slate-500 font-normal">คมชัด 100% สำหรับโรงพิมพ์</div>
                                </div>
                            </button>
                        </div>
                    </div>

                    {format === 'png' && (
                        <div>
                            <label className="block text-xs font-semibold text-slate-700 mb-2">
                                ขนาดความละเอียด (Pixels)
                            </label>
                            <div className="grid grid-cols-3 gap-2">
                                {[
                                    { s: 300, label: '300px (เล็ก)' },
                                    { s: 600, label: '600px (ปกติ)' },
                                    { s: 1200, label: '1200px (ใหญ่/พิมพ์)' },
                                ].map((item) => (
                                    <button
                                        key={item.s}
                                        type="button"
                                        onClick={() => setSize(item.s)}
                                        className={`p-2 rounded-lg border text-center text-xs transition ${
                                            size === item.s
                                                ? 'border-blue-500 bg-blue-50 text-blue-800 font-semibold'
                                                : 'border-slate-200 hover:bg-slate-50 text-slate-600'
                                        }`}
                                    >
                                        {item.label}
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}
                </div>

                <footer className="border-t border-slate-200 px-6 py-4 flex justify-end gap-2 bg-slate-50">
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                    >
                        ยกเลิก
                    </button>
                    <button
                        type="button"
                        onClick={handleDownload}
                        className="inline-flex items-center gap-1.5 rounded-lg bg-[#000080] px-4 py-2 text-xs font-bold text-white hover:bg-blue-900"
                    >
                        <Download className="h-4 w-4" />
                        ดาวน์โหลด ({format.toUpperCase()})
                    </button>
                </footer>
            </div>
        </div>
    );
}
