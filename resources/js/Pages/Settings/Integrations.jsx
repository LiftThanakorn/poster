import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { FacebookIcon, GoogleDriveIcon, WordPressIcon } from '@/Components/ChannelIcons';
import {
    CheckCircle2,
    CircleAlert,
    Clock3,
    Database,
    Eye,
    EyeOff,
    FolderCheck,
    FolderTree,
    KeyRound,
    Link2,
    LogIn,
    LogOut,
    RefreshCw,
    Save,
    ShieldCheck,
    Sparkles,
    UserRound,
} from 'lucide-react';
import { useState } from 'react';

const statusStyles = {
    connected: {
        label: 'เชื่อมต่อแล้ว',
        className: 'border-emerald-200 bg-emerald-50 text-emerald-700',
        dot: 'bg-emerald-500',
    },
    error: {
        label: 'เชื่อมต่อไม่สำเร็จ',
        className: 'border-rose-200 bg-rose-50 text-rose-700',
        dot: 'bg-rose-500',
    },
    disconnected: {
        label: 'ยังไม่ได้ตั้งค่า',
        className: 'border-slate-200 bg-slate-50 text-slate-600',
        dot: 'bg-slate-400',
    },
};

function StatusBadge({ status }) {
    const item = statusStyles[status] || statusStyles.disconnected;

    return (
        <span className={`inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold ${item.className}`}>
            <span className={`h-2 w-2 rounded-full ${item.dot}`} />
            {item.label}
        </span>
    );
}

function LastChecked({ value }) {
    if (!value) {
        return <span>ยังไม่เคยตรวจสอบ</span>;
    }

    return (
        <span>
            ตรวจสอบล่าสุด {new Date(value).toLocaleString('th-TH', {
                dateStyle: 'medium',
                timeStyle: 'short',
            })}
        </span>
    );
}

