import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import {
    QrCode as QrIcon,
    Plus,
    Search,
    BarChart3,
    Edit2,
    Trash2,
    Download,
    Copy,
    Check,
    ExternalLink,
    AlertTriangle,
    Eye,
} from 'lucide-react';
import Swal from 'sweetalert2';
import QrCodeFormModal from '@/Components/QrCode/QrCodeFormModal';
import QrCodeAnalyticsModal from '@/Components/QrCode/QrCodeAnalyticsModal';
import QrCodeDownloadModal from '@/Components/QrCode/QrCodeDownloadModal';
import { isLocalhostUrl } from '@/Utils/qrcode';

export default function Index({ auth, qrCodes, filters, metrics, mediaList = [] }) {
    const [search, setSearch] = useState(filters.search || '');
    const [statusFilter, setStatusFilter] = useState(filters.status || 'all');
    const [copiedCode, setCopiedCode] = useState(null);

    // Modals
    const [formModalOpen, setFormModalOpen] = useState(false);
    const [editingQr, setEditingQr] = useState(null);
    const [analyticsModalOpen, setAnalyticsModalOpen] = useState(false);
    const [selectedAnalyticsQr, setSelectedAnalyticsQr] = useState(null);
    const [downloadModalOpen, setDownloadModalOpen] = useState(false);
    const [selectedDownloadQr, setSelectedDownloadQr] = useState(null);

    const handleSearchSubmit = (e) => {
        e.preventDefault();
        router.get(route('qr-codes.index'), {
            search: search.trim() || undefined,
            status: statusFilter !== 'all' ? statusFilter : undefined,
        }, { preserveState: true, replace: true });
    };

    const handleStatusChange = (val) => {
        setStatusFilter(val);
        router.get(route('qr-codes.index'), {
            search: search.trim() || undefined,
            status: val !== 'all' ? val : undefined,
        }, { preserveState: true, replace: true });
    };

    const copyToClipboard = (url, code) => {
        navigator.clipboard.writeText(url);
        setCopiedCode(code);
        setTimeout(() => setCopiedCode(null), 2000);
    };

    const handleDelete = (qr) => {
        if (qr.used_in_count > 0) {
            Swal.fire({
                icon: 'warning',
                title: 'ไม่สามารถลบได้',
                text: `QR Code นี้ถูกใช้งานอยู่ใน ${qr.used_in_count} โพสต์/เทมเพลต กรุณาเปลี่ยนเป็นสถานะ "ปิดใช้งาน" แทนเพื่อไม่ให้ลิงก์ในรูปเสีย`,
                confirmButtonColor: '#000080',
            });
            return;
        }

        Swal.fire({
            title: `ลบ QR Code "${qr.title}"?`,
            text: 'การสแกนหลังจากนี้จะได้รับแจ้งว่าไม่พบรหัส ยืนยันการลบหรือไม่?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'ลบรายการ',
            cancelButtonText: 'ยกเลิก',
        }).then((res) => {
            if (res.isConfirmed) {
                router.delete(route('qr-codes.destroy', qr.id), {
                    preserveScroll: true,
                });
            }
        });
    };

    const hasAnyLocalhost = qrCodes.data?.some((q) => isLocalhostUrl(q.target_url) || isLocalhostUrl(q.scan_url));

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 className="text-xl font-bold leading-tight text-slate-900">
                            จัดการ Dynamic QR Code
                        </h2>
                        <p className="text-xs text-slate-500">
                            สร้างและแก้ไขลิงก์ปลายทางของ QR Code ได้ตลอดเวลา พร้อมรายงานสถิติการสแกน
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={() => {
                            setEditingQr(null);
                            setFormModalOpen(true);
                        }}
                        className="inline-flex items-center gap-1.5 rounded-lg bg-[#000080] px-4 py-2 text-xs font-semibold text-white shadow-xs hover:bg-blue-900 transition"
                    >
                        <Plus className="h-4 w-4" />
                        <span>สร้าง Dynamic QR Code</span>
                    </button>
                </div>
            }
        >
            <Head title="จัดการ Dynamic QR Code" />

            <div className="py-6">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-6">
                    {/* Localhost Environment Warning */}
                    {hasAnyLocalhost && (
                        <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 flex items-start gap-3">
                            <AlertTriangle className="h-5 w-5 text-amber-600 shrink-0 mt-0.5" />
                            <div className="text-xs text-amber-800 space-y-1">
                                <p className="font-semibold">ข้อควรระวังสำหรับสภาพแวดล้อมจำลอง (Localhost / Laragon):</p>
                                <p>
                                    ระบบตรวจพบว่า URL สแกนหรือ URL ปลายทางชี้ไปที่ `localhost` หากผู้ใช้ภายนอกใช้มือถือจริงสแกนจะไม่สามารถเปิดได้ (เนื่องจากโทรศัพท์ไม่ได้อยู่ในเครื่องนี้) ในการใช้งานจริงเมื่อขึ้น Production URL สแกนจะกลายเป็นโดเมนขององค์กรโดยอัตโนมัติ
                                </p>
                            </div>
                        </div>
                    )}

                    {/* Metrics Summary Cards */}
                    <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-xs">
                            <span className="text-xs font-medium text-slate-500">QR Code ทั้งหมด</span>
                            <div className="mt-1 text-2xl font-bold text-slate-900">{metrics.total_qrs}</div>
                        </div>
                        <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-xs">
                            <span className="text-xs font-medium text-emerald-600">เปิดใช้งานอยู่ (Active)</span>
                            <div className="mt-1 text-2xl font-bold text-emerald-700">{metrics.active_qrs}</div>
                        </div>
                        <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-xs">
                            <span className="text-xs font-medium text-blue-600">ยอดสแกนสะสมรวม</span>
                            <div className="mt-1 text-2xl font-bold text-blue-900">{metrics.total_scans.toLocaleString()}</div>
                        </div>
                        <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-xs">
                            <span className="text-xs font-medium text-purple-600">สแกน 7 วันล่าสุด</span>
                            <div className="mt-1 text-2xl font-bold text-purple-900">{metrics.recent_scans.toLocaleString()}</div>
                        </div>
                    </div>

                    {/* Search & Filter Toolbar */}
                    <div className="flex flex-col sm:flex-row gap-3 items-center justify-between bg-white p-4 rounded-xl border border-slate-200 shadow-xs">
                        <form onSubmit={handleSearchSubmit} className="relative w-full sm:w-80">
                            <Search className="absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
                            <input
                                type="text"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="ค้นหาชื่อ, รหัสสั้น, หรือ URL..."
                                className="w-full rounded-lg border-slate-300 pl-9 text-xs focus:border-blue-500 focus:ring-blue-500"
                            />
                        </form>

                        <div className="flex items-center gap-2 w-full sm:w-auto justify-end">
                            <div className="flex rounded-lg border border-slate-200 bg-slate-50 p-1 text-xs">
                                {[
                                    { id: 'all', label: 'ทั้งหมด' },
                                    { id: 'active', label: 'เปิดใช้งาน' },
                                    { id: 'inactive', label: 'ปิดใช้งาน' },
                                ].map((tab) => (
                                    <button
                                        key={tab.id}
                                        type="button"
                                        onClick={() => handleStatusChange(tab.id)}
                                        className={`rounded-md px-3 py-1 font-medium transition ${
                                            statusFilter === tab.id
                                                ? 'bg-white text-slate-900 shadow-xs'
                                                : 'text-slate-500 hover:text-slate-800'
                                        }`}
                                    >
                                        {tab.label}
                                    </button>
                                ))}
                            </div>
                        </div>
                    </div>

                    {/* List Table / Cards */}
                    <div className="rounded-xl border border-slate-200 bg-white shadow-xs overflow-hidden">
                        {qrCodes.data.length === 0 ? (
                            <div className="p-12 text-center">
                                <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-slate-100 text-slate-400 mb-3">
                                    <QrIcon className="h-7 w-7" />
                                </div>
                                <h3 className="font-bold text-slate-800 text-sm">ไม่พบรายการ Dynamic QR Code</h3>
                                <p className="text-xs text-slate-500 max-w-sm mx-auto mt-1">
                                    {search ? 'ไม่พบข้อมูลที่ตรงกับคำค้นหา ลองล้างการค้นหาหรือค้นหาคำอื่น' : 'ยังไม่มี Dynamic QR Code ในระบบ กดสร้างเพื่อนำไปใส่ในโปสเตอร์'}
                                </p>
                                {!search && (
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setEditingQr(null);
                                            setFormModalOpen(true);
                                        }}
                                        className="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-[#000080] px-4 py-2 text-xs font-semibold text-white shadow-xs hover:bg-blue-900"
                                    >
                                        <Plus className="h-4 w-4" /> สร้าง QR Code แรก
                                    </button>
                                )}
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-xs">
                                    <thead className="border-b border-slate-200 bg-slate-50/75 text-slate-600 font-semibold">
                                        <tr>
                                            <th className="px-4 py-3">ภาพตัวอย่าง</th>
                                            <th className="px-4 py-3">ชื่อ / รหัสสั้น</th>
                                            <th className="px-4 py-3">ลิงก์ปลายทาง (Target URL)</th>
                                            <th className="px-4 py-3 text-center">สถานะ</th>
                                            <th className="px-4 py-3 text-center">ยอดสแกน</th>
                                            <th className="px-4 py-3 text-center">การใช้งานในระบบ</th>
                                            <th className="px-4 py-3 text-right">จัดการ</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {qrCodes.data.map((qr) => (
                                            <tr key={qr.id} className="hover:bg-slate-50/60 transition">
                                                {/* QR Thumbnail */}
                                                <td className="px-4 py-3 w-16">
                                                    <div
                                                        onClick={() => {
                                                            setSelectedDownloadQr(qr);
                                                            setDownloadModalOpen(true);
                                                        }}
                                                        className="h-12 w-12 rounded-lg border border-slate-200 bg-white p-1 cursor-pointer hover:border-blue-400 transition flex items-center justify-center group relative shadow-xs"
                                                        title="คลิกเพื่อดาวน์โหลด"
                                                    >
                                                        <img
                                                            src={qr.image_url}
                                                            alt={qr.code}
                                                            className="h-full w-full object-contain"
                                                            loading="lazy"
                                                        />
                                                    </div>
                                                </td>

                                                {/* Title & Short Link */}
                                                <td className="px-4 py-3">
                                                    <div className="font-bold text-slate-900 text-sm">{qr.title}</div>
                                                    <div className="flex items-center gap-1.5 mt-1">
                                                        <span className="font-mono text-[11px] text-[#000080] bg-blue-50 px-2 py-0.5 rounded">
                                                            /qr/{qr.code}
                                                        </span>
                                                        <button
                                                            type="button"
                                                            onClick={() => copyToClipboard(qr.scan_url, qr.code)}
                                                            className="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700 transition"
                                                            title="คัดลอกลิงก์สแกน"
                                                        >
                                                            {copiedCode === qr.code ? (
                                                                <Check className="h-3.5 w-3.5 text-emerald-600" />
                                                            ) : (
                                                                <Copy className="h-3.5 w-3.5" />
                                                            )}
                                                        </button>
                                                    </div>
                                                </td>

                                                {/* Target URL */}
                                                <td className="px-4 py-3 max-w-xs">
                                                    <a
                                                        href={qr.target_url}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="inline-flex items-center gap-1 text-slate-600 hover:text-blue-600 font-mono text-[11px] truncate max-w-full"
                                                        title={qr.target_url}
                                                    >
                                                        <span className="truncate">{qr.target_url}</span>
                                                        <ExternalLink className="h-3 w-3 shrink-0" />
                                                    </a>
                                                </td>

                                                {/* Status */}
                                                <td className="px-4 py-3 text-center">
                                                    {qr.is_redirectable ? (
                                                        <span className="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-[11px] font-semibold text-emerald-700 border border-emerald-200">
                                                            เปิดใช้งาน
                                                        </span>
                                                    ) : (
                                                        <span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-semibold text-slate-600 border border-slate-200">
                                                            ปิดใช้งาน
                                                        </span>
                                                    )}
                                                </td>

                                                {/* Scan Count */}
                                                <td className="px-4 py-3 text-center">
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            setSelectedAnalyticsQr(qr);
                                                            setAnalyticsModalOpen(true);
                                                        }}
                                                        className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-blue-50 text-[#000080] font-bold hover:bg-blue-100 transition"
                                                        title="คลิกเพื่อดูสถิติละเอียด"
                                                    >
                                                        <BarChart3 className="h-3.5 w-3.5" />
                                                        <span>{qr.scan_count.toLocaleString()}</span>
                                                    </button>
                                                </td>

                                                {/* Used In Count */}
                                                <td className="px-4 py-3 text-center">
                                                    {qr.used_in_count > 0 ? (
                                                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-medium bg-amber-50 text-amber-700 border border-amber-200">
                                                            {qr.used_in_count} ชิ้นงาน
                                                        </span>
                                                    ) : (
                                                        <span className="text-slate-400 text-[11px]">-</span>
                                                    )}
                                                </td>

                                                {/* Actions */}
                                                <td className="px-4 py-3 text-right">
                                                    <div className="flex items-center justify-end gap-1">
                                                        <button
                                                            type="button"
                                                            onClick={() => {
                                                                setSelectedDownloadQr(qr);
                                                                setDownloadModalOpen(true);
                                                            }}
                                                            className="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-800"
                                                            title="ดาวน์โหลดไฟล์รูป"
                                                        >
                                                            <Download className="h-4 w-4" />
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() => {
                                                                setEditingQr(qr);
                                                                setFormModalOpen(true);
                                                            }}
                                                            className="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 hover:text-blue-600"
                                                            title="แก้ไขลิงก์ปลายทาง"
                                                        >
                                                            <Edit2 className="h-4 w-4" />
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() => handleDelete(qr)}
                                                            className="rounded-lg p-1.5 text-slate-500 hover:bg-rose-50 hover:text-rose-600"
                                                            title="ลบ QR Code"
                                                        >
                                                            <Trash2 className="h-4 w-4" />
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                </div>
            </div>

            {/* Modals */}
            <QrCodeFormModal
                open={formModalOpen}
                onClose={() => setFormModalOpen(false)}
                qrCode={editingQr}
                mediaList={mediaList}
                onSaved={() => router.reload({ only: ['qrCodes', 'metrics', 'mediaList'] })}
            />

            <QrCodeAnalyticsModal
                open={analyticsModalOpen}
                onClose={() => setAnalyticsModalOpen(false)}
                qrCode={selectedAnalyticsQr}
            />

            <QrCodeDownloadModal
                open={downloadModalOpen}
                onClose={() => setDownloadModalOpen(false)}
                qrCode={selectedDownloadQr}
            />
        </AuthenticatedLayout>
    );
}
