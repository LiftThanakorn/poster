import { useEffect } from 'react';
import { AlertCircle, ArrowRight, CheckCircle2, ExternalLink, Loader2, RefreshCw } from 'lucide-react';
import { FacebookIcon, WordPressIcon } from '@/Components/ChannelIcons';

export default function PostSubmitProgressModal({
    isOpen,
    status = 'uploading', // 'uploading' | 'publishing' | 'success' | 'partial' | 'error'
    progress = 0,
    channels = [],
    targets = [],
    actionType = 'publish', // 'publish' | 'draft' | 'schedule'
    errorMessage = '',
    onClose,
    onNavigateNow,
}) {
    if (!isOpen) return null;

    const isPublish = actionType === 'publish';
    const isSchedule = actionType === 'schedule';

    const getActionTitle = () => {
        if (status === 'success') {
            if (isPublish) return 'เผยแพร่โพสต์จริงสำเร็จเรียบร้อยแล้ว!';
            if (isSchedule) return 'บันทึกและตั้งเวลาเผยแพร่สำเร็จ!';
            return 'บันทึกฉบับร่างเรียบร้อยแล้ว!';
        }
        if (status === 'partial') {
            return 'เผยแพร่สำเร็จบางช่องทาง';
        }
        if (status === 'error') {
            return 'เกิดข้อผิดพลาดในการเผยแพร่';
        }
        if (status === 'publishing') {
            return 'กำลังส่งโพสต์ขึ้นระบบจริง...';
        }
        if (isPublish) return 'กำลังอัปโหลดข้อมูลและไฟล์แนบ...';
        if (isSchedule) return 'กำลังตั้งเวลาเผยแพร่...';
        return 'กำลังบันทึกฉบับร่าง...';
    };

    // Calculate overall publishing percentage
    const getOverallPercentage = () => {
        if (status === 'success') return 100;
        if (status === 'uploading') return Math.round(progress * 0.35); // 0% - 35% during upload
        if (status === 'publishing') {
            if (!targets || targets.length === 0) return 40;
            const targetAvg = targets.reduce((sum, t) => sum + (t.progress || 10), 0) / targets.length;
            // Scale from 35% to 95% based on target progress
            return Math.min(95, Math.round(35 + (targetAvg * 0.6)));
        }
        return progress;
    };

    const overallPercent = getOverallPercentage();

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/65 backdrop-blur-xs p-4 animate-in fade-in duration-200">
            <div className="relative w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl border border-slate-100 text-center overflow-hidden">
                {/* Top status indicator bar */}
                <div
                    className={`absolute top-0 left-0 right-0 h-1.5 transition-colors duration-300 ${
                        status === 'success'
                            ? 'bg-emerald-500'
                            : status === 'partial'
                            ? 'bg-amber-500'
                            : status === 'error'
                            ? 'bg-rose-500'
                            : 'bg-[#000080]'
                    }`}
                />

                {/* Status Graphic & Icon */}
                <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full">
                    {(status === 'uploading' || status === 'publishing') && (
                        <div className="relative flex items-center justify-center">
                            <div className="h-16 w-16 rounded-full border-4 border-slate-100 border-t-[#000080] animate-spin" />
                            <span className="absolute text-xs font-bold text-[#000080]">
                                {overallPercent}%
                            </span>
                        </div>
                    )}

                    {status === 'success' && (
                        <div className="flex h-16 w-16 items-center justify-center rounded-full bg-emerald-50 text-emerald-600 animate-in zoom-in-75 duration-300">
                            <CheckCircle2 className="h-10 w-10 text-emerald-600" />
                        </div>
                    )}

                    {status === 'partial' && (
                        <div className="flex h-16 w-16 items-center justify-center rounded-full bg-amber-50 text-amber-600 animate-in zoom-in-75 duration-300">
                            <AlertCircle className="h-10 w-10 text-amber-600" />
                        </div>
                    )}

                    {status === 'error' && (
                        <div className="flex h-16 w-16 items-center justify-center rounded-full bg-rose-50 text-rose-600 animate-in zoom-in-75 duration-300">
                            <AlertCircle className="h-10 w-10 text-rose-600" />
                        </div>
                    )}
                </div>

                {/* Title */}
                <h3 className="text-lg font-bold text-slate-900">
                    {getActionTitle()}
                </h3>

                {/* Subtitle / Status description */}
                <p className="mt-1.5 text-xs text-slate-500">
                    {status === 'uploading' && `กำลังอัปโหลดข้อมูลและไฟล์เอกสาร (${progress}%)...`}
                    {status === 'publishing' && 'กำลังเชื่อมต่อและนำโพสต์ขึ้นสู่ช่องทางจริง กรุณารอสักครู่...'}
                    {status === 'success' && 'โพสต์ถูกเผยแพร่จริงบนระบบปลายทางเรียบร้อยแล้ว'}
                    {status === 'partial' && 'บางช่องทางเผยแพร่สำเร็จ แต่มีบางช่องทางเกิดข้อผิดพลาด'}
                    {status === 'error' && (errorMessage || 'ไม่สามารถเผยแพร่โพสต์ได้')}
                </p>

                {/* Overall Progress Bar during uploading or publishing */}
                {(status === 'uploading' || status === 'publishing') && (
                    <div className="mt-4 space-y-2">
                        <div className="h-2 w-full overflow-hidden rounded-full bg-slate-100">
                            <div
                                className="h-full rounded-full bg-[#000080] transition-all duration-300 ease-out"
                                style={{ width: `${Math.max(8, overallPercent)}%` }}
                            />
                        </div>
                        <div className="flex items-center justify-between text-[11px] text-slate-400">
                            <span>ขั้นตอน: {status === 'uploading' ? 'อัปโหลดไฟล์ข้อมูล' : 'ส่งโพสต์ขึ้นระบบจริง'}</span>
                            <span>{overallPercent}%</span>
                        </div>
                    </div>
                )}

                {/* Live Channel Status Cards (WordPress & Facebook) */}
                {channels.length > 0 && isPublish && (
                    <div className="mt-5 space-y-2.5 text-left border-t border-slate-100 pt-4">
                        <div className="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">
                            สถานะการส่งไปยังแต่ละช่องทาง:
                        </div>

                        {channels.map((channel) => {
                            const target = targets.find((t) => t.channel === channel);
                            const tStatus = target?.status || (status === 'uploading' ? 'pending' : 'queued');
                            const externalUrl = target?.external_url;
                            const targetError = target?.error;

                            return (
                                <div
                                    key={channel}
                                    className={`flex items-center justify-between gap-3 p-3 rounded-xl border transition-all ${
                                        tStatus === 'published'
                                            ? 'border-emerald-200 bg-emerald-50/60'
                                            : tStatus === 'failed'
                                            ? 'border-rose-200 bg-rose-50/60'
                                            : tStatus === 'processing'
                                            ? 'border-blue-200 bg-blue-50/50'
                                            : 'border-slate-200 bg-slate-50/50'
                                    }`}
                                >
                                    <div className="flex items-center gap-2.5 min-w-0">
                                        {channel === 'wordpress' ? (
                                            <WordPressIcon className="h-5 w-5 text-[#21759B] shrink-0" />
                                        ) : (
                                            <FacebookIcon className="h-5 w-5 text-[#1877F2] shrink-0" />
                                        )}
                                        <div className="min-w-0">
                                            <div className="text-xs font-semibold text-slate-900 flex items-center gap-1.5">
                                                <span>{channel === 'wordpress' ? 'WordPress' : 'Facebook Page'}</span>
                                                {tStatus === 'published' && (
                                                    <span className="text-[10px] text-emerald-700 bg-emerald-100 font-medium px-1.5 py-0.2 rounded">
                                                        โพสต์จริงแล้ว
                                                    </span>
                                                )}
                                            </div>
                                            <div className="text-[11px] text-slate-500 mt-0.5 truncate">
                                                {tStatus === 'pending' && 'กำลังเตรียมข้อมูล...'}
                                                {tStatus === 'queued' && 'อยู่ในคิวพร้อมเผยแพร่...'}
                                                {tStatus === 'processing' && 'กำลังส่งขึ้น API ปลายทาง...'}
                                                {tStatus === 'published' && 'เผยแพร่เสร็จสมบูรณ์'}
                                                {tStatus === 'failed' && (
                                                    <span className="text-rose-600">
                                                        {targetError || 'ส่งไม่สำเร็จ'}
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                    </div>

                                    <div className="flex items-center gap-2 shrink-0">
                                        {tStatus === 'processing' && (
                                            <Loader2 className="h-4 w-4 animate-spin text-blue-600" />
                                        )}
                                        {tStatus === 'published' && externalUrl && (
                                            <a
                                                href={externalUrl}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="inline-flex items-center gap-1 text-[11px] font-medium text-emerald-700 hover:text-emerald-900 bg-emerald-100/80 px-2 py-1 rounded hover:bg-emerald-200 transition-colors"
                                            >
                                                <span>เปิดดู</span>
                                                <ExternalLink className="h-3 w-3" />
                                            </a>
                                        )}
                                        {tStatus === 'published' && !externalUrl && (
                                            <CheckCircle2 className="h-4 w-4 text-emerald-600" />
                                        )}
                                        {tStatus === 'failed' && (
                                            <AlertCircle className="h-4 w-4 text-rose-600" />
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}

                {/* Success Action and Countdown */}
                {status === 'success' && (
                    <div className="mt-5 space-y-3">
                        <p className="text-xs text-slate-500">
                            ระบบกำลังนำคุณไปยังหน้ารายการรวม...
                        </p>
                        <button
                            type="button"
                            onClick={onNavigateNow}
                            className="inline-flex items-center gap-1.5 rounded-lg bg-[#000080] px-5 py-2.5 text-xs font-semibold text-white shadow-sm hover:bg-blue-900 transition-colors"
                        >
                            <span>ไปที่หน้ารายการทันที</span>
                            <ArrowRight className="h-3.5 w-3.5" />
                        </button>
                    </div>
                )}

                {/* Partial Success Action */}
                {status === 'partial' && (
                    <div className="mt-5 space-y-3">
                        <button
                            type="button"
                            onClick={onNavigateNow}
                            className="inline-flex items-center gap-1.5 rounded-lg bg-[#000080] px-5 py-2.5 text-xs font-semibold text-white shadow-sm hover:bg-blue-900 transition-colors"
                        >
                            <span>ไปที่หน้ารายการเพื่อจัดการ</span>
                            <ArrowRight className="h-3.5 w-3.5" />
                        </button>
                    </div>
                )}

                {/* Error Action */}
                {status === 'error' && (
                    <div className="mt-5 flex gap-2">
                        <button
                            type="button"
                            onClick={onClose}
                            className="flex-1 rounded-lg bg-slate-100 px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-200 transition-colors"
                        >
                            กลับไปแก้ไขในฟอร์ม
                        </button>
                        <button
                            type="button"
                            onClick={onNavigateNow}
                            className="flex-1 rounded-lg border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition-colors"
                        >
                            ไปที่หน้ารายการ
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}
