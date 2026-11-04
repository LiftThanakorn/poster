import React, { useState, useEffect } from 'react';
import { X, BarChart3, Smartphone, Laptop, Globe, Calendar, RefreshCw } from 'lucide-react';
import axios from 'axios';

export default function QrCodeAnalyticsModal({
    open,
    onClose,
    qrCode,
}) {
    const [periodDays, setPeriodDays] = useState(30);
    const [loading, setLoading] = useState(false);
    const [data, setData] = useState(null);

    useEffect(() => {
        if (!open || !qrCode) return;
        fetchAnalytics(periodDays);
    }, [open, qrCode, periodDays]);

    const fetchAnalytics = async (days) => {
        setLoading(true);
        try {
            const res = await axios.get(route('qr-codes.analytics', qrCode.id), {
                params: { days },
            });
            setData(res.data);
        } catch {
            // Error handling
        } finally {
            setLoading(false);
        }
    };

    if (!open || !qrCode) return null;

    const timeline = data?.timeline || [];
    const maxScans = Math.max(...timeline.map((t) => t.scans), 1);
    const devices = data?.devices || {};
    const browsers = data?.browsers || {};
    const platforms = data?.platforms || {};
    const recentScans = data?.recent_scans || [];

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 p-4">
            <div className="flex w-full max-w-3xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl max-h-[92vh]">
                <header className="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                    <div className="flex items-center gap-2.5">
                        <div className="rounded-lg bg-blue-50 p-2 text-[#000080]">
                            <BarChart3 className="h-5 w-5" />
                        </div>
                        <div>
                            <h3 className="font-bold text-slate-900 text-base">
                                สถิติการสแกน: {qrCode.title}
                            </h3>
                            <p className="text-xs text-slate-500 font-mono">
                                {qrCode.scan_url} • รวมทั้งหมด {qrCode.scan_count || 0} ครั้ง
                            </p>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <select
                            value={periodDays}
                            onChange={(e) => setPeriodDays(Number(e.target.value))}
                            className="rounded-lg border-slate-300 text-xs py-1.5"
                        >
                            <option value={7}>7 วันล่าสุด</option>
                            <option value={30}>30 วันล่าสุด</option>
                            <option value={90}>90 วันล่าสุด</option>
                        </select>
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                        >
                            <X className="h-5 w-5" />
                        </button>
                    </div>
                </header>

                <div className="flex-1 overflow-y-auto p-6 space-y-6">
                    {loading ? (
                        <div className="flex h-64 items-center justify-center">
                            <RefreshCw className="h-6 w-6 animate-spin text-[#000080]" />
                        </div>
                    ) : (
                        <>
                            {/* Trend Chart (Simple Responsive CSS Bars) */}
                            <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                <h4 className="text-xs font-bold text-slate-700 uppercase tracking-wide mb-3 flex items-center gap-1.5">
                                    <Calendar className="h-4 w-4 text-[#000080]" /> แนวโน้มการสแกนรายวัน ({periodDays} วันล่าสุด)
                                </h4>
                                <div className="h-40 flex items-end gap-1 pt-6 overflow-x-auto">
                                    {timeline.map((item) => {
                                        const pct = (item.scans / maxScans) * 100;
                                        return (
                                            <div
                                                key={item.date}
                                                className="group relative flex-1 min-w-[12px] flex flex-col items-center h-full justify-end"
                                            >
                                                <div
                                                    style={{ height: `${Math.max(pct, item.scans > 0 ? 6 : 2)}%` }}
                                                    className={`w-full rounded-t transition-all ${
                                                        item.scans > 0 ? 'bg-[#000080] group-hover:bg-blue-600' : 'bg-slate-200'
                                                    }`}
                                                />
                                                {/* Tooltip */}
                                                <div className="absolute -top-7 hidden group-hover:flex z-10 flex-col items-center pointer-events-none">
                                                    <span className="rounded bg-slate-900 px-1.5 py-0.5 text-[10px] text-white whitespace-nowrap">
                                                        {item.date}: {item.scans} ครั้ง
                                                    </span>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>

                            {/* Device & Browser Breakdown */}
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div className="rounded-xl border border-slate-200 p-4">
                                    <h4 className="text-xs font-bold text-slate-700 uppercase tracking-wide mb-3 flex items-center gap-1.5">
                                        <Smartphone className="h-4 w-4 text-emerald-600" /> สัดส่วนอุปกรณ์ (Device Type)
                                    </h4>
                                    <div className="space-y-2">
                                        {Object.keys(devices).length === 0 ? (
                                            <p className="text-xs text-slate-400 py-3 text-center">ยังไม่มีข้อมูลการสแกน</p>
                                        ) : (
                                            Object.entries(devices).map(([dev, count]) => (
                                                <div key={dev} className="flex items-center justify-between text-xs">
                                                    <span className="capitalize text-slate-600">{dev}</span>
                                                    <span className="font-semibold text-slate-900">{count} ครั้ง</span>
                                                </div>
                                            ))
                                        )}
                                    </div>
                                </div>

                                <div className="rounded-xl border border-slate-200 p-4">
                                    <h4 className="text-xs font-bold text-slate-700 uppercase tracking-wide mb-3 flex items-center gap-1.5">
                                        <Globe className="h-4 w-4 text-blue-600" /> แอปและเบราว์เซอร์ (Browsers)
                                    </h4>
                                    <div className="space-y-2">
                                        {Object.keys(browsers).length === 0 ? (
                                            <p className="text-xs text-slate-400 py-3 text-center">ยังไม่มีข้อมูลการสแกน</p>
                                        ) : (
                                            Object.entries(browsers).map(([br, count]) => (
                                                <div key={br} className="flex items-center justify-between text-xs">
                                                    <span className="text-slate-600">{br}</span>
                                                    <span className="font-semibold text-slate-900">{count} ครั้ง</span>
                                                </div>
                                            ))
                                        )}
                                    </div>
                                </div>
                            </div>

                            {/* Recent Scans Table */}
                            <div className="rounded-xl border border-slate-200 overflow-hidden">
                                <div className="bg-slate-50 px-4 py-2.5 border-b border-slate-200">
                                    <h4 className="text-xs font-bold text-slate-700 uppercase tracking-wide">
                                        ประวัติการสแกน 20 รายการล่าสุด
                                    </h4>
                                </div>
                                <div className="max-h-48 overflow-y-auto">
                                    {recentScans.length === 0 ? (
                                        <p className="text-xs text-slate-400 py-6 text-center">ยังไม่มีประวัติการสแกน</p>
                                    ) : (
                                        <table className="w-full text-left text-xs">
                                            <thead className="bg-white text-slate-400 border-b border-slate-100">
                                                <tr>
                                                    <th className="px-4 py-2 font-medium">วัน-เวลา</th>
                                                    <th className="px-4 py-2 font-medium">อุปกรณ์</th>
                                                    <th className="px-4 py-2 font-medium">ระบบ / เบราว์เซอร์</th>
                                                    <th className="px-4 py-2 font-medium">แหล่งที่มา</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-slate-100">
                                                {recentScans.map((scan) => (
                                                    <tr key={scan.id} className="hover:bg-slate-50">
                                                        <td className="px-4 py-2 text-slate-600 whitespace-nowrap">
                                                            {new Date(scan.scanned_at).toLocaleString('th-TH')}
                                                        </td>
                                                        <td className="px-4 py-2 font-medium capitalize text-slate-700">
                                                            {scan.device_type}
                                                        </td>
                                                        <td className="px-4 py-2 text-slate-600">
                                                            {scan.platform} • {scan.browser}
                                                        </td>
                                                        <td className="px-4 py-2 text-slate-400 truncate max-w-[150px]">
                                                            {scan.referrer || '-'}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    )}
                                </div>
                            </div>
                        </>
                    )}
                </div>

                <footer className="border-t border-slate-200 px-6 py-3 flex justify-end">
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-lg bg-slate-100 px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-200"
                    >
                        ปิดหน้าต่าง
                    </button>
                </footer>
            </div>
        </div>
    );
}