export default function Integrations({ wordpress, facebook, googleDrive = {} }) {
    const [showWordPressPassword, setShowWordPressPassword] = useState(false);
    const [showFacebookToken, setShowFacebookToken] = useState(false);
    const [showGoogleDriveSecret, setShowGoogleDriveSecret] = useState(false);
    const [checkingChannel, setCheckingChannel] = useState(null);
    const [inspectingFolders, setInspectingFolders] = useState(false);
    const [folderInspectionResult, setFolderInspectionResult] = useState(null);
    const [copiedRedirect, setCopiedRedirect] = useState(false);

    const {
        data,
        setData,
        post,
        processing,
        errors,
        reset,
        isDirty,
        recentlySuccessful,
    } = useForm({
        wordpress_url: wordpress.url || '',
        wordpress_username: wordpress.username || '',
        wordpress_password: '',
        facebook_page_id: facebook.page_id || '',
        facebook_access_token: '',
        google_drive_client_id: googleDrive.client_id || '',
        google_drive_client_secret: '',
        google_drive_root_folder_id: googleDrive.root_folder_id || '',
        google_drive_default_category: googleDrive.default_category || 'รายวัน',
        google_drive_default_stage: googleDrive.default_stage || 'รับสมัคร',
        google_drive_default_comment_template: googleDrive.default_comment_template || "📄 เอกสารประกาศฉบับเต็ม:\n{drive_url}",
    });

    const submit = (event) => {
        event.preventDefault();

        post(route('settings.integrations.update'), {
            preserveScroll: true,
            onSuccess: () => {
                reset('wordpress_password', 'facebook_access_token', 'google_drive_client_secret');
            },
        });
    };

    const checkConnection = (channel) => {
        router.post(
            route('settings.integrations.check', channel),
            {},
            {
                preserveScroll: true,
                onStart: () => setCheckingChannel(channel),
                onFinish: () => setCheckingChannel(null),
            },
        );
    };

    const inspectFolders = async (createMissing = false) => {
        setInspectingFolders(true);
        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            const res = await fetch(route('settings.integrations.google-drive.inspect-folders'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({ create_missing: createMissing }),
            });
            const json = await res.json();
            setFolderInspectionResult(json);
        } catch {
            setFolderInspectionResult({ success: false, error: 'ไม่สามารถตรวจสอบโฟลเดอร์ได้' });
        } finally {
            setInspectingFolders(false);
        }
    };

    const copyRedirectUri = () => {
        const uri = googleDrive.redirect_uri || window.location.origin + '/settings/integrations/google-drive/callback';
        navigator.clipboard.writeText(uri);
        setCopiedRedirect(true);
        setTimeout(() => setCopiedRedirect(false), 2000);
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-xl font-semibold text-slate-900">การเชื่อมต่อ</h1>
                        <p className="mt-1 text-sm text-slate-500">
                            ตั้งค่าช่องทางสำหรับเผยแพร่ข่าวจากระบบ Poster
                        </p>
                    </div>
                    <div className="flex items-center gap-2 text-xs text-slate-500">
                        <ShieldCheck className="h-4 w-4 text-emerald-600" />
                        ข้อมูลลับถูกจัดเก็บแบบเข้ารหัส
                    </div>
                </div>
            }
        >
            <Head title="การเชื่อมต่อ" />

            <div className="py-8">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">

                    <div className="grid gap-4 lg:grid-cols-3">
                        <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <div className="flex items-start justify-between gap-4">
                                <div className="flex items-center gap-3">
                                    <div className="rounded-xl bg-[#21759B]/10 p-2.5 text-[#21759B]">
                                        <WordPressIcon className="h-5 w-5" />
                                    </div>
                                    <div>
                                        <p className="font-semibold text-slate-900">WordPress</p>
                                        <p className="text-sm text-slate-500">เผยแพร่ข่าวและรูปประกอบไปยังเว็บไซต์</p>
                                    </div>
                                </div>
                                <StatusBadge status={wordpress.status} />
                            </div>
                            <div className="mt-4 flex items-center justify-between gap-3 border-t border-slate-100 pt-4 text-xs text-slate-500">
                                <div className="flex items-center gap-1.5">
                                    <Clock3 className="h-3.5 w-3.5" />
                                    <LastChecked value={wordpress.last_checked_at} />
                                </div>
                                {wordpress.configured && (
                                    <button
                                        type="button"
                                        onClick={() => checkConnection('wordpress')}
                                        disabled={checkingChannel === 'wordpress'}
                                        className="inline-flex items-center gap-1.5 font-medium text-[#000080] hover:text-blue-900 disabled:opacity-50"
                                    >
                                        <RefreshCw className={`h-3.5 w-3.5 ${checkingChannel === 'wordpress' ? 'animate-spin' : ''}`} />
                                        ตรวจสอบ
                                    </button>
                                )}
                            </div>
                        </div>

                        <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <div className="flex items-start justify-between gap-4">
                                <div className="flex items-center gap-3">
                                    <div className="rounded-xl bg-[#1877F2]/10 p-2.5 text-[#1877F2]">
                                        <FacebookIcon className="h-5 w-5" />
                                    </div>
                                    <div>
                                        <p className="font-semibold text-slate-900">Facebook Page</p>
                                        <p className="text-sm text-slate-500">เผยแพร่โพสต์ รูปภาพ และคอมเมนต์แรก</p>
                                    </div>
                                </div>
                                <StatusBadge status={facebook.status} />
                            </div>
                            <div className="mt-4 flex items-center justify-between gap-3 border-t border-slate-100 pt-4 text-xs text-slate-500">
                                <div className="flex items-center gap-1.5">
                                    <Clock3 className="h-3.5 w-3.5" />
                                    <LastChecked value={facebook.last_checked_at} />
                                </div>
                                {facebook.configured && (
                                    <button
                                        type="button"
                                        onClick={() => checkConnection('facebook')}
                                        disabled={checkingChannel === 'facebook'}
                                        className="inline-flex items-center gap-1.5 font-medium text-[#000080] hover:text-blue-900 disabled:opacity-50"
                                    >
                                        <RefreshCw className={`h-3.5 w-3.5 ${checkingChannel === 'facebook' ? 'animate-spin' : ''}`} />
                                        ตรวจสอบ
                                    </button>
                                )}
                            </div>
                        </div>

                        <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <div className="flex items-start justify-between gap-4">
                                <div className="flex items-center gap-3">
                                    <div className="rounded-xl bg-blue-50 p-2.5">
                                        <GoogleDriveIcon className="h-5 w-5" />
                                    </div>
                                    <div>
                                        <p className="font-semibold text-slate-900">Google Drive</p>
                                        <p className="text-sm text-slate-500">อัปโหลดจัดเก็บไฟล์ประกาศอัตโนมัติ</p>
                                    </div>
                                </div>
                                <StatusBadge status={googleDrive.status} />
                            </div>
                            <div className="mt-4 flex items-center justify-between gap-3 border-t border-slate-100 pt-4 text-xs text-slate-500">
                                <div className="flex items-center gap-1.5">
                                    <Clock3 className="h-3.5 w-3.5" />
                                    <LastChecked value={googleDrive.last_checked_at} />
                                </div>
                                {googleDrive.configured && (
                                    <button
                                        type="button"
                                        onClick={() => checkConnection('google_drive')}
                                        disabled={checkingChannel === 'google_drive'}
                                        className="inline-flex items-center gap-1.5 font-medium text-[#000080] hover:text-blue-900 disabled:opacity-50"
                                    >
                                        <RefreshCw className={`h-3.5 w-3.5 ${checkingChannel === 'google_drive' ? 'animate-spin' : ''}`} />
                                        ตรวจสอบ
                                    </button>
                                )}
                            </div>
                        </div>
                    </div>

                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid gap-6 xl:grid-cols-2">

                            <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                                <div className="border-b border-slate-100 px-6 py-5">
                                    <div className="flex items-center gap-3">
                                        <WordPressIcon className="h-5 w-5 text-[#21759B]" />
                                        <div>
                                            <h2 className="font-semibold text-slate-900">ตั้งค่า WordPress</h2>
                                            <p className="mt-0.5 text-sm text-slate-500">ใช้ REST API และ Application Password</p>
                                        </div>
                                    </div>
                                </div>
                                <div className="space-y-5 p-6">
                                    <div>
                                        <label htmlFor="wordpress_url" className="mb-1.5 flex items-center gap-2 text-sm font-medium text-slate-700">
                                            <Link2 className="h-4 w-4 text-slate-400" />
                                            URL เว็บไซต์
                                        </label>
                                        <input
                                            id="wordpress_url"
                                            type="url"
                                            value={data.wordpress_url}
                                            onChange={(event) => setData('wordpress_url', event.target.value)}
                                            placeholder="https://example.com"
                                            className="block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-[#000080] focus:ring-[#000080]"
                                        />
                                        {errors.wordpress_url && <p className="mt-1.5 text-xs text-rose-600">{errors.wordpress_url}</p>}
                                    </div>

                                    <div>
                                        <label htmlFor="wordpress_username" className="mb-1.5 flex items-center gap-2 text-sm font-medium text-slate-700">
                                            <UserRound className="h-4 w-4 text-slate-400" />
                                            ชื่อผู้ใช้ API
                                        </label>
                                        <input
                                            id="wordpress_username"
                                            type="text"
                                            value={data.wordpress_username}
                                            onChange={(event) => setData('wordpress_username', event.target.value)}
                                            placeholder="ชื่อผู้ใช้ WordPress"
                                            className="block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-[#000080] focus:ring-[#000080]"
                                        />
                                    </div>

                                    <div>
                                        <label htmlFor="wordpress_password" className="mb-1.5 flex items-center gap-2 text-sm font-medium text-slate-700">
                                            <KeyRound className="h-4 w-4 text-slate-400" />
                                            Application Password
                                        </label>
                                        <div className="relative">
                                            <input
                                                id="wordpress_password"
                                                type={showWordPressPassword ? 'text' : 'password'}
                                                value={data.wordpress_password}
                                                onChange={(event) => setData('wordpress_password', event.target.value)}
                                                placeholder={wordpress.password_configured ? 'บันทึกไว้แล้ว — เว้นว่างหากไม่เปลี่ยน' : 'กรอก Application Password'}
                                                autoComplete="new-password"
                                                className="block w-full rounded-xl border-slate-300 pr-11 text-sm shadow-sm focus:border-[#000080] focus:ring-[#000080]"
                                            />
                                            <button
                                                type="button"
                                                onClick={() => setShowWordPressPassword((value) => !value)}
                                                className="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-slate-400 hover:text-slate-700"
                                                aria-label={showWordPressPassword ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน'}
                                            >
                                                {showWordPressPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                                            </button>
                                        </div>
                                        <p className="mt-1.5 text-xs text-slate-500">ระบบจะไม่แสดงรหัสผ่านเดิมกลับมาบนหน้าเว็บ</p>
                                    </div>
                                </div>
                            </section>

                            <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                                <div className="border-b border-slate-100 px-6 py-5">
                                    <div className="flex items-center gap-3">
                                        <FacebookIcon className="h-5 w-5 text-[#1877F2]" />
                                        <div>
                                            <h2 className="font-semibold text-slate-900">ตั้งค่า Facebook Page</h2>
                                            <p className="mt-0.5 text-sm text-slate-500">เชื่อมต่อผ่าน Facebook Graph API {facebook.graph_version}</p>
                                        </div>
                                    </div>
                                </div>
                                <div className="space-y-5 p-6">
                                    <div>
                                        <label htmlFor="facebook_page_id" className="mb-1.5 flex items-center gap-2 text-sm font-medium text-slate-700">
                                            <Database className="h-4 w-4 text-slate-400" />
                                            Page ID
                                        </label>
                                        <input
                                            id="facebook_page_id"
                                            type="text"
                                            value={data.facebook_page_id}
                                            onChange={(event) => setData('facebook_page_id', event.target.value)}
                                            placeholder="Facebook Page ID"
                                            className="block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-[#000080] focus:ring-[#000080]"
                                        />
                                    </div>

                                    <div>
                                        <label htmlFor="facebook_access_token" className="mb-1.5 flex items-center gap-2 text-sm font-medium text-slate-700">
                                            <KeyRound className="h-4 w-4 text-slate-400" />
                                            User / Page Access Token
                                        </label>
                                        <div className="relative">
                                            <input
                                                id="facebook_access_token"
                                                type={showFacebookToken ? 'text' : 'password'}
                                                value={data.facebook_access_token}
                                                onChange={(event) => setData('facebook_access_token', event.target.value)}
                                                placeholder={facebook.token_configured ? 'บันทึก Token ไว้แล้ว — เว้นว่างหากไม่เปลี่ยน' : 'วาง Access Token'}
                                                autoComplete="new-password"
                                                className="block w-full rounded-xl border-slate-300 pr-11 text-sm shadow-sm focus:border-[#000080] focus:ring-[#000080]"
                                            />
                                            <button
                                                type="button"
                                                onClick={() => setShowFacebookToken((value) => !value)}
                                                className="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-slate-400 hover:text-slate-700"
                                                aria-label={showFacebookToken ? 'ซ่อน Access Token' : 'แสดง Access Token'}
                                            >
                                                {showFacebookToken ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                                            </button>
                                        </div>
                                        <div className="mt-2 flex gap-2 rounded-xl bg-blue-50 px-3 py-2.5 text-xs leading-5 text-blue-800">
                                            <ShieldCheck className="mt-0.5 h-4 w-4 shrink-0" />
                                            ระบบรองรับ User Access Token และจะค้นหา Page Access Token ที่ตรงกับ Page ID ให้อัตโนมัติ
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm xl:col-span-2">
                                <div className="border-b border-slate-100 px-6 py-5">
                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                        <div className="flex items-center gap-3">
                                            <div className="rounded-xl bg-blue-50 p-2.5">
                                                <GoogleDriveIcon className="h-5 w-5" />
                                            </div>
                                            <div>
                                                <h2 className="font-semibold text-slate-900">ตั้งค่า Google Drive (OAuth 2.0)</h2>
                                                <p className="mt-0.5 text-sm text-slate-500">
                                                    อัปโหลดและจัดระเบียบไฟล์ประกาศลง My Drive ของมหาวิทยาลัยอัตโนมัติ
                                                </p>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            {googleDrive.refresh_token_configured ? (
                                                <div className="flex items-center gap-2">
                                                    <span className="inline-flex items-center gap-1.5 rounded-lg bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">
                                                        <CheckCircle2 className="h-3.5 w-3.5" />
                                                        {googleDrive.connected_email ? `เชื่อมต่อ: ${googleDrive.connected_email}` : 'เชื่อมต่อบัญชีแล้ว'}
                                                    </span>
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            if (confirm('คุณต้องการยกเลิกการเชื่อมต่อบัญชี Google Drive หรือไม่?')) {
                                                                router.post(route('settings.integrations.google-drive.disconnect'));
                                                            }
                                                        }}
                                                        className="inline-flex items-center gap-1 rounded-lg border border-rose-200 bg-rose-50 px-2.5 py-1 text-xs font-medium text-rose-700 hover:bg-rose-100"
                                                    >
                                                        <LogOut className="h-3.5 w-3.5" />
                                                        ยกเลิกเชื่อมต่อ
                                                    </button>
                                                </div>
                                            ) : (
                                                <a
                                                    href={route('settings.integrations.google-drive.redirect')}
                                                    className="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-blue-700"
                                                >
                                                    <LogIn className="h-4 w-4" />
                                                    เชื่อมต่อ Google Account (OAuth)
                                                </a>
                                            )}
                                        </div>
                                    </div>
                                </div>

                                <div className="space-y-6 p-6">
                                    <div className="grid gap-5 md:grid-cols-2">
                                        <div>
                                            <label htmlFor="google_drive_client_id" className="mb-1.5 flex items-center gap-2 text-sm font-medium text-slate-700">
                                                <KeyRound className="h-4 w-4 text-slate-400" />
                                                Google Client ID
                                            </label>
                                            <input
                                                id="google_drive_client_id"
                                                type="text"
                                                value={data.google_drive_client_id}
                                                onChange={(e) => setData('google_drive_client_id', e.target.value)}
                                                placeholder="xxxx.apps.googleusercontent.com"
                                                className="block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-[#000080] focus:ring-[#000080]"
                                            />
                                        </div>

                                        <div>
                                            <label htmlFor="google_drive_client_secret" className="mb-1.5 flex items-center gap-2 text-sm font-medium text-slate-700">
                                                <KeyRound className="h-4 w-4 text-slate-400" />
                                                Google Client Secret
                                            </label>
                                            <div className="relative">
                                                <input
                                                    id="google_drive_client_secret"
                                                    type={showGoogleDriveSecret ? 'text' : 'password'}
                                                    value={data.google_drive_client_secret}
                                                    onChange={(e) => setData('google_drive_client_secret', e.target.value)}
                                                    placeholder={googleDrive.client_secret_configured ? 'บันทึก Secret ไว้แล้ว — เว้นว่างหากไม่เปลี่ยน' : 'GOCSPX-xxxx'}
                                                    autoComplete="new-password"
                                                    className="block w-full rounded-xl border-slate-300 pr-11 text-sm shadow-sm focus:border-[#000080] focus:ring-[#000080]"
                                                />
                                                <button
                                                    type="button"
                                                    onClick={() => setShowGoogleDriveSecret(!showGoogleDriveSecret)}
                                                    className="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-slate-400 hover:text-slate-700"
                                                >
                                                    {showGoogleDriveSecret ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                            <div>
                                                <span className="text-xs font-semibold text-slate-700">Authorized Redirect URI (สำหรับ Google Cloud Console):</span>
                                                <code className="mt-1 block text-xs text-[#000080] font-mono break-all select-all">
                                                    {googleDrive.redirect_uri || 'http://localhost/settings/integrations/google-drive/callback'}
                                                </code>
                                            </div>
                                            <button
                                                type="button"
                                                onClick={copyRedirectUri}
                                                className="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
                                            >
                                                {copiedRedirect ? <CheckCircle2 className="h-3.5 w-3.5 text-emerald-600" /> : <Link2 className="h-3.5 w-3.5 text-slate-500" />}
                                                {copiedRedirect ? 'คัดลอกแล้ว' : 'คัดลอก URI'}
                                            </button>
                                        </div>
                                    </div>

                                    <div>
                                        <div className="flex items-center justify-between">
                                            <label htmlFor="google_drive_root_folder_id" className="mb-1.5 flex items-center gap-2 text-sm font-medium text-slate-700">
                                                <FolderTree className="h-4 w-4 text-slate-400" />
                                                โฟลเดอร์หลักสำหรับจัดเก็บเอกสาร (Root Folder ID หรือ URL)
                                            </label>
                                            {googleDrive.refresh_token_configured && (
                                                <button
                                                    type="button"
                                                    onClick={() => inspectFolders(false)}
                                                    disabled={inspectingFolders}
                                                    className="inline-flex items-center gap-1.5 text-xs font-medium text-[#000080] hover:text-blue-900 disabled:opacity-50"
                                                >
                                                    <RefreshCw className={`h-3 w-3 ${inspectingFolders ? 'animate-spin' : ''}`} />
                                                    ตรวจสอบโครงสร้างโฟลเดอร์
                                                </button>
                                            )}
                                        </div>
                                        <input
                                            id="google_drive_root_folder_id"
                                            type="text"
                                            value={data.google_drive_root_folder_id}
                                            onChange={(e) => setData('google_drive_root_folder_id', e.target.value)}
                                            placeholder="วาง Folder ID เช่น 1aBcDeFgHiJkLmNoP หรือ URL ของโฟลเดอร์ Google Drive"
                                            className="block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-[#000080] focus:ring-[#000080]"
                                        />
                                        <p className="mt-1 text-xs text-slate-500">
                                            ระบบจะค้นหาหรือสร้างโฟลเดอร์ย่อย 4 ประเภท (รายวัน, พนักงานประจำตามสัญญา, พนักงานมหาวิทยาลัย, พนักงานอื่นๆ) และขั้นตอน 3 ขั้น (รับสมัคร, ประกาศรายชื่อ, ผลการคัดเลือก) ในโฟลเดอร์นี้ให้อัตโนมัติ
                                        </p>
                                    </div>

                                    {folderInspectionResult && (
                                        <div className={`rounded-xl border p-4 text-xs ${folderInspectionResult.success ? 'border-emerald-200 bg-emerald-50/50' : 'border-rose-200 bg-rose-50'}`}>
                                            {folderInspectionResult.success ? (
                                                <div>
                                                    <div className="flex items-center justify-between pb-2 mb-2 border-b border-emerald-200">
                                                        <div className="flex items-center gap-1.5 font-semibold text-emerald-800">
                                                            <FolderCheck className="h-4 w-4 text-emerald-600" />
                                                            เข้าถึงโฟลเดอร์หลัก: {folderInspectionResult.root_name || 'My Drive'} ({folderInspectionResult.root_id})
                                                        </div>
                                                        <button
                                                            type="button"
                                                            onClick={() => inspectFolders(true)}
                                                            className="text-emerald-700 underline hover:text-emerald-900"
                                                        >
                                                            สร้างโฟลเดอร์ที่ยังไม่มี
                                                        </button>
                                                    </div>
                                                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
                                                        {Object.entries(folderInspectionResult.tree || {}).map(([cat, info]) => (
                                                            <div key={cat} className="rounded-lg bg-white p-2 border border-emerald-100">
                                                                <div className="font-medium text-slate-800 flex items-center gap-1">
                                                                    <span className={info.exists ? 'text-emerald-600' : 'text-slate-400'}>📁</span>
                                                                    {cat}
                                                                </div>
                                                                <ul className="mt-1 space-y-0.5 text-[11px] text-slate-600 pl-4 list-disc">
                                                                    {Object.entries(info.stages || {}).map(([stg, sInfo]) => (
                                                                        <li key={stg} className={sInfo.exists ? 'text-emerald-700' : 'text-slate-400'}>
                                                                            {stg} {sInfo.exists ? '✓' : '(ยังไม่มี)'}
                                                                        </li>
                                                                    ))}
                                                                </ul>
                                                            </div>
                                                        ))}
                                                    </div>
                                                </div>
                                            ) : (
                                                <div className="flex items-center gap-2 text-rose-700">
                                                    <CircleAlert className="h-4 w-4 shrink-0" />
                                                    <span>ตรวจสอบไม่สำเร็จ: {folderInspectionResult.error}</span>
                                                </div>
                                            )}
                                        </div>
                                    )}

                                    <div className="grid gap-5 md:grid-cols-2">
                                        <div>
                                            <label htmlFor="google_drive_default_category" className="mb-1.5 block text-sm font-medium text-slate-700">
                                                ประเภทบุคลากรเริ่มต้น
                                            </label>
                                            <select
                                                id="google_drive_default_category"
                                                value={data.google_drive_default_category}
                                                onChange={(e) => setData('google_drive_default_category', e.target.value)}
                                                className="block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-[#000080] focus:ring-[#000080]"
                                            >
                                                <option value="รายวัน">รายวัน</option>
                                                <option value="พนักงานประจำตามสัญญา">พนักงานประจำตามสัญญา</option>
                                                <option value="พนักงานมหาวิทยาลัย">พนักงานมหาวิทยาลัย</option>
                                                <option value="พนักงานอื่นๆ">พนักงานอื่นๆ</option>
                                            </select>
                                        </div>

                                        <div>
                                            <label htmlFor="google_drive_default_stage" className="mb-1.5 block text-sm font-medium text-slate-700">
                                                ขั้นตอนเริ่มต้น
                                            </label>
                                            <select
                                                id="google_drive_default_stage"
                                                value={data.google_drive_default_stage}
                                                onChange={(e) => setData('google_drive_default_stage', e.target.value)}
                                                className="block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-[#000080] focus:ring-[#000080]"
                                            >
                                                <option value="รับสมัคร">รับสมัคร</option>
                                                <option value="ประกาศรายชื่อ">ประกาศรายชื่อ</option>
                                                <option value="ผลการคัดเลือก">ผลการคัดเลือก</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div>
                                        <div className="flex items-center justify-between mb-1.5">
                                            <label htmlFor="google_drive_default_comment_template" className="flex items-center gap-2 text-sm font-medium text-slate-700">
                                                <Sparkles className="h-4 w-4 text-[#000080]" />
                                                ข้อความคอมเมนต์แรกเริ่มต้นสำหรับ Facebook
                                            </label>
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    const cur = data.google_drive_default_comment_template || '';
                                                    if (!cur.includes('{drive_url}')) {
                                                        setData('google_drive_default_comment_template', cur ? `${cur}\n{drive_url}` : '{drive_url}');
                                                    }
                                                }}
                                                className="inline-flex items-center gap-1 rounded-md bg-blue-50 px-2 py-0.5 text-[11px] font-semibold text-[#000080] hover:bg-blue-100"
                                            >
                                                + แทรก {`{drive_url}`}
                                            </button>
                                        </div>
                                        <textarea
                                            id="google_drive_default_comment_template"
                                            rows={2}
                                            value={data.google_drive_default_comment_template}
                                            onChange={(e) => setData('google_drive_default_comment_template', e.target.value)}
                                            placeholder="📄 เอกสารประกาศฉบับเต็ม:\n{drive_url}"
                                            className="block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-[#000080] focus:ring-[#000080]"
                                        />
                                        <p className="mt-1 text-xs text-slate-500">
                                            ระบบจะแทนที่แท็ก <code className="text-[#000080] font-semibold">{`{drive_url}`}</code> ด้วยลิงก์ Google Drive สาธารณะให้อัตโนมัติในคอมเมนต์แรก (First Comment)
                                        </p>
                                    </div>
                                </div>
                            </section>
                        </div>

                        <div className="sticky bottom-4 z-10 rounded-2xl border border-slate-200 bg-white/95 p-4 shadow-lg backdrop-blur">
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div className="flex items-center gap-2 text-sm">
                                    {recentlySuccessful ? (
                                        <>
                                            <CheckCircle2 className="h-4 w-4 text-emerald-600" />
                                            <span className="font-medium text-emerald-700">บันทึกเรียบร้อยแล้ว</span>
                                        </>
                                    ) : isDirty ? (
                                        <>
                                            <CircleAlert className="h-4 w-4 text-amber-500" />
                                            <span className="text-slate-600">มีการเปลี่ยนแปลงที่ยังไม่ได้บันทึก</span>
                                        </>
                                    ) : (
                                        <>
                                            <ShieldCheck className="h-4 w-4 text-slate-400" />
                                            <span className="text-slate-500">ข้อมูลการเชื่อมต่อพร้อมใช้งาน</span>
                                        </>
                                    )}
                                </div>

                                <button
                                    type="submit"
                                    disabled={processing || !isDirty}
                                    className="inline-flex min-w-44 items-center justify-center gap-2 rounded-xl bg-[#000080] px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-900 focus:outline-none focus:ring-2 focus:ring-[#000080] focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {processing ? (
                                        <>
                                            <RefreshCw className="h-4 w-4 animate-spin" />
                                            กำลังบันทึก...
                                        </>
                                    ) : (
                                        <>
                                            <Save className="h-4 w-4" />
                                            บันทึกการตั้งค่า
                                        </>
                                    )}
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
