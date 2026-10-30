import {
    AlertTriangle,
    CheckCircle2,
    CircleDot,
    RotateCcw,
} from 'lucide-react';
import { FacebookIcon, WordPressIcon } from '@/Components/ChannelIcons';

const EVENT_ICONS = {
    publish_succeeded: CheckCircle2,
    publish_failed: AlertTriangle,
    publish_retrying: RotateCcw,
};

function formatDate(value) {
    if (!value) return '';

    return new Date(value).toLocaleString('th-TH', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

export default function PublishTimeline({ events = [] }) {
    if (!events.length) {
        return (
            <div className="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-5 py-10 text-center text-sm text-slate-500">
                ยังไม่มีกิจกรรมการเผยแพร่
            </div>
        );
    }

    return (
        <div className="space-y-1">
            {events.map((event) => {
                const Icon = EVENT_ICONS[event.event_type] || CircleDot;
                const tone = event.level === 'error'
                    ? 'text-rose-600 bg-rose-50'
                    : event.level === 'warning'
                      ? 'text-amber-600 bg-amber-50'
                      : 'text-blue-700 bg-blue-50';

                return (
                    <div key={event.id} className="relative flex gap-3 py-3">
                        <div className={`mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full ${tone}`}>
                            <Icon className="h-4 w-4" />
                        </div>
                        <div className="min-w-0 flex-1 border-b border-slate-100 pb-3">
                            <div className="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                                <p className="text-sm font-medium text-slate-800">{event.message}</p>
                                <span className="shrink-0 text-xs text-slate-400">{formatDate(event.created_at)}</span>
                            </div>
                            {event.channel && (
                                <div className="mt-1 flex items-center gap-1.5 text-xs text-slate-500">
                                    <span>ช่องทาง:</span>
                                    {event.channel === 'facebook' ? (
                                        <span className="inline-flex items-center gap-1 font-medium text-slate-700">
                                            <FacebookIcon className="h-3.5 w-3.5 text-[#1877F2]" />
                                            Facebook
                                        </span>
                                    ) : (
                                        <span className="inline-flex items-center gap-1 font-medium text-slate-700">
                                            <WordPressIcon className="h-3.5 w-3.5 text-[#21759B]" />
                                            WordPress
                                        </span>
                                    )}
                                </div>
                            )}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
