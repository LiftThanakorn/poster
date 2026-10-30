import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { GoogleDriveIcon } from '@/Components/ChannelIcons';
import {
    AlertCircle,
    Calendar,
    Check,
    CheckCircle2,
    Copy,
    Download,
    ExternalLink,
    FileText,
    Filter,
    FolderTree,
    Plus,
    RefreshCw,
    Search,
    Share2,
    UploadCloud,
} from 'lucide-react';
import { useState } from 'react';

export default function AnnouncementsIndex({
    announcements,
    categories = [],
    stages = [],
    currentCategory = 'all',
    currentStage = 'all',
    currentSearch = '',
    stats = {},
    driveConfig = {},
}) {
    const today = new Date().toISOString().slice(0, 10);
    const [copiedId, setCopiedId] = useState(null);
    const [syncingId, setSyncingId] = useState(null);
    const [searchQuery, setSearchQuery] = useState(currentSearch);
    const [showUploadModal, setShowUploadModal] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        pdf_file: null,
        category: driveConfig.default_category || categories[0] || 'รายวัน',
        stage: driveConfig.default_stage || stages[0] || 'รับสมัคร',
        document_date: today,
        position_title: '',
        custom_filename: '',
    });

    const calculatedFilename = () => {
        if (data.custom_filename && data.custom_filename.trim()) {
            const trimmed = data.custom_filename.trim();
            return trimmed.endsWith('.pdf') ? trimmed : `${trimmed}.pdf`;
        }
        const date = data.document_date || today;
        const stage = data.stage || 'รับสมัคร';
        const cat = data.category || 'รายวัน';
        const pos = data.position_title ? `-${data.position_title.trim()}` : '';
        return `${date} ${stage}-${cat}${pos}.pdf`;
    };

    const handleUploadSubmit = (e) => {
        e.preventDefault();
        post(route('announcements.store'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setShowUploadModal(false);
                reset('pdf_file', 'position_title', 'custom_filename');
            },
        });
    };

    const copyDriveLink = (id, link) => {
        if (!link) return;
        navigator.clipboard.writeText(link);
        setCopiedId(id);
        setTimeout(() => setCopiedId(null), 2000);
    };

    const handleSync = (mediaId) => {
        setSyncingId(mediaId);
        router.post(
            route('announcements.sync', mediaId),
            {},
            {
                preserveScroll: true,
                onFinish: () => setSyncingId(null),
            }
        );
    };

    const applyFilter = (key, val) => {
        const query = {
            search: searchQuery,
            category: currentCategory,
            stage: currentStage,
            [key]: val,
        };
        router.get(route('announcements.index'), query, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const handleSearchSubmit = (e) => {
        e.preventDefault();
        applyFilter('search', searchQuery);
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="flex items-center gap-2">
                            <h1 className="text-xl font-semibold text-slate-900">
                                สารบัญเอกสารประกาศ
                            </h1>
                            <span className="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2.5 py-0.5 text-xs font-medium text-[#000080]">
                                <GoogleDriveIcon className="h-3.5 w-3.5" />
                                Google Drive
                            </span>
                        </div>
                        <p className="mt-1 text-sm text-slate-500">
                            คลังไฟล์ประกาศรับสมัครและผลการคัดเลือก พร้อมซิงค์และสร้างโครงสร้างโฟลเดอร์อัตโนมัติ
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        {driveConfig.root_folder_url && (
                            <a
                                href={driveConfig.root_folder_url}
                                target="_blank"
                                rel="noreferrer"
                                className="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 shadow-xs hover:bg-slate-50"
                            >
                                <FolderTree className="h-4 w-4 text-slate-500" />
                                <span>เปิดโฟลเดอร์หลักใน Drive</span>
                                <ExternalLink className="h-3 w-3 text-slate-400" />
                            </a>
                        )}
                        <button
                            type="button"
                            onClick={() => setShowUploadModal(true)}
                            className="inline-flex items-center gap-1.5 rounded-xl bg-[#000080] px-4 py-2 text-xs font-semibold text-white shadow-xs transition hover:bg-blue-900"
                        >
                            <Plus className="h-4 w-4" />
                            <span>อัปโหลดประกาศใหม่</span>
                        </button>
                    </div>
                </div>
            }
        >
            <Head title="สารบัญเอกสารประกาศ — Google Drive" />

            <div className="py-8">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                    {/* Stats */}
                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-xs">
                            <span className="text-xs font-medium text-slate-500">เอกสารประกาศทั้งหมด</span>
                            <p className="mt-1 text-2xl font-bold text-slate-900">{stats.total || 0}</p>
                        </div>
                        <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-xs">
                            <span className="text-xs font-medium text-emerald-600">ซิงค์ขึ้น Drive แล้ว</span>
                            <p className="mt-1 text-2xl font-bold text-emerald-700">{stats.synced || 0}</p>
                        </div>
                        <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-xs">
                            <span className="text-xs font-medium text-amber-600">กำลังอัปโหลด / รอคิว</span>
                            <p className="mt-1 text-2xl font-bold text-amber-700">{stats.uploading || 0}</p>
                        </div>
                        <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-xs">
                            <span className="text-xs font-medium text-rose-600">ซิงค์ไม่สำเร็จ</span>
                            <p className="mt-1 text-2xl font-bold text-rose-700">{stats.failed || 0}</p>
                        </div>
                    </div>

                    {/* Filter and Search Bar */}
                    <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-xs">
                        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                            <form onSubmit={handleSearchSubmit} className="relative flex-1 max-w-md">
                                <Search className="absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                                <input
                                    type="text"
                                    value={searchQuery}
                                    onChange={(e) => setSearchQuery(e.target.value)}
                                    placeholder="ค้นหาชื่อไฟล์, ตำแหน่ง หรือข้อความ..."
                                    className="w-full rounded-xl border-slate-300 pl-10 text-sm shadow-xs focus:border-[#000080] focus:ring-[#000080]"
                                />
                            </form>

                            <div className="flex flex-wrap items-center gap-2">
                                <div className="flex items-center gap-1.5 text-xs text-slate-500">
                                    <Filter className="h-3.5 w-3.5" />
                                    <span>ประเภท:</span>
                                </div>
                                <select
                                    value={currentCategory}
                                    onChange={(e) => applyFilter('category', e.target.value)}
                                    className="rounded-xl border-slate-300 py-1.5 text-xs shadow-xs focus:border-[#000080] focus:ring-[#000080]"
                                >
                                    <option value="all">ทุกประเภทบุคลากร</option>
                                    {categories.map((c) => (
                                        <option key={c} value={c}>
                                            {c}
                                        </option>
                                    ))}
                                </select>

                                <div className="flex items-center gap-1.5 text-xs text-slate-500 ml-2">
                                    <span>ขั้นตอน:</span>
                                </div>
                                <select
                                    value={currentStage}
                                    onChange={(e) => applyFilter('stage', e.target.value)}
                                    className="rounded-xl border-slate-300 py-1.5 text-xs shadow-xs focus:border-[#000080] focus:ring-[#000080]"
                                >
                                    <option value="all">ทุกขั้นตอน</option>
                                    {stages.map((s) => (
                                        <option key={s} value={s}>
                                            {s}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>
                    </div>

                    {/* Announcements List */}
                    <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs">
                        {announcements.data?.length === 0 ? (
                            <div className="py-16 text-center">
                                <FileText className="mx-auto h-12 w-12 text-slate-300" />
                                <h3 className="mt-3 text-sm font-semibold text-slate-800">ไม่พบเอกสารประกาศ</h3>
                                <p className="mt-1 text-xs text-slate-500">
                                    คุณสามารถคลิกปุ่ม &quot;อัปโหลดประกาศใหม่&quot; หรือแนบไฟล์ PDF ตอนเขียนข่าว
                                </p>
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm text-slate-600">
                                    <thead className="border-b border-slate-100 bg-slate-50/75 text-xs uppercase text-slate-500">
                                        <tr>
                                            <th className="px-6 py-4 font-semibold">ชื่อเอกสารประกาศ</th>
                                            <th className="px-4 py-4 font-semibold">ประเภท & ขั้นตอน</th>
                                            <th className="px-4 py-4 font-semibold">วันที่ประกาศ</th>
                                            <th className="px-4 py-4 font-semibold">สถานะ Google Drive</th>
                                            <th className="px-6 py-4 font-semibold text-right">การจัดการ</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {announcements.data.map((item) => (
                                            <tr key={item.id} className="hover:bg-slate-50/50">
                                                <td className="px-6 py-4">
                                                    <div className="flex items-start gap-3">
                                                        <span className="rounded-lg bg-rose-50 p-2 text-rose-600">
                                                            <FileText className="h-5 w-5" />
                                                        </span>
                                                        <div>
                                                            <p className="font-semibold text-slate-900">
                                                                {item.drive_file_name || item.original_name}
                                                            </p>
                                                            {item.drive_position_title && (
                                                                <p className="text-xs text-slate-500">
                                                                    ตำแหน่ง: {item.drive_position_title}
                                                                </p>
                                                            )}
                                                            {item.post && (
                                                                <Link
                                                                    href={route('posts.edit', item.post.id)}
                                                                    className="mt-0.5 inline-flex items-center gap-1 text-[11px] text-[#000080] hover:underline"
                                                                >
                                                                    โพสต์ข่าว: {item.post.title}
                                                                </Link>
                                                            )}
                                                        </div>
                                                    </div>
                                                </td>

                                                <td className="px-4 py-4">
                                                    <div className="flex flex-col gap-1 items-start">
                                                        <span className="inline-flex rounded-md bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700">
                                                            {item.drive_employee_category || 'ไม่ระบุประเภท'}
                                                        </span>
                                                        <span className="inline-flex rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600">
                                                            {item.drive_announcement_stage || 'ไม่ระบุขั้นตอน'}
                                                        </span>
                                                    </div>
                                                </td>

                                                <td className="px-4 py-4 text-xs text-slate-600 whitespace-nowrap">
                                                    <span className="inline-flex items-center gap-1">
                                                        <Calendar className="h-3.5 w-3.5 text-slate-400" />
                                                        {item.drive_document_date || '-'}
                                                    </span>
                                                </td>

                                                <td className="px-4 py-4">
                                                    {item.drive_sync_status === 'synced' ? (
                                                        <div className="flex flex-col gap-1">
                                                            <span className="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-700">
                                                                <CheckCircle2 className="h-4 w-4 text-emerald-600" />
                                                                ซิงค์แล้ว
                                                            </span>
                                                            {item.drive_web_view_link && (
                                                                <div className="flex items-center gap-2">
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => copyDriveLink(item.id, item.drive_web_view_link)}
                                                                        className="inline-flex items-center gap-1 text-xs text-[#000080] hover:underline"
                                                                    >
                                                                        {copiedId === item.id ? (
                                                                            <>
                                                                                <Check className="h-3 w-3 text-emerald-600" />
                                                                                คัดลอกแล้ว
                                                                            </>
                                                                        ) : (
                                                                            <>
                                                                                <Copy className="h-3 w-3" />
                                                                                คัดลอกลิงก์
                                                                            </>
                                                                        )}
                                                                    </button>
                                                                    <a
                                                                        href={item.drive_web_view_link}
                                                                        target="_blank"
                                                                        rel="noreferrer"
                                                                        className="inline-flex items-center gap-0.5 text-xs text-slate-500 hover:text-slate-800"
                                                                    >
                                                                        <ExternalLink className="h-3 w-3" />
                                                                    </a>
                                                                </div>
                                                            )}
                                                        </div>
                                                    ) : item.drive_sync_status === 'failed' ? (
                                                        <div className="flex flex-col gap-1">
                                                            <span className="inline-flex items-center gap-1 text-xs font-medium text-rose-700">
                                                                <AlertCircle className="h-3.5 w-3.5 text-rose-500" />
                                                                ล้มเหลว
                                                            </span>
                                                            <button
                                                                type="button"
                                                                onClick={() => handleSync(item.id)}
                                                                disabled={syncingId === item.id}
                                                                className="inline-flex items-center gap-1 text-xs text-[#000080] hover:underline disabled:opacity-50"
                                                            >
                                                                <RefreshCw className={`h-3 w-3 ${syncingId === item.id ? 'animate-spin' : ''}`} />
                                                                ซิงค์ใหม่
                                                            </button>
                                                        </div>
                                                    ) : (
                                                        <div className="flex items-center gap-1 text-xs text-amber-600">
                                                            <RefreshCw className="h-3 w-3 animate-spin" />
                                                            <span>กำลังดำเนินการ...</span>
                                                        </div>
                                                    )}
                                                </td>

                                                <td className="px-6 py-4 text-right">
                                                    <div className="flex items-center justify-end gap-2">
                                                        <Link
                                                            href={route('posts.create', { pdf_media_id: item.id })}
                                                            className="inline-flex items-center gap-1 rounded-lg border border-blue-200 bg-blue-50 px-2.5 py-1 text-xs font-medium text-[#000080] hover:bg-blue-100"
                                                        >
                                                            <Share2 className="h-3 w-3" />
                                                            สร้างโพสต์ข่าว
                                                        </Link>
                                                        {item.url && (
                                                            <a
                                                                href={item.url}
                                                                download
                                                                className="rounded-lg border border-slate-200 p-1 text-slate-500 hover:bg-slate-100"
                                                                title="ดาวน์โหลดไฟล์ต้นฉบับ"
                                                            >
                                                                <Download className="h-4 w-4" />
                                                            </a>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        {/* Pagination */}
                        {announcements.links && announcements.links.length > 3 && (
                            <div className="flex items-center justify-between border-t border-slate-100 px-6 py-4">
                                <div className="text-xs text-slate-500">
                                    แสดง {announcements.from || 0} ถึง {announcements.to || 0} จากทั้งหมด {announcements.total || 0} รายการ
                                </div>
                                <div className="flex gap-1">
                                    {announcements.links.map((link, idx) => (
                                        <Link
                                            key={idx}
                                            href={link.url || '#'}
                                            dangerouslySetInnerHTML={{ __html: link.label }}
                                            className={`rounded-lg px-3 py-1.5 text-xs font-medium ${
                                                link.active
                                                    ? 'bg-[#000080] text-white'
                                                    : link.url
                                                    ? 'text-slate-600 hover:bg-slate-100'
                                                    : 'cursor-not-allowed text-slate-300'
                                            }`}
                                        />
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </div>

            {/* Upload Modal */}
            {showUploadModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-xs">
                    <div className="w-full max-w-lg rounded-2xl bg-white shadow-xl">
                        <div className="border-b border-slate-100 px-6 py-4 flex items-center justify-between">
                            <div className="flex items-center gap-2 font-semibold text-slate-900">
                                <UploadCloud className="h-5 w-5 text-[#000080]" />
                                <span>อัปโหลดประกาศใหม่ขึ้น Google Drive</span>
                            </div>
                            <button
                                type="button"
                                onClick={() => setShowUploadModal(false)}
                                className="text-slate-400 hover:text-slate-600 text-lg leading-none"
                            >
                                &times;
                            </button>
                        </div>

                        <form onSubmit={handleUploadSubmit} className="space-y-4 p-6">
                            <div>
                                <label className="mb-1 block text-xs font-semibold text-slate-700">
                                    เลือกไฟล์ประกาศ (PDF) *
                                </label>
                                <input
                                    type="file"
                                    accept="application/pdf"
                                    required
                                    onChange={(e) => setData('pdf_file', e.target.files[0])}
                                    className="block w-full text-xs text-slate-600 file:mr-3 file:rounded-xl file:border-0 file:bg-blue-50 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-[#000080] hover:file:bg-blue-100"
                                />
                                {errors.pdf_file && <p className="mt-1 text-xs text-rose-600">{errors.pdf_file}</p>}
                            </div>

                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <label className="mb-1 block text-xs font-semibold text-slate-700">
                                        ประเภทบุคลากร *
                                    </label>
                                    <select
                                        value={data.category}
                                        onChange={(e) => setData('category', e.target.value)}
                                        className="w-full rounded-xl border-slate-300 text-xs shadow-xs focus:border-[#000080] focus:ring-[#000080]"
                                    >
                                        {categories.map((c) => (
                                            <option key={c} value={c}>
                                                {c}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div>
                                    <label className="mb-1 block text-xs font-semibold text-slate-700">
                                        ขั้นตอนประกาศ *
                                    </label>
                                    <select
                                        value={data.stage}
                                        onChange={(e) => setData('stage', e.target.value)}
                                        className="w-full rounded-xl border-slate-300 text-xs shadow-xs focus:border-[#000080] focus:ring-[#000080]"
                                    >
                                        {stages.map((s) => (
                                            <option key={s} value={s}>
                                                {s}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            </div>

                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <label className="mb-1 block text-xs font-semibold text-slate-700">
                                        วันที่เอกสารประกาศ *
                                    </label>
                                    <input
                                        type="date"
                                        required
                                        value={data.document_date}
                                        onChange={(e) => setData('document_date', e.target.value)}
                                        className="w-full rounded-xl border-slate-300 text-xs shadow-xs focus:border-[#000080] focus:ring-[#000080]"
                                    >
                                    </input>
                                </div>
                                <div>
                                    <label className="mb-1 block text-xs font-semibold text-slate-700">
                                        ตำแหน่งหรือรายละเอียด (ถ้ามี)
                                    </label>
                                    <input
                                        type="text"
                                        value={data.position_title}
                                        onChange={(e) => setData('position_title', e.target.value)}
                                        placeholder="เช่น พนักงานขับรถ, แม่บ้าน"
                                        className="w-full rounded-xl border-slate-300 text-xs shadow-xs focus:border-[#000080] focus:ring-[#000080]"
                                    />
                                </div>
                            </div>

                            <div className="rounded-xl border border-blue-100 bg-blue-50/60 p-3">
                                <span className="block text-[11px] font-semibold text-slate-700">
                                    ชื่อไฟล์บน Google Drive (ตามสูตรมาตรฐาน):
                                </span>
                                <code className="mt-1 block font-mono text-xs font-semibold text-[#000080] break-all">
                                    {calculatedFilename()}
                                </code>
                                <span className="mt-1 block text-[11px] text-slate-500">
                                    โฟลเดอร์ปลายทาง: {data.category} / {data.stage}
                                </span>
                            </div>

                            <div className="mt-6 flex items-center justify-end gap-3 border-t border-slate-100 pt-4">
                                <button
                                    type="button"
                                    onClick={() => setShowUploadModal(false)}
                                    className="rounded-xl border border-slate-300 px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50"
                                >
                                    ยกเลิก
                                </button>
                                <button
                                    type="submit"
                                    disabled={processing || !data.pdf_file}
                                    className="inline-flex items-center gap-1.5 rounded-xl bg-[#000080] px-4 py-2 text-xs font-semibold text-white shadow-xs hover:bg-blue-900 disabled:opacity-50"
                                >
                                    {processing ? (
                                        <>
                                            <RefreshCw className="h-3.5 w-3.5 animate-spin" />
                                            กำลังอัปโหลด...
                                        </>
                                    ) : (
                                        <>
                                            <UploadCloud className="h-3.5 w-3.5" />
                                            อัปโหลดและซิงค์ทันที
                                        </>
                                    )}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
