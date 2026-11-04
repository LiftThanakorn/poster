import React, { useState, useEffect } from 'react';
import { X, Search, Plus, QrCode as QrIcon, Check, ExternalLink, Loader2 } from 'lucide-react';
import axios from 'axios';
import { generateQrDataUrl } from '@/Utils/qrcode';

export default function QrSelectorPopover({
    open,
    onClose,
    onSelectQr,
    initialTargetUrl = '',
    initialTitle = '',
}) {
    const [activeTab, setActiveTab] = useState('library'); // 'library' | 'create'
    const [qrList, setQrList] = useState([]);
    const [search, setSearch] = useState('');
    const [loading, setLoading] = useState(false);

    // Quick create state
    const [title, setTitle] = useState(initialTitle || '');
    const [targetUrl, setTargetUrl] = useState(initialTargetUrl || '');
    const [code, setCode] = useState('');
    const [darkColor, setDarkColor] = useState('#000000');
    const [lightColor, setLightColor] = useState('#ffffff');
    const [transparentBg, setTransparentBg] = useState(false);
    const [isCreating, setIsCreating] = useState(false);
    const [createError, setCreateError] = useState('');

    useEffect(() => {
        if (!open) return;
        fetchQrList();
        if (initialTargetUrl && !targetUrl) setTargetUrl(initialTargetUrl);
        if (initialTitle && !title) setTitle(initialTitle);
        setCreateError('');
    }, [open]);

    const fetchQrList = async () => {
        setLoading(true);
        try {
            const res = await axios.get(route('qr-codes.index'), {
                headers: { Accept: 'application/json' },
            });
            setQrList(res.data.qr_codes || []);
        } catch {
            // Error
        } finally {
            setLoading(false);
        }
    };

    if (!open) return null;

    const handleSelectExisting = async (qr) => {
        const styling = qr.styling || {};
        const dataUrl = await generateQrDataUrl(qr.scan_url, {
            size: 800,
            margin: Number(styling.margin) || 2,
            errorCorrectionLevel: styling.errorCorrection || 'H',
            darkColor: styling.darkColor || '#000000',
            lightColor: styling.lightColor || '#ffffff',
            transparentBg: styling.transparentBg ?? false,
        });

        onSelectQr({
            qrCodeId: qr.id,
            code: qr.code,
            title: qr.title,
            targetUrl: qr.target_url,
            scanUrl: qr.scan_url,
            src: dataUrl,
            styling: {
                darkColor: styling.darkColor || '#000000',
                lightColor: styling.lightColor || '#ffffff',
                transparentBg: styling.transparentBg ?? false,
                margin: Number(styling.margin) || 2,
                errorCorrection: styling.errorCorrection || 'H',
            },
        });
        onClose();
    };

    const handleQuickCreate = async (e) => {
        e.preventDefault();
        setIsCreating(true);
        setCreateError('');

        try {
            const res = await axios.post(route('qr-codes.store'), {
                title: title.trim(),
                target_url: targetUrl.trim(),
                code: code.trim() || undefined,
                is_active: true,
                styling: {
                    darkColor,
                    lightColor,
                    transparentBg,
                    margin: 2,
                    errorCorrection: 'H',
                },
            }, {
                headers: { Accept: 'application/json' },
            });

            const newQr = res.data.qr_code;
            await handleSelectExisting(newQr);
        } catch (err) {
            setCreateError(
                err.response?.data?.errors?.target_url?.[0] ||
                err.response?.data?.errors?.code?.[0] ||
                err.response?.data?.errors?.title?.[0] ||
                err.response?.data?.message ||
                'ไม่สามารถสร้าง QR Code ได้'
            );
        } finally {
            setIsCreating(false);
        }
    };

    const filteredList = qrList.filter((qr) => {
        const q = search.toLowerCase();
        return qr.title.toLowerCase().includes(q) || qr.code.toLowerCase().includes(q);
    });

    return (
        <div
            className="fixed inset-0 z-[60] flex items-center justify-center bg-slate-950/60 p-4"
            onKeyDown={(e) => {
                if (e.key === 'Escape') {
                    e.stopPropagation();
                    onClose();
                }
            }}
        >
            <div className="flex w-full max-w-lg flex-col overflow-hidden rounded-2xl bg-white shadow-2xl max-h-[85vh]">
                <header className="flex items-center justify-between border-b border-slate-200 px-5 py-3.5">
                    <div className="flex items-center gap-2">
                        <div className="rounded-lg bg-blue-50 p-1.5 text-[#000080]">
                            <QrIcon className="h-4 w-4" />
                        </div>
                        <div>
                            <h3 className="font-bold text-slate-900 text-sm">เพิ่ม Dynamic QR Code ลงในภาพ</h3>
                            <p className="text-[11px] text-slate-500">เลือกจากคลัง หรือสร้างรหัสใหม่ทันที</p>
                        </div>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                    >
                        <X className="h-4 w-4" />
                    </button>
                </header>

                {/* Tabs */}
                <div className="flex border-b border-slate-200 bg-slate-50 px-5 pt-2">
                    <button
                        type="button"
                        onClick={() => setActiveTab('library')}
                        className={`pb-2.5 px-3 text-xs font-semibold border-b-2 transition ${
                            activeTab === 'library'
                                ? 'border-[#000080] text-[#000080]'
                                : 'border-transparent text-slate-500 hover:text-slate-800'
                        }`}
                    >
                        เลือกจากคลัง QR ({qrList.length})
                    </button>
                    <button
                        type="button"
                        onClick={() => setActiveTab('create')}
                        className={`pb-2.5 px-3 text-xs font-semibold border-b-2 transition ${
                            activeTab === 'create'
                                ? 'border-[#000080] text-[#000080]'
                                : 'border-transparent text-slate-500 hover:text-slate-800'
                        }`}
                    >
                        + สร้าง QR ใหม่ทันที
                    </button>
                </div>

                <div className="flex-1 overflow-y-auto p-5">
                    {activeTab === 'library' ? (
                        <div className="space-y-3">
                            <div className="relative">
                                <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-slate-400" />
                                <input
                                    type="text"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="ค้นหาชื่อหรือรหัส..."
                                    className="w-full rounded-lg border-slate-300 pl-8 text-xs focus:border-blue-500 focus:ring-blue-500"
                                />
                            </div>

                            {loading ? (
                                <div className="py-8 text-center text-xs text-slate-400 flex items-center justify-center gap-2">
                                    <Loader2 className="h-4 w-4 animate-spin text-[#000080]" /> กำลังโหลด...
                                </div>
                            ) : filteredList.length === 0 ? (
                                <div className="py-8 text-center">
                                    <p className="text-xs text-slate-500">
                                        {search ? 'ไม่พบ QR Code ที่ตรงกับคำค้นหา' : 'ยังไม่มี QR Code ในคลัง'}
                                    </p>
                                    <button
                                        type="button"
                                        onClick={() => setActiveTab('create')}
                                        className="mt-2 text-xs font-semibold text-blue-700 hover:underline"
                                    >
                                        คลิกเพื่อสร้าง QR Code ใหม่
                                    </button>
                                </div>
                            ) : (
                                <div className="grid grid-cols-1 gap-2 max-h-80 overflow-y-auto pr-1">
                                    {filteredList.map((qr) => (
                                        <div
                                            key={qr.id}
                                            onClick={() => handleSelectExisting(qr)}
                                            className="flex items-center gap-3 p-2.5 rounded-xl border border-slate-200 hover:border-blue-400 hover:bg-blue-50/40 cursor-pointer transition group"
                                        >
                                            <div className="h-12 w-12 rounded-lg border border-slate-200 bg-white p-1 shrink-0 flex items-center justify-center shadow-xs">
                                                <img src={qr.image_url} alt="" className="h-full w-full object-contain" />
                                            </div>
                                            <div className="flex-1 min-w-0">
                                                <div className="flex items-center justify-between">
                                                    <span className="font-bold text-slate-800 text-xs truncate">{qr.title}</span>
                                                    <span className="text-[10px] text-slate-400 shrink-0 font-mono">/qr/{qr.code}</span>
                                                </div>
                                                <p className="text-[11px] text-slate-500 truncate font-mono mt-0.5">{qr.target_url}</p>
                                            </div>
                                            <button
                                                type="button"
                                                className="shrink-0 px-2.5 py-1 rounded-md bg-blue-50 text-[11px] font-semibold text-blue-700 group-hover:bg-[#000080] group-hover:text-white transition"
                                            >
                                                แทรก
                                            </button>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    ) : (
                        <form onSubmit={handleQuickCreate} className="space-y-3.5">
                            {createError && (
                                <div className="rounded-lg bg-red-50 p-2.5 text-xs text-red-700">
                                    {createError}
                                </div>
                            )}

                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">
                                    ชื่อเรียก QR Code <span className="text-red-500">*</span>
                                </label>
                                <input
                                    type="text"
                                    value={title}
                                    onChange={(e) => setTitle(e.target.value)}
                                    placeholder="เช่น ลิงก์ดาวน์โหลดระเบียบการ"
                                    className="w-full rounded-lg border-slate-300 text-xs focus:border-blue-500 focus:ring-blue-500"
                                    required
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">
                                    URL ปลายทาง (Target URL) <span className="text-red-500">*</span>
                                </label>
                                <input
                                    type="url"
                                    value={targetUrl}
                                    onChange={(e) => setTargetUrl(e.target.value)}
                                    placeholder="https://drive.google.com/..."
                                    className="w-full rounded-lg border-slate-300 text-xs focus:border-blue-500 focus:ring-blue-500 font-mono"
                                    required
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">
                                    รหัสสั้น (เว้นว่างเพื่อให้ระบบสุ่ม 8 ตัวอักษร)
                                </label>
                                <div className="flex rounded-lg shadow-xs">
                                    <span className="inline-flex items-center rounded-l-lg border border-r-0 border-slate-300 bg-slate-50 px-2.5 text-xs text-slate-500 font-mono">
                                        /qr/
                                    </span>
                                    <input
                                        type="text"
                                        value={code}
                                        onChange={(e) => setCode(e.target.value)}
                                        placeholder="quick-link"
                                        className="w-full rounded-none rounded-r-lg border-slate-300 text-xs focus:border-blue-500 focus:ring-blue-500 font-mono"
                                    />
                                </div>
                            </div>

                            <div className="grid grid-cols-2 gap-3 pt-1 border-t border-slate-100">
                                <div>
                                    <label className="text-[11px] text-slate-500 block mb-1">สีตัวโค้ด</label>
                                    <div className="flex items-center gap-1.5">
                                        <input
                                            type="color"
                                            value={darkColor}
                                            onChange={(e) => setDarkColor(e.target.value)}
                                            className="h-7 w-8 cursor-pointer rounded border border-slate-200 p-0.5"
                                        />
                                        <span className="font-mono text-xs text-slate-600">{darkColor}</span>
                                    </div>
                                </div>
                                <div>
                                    <label className="text-[11px] text-slate-500 block mb-1">สีพื้นหลัง</label>
                                    <label className="inline-flex items-center gap-1.5 text-xs text-slate-600 cursor-pointer pt-1">
                                        <input
                                            type="checkbox"
                                            checked={transparentBg}
                                            onChange={(e) => setTransparentBg(e.target.checked)}
                                            className="rounded border-slate-300 text-[#000080]"
                                        />
                                        พื้นหลังโปร่งใส
                                    </label>
                                </div>
                            </div>

                            <div className="pt-3 border-t border-slate-200 flex justify-end gap-2">
                                <button
                                    type="button"
                                    onClick={onClose}
                                    className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                                >
                                    ยกเลิก
                                </button>
                                <button
                                    type="submit"
                                    disabled={isCreating}
                                    className="inline-flex items-center gap-1.5 rounded-lg bg-[#000080] px-4 py-1.5 text-xs font-bold text-white hover:bg-blue-900 disabled:opacity-50"
                                >
                                    {isCreating ? 'กำลังสร้าง...' : 'สร้างและแทรกลงในภาพ'}
                                </button>
                            </div>
                        </form>
                    )}
                </div>
            </div>
        </div>
    );
}
