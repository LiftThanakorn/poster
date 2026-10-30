import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { FacebookIcon, WordPressIcon } from '@/Components/ChannelIcons';
import {
    AlertTriangle,
    ArrowUpRight,
    Calendar,
    CalendarClock,
    CheckCircle2,
    Clock,
    Edit3,
    ExternalLink,
    Eye,
    FileEdit,
    FileText,
    FolderOpen,
    HardDrive,
    Image as ImageIcon,
    LayoutDashboard,
    LayoutTemplate,
    Newspaper,
    Plus,
    RefreshCw,
    Share2,
    Sparkles,
} from 'lucide-react';

function formatBytes(bytes) {
    if (!bytes || bytes === 0) return '0 KB';
    const units = ['B', 'KB', 'MB', 'GB'];
    const index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
    const val = (bytes / (1024 ** index));
    return `${val < 10 && index > 0 ? val.toFixed(1) : Math.round(val)} ${units[index]}`;
}

function formatDate(value) {
    if (!value) return '-';
    const date = new Date(value);
    if (isNaN(date.getTime())) return '-';
    
    return date.toLocaleString('th-TH', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

function getStatusBadge(status) {
    switch (status) {
        case 'published':
            return {
                label: 'เผยแพร่แล้ว',
                className: 'bg-emerald-50 text-emerald-700 border-emerald-200',
                dotClass: 'bg-emerald-500',
            };
        case 'scheduled':
            return {
                label: 'ตั้งเวลาไว้',
                className: 'bg-amber-50 text-amber-700 border-amber-200',
                dotClass: 'bg-amber-500',
            };
        case 'publishing':
        case 'queued':
        case 'processing':
        case 'retrying':
            return {
                label: 'กำลังส่ง...',
                className: 'bg-blue-50 text-blue-700 border-blue-200',
                dotClass: 'bg-blue-500 animate-pulse',
            };
        case 'failed':
            return {
                label: 'ล้มเหลว',
                className: 'bg-rose-50 text-rose-700 border-rose-200',
                dotClass: 'bg-rose-500',
            };
        case 'partially_published':
            return {
                label: 'สำเร็จบางช่องทาง',
                className: 'bg-orange-50 text-orange-700 border-orange-200',
                dotClass: 'bg-orange-500',
            };
        case 'draft':
        default:
            return {
                label: 'ฉบับร่าง',
                className: 'bg-slate-50 text-slate-700 border-slate-200',
                dotClass: 'bg-slate-400',
            };
    }
}

function getTargetChannelBadge(target) {
    const isWP = target.channel === 'wordpress';
    const label = isWP ? 'WordPress' : 'Facebook';

    let color = 'bg-slate-100 text-slate-600 border-slate-200';
    if (target.status === 'published') {
        color = isWP
            ? 'bg-[#21759B]/10 text-[#21759B] border-[#21759B]/20'
            : 'bg-[#1877F2]/10 text-[#1877F2] border-[#1877F2]/20';
    } else if (target.status === 'failed') {
        color = 'bg-rose-50 text-rose-700 border-rose-200';
    } else if (['processing', 'retrying', 'queued', 'pending'].includes(target.status)) {
        color = 'bg-amber-50 text-amber-700 border-amber-200';
    }

    return (
        <span
            key={target.id || target.channel}
            className={`inline-flex items-center gap-1.5 rounded-md border px-2 py-0.5 text-[10px] font-semibold ${color}`}
            title={`${label}: ${target.status}`}
        >
            {isWP ? (
                <WordPressIcon className="h-3 w-3 shrink-0" />
            ) : (
                <FacebookIcon className="h-3 w-3 shrink-0" />
            )}
            <span>{label}</span>
            {target.status === 'published' && <CheckCircle2 className="h-2.5 w-2.5 text-emerald-600 ml-0.5" />}
            {target.status === 'failed' && <AlertTriangle className="h-2.5 w-2.5 text-rose-600 ml-0.5" />}
        </span>
    );
}

export default function Dashboard({
    auth,
    stats = {},
    attentionPosts = [],
    scheduledPosts = [],
    recentPosts = [],
    integrations = {},
    mediaStats = {},
}) {
    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="flex items-center gap-2">
                            <h1 className="text-xl font-bold text-slate-900">แดชบอร์ดภาพรวมระบบ</h1>
                            <span className="rounded-md bg-blue-50 px-2 py-0.5 text-xs font-semibold text-blue-800 border border-blue-200">
                                ศูนย์ปฏิบัติการข่าวสาร
                            </span>
                        </div>
                        <p className="mt-0.5 text-xs text-slate-500">
                            จัดการและเผยแพร่ข่าวสารองค์กรไปยัง WordPress และ Facebook Page ในที่เดียว
                        </p>
                    </div>

                    {/* Quick Action Buttons in Header */}
                    <div className="flex items-center gap-2">
                        <Link
                            href={route('media.index')}
                            className="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 shadow-xs transition hover:bg-slate-50 hover:border-slate-300"
                        >
                            <FolderOpen className="h-4 w-4 text-amber-500" />
                            <span>คลังไฟล์</span>
                        </Link>
                        <Link
                            href={route('posts.create')}
                            className="inline-flex items-center gap-1.5 rounded-xl bg-[#000080] px-4 py-2 text-xs font-semibold text-white shadow-xs transition hover:bg-blue-900"
                        >
                            <Plus className="h-4 w-4" />
                            <span>เขียนข่าวใหม่</span>
                        </Link>
                    </div>
                </div>
            }
        >
            <Head title="แดชบอร์ดภาพรวม (Dashboard)" />

            <div className="py-6">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-6">

                    {/* =========================================================
                        SECTION 1: ACTIONABLE KPI METRICS CARDS
                        ========================================================= */}
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                        
                        {/* 1. ข่าวทั้งหมด */}
                        <Link
                            href={route('posts.index')}
                            className="group rounded-2xl border border-slate-200 bg-white p-4 shadow-xs transition hover:border-blue-300 hover:shadow-md"
                        >
                            <div className="flex items-center justify-between">
                                <span className="text-xs font-semibold text-slate-500">ข่าวทั้งหมด</span>
                                <div className="rounded-xl bg-blue-50 p-2 text-blue-700 group-hover:scale-110 transition">
                                    <Newspaper className="h-4 w-4" />
                                </div>
                            </div>
                            <div className="mt-2 flex items-baseline gap-2">
                                <span className="text-2xl font-black text-slate-900">{stats.total || 0}</span>
                                <span className="text-xs text-slate-400">รายการ</span>
                            </div>
                            <div className="mt-2 flex items-center text-[11px] font-medium text-blue-700 group-hover:underline">
                                <span>ดูรายการทั้งหมด</span>
                                <ArrowUpRight className="h-3 w-3 ml-0.5" />
                            </div>
                        </Link>

                        {/* 2. เผยแพร่แล้ว */}
                        <Link
                            href={route('posts.index', { status: 'published' })}
                            className="group rounded-2xl border border-slate-200 bg-white p-4 shadow-xs transition hover:border-emerald-300 hover:shadow-md"
                        >
                            <div className="flex items-center justify-between">
                                <span className="text-xs font-semibold text-slate-500">เผยแพร่แล้ว</span>
                                <div className="rounded-xl bg-emerald-50 p-2 text-emerald-700 group-hover:scale-110 transition">
                                    <CheckCircle2 className="h-4 w-4" />
                                </div>
                            </div>
                            <div className="mt-2 flex items-baseline gap-2">
                                <span className="text-2xl font-black text-slate-900">{stats.published || 0}</span>
                                <span className="text-xs text-emerald-600 font-semibold">สำเร็จ</span>
                            </div>
                            <div className="mt-2 text-[11px] text-slate-400">
                                WP: <strong className="text-slate-700">{stats.wordpress_published || 0}</strong> • FB: <strong className="text-slate-700">{stats.facebook_published || 0}</strong>
                            </div>
                        </Link>

                        {/* 3. รอเผยแพร่ตามเวลา */}
                        <Link
                            href={route('posts.index', { status: 'scheduled' })}
                            className="group rounded-2xl border border-slate-200 bg-white p-4 shadow-xs transition hover:border-amber-300 hover:shadow-md"
                        >
                            <div className="flex items-center justify-between">
                                <span className="text-xs font-semibold text-slate-500">ตั้งเวลาไว้</span>
                                <div className="rounded-xl bg-amber-50 p-2 text-amber-700 group-hover:scale-110 transition">
                                    <CalendarClock className="h-4 w-4" />
                                </div>
                            </div>
                            <div className="mt-2 flex items-baseline gap-2">
                                <span className="text-2xl font-black text-slate-900">{stats.scheduled || 0}</span>
                                <span className="text-xs text-amber-600 font-semibold">รอส่ง</span>
                            </div>
                            <div className="mt-2 flex items-center text-[11px] font-medium text-amber-700 group-hover:underline">
                                <span>ดูคิวรอเผยแพร่</span>
                                <ArrowUpRight className="h-3 w-3 ml-0.5" />
                            </div>
                        </Link>

                        {/* 4. ฉบับร่าง */}
                        <Link
                            href={route('posts.index', { status: 'draft' })}
                            className="group rounded-2xl border border-slate-200 bg-white p-4 shadow-xs transition hover:border-slate-400 hover:shadow-md"
                        >
                            <div className="flex items-center justify-between">
                                <span className="text-xs font-semibold text-slate-500">ฉบับร่าง</span>
                                <div className="rounded-xl bg-slate-100 p-2 text-slate-700 group-hover:scale-110 transition">
                                    <FileEdit className="h-4 w-4" />
                                </div>
                            </div>
                            <div className="mt-2 flex items-baseline gap-2">
                                <span className="text-2xl font-black text-slate-900">{stats.draft || 0}</span>
                                <span className="text-xs text-slate-400">แบบร่าง</span>
                            </div>
                            <div className="mt-2 flex items-center text-[11px] font-medium text-slate-600 group-hover:underline">
                                <span>กลับไปแต่งต่อ</span>
                                <ArrowUpRight className="h-3 w-3 ml-0.5" />
                            </div>
                        </Link>

                        {/* 5. พบปัญหา / ต้องตรวจสอบ */}
                        <Link
                            href={route('posts.index', { status: 'failed' })}
                            className={`group rounded-2xl border p-4 shadow-xs transition col-span-2 sm:col-span-1 hover:shadow-md ${
                                (stats.failed || 0) > 0
                                    ? 'border-rose-300 bg-rose-50/50 hover:border-rose-400'
                                    : 'border-slate-200 bg-white hover:border-slate-300'
                            }`}
                        >
                            <div className="flex items-center justify-between">
                                <span className="text-xs font-semibold text-slate-500">ต้องตรวจสอบ</span>
                                <div className={`rounded-xl p-2 group-hover:scale-110 transition ${
                                    (stats.failed || 0) > 0 ? 'bg-rose-100 text-rose-700' : 'bg-slate-100 text-slate-400'
                                }`}>
                                    <AlertTriangle className="h-4 w-4" />
                                </div>
                            </div>
                            <div className="mt-2 flex items-baseline gap-2">
                                <span className={`text-2xl font-black ${
                                    (stats.failed || 0) > 0 ? 'text-rose-700' : 'text-slate-900'
                                }`}>
                                    {stats.failed || 0}
                                </span>
                                <span className="text-xs text-rose-600 font-semibold">
                                    {(stats.failed || 0) > 0 ? 'พบข้อผิดพลาด' : 'ปกติ'}
                                </span>
                            </div>
                            <div className="mt-2 flex items-center text-[11px] font-medium text-rose-700 group-hover:underline">
                                <span>คลิกเพื่อดูและลองใหม่</span>
                                <ArrowUpRight className="h-3 w-3 ml-0.5" />
                            </div>
                        </Link>
                    </div>

                    {/* =========================================================
                        SECTION 2: ACTIONABLE ALERT BOX (IF POSTS FAILED)
                        ========================================================= */}
                    {attentionPosts.length > 0 && (
                        <div className="rounded-2xl border border-rose-200 bg-rose-50/90 p-4 shadow-xs animate-in fade-in">
                            <div className="flex items-start gap-3">
                                <div className="rounded-xl bg-rose-100 p-2 text-rose-700 shrink-0 mt-0.5">
                                    <AlertTriangle className="h-5 w-5" />
                                </div>
                                <div className="space-y-2 flex-1">
                                    <div className="flex items-center justify-between">
                                        <h3 className="text-sm font-bold text-rose-950">
                                            มี {attentionPosts.length} ข่าวสารที่เผยแพร่ไม่สำเร็จและต้องการการแก้ไข
                                        </h3>
                                        <Link
                                            href={route('posts.index', { status: 'failed' })}
                                            className="text-xs font-semibold text-rose-800 hover:underline"
                                        >
                                            ดูทั้งหมด &rarr;
                                        </Link>
                                    </div>
                                    <div className="divide-y divide-rose-200/60 rounded-xl bg-white/80 border border-rose-200">
                                        {attentionPosts.map((post) => (
                                            <div key={post.id} className="flex flex-col gap-2 p-3 sm:flex-row sm:items-center sm:justify-between">
                                                <div className="min-w-0 flex-1">
                                                    <p className="text-xs font-bold text-slate-900 truncate">
                                                        {post.title}
                                                    </p>
                                                    <div className="mt-1 flex flex-wrap items-center gap-1.5">
                                                        {post.targets.map((target) => (
                                                            <span
                                                                key={target.id}
                                                                className={`inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-semibold ${
                                                                    target.status === 'failed'
                                                                        ? 'bg-rose-100 text-rose-800'
                                                                        : 'bg-emerald-100 text-emerald-800'
                                                                }`}
                                                            >
                                                                {target.channel === 'facebook' ? (
                                                                    <FacebookIcon className="h-2.5 w-2.5 text-[#1877F2]" />
                                                                ) : (
                                                                    <WordPressIcon className="h-2.5 w-2.5 text-[#21759B]" />
                                                                )}
                                                                <span>{target.channel}: {target.status}</span>
                                                                {target.error_message && ` (${target.error_message})`}
                                                            </span>
                                                        ))}
                                                    </div>
                                                </div>
                                                <div className="flex items-center gap-2 shrink-0">
                                                    <Link
                                                        href={route('posts.show', post.id)}
                                                        className="rounded-lg bg-rose-700 px-3 py-1 text-xs font-semibold text-white hover:bg-rose-800 shadow-xs"
                                                    >
                                                        ตรวจสอบ & ส่งซ้ำ
                                                    </Link>
                                                    <Link
                                                        href={route('posts.edit', post.id)}
                                                        className="rounded-lg border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50"
                                                    >
                                                        แก้ไขเนื้อหา
                                                    </Link>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* =========================================================
                        SECTION 3: INTEGRATION HEALTH STATUS CARDS
                        ========================================================= */}
                    <div>
                        <div className="flex items-center justify-between mb-3">
                            <div className="flex items-center gap-2">
                                <Share2 className="h-4 w-4 text-slate-500" />
                                <h2 className="text-xs font-bold uppercase tracking-wider text-slate-500">
                                    สถานะการเชื่อมต่อช่องทางเผยแพร่ (Integrations Status)
                                </h2>
                            </div>
                            <Link
                                href={route('settings.integrations')}
                                className="text-xs font-semibold text-blue-700 hover:underline"
                            >
                                จัดการการเชื่อมต่อทั้งหมด &rarr;
                            </Link>
                        </div>

                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            {/* WordPress Card */}
                            <div className="flex items-center justify-between rounded-2xl border border-slate-200 bg-white p-4 shadow-xs">
                                <div className="flex items-center gap-3 min-w-0">
                                    <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[#21759B]/10 text-[#21759B]">
                                        <WordPressIcon className="h-6 w-6" />
                                    </div>
                                    <div className="min-w-0">
                                        <div className="flex items-center gap-2">
                                            <h3 className="text-sm font-bold text-slate-900">WordPress Website</h3>
                                            <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold ${
                                                integrations.wordpress?.status === 'connected'
                                                    ? 'bg-emerald-100 text-emerald-800'
                                                    : integrations.wordpress?.status === 'error'
                                                    ? 'bg-rose-100 text-rose-800'
                                                    : 'bg-slate-100 text-slate-600'
                                            }`}>
                                                <span className={`h-1.5 w-1.5 rounded-full ${
                                                    integrations.wordpress?.status === 'connected'
                                                        ? 'bg-emerald-500'
                                                        : integrations.wordpress?.status === 'error'
                                                        ? 'bg-rose-500'
                                                        : 'bg-slate-400'
                                                }`} />
                                                <span>
                                                    {integrations.wordpress?.status === 'connected'
                                                        ? 'พร้อมใช้งาน'
                                                        : integrations.wordpress?.status === 'error'
                                                        ? 'ข้อผิดพลาด'
                                                        : 'ยังไม่ได้ตั้งค่า'}
                                                </span>
                                            </span>
                                        </div>
                                        <p className="mt-0.5 text-xs text-slate-500 truncate" title={integrations.wordpress?.url}>
                                            {integrations.wordpress?.url || 'ยังไม่ได้ระบุ URL เว็บไซต์'}
                                        </p>
                                    </div>
                                </div>

                                <Link
                                    href={route('settings.integrations')}
                                    className="shrink-0 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 shadow-xs"
                                >
                                    ตรวจสอบ
                                </Link>
                            </div>

                            {/* Facebook Page Card */}
                            <div className="flex items-center justify-between rounded-2xl border border-slate-200 bg-white p-4 shadow-xs">
                                <div className="flex items-center gap-3 min-w-0">
                                    <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[#1877F2]/10 text-[#1877F2]">
                                        <FacebookIcon className="h-6 w-6" />
                                    </div>
                                    <div className="min-w-0">
                                        <div className="flex items-center gap-2">
                                            <h3 className="text-sm font-bold text-slate-900">Facebook Fanpage</h3>
                                            <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold ${
                                                integrations.facebook?.status === 'connected'
                                                    ? 'bg-emerald-100 text-emerald-800'
                                                    : integrations.facebook?.status === 'error'
                                                    ? 'bg-rose-100 text-rose-800'
                                                    : 'bg-slate-100 text-slate-600'
                                            }`}>
                                                <span className={`h-1.5 w-1.5 rounded-full ${
                                                    integrations.facebook?.status === 'connected'
                                                        ? 'bg-emerald-500'
                                                        : integrations.facebook?.status === 'error'
                                                        ? 'bg-rose-500'
                                                        : 'bg-slate-400'
                                                }`} />
                                                <span>
                                                    {integrations.facebook?.status === 'connected'
                                                        ? 'พร้อมใช้งาน'
                                                        : integrations.facebook?.status === 'error'
                                                        ? 'ข้อผิดพลาด'
                                                        : 'ยังไม่ได้ตั้งค่า'}
                                                </span>
                                            </span>
                                        </div>
                                        <p className="mt-0.5 text-xs text-slate-500 truncate">
                                            {integrations.facebook?.page_id ? `Page ID: ${integrations.facebook.page_id}` : 'ยังไม่ได้เชื่อมต่อเพจ'}
                                        </p>
                                    </div>
                                </div>

                                <Link
                                    href={route('settings.integrations')}
                                    className="shrink-0 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 shadow-xs"
                                >
                                    ตรวจสอบ
                                </Link>
                            </div>
                        </div>
                    </div>

                    {/* =========================================================
                        SECTION 4: TWO-COLUMN MAIN WORKSPACE
                        ========================================================= */}
                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">

                        {/* LEFT COLUMN: RECENT POSTS (2/3 width) */}
                        <div className="lg:col-span-2 space-y-4">
                            <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs">
                                <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                                    <div className="flex items-center gap-2">
                                        <Newspaper className="h-4 w-4 text-[#000080]" />
                                        <h2 className="text-sm font-bold text-slate-900">ข่าวสารล่าสุดในระบบ</h2>
                                        <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600">
                                            {recentPosts.length} รายการ
                                        </span>
                                    </div>
                                    <Link
                                        href={route('posts.index')}
                                        className="text-xs font-semibold text-blue-700 hover:underline"
                                    >
                                        ดูข่าวทั้งหมด &rarr;
                                    </Link>
                                </div>

                                {recentPosts.length > 0 ? (
                                    <div className="divide-y divide-slate-100">
                                        {recentPosts.map((post) => {
                                            const statusBadge = getStatusBadge(post.status);

                                            return (
                                                <div
                                                    key={post.id}
                                                    className="flex flex-col gap-3 p-4 transition hover:bg-slate-50/80 sm:flex-row sm:items-center sm:justify-between"
                                                >
                                                    <div className="min-w-0 flex-1 space-y-1">
                                                        <div className="flex items-center gap-2 flex-wrap">
                                                            <Link
                                                                href={route('posts.show', post.id)}
                                                                className="text-xs font-bold text-slate-900 hover:text-blue-700 truncate"
                                                                title={post.title}
                                                            >
                                                                {post.title}
                                                            </Link>
                                                            <span className={`inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-[10px] font-semibold ${statusBadge.className}`}>
                                                                <span className={`h-1.5 w-1.5 rounded-full ${statusBadge.dotClass}`} />
                                                                <span>{statusBadge.label}</span>
                                                            </span>
                                                        </div>

                                                        <div className="flex flex-wrap items-center gap-2 text-[11px] text-slate-400">
                                                            <span>โดย {post.author?.name || 'แอดมิน'}</span>
                                                            <span>•</span>
                                                            <span>{formatDate(post.created_at)}</span>
                                                        </div>
                                                    </div>

                                                    {/* Targets Badges & Actions */}
                                                    <div className="flex items-center justify-between sm:justify-end gap-3 shrink-0">
                                                        <div className="flex items-center gap-1">
                                                            {post.targets?.map((target) => getTargetChannelBadge(target))}
                                                        </div>

                                                        <div className="flex items-center gap-1">
                                                            <Link
                                                                href={route('posts.show', post.id)}
                                                                className="rounded-lg border border-slate-200 bg-white p-1.5 text-slate-600 hover:bg-slate-100 shadow-xs"
                                                                title="ดูรายละเอียดและไทม์ไลน์"
                                                            >
                                                                <Eye className="h-3.5 w-3.5" />
                                                            </Link>
                                                            <Link
                                                                href={route('posts.edit', post.id)}
                                                                className="rounded-lg border border-slate-200 bg-white p-1.5 text-slate-600 hover:bg-slate-100 shadow-xs"
                                                                title="แก้ไขข่าว"
                                                            >
                                                                <Edit3 className="h-3.5 w-3.5" />
                                                            </Link>
                                                        </div>
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                ) : (
                                    <div className="p-8 text-center">
                                        <Newspaper className="mx-auto h-10 w-10 text-slate-300" />
                                        <p className="mt-2 text-xs font-semibold text-slate-700">ยังไม่มีข้อมูลข่าวสารในระบบ</p>
                                        <Link
                                            href={route('posts.create')}
                                            className="mt-3 inline-flex items-center gap-1 rounded-lg bg-[#000080] px-3.5 py-1.5 text-xs font-semibold text-white shadow-xs"
                                        >
                                            <Plus className="h-3.5 w-3.5" />
                                            <span>เริ่มเขียนข่าวแรก</span>
                                        </Link>
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* RIGHT COLUMN: UPCOMING & MEDIA STATS (1/3 width) */}
                        <div className="space-y-4">

                            {/* WIDGET 1: UPCOMING SCHEDULED POSTS */}
                            <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs">
                                <div className="border-b border-slate-100 px-4 py-3 bg-amber-50/40">
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-2">
                                            <CalendarClock className="h-4 w-4 text-amber-600" />
                                            <h3 className="text-xs font-bold text-slate-900">กำหนดเผยแพร่เร็วๆ นี้</h3>
                                        </div>
                                        <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold text-amber-800">
                                            {scheduledPosts.length} คิว
                                        </span>
                                    </div>
                                </div>

                                <div className="p-3">
                                    {scheduledPosts.length > 0 ? (
                                        <div className="space-y-2">
                                            {scheduledPosts.map((post) => (
                                                <div
                                                    key={post.id}
                                                    className="rounded-xl border border-slate-100 bg-slate-50/50 p-2.5 transition hover:bg-white hover:border-slate-300"
                                                >
                                                    <div className="flex items-start justify-between gap-2">
                                                        <Link
                                                            href={route('posts.show', post.id)}
                                                            className="text-xs font-bold text-slate-800 hover:text-blue-700 truncate block"
                                                        >
                                                            {post.title}
                                                        </Link>
                                                    </div>
                                                    <div className="mt-1 flex items-center justify-between text-[11px] text-slate-500">
                                                        <span className="inline-flex items-center gap-1 text-amber-700 font-medium">
                                                            <Clock className="h-3 w-3" />
                                                            {formatDate(post.scheduled_at)}
                                                        </span>
                                                        <div className="flex items-center gap-1.5">
                                                            {post.targets?.map((t) => (
                                                                <span key={t.id} title={t.channel === 'wordpress' ? 'WordPress' : 'Facebook'}>
                                                                    {t.channel === 'wordpress' ? (
                                                                        <WordPressIcon className="h-3.5 w-3.5 text-[#21759B]" />
                                                                    ) : (
                                                                        <FacebookIcon className="h-3.5 w-3.5 text-[#1877F2]" />
                                                                    )}
                                                                </span>
                                                            ))}
                                                        </div>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <div className="py-6 text-center text-slate-400">
                                            <Clock className="mx-auto h-8 w-8 text-slate-300" />
                                            <p className="mt-1 text-xs">ไม่มีโพสต์ที่ตั้งเวลารอส่ง</p>
                                        </div>
                                    )}
                                </div>
                            </div>

                            {/* WIDGET 2: MEDIA EXPLORER & STORAGE SUMMARY */}
                            <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs">
                                <div className="border-b border-slate-100 px-4 py-3">
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-2">
                                            <FolderOpen className="h-4 w-4 text-blue-600" />
                                            <h3 className="text-xs font-bold text-slate-900">คลังไฟล์และพื้นที่สื่อ</h3>
                                        </div>
                                        <Link
                                            href={route('media.index')}
                                            className="text-xs font-semibold text-blue-700 hover:underline"
                                        >
                                            เปิด Explorer &rarr;
                                        </Link>
                                    </div>
                                </div>

                                <div className="p-4 space-y-3">
                                    <div className="grid grid-cols-2 gap-2 text-center">
                                        <div className="rounded-xl border border-slate-100 bg-slate-50 p-2.5">
                                            <p className="text-[11px] text-slate-400 font-medium">รูปภาพ</p>
                                            <p className="text-lg font-bold text-slate-800">{mediaStats.images || 0}</p>
                                        </div>
                                        <div className="rounded-xl border border-slate-100 bg-slate-50 p-2.5">
                                            <p className="text-[11px] text-slate-400 font-medium">เอกสาร PDF</p>
                                            <p className="text-lg font-bold text-slate-800">{mediaStats.pdfs || 0}</p>
                                        </div>
                                        <div className="rounded-xl border border-slate-100 bg-slate-50 p-2.5">
                                            <p className="text-[11px] text-slate-400 font-medium">โฟลเดอร์</p>
                                            <p className="text-lg font-bold text-slate-800">{mediaStats.folders || 0}</p>
                                        </div>
                                        <div className="rounded-xl border border-slate-100 bg-slate-50 p-2.5">
                                            <p className="text-[11px] text-slate-400 font-medium">ความจุใช้งาน</p>
                                            <p className="text-lg font-bold text-slate-800">{formatBytes(mediaStats.bytes)}</p>
                                        </div>
                                    </div>

                                    <Link
                                        href={route('media.index')}
                                        className="flex items-center justify-center gap-2 w-full rounded-xl bg-slate-100 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-200 transition"
                                    >
                                        <HardDrive className="h-3.5 w-3.5 text-blue-600" />
                                        <span>จัดการไฟล์ใน Windows Explorer</span>
                                    </Link>
                                </div>
                            </div>

                            {/* WIDGET 3: POSTER TEMPLATES QUICK LAUNCH */}
                            <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-xs">
                                <div className="flex items-center justify-between">
                                    <div className="flex items-center gap-2.5">
                                        <div className="rounded-xl bg-purple-50 p-2 text-purple-600">
                                            <LayoutTemplate className="h-4 w-4" />
                                        </div>
                                        <div>
                                            <h3 className="text-xs font-bold text-slate-900">เทมเพลตโปสเตอร์</h3>
                                            <p className="text-[11px] text-slate-400">
                                                มี {mediaStats.templates_count || 0} เทมเพลตพร้อมใช้งาน
                                            </p>
                                        </div>
                                    </div>
                                    <Link
                                        href={route('templates.index')}
                                        className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                                    >
                                        ออกแบบ
                                    </Link>
                                </div>
                            </div>

                        </div>
                    </div>

                </div>
            </div>
        </AuthenticatedLayout>
    );
}
