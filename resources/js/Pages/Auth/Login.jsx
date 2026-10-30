import { Head, Link, useForm } from '@inertiajs/react';
import { FacebookIcon, WordPressIcon } from '@/Components/ChannelIcons';
import {
    AlertCircle,
    ArrowRight,
    Building2,
    CalendarClock,
    CheckCircle2,
    Clock,
    ExternalLink,
    Eye,
    EyeOff,
    FolderOpen,
    Loader2,
    Lock,
    Mail,
    Newspaper,
    ShieldCheck,
} from 'lucide-react';
import { useEffect, useState } from 'react';

export default function Login({ status, canResetPassword }) {
    const [showPassword, setShowPassword] = useState(false);
    const [previewTab, setPreviewTab] = useState('wordpress');

    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    // Auto-toggle preview tab gently every 6 seconds to showcase cross-publishing
    useEffect(() => {
        const timer = setInterval(() => {
            setPreviewTab((prev) => (prev === 'wordpress' ? 'facebook' : 'wordpress'));
        }, 6000);
        return () => clearInterval(timer);
    }, []);

    const submit = (e) => {
        e.preventDefault();

        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <div className="min-h-screen bg-[#F8FAFC] text-slate-800 font-sans flex flex-col justify-between selection:bg-[#000080] selection:text-white">
            <Head title="เข้าสู่ระบบ • Poster ระบบประชาสัมพันธ์ข่าวสาร" />

            {/* Top Clean Institutional Bar */}
            <header className="border-b border-slate-200/80 bg-white/80 backdrop-blur-md sticky top-0 z-20">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-[#000080] text-white shadow-xs">
                            <Newspaper className="h-5 w-5" />
                        </div>
                        <div>
                            <div className="flex items-center gap-2">
                                <span className="text-base font-bold text-slate-900 tracking-tight">
                                    Poster
                                </span>
                                <span className="rounded-md bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-600">
                                    PR Hub
                                </span>
                            </div>
                            <p className="text-xs text-slate-500 hidden sm:block">
                                ระบบบริหารจัดการและกระจายข่าวสารประชาสัมพันธ์
                            </p>
                        </div>
                    </div>

                    <div className="flex items-center gap-2 text-xs text-slate-600">
                        <Building2 className="h-4 w-4 text-[#000080]" />
                        <span className="font-medium hidden sm:inline">มหาวิทยาลัยราชภัฏร้อยเอ็ด</span>
                        <span className="font-semibold text-slate-900 sm:hidden">RERU</span>
                    </div>
                </div>
            </header>

            {/* Main Content Area */}
            <main className="flex-1 flex items-center py-10 lg:py-16">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 w-full">
                    <div className="grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-14 items-center">
                        
                        {/* =========================================================
                            LEFT COLUMN: SYSTEM CAPABILITY & REALISTIC PREVIEW
                            ========================================================= */}
                        <div className="lg:col-span-7 space-y-6">
                            
                            {/* Headline */}
                            <div>
                                <span className="inline-flex items-center gap-1.5 rounded-full bg-blue-50 border border-blue-200/60 px-3 py-1 text-xs font-semibold text-[#000080] mb-3">
                                    <span className="h-2 w-2 rounded-full bg-emerald-500 animate-pulse" />
                                    ระบบเผยแพร่ข้อมูลข่าวสารพร้อมกัน 2 ช่องทาง
                                </span>
                                <h1 className="text-2xl sm:text-3xl lg:text-4xl font-extrabold text-slate-900 tracking-tight leading-tight">
                                    สร้างข่าวสารครั้งเดียว <br />
                                    กระจายสู่ <span className="text-[#21759B]">WordPress</span> และ <span className="text-[#1877F2]">Facebook</span> อัตโนมัติ
                                </h1>
                                <p className="mt-3 text-sm sm:text-base text-slate-600 leading-relaxed max-w-2xl">
                                    ศูนย์กลางสำหรับฝ่ายประชาสัมพันธ์และงานบุคคลในการร่างบทความ จัดหมวดหมู่คลังไฟล์สื่อ และตั้งเวลาเผยแพร่ออนไลน์อย่างแม่นยำ ป้องกันการตกหล่นของข้อมูล
                                </p>
                            </div>

                            {/* Interactive Showcase Card: Realistic Channel Switcher */}
                            <div className="rounded-2xl border border-slate-200 bg-white p-5 sm:p-6 shadow-sm">
                                
                                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-100 pb-4">
                                    <div className="flex items-center gap-2">
                                        <span className="text-xs font-bold text-slate-700">
                                            ตัวอย่างการกระจายข่าวจริง:
                                        </span>
                                    </div>

                                    {/* Tab Switcher */}
                                    <div className="inline-flex rounded-xl bg-slate-100 p-1">
                                        <button
                                            type="button"
                                            onClick={() => setPreviewTab('wordpress')}
                                            className={`inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition-all ${
                                                previewTab === 'wordpress'
                                                    ? 'bg-white text-slate-900 shadow-xs'
                                                    : 'text-slate-500 hover:text-slate-800'
                                            }`}
                                        >
                                            <WordPressIcon className="h-4 w-4 text-[#21759B]" />
                                            <span>เว็บไซต์ WordPress</span>
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => setPreviewTab('facebook')}
                                            className={`inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition-all ${
                                                previewTab === 'facebook'
                                                    ? 'bg-white text-slate-900 shadow-xs'
                                                    : 'text-slate-500 hover:text-slate-800'
                                            }`}
                                        >
                                            <FacebookIcon className="h-4 w-4 text-[#1877F2]" />
                                            <span>Facebook Page</span>
                                        </button>
                                    </div>
                                </div>

                                {/* Preview Display */}
                                <div className="mt-4 transition-all duration-300">
                                    {previewTab === 'wordpress' ? (
                                        /* WordPress Realistic Article Card */
                                        <div className="rounded-xl border border-slate-200/80 bg-slate-50/50 p-4 space-y-3">
                                            <div className="flex items-center justify-between text-xs text-slate-500">
                                                <span className="font-semibold text-[#21759B] bg-[#21759B]/10 px-2 py-0.5 rounded-md">
                                                    หมวดหมู่: ข่าวรับสมัครงาน
                                                </span>
                                                <span className="flex items-center gap-1">
                                                    <Clock className="h-3 w-3" /> เผยแพร่วันนี้ 09:00 น.
                                                </span>
                                            </div>

                                            <h3 className="text-sm font-bold text-slate-900 leading-snug">
                                                ประกาศรับสมัครบุคคลเพื่อเลือกสรรเป็นพนักงานในสถาบันอุดมศึกษา สังกัดมหาวิทยาลัยราชภัฏร้อยเอ็ด
                                            </h3>

                                            <p className="text-xs text-slate-600 line-clamp-2 leading-relaxed">
                                                ด้วยมหาวิทยาลัยราชภัฏร้อยเอ็ด มีความประสงค์จะรับสมัครบุคคลทั่วไปเพื่อจัดจ้างเป็นพนักงานในสถาบันอุดมศึกษา ประจำปีงบประมาณ 2569...
                                            </p>

                                            <div className="flex items-center justify-between pt-2 border-t border-slate-200/60 text-[11px] text-slate-500">
                                                <span>แนบไฟล์ประกาศ PDF • ภาพประกอบพร้อม</span>
                                                <span className="font-semibold text-emerald-700 flex items-center gap-1">
                                                    <CheckCircle2 className="h-3.5 w-3.5" /> ซิงค์ผ่าน REST API แล้ว
                                                </span>
                                            </div>
                                        </div>
                                    ) : (
                                        /* Facebook Realistic Post Card */
                                        <div className="rounded-xl border border-slate-200/80 bg-slate-50/50 p-4 space-y-3">
                                            <div className="flex items-center gap-2.5">
                                                <div className="h-8 w-8 rounded-full bg-[#1877F2] flex items-center justify-center text-white">
                                                    <FacebookIcon className="h-5 w-5" />
                                                </div>
                                                <div>
                                                    <p className="text-xs font-bold text-slate-900">มหาวิทยาลัยราชภัฏร้อยเอ็ด RERU</p>
                                                    <p className="text-[10px] text-slate-400">เมื่อสักครู่ • โพสต์ผ่านระบบอัตโนมัติ</p>
                                                </div>
                                            </div>

                                            <p className="text-xs text-slate-700 leading-relaxed">
                                                ประกาศรับสมัครบุคคลเพื่อเลือกสรรเป็นพนักงานในสถาบันอุดมศึกษา ประจำปีงบประมาณ 2569 ผู้สนใจสามารถอ่านรายละเอียดและดาวน์โหลดใบสมัครได้ทางลิงก์แนบ...
                                            </p>

                                            <div className="rounded-lg bg-blue-50/80 border border-blue-100 p-2 text-[11px] text-blue-900 flex items-center justify-between">
                                                <span>💬 คอมเมนต์แรก: แนบลิงก์บทความ WordPress อัตโนมัติ</span>
                                                <span className="font-semibold text-emerald-700 flex items-center gap-1">
                                                    <CheckCircle2 className="h-3.5 w-3.5" /> Graph API เชื่อมต่อแล้ว
                                                </span>
                                            </div>
                                        </div>
                                    )}
                                </div>

                            </div>

                            {/* Feature Pillars */}
                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-1">
                                <div className="flex items-start gap-2.5 rounded-xl border border-slate-200/80 bg-white p-3 shadow-xs">
                                    <div className="p-1.5 rounded-lg bg-blue-50 text-[#000080]">
                                        <CalendarClock className="h-4 w-4" />
                                    </div>
                                    <div>
                                        <p className="text-xs font-bold text-slate-800">ตั้งเวลาล่วงหน้า</p>
                                        <p className="text-[11px] text-slate-500 mt-0.5">ระบุวันและเวลาโพสต์ 24 ชม.</p>
                                    </div>
                                </div>

                                <div className="flex items-start gap-2.5 rounded-xl border border-slate-200/80 bg-white p-3 shadow-xs">
                                    <div className="p-1.5 rounded-lg bg-blue-50 text-[#000080]">
                                        <FolderOpen className="h-4 w-4" />
                                    </div>
                                    <div>
                                        <p className="text-xs font-bold text-slate-800">คลังสื่อภาพและเอกสาร</p>
                                        <p className="text-[11px] text-slate-500 mt-0.5">จัดระเบียบโฟลเดอร์และหมวด</p>
                                    </div>
                                </div>

                                <div className="flex items-start gap-2.5 rounded-xl border border-slate-200/80 bg-white p-3 shadow-xs">
                                    <div className="p-1.5 rounded-lg bg-blue-50 text-[#000080]">
                                        <ShieldCheck className="h-4 w-4" />
                                    </div>
                                    <div>
                                        <p className="text-xs font-bold text-slate-800">คิวงานมั่นคงปลอดภัย</p>
                                        <p className="text-[11px] text-slate-500 mt-0.5">มีระบบลองใหม่เมื่อเน็ตขัดข้อง</p>
                                    </div>
                                </div>
                            </div>

                        </div>

                        {/* =========================================================
                            RIGHT COLUMN: CLEAN HUMAN-DESIGNED LOGIN FORM
                            ========================================================= */}
                        <div className="lg:col-span-5">
                            <div className="rounded-2xl border border-slate-200 bg-white p-7 sm:p-9 shadow-sm">
                                
                                {/* Form Title */}
                                <div className="mb-6">
                                    <div className="inline-flex items-center gap-1.5 rounded-md bg-slate-100 px-2 py-1 text-[11px] font-semibold text-slate-700 mb-2">
                                        <Lock className="h-3 w-3 text-[#000080]" />
                                        เข้าสู่ระบบสำหรับเจ้าหน้าที่
                                    </div>
                                    <h2 className="text-xl sm:text-2xl font-bold text-slate-900 tracking-tight">
                                        ลงชื่อเข้าใช้งาน
                                    </h2>
                                    <p className="mt-1 text-xs text-slate-500">
                                        กรอกบัญชีผู้ใช้เพื่อเข้าจัดการข่าวสารและสื่อประชาสัมพันธ์
                                    </p>
                                </div>

                                {/* Status Notice */}
                                {status && (
                                    <div className="mb-5 flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-xs font-semibold text-emerald-800">
                                        <CheckCircle2 className="h-4 w-4 shrink-0 text-emerald-600" />
                                        <span>{status}</span>
                                    </div>
                                )}

                                {/* Login Form */}
                                <form onSubmit={submit} className="space-y-4">
                                    
                                    {/* Email Field */}
                                    <div>
                                        <label htmlFor="email" className="block text-xs font-semibold text-slate-700 mb-1.5">
                                            อีเมลบุคลากร
                                        </label>
                                        <div className="relative">
                                            <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                                                <Mail className="h-4 w-4" />
                                            </div>
                                            <input
                                                id="email"
                                                type="email"
                                                name="email"
                                                value={data.email}
                                                autoComplete="username"
                                                autoFocus
                                                placeholder="personnel.data@reru.ac.th"
                                                onChange={(e) => setData('email', e.target.value)}
                                                className={`block w-full rounded-xl border pl-9 pr-3 py-2.5 text-sm transition focus:outline-none ${
                                                    errors.email
                                                        ? 'border-rose-300 bg-rose-50/20 text-rose-900 focus:border-rose-500 focus:ring-2 focus:ring-rose-200'
                                                        : 'border-slate-300 text-slate-900 focus:border-[#000080] focus:ring-2 focus:ring-[#000080]/15'
                                                }`}
                                                required
                                            />
                                        </div>
                                        {errors.email && (
                                            <div className="mt-1 flex items-center gap-1 text-xs text-rose-600">
                                                <AlertCircle className="h-3.5 w-3.5 shrink-0" />
                                                <span>{errors.email}</span>
                                            </div>
                                        )}
                                    </div>

                                    {/* Password Field */}
                                    <div>
                                        <label htmlFor="password" className="block text-xs font-semibold text-slate-700 mb-1.5">
                                            รหัสผ่าน
                                        </label>
                                        <div className="relative">
                                            <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                                                <Lock className="h-4 w-4" />
                                            </div>
                                            <input
                                                id="password"
                                                type={showPassword ? 'text' : 'password'}
                                                name="password"
                                                value={data.password}
                                                autoComplete="current-password"
                                                placeholder="••••••••"
                                                onChange={(e) => setData('password', e.target.value)}
                                                className={`block w-full rounded-xl border pl-9 pr-10 py-2.5 text-sm transition focus:outline-none ${
                                                    errors.password
                                                        ? 'border-rose-300 bg-rose-50/20 text-rose-900 focus:border-rose-500 focus:ring-2 focus:ring-rose-200'
                                                        : 'border-slate-300 text-slate-900 focus:border-[#000080] focus:ring-2 focus:ring-[#000080]/15'
                                                }`}
                                                required
                                            />
                                            <button
                                                type="button"
                                                onClick={() => setShowPassword(!showPassword)}
                                                className="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-600"
                                                aria-label={showPassword ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน'}
                                            >
                                                {showPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                                            </button>
                                        </div>
                                        {errors.password && (
                                            <div className="mt-1 flex items-center gap-1 text-xs text-rose-600">
                                                <AlertCircle className="h-3.5 w-3.5 shrink-0" />
                                                <span>{errors.password}</span>
                                            </div>
                                        )}
                                    </div>

                                    {/* Remember & Forgot Password */}
                                    <div className="flex items-center justify-between pt-1">
                                        <label className="flex items-center gap-2 cursor-pointer select-none">
                                            <input
                                                type="checkbox"
                                                name="remember"
                                                checked={data.remember}
                                                onChange={(e) => setData('remember', e.target.checked)}
                                                className="h-4 w-4 rounded border-slate-300 text-[#000080] focus:ring-[#000080]/20"
                                            />
                                            <span className="text-xs text-slate-600">
                                                จดจำการเข้าสู่ระบบ
                                            </span>
                                        </label>

                                        {canResetPassword && (
                                            <Link
                                                href={route('password.request')}
                                                className="text-xs font-semibold text-[#000080] hover:underline"
                                            >
                                                ลืมรหัสผ่าน?
                                            </Link>
                                        )}
                                    </div>

                                    {/* Submit Button */}
                                    <div className="pt-2">
                                        <button
                                            type="submit"
                                            disabled={processing}
                                            className="w-full rounded-xl bg-[#000080] py-3 px-4 text-sm font-semibold text-white shadow-xs transition hover:bg-[#000066] active:scale-[0.99] disabled:opacity-60 flex items-center justify-center gap-2"
                                        >
                                            {processing ? (
                                                <>
                                                    <Loader2 className="h-4 w-4 animate-spin" />
                                                    <span>กำลังเข้าสู่ระบบ...</span>
                                                </>
                                            ) : (
                                                <>
                                                    <span>เข้าสู่ระบบ</span>
                                                    <ArrowRight className="h-4 w-4" />
                                                </>
                                            )}
                                        </button>
                                    </div>

                                </form>

                                {/* Institutional Guidance Box */}
                                <div className="mt-6 rounded-xl border border-slate-100 bg-slate-50 p-3 text-xs text-slate-500">
                                    <p className="font-semibold text-slate-700">คำแนะนำการใช้งาน:</p>
                                    <p className="mt-0.5 leading-relaxed">
                                        ใช้บัญชีอีเมลสถาบัน (@reru.ac.th) ในการเข้าสู่ระบบ หากพบปัญหาการเข้าใช้งาน โปรดติดต่อผู้ดูแลระบบสารสนเทศ
                                    </p>
                                </div>

                            </div>
                        </div>

                    </div>
                </div>
            </main>

            {/* Bottom Institutional Footer */}
            <footer className="border-t border-slate-200/80 bg-white py-4 text-center text-xs text-slate-500">
                <div className="mx-auto max-w-7xl px-4 flex flex-col sm:flex-row items-center justify-between gap-2">
                    <p>© 2026 มหาวิทยาลัยราชภัฏร้อยเอ็ด • Roi Et Rajabhat University</p>
                    <p className="flex items-center gap-1.5 text-slate-400">
                        <ShieldCheck className="h-4 w-4 text-emerald-600" />
                        ระบบความปลอดภัยและข้อมูลเข้ารหัสตามมาตรฐาน
                    </p>
                </div>
            </footer>

        </div>
    );
}
