import React, { useState, useEffect } from 'react';
import { X, ExternalLink, QrCode as QrIcon } from 'lucide-react';
import axios from 'axios';
import { generateQrDataUrl, isLocalhostUrl } from '@/Utils/qrcode';

export default function QrCodeFormModal({
    open,
    onClose,
    qrCode = null, // null for create, object for edit
    onSaved,
}) {
    const isEdit = !!qrCode;
    const [title, setTitle] = useState('');
    const [code, setCode] = useState('');
    const [targetUrl, setTargetUrl] = useState('');
    const [isActive, setIsActive] = useState(true);
    const [darkColor, setDarkColor] = useState('#000000');
    const [lightColor, setLightColor] = useState('#ffffff');
    const [transparentBg, setTransparentBg] = useState(false);
    const [margin, setMargin] = useState(2);
    const [errorCorrection, setErrorCorrection] = useState('H');

    const [previewUrl, setPreviewUrl] = useState('');
    const [isSaving, setIsSaving] = useState(false);
    const [errors, setErrors] = useState({});

    useEffect(() => {
        if (!open) return;
        if (qrCode) {
            setTitle(qrCode.title || '');
            setCode(qrCode.code || '');
            setTargetUrl(qrCode.target_url || '');
            setIsActive(qrCode.is_active ?? true);
            const styling = qrCode.styling || {};
            setDarkColor(styling.darkColor || '#000000');
            setLightColor(styling.lightColor || '#ffffff');
            setTransparentBg(styling.transparentBg ?? false);
            setMargin(styling.margin ?? 2);
            setErrorCorrection(styling.errorCorrection || 'H');
        } else {
            setTitle('');
            setCode('');
            setTargetUrl('');
            setIsActive(true);
            setDarkColor('#000000');
            setLightColor('#ffffff');
            setTransparentBg(false);
            setMargin(2);
            setErrorCorrection('H');
        }
        setErrors({});
    }, [open, qrCode]);

    // Live preview generator
    useEffect(() => {
        if (!open) return;
        let isMounted = true;
        const testCode = code.trim() || 'demo-preview';
        const dummyUrl = typeof window !== 'undefined'
            ? `${window.location.origin}/qr/${testCode}`
            : `https://domain/qr/${testCode}`;

        generateQrDataUrl(dummyUrl, {
            size: 300,
            margin: Number(margin) || 2,
            errorCorrectionLevel: errorCorrection,
            darkColor,
            lightColor,
            transparentBg,
        }).then((dataUrl) => {
            if (isMounted) setPreviewUrl(dataUrl);
        }).catch(() => {});

        return () => { isMounted = false; };
    }, [open, code, darkColor, lightColor, transparentBg, margin, errorCorrection]);

    if (!open) return null;

    const handleSubmit = async (e) => {
        e.preventDefault();
        setIsSaving(true);
        setErrors({});

        const payload = {
            title: title.trim(),
            code: code.trim() || undefined,
            target_url: targetUrl.trim(),
            is_active: isActive,
            styling: {
                darkColor,
                lightColor,
                transparentBg,
                margin: Number(margin) || 2,
                errorCorrection,
            },
        };

        try {
            let res;
            if (isEdit) {
                res = await axios.put(route('qr-codes.update', qrCode.id), payload);
            } else {
                res = await axios.post(route('qr-codes.store'), payload);
            }
            onSaved?.(res.data.qr_code);
            onClose();
        } catch (err) {
            if (err.response?.status === 422 && err.response.data?.errors) {
                setErrors(err.response.data.errors);
            } else {
                setErrors({ general: err.response?.data?.message || 'เกิดข้อผิดพลาดในการบันทึกข้อมูล' });
            }
        } finally {
            setIsSaving(false);
        }
    };

    const hasLocalhostTarget = isLocalhostUrl(targetUrl);

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 p-4">
            <div className="flex w-full max-w-2xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl max-h-[92vh]">
                <header className="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                    <div className="flex items-center gap-2.5">
                        <div className="rounded-lg bg-blue-50 p-2 text-[#000080]">
                            <QrIcon className="h-5 w-5" />
                        </div>
                        <div>
                            <h3 className="font-bold text-slate-900 text-base">
                                {isEdit ? 'แก้ไข Dynamic QR Code' : 'สร้าง Dynamic QR Code ใหม่'}
                            </h3>
                            <p className="text-xs text-slate-500">
                                {isEdit ? 'แก้ไขลิงก์ปลายทาง หรือปรับแต่งดีไซน์' : 'สร้างรหัสสั้นเพื่อนำไปใส่ในโปสเตอร์และปรับเปลี่ยนลิงก์ได้ตลอดเวลา'}
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

                <form onSubmit={handleSubmit} className="flex-1 overflow-y-auto p-6 space-y-4">
                    {errors.general && (
                        <div className="rounded-lg bg-red-50 p-3 text-xs text-red-700">
                            {errors.general}
                        </div>
                    )}

                    <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                        {/* Form Inputs (2 Columns) */}
                        <div className="md:col-span-2 space-y-4">
                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">
                                    ชื่อเรียก QR Code <span className="text-red-500">*</span>
                                </label>
                                <input
                                    type="text"
                                    value={title}
                                    onChange={(e) => setTitle(e.target.value)}
                                    placeholder="เช่น ประกาศรับสมัครงาน ม.ค. 69, ลิงก์ดาวน์โหลดระเบียบการ"
                                    className="w-full rounded-lg border-slate-300 text-sm focus:border-blue-500 focus:ring-blue-500"
                                    required
                                />
                                {errors.title && <p className="mt-1 text-xs text-red-600">{errors.title[0]}</p>}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">
                                    URL ปลายทาง (Target URL) <span className="text-red-500">*</span>
                                </label>
                                <input
                                    type="url"
                                    value={targetUrl}
                                    onChange={(e) => setTargetUrl(e.target.value)}
                                    placeholder="https://drive.google.com/... หรือ https://your-form.com"
                                    className="w-full rounded-lg border-slate-300 text-sm focus:border-blue-500 focus:ring-blue-500 font-mono"
                                    required
                                />
                                {hasLocalhostTarget && (
                                    <p className="mt-1 text-xs text-amber-600 bg-amber-50 p-2 rounded border border-amber-200">
                                        ⚠️ คำเตือน: URL ปลายทางชี้ไปที่ localhost/เครื่องส่วนตัว หากผู้ใช้ภายนอกสแกนจะไม่สามารถเข้าถึงได้
                                    </p>
                                )}
                                {errors.target_url && <p className="mt-1 text-xs text-red-600">{errors.target_url[0]}</p>}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">
                                    รหัสสั้นกำหนดเอง (Slug) <span className="text-slate-400 font-normal">(เว้นว่างเพื่อให้ระบบสุ่ม 8 ตัวอักษร)</span>
                                </label>
                                <div className="flex rounded-lg shadow-xs">
                                    <span className="inline-flex items-center rounded-l-lg border border-r-0 border-slate-300 bg-slate-50 px-3 text-xs text-slate-500 font-mono">
                                        /qr/
                                    </span>
                                    <input
                                        type="text"
                                        value={code}
                                        onChange={(e) => setCode(e.target.value)}
                                        placeholder="job-2026-01"
                                        className="w-full rounded-none rounded-r-lg border-slate-300 text-sm focus:border-blue-500 focus:ring-blue-500 font-mono"
                                    />
                                </div>
                                {errors.code && <p className="mt-1 text-xs text-red-600">{errors.code[0]}</p>}
                            </div>

                            {/* Styling row */}
                            <div className="border-t border-slate-100 pt-3">
                                <span className="block text-xs font-semibold text-slate-700 mb-2">ปรับแต่งการแสดงผล</span>
                                <div className="grid grid-cols-2 gap-3">
                                    <div>
                                        <label className="text-[11px] text-slate-500 block mb-1">สีตัวโค้ด</label>
                                        <div className="flex items-center gap-2">
                                            <input
                                                type="color"
                                                value={darkColor}
                                                onChange={(e) => setDarkColor(e.target.value)}
                                                className="h-8 w-10 cursor-pointer rounded border border-slate-200 p-0.5"
                                            />
                                            <span className="font-mono text-xs text-slate-600">{darkColor}</span>
                                        </div>
                                    </div>
                                    <div>
                                        <label className="text-[11px] text-slate-500 block mb-1">สีพื้นหลัง</label>
                                        <div className="flex items-center gap-2">
                                            <input
                                                type="color"
                                                disabled={transparentBg}
                                                value={lightColor}
                                                onChange={(e) => setLightColor(e.target.value)}
                                                className="h-8 w-10 cursor-pointer rounded border border-slate-200 p-0.5 disabled:opacity-40"
                                            />
                                            <label className="inline-flex items-center gap-1 text-xs text-slate-600 cursor-pointer">
                                                <input
                                                    type="checkbox"
                                                    checked={transparentBg}
                                                    onChange={(e) => setTransparentBg(e.target.checked)}
                                                    className="rounded border-slate-300 text-[#000080]"
                                                />
                                                โปร่งใส
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {/* Active Toggle */}
                            <div className="flex items-center justify-between border-t border-slate-100 pt-3">
                                <div>
                                    <span className="text-xs font-semibold text-slate-700 block">เปิดใช้งาน (Active)</span>
                                    <span className="text-[11px] text-slate-500">หากปิดการใช้งาน ผู้สแกนจะเห็นหน้าแจ้งเตือนว่าลิงก์ไม่พร้อมใช้งาน</span>
                                </div>
                                <label className="relative inline-flex cursor-pointer items-center">
                                    <input
                                        type="checkbox"
                                        checked={isActive}
                                        onChange={(e) => setIsActive(e.target.checked)}
                                        className="peer sr-only"
                                    />
                                    <div className="peer h-6 w-11 rounded-full bg-slate-200 after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:border after:border-slate-300 after:bg-white after:transition-all after:content-[''] peer-checked:bg-[#000080] peer-checked:after:translate-x-full peer-checked:after:border-white"></div>
                                </label>
                            </div>
                        </div>

                        {/* Live Preview Column */}
                        <div className="flex flex-col items-center justify-center rounded-xl bg-slate-50 p-4 border border-slate-200">
                            <span className="text-xs font-semibold text-slate-600 mb-2">ตัวอย่าง QR Code สด</span>
                            <div className="p-3 bg-white rounded-lg shadow-xs border border-slate-200 flex items-center justify-center min-h-[160px] min-w-[160px]">
                                {previewUrl ? (
                                    <img src={previewUrl} alt="Preview" className="h-36 w-36 object-contain" />
                                ) : (
                                    <span className="text-xs text-slate-400">กำลังเตรียมตัวอย่าง...</span>
                                )}
                            </div>
                            <span className="text-[11px] font-mono text-slate-500 mt-2 text-center break-all">
                                /qr/{code.trim() || 'auto'}
                            </span>
                        </div>
                    </div>

                    <footer className="border-t border-slate-200 pt-4 flex items-center justify-end gap-2">
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                        >
                            ยกเลิก
                        </button>
                        <button
                            type="submit"
                            disabled={isSaving}
                            className="inline-flex items-center gap-2 rounded-lg bg-[#000080] px-5 py-2 text-sm font-bold text-white hover:bg-blue-900 disabled:opacity-50"
                        >
                            {isSaving ? 'กำลังบันทึก...' : (isEdit ? 'บันทึกการแก้ไข' : 'สร้าง QR Code')}
                        </button>
                    </footer>
                </form>
            </div>
        </div>
    );
}
