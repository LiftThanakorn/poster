import {
    CheckCircle2,
    CircleAlert,
    Clock3,
    LoaderCircle,
    RotateCcw,
} from 'lucide-react';
import { FacebookIcon, WordPressIcon } from '@/Components/ChannelIcons';

const STATUS_META = {
    pending: { label: 'รอดำเนินการ', icon: Clock3, tone: 'text-slate-600 bg-slate-100' },
    queued: { label: 'เข้าคิวแล้ว', icon: Clock3, tone: 'text-blue-700 bg-blue-50' },
    processing: { label: 'กำลังเผยแพร่', icon: LoaderCircle, tone: 'text-blue-700 bg-blue-50' },
    retrying: { label: 'กำลังลองใหม่', icon: RotateCcw, tone: 'text-amber-700 bg-amber-50' },
    published: { label: 'เผยแพร่แล้ว', icon: CheckCircle2, tone: 'text-emerald-700 bg-emerald-50' },
    failed: { label: 'ล้มเหลว', icon: CircleAlert, tone: 'text-rose-700 bg-rose-50' },
};

const STEP_LABELS = {
    pending: 'รอคิวเผยแพร่',
    queued: 'อยู่ในคิว',
    processing: 'กำลังส่งข้อมูลไปยังปลายทาง',
    retrying: 'รอลองเผยแพร่อีกครั้ง',
    published: 'เผยแพร่สำเร็จ',
    failed: 'เผยแพร่ไม่สำเร็จ',
};

export default function PublishProgress({ target }) {
    const meta = STATUS_META[target.status] || STATUS_META.pending;
    const Icon = meta.icon;
    const progress = Math.max(0, Math.min(100, Number(target.progress ?? 0)));

    return (
        <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div className="flex items-start justify-between gap-4">
                <div className="flex items-center gap-3">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-slate-50 border border-slate-100">
                        {target.channel === 'facebook' ? (
                            <FacebookIcon className="h-5 w-5 text-[#1877F2]" />
                        ) : (
                            <WordPressIcon className="h-5 w-5 text-[#21759B]" />
                        )}
                    </div>
                    <div>
                        <p className="text-sm font-semibold text-slate-900">
                            {target.channel === 'wordpress' ? 'WordPress' : 'Facebook Page'}
                        </p>
                        <p className="mt-0.5 text-xs text-slate-500">
                            {STEP_LABELS[target.step] || STEP_LABELS[target.status] || 'กำลังตรวจสอบสถานะ'}
                        </p>
                    </div>
                </div>

                <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ${meta.tone}`}>
                    <Icon className={`h-3.5 w-3.5 ${target.status === 'processing' ? 'animate-spin' : ''}`} />
                    {meta.label}
                </span>
            </div>

            <div className="mt-4">
                <div className="mb-1.5 flex items-center justify-between text-xs text-slate-500">
                    <span>ความคืบหน้า</span>
                    <span>{progress}%</span>
                </div>
                <div className="h-2 overflow-hidden rounded-full bg-slate-100">
                    <div
                        className={`h-full rounded-full transition-all duration-500 ${
                            target.status === 'failed'
                                ? 'bg-rose-500'
                                : target.status === 'published'
                                  ? 'bg-emerald-500'
                                  : 'bg-[#000080]'
                        }`}
                        style={{ width: `${progress}%` }}
                    />
                </div>
            </div>

            {target.error && (
                <div className="mt-4 rounded-xl border border-rose-100 bg-rose-50 px-3 py-2.5 text-xs leading-5 text-rose-700">
                    {target.error}
                </div>
            )}
        </div>
    );
}
