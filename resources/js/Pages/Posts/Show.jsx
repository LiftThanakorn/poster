import PublishProgress from '@/Components/PublishProgress';
import PublishTimeline from '@/Components/PublishTimeline';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import {
    ArrowLeft,
    Copy,
    ExternalLink,
    Pencil,
    RefreshCw,
    Trash2,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import Swal from 'sweetalert2';

const ACTIVE_STATES = ['publishing', 'queued', 'processing', 'retrying'];

const POST_STATUS_LABELS = {
    draft: 'ฉบับร่าง',
    scheduled: 'ตั้งเวลาแล้ว',
    publishing: 'กำลังเผยแพร่',
    published: 'เผยแพร่แล้ว',
    partially_published: 'เผยแพร่บางส่วน',
    failed: 'ล้มเหลว',
};

function formatDate(value) {
    if (!value) return '-';

    return new Date(value).toLocaleString('th-TH', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

export default function Show({ post, replacementOriginal = null }) {
    const [statusData, setStatusData] = useState({
        id: post.id,
        status: post.status,
        is_terminal: !ACTIVE_STATES.includes(post.status),
        targets: (post.targets || []).map((target) => ({
            ...target,
            progress: target.status === 'published' ? 100 : target.status === 'failed' ? 100 : 10,
            step: target.status,
        })),
    });
    const [timeline, setTimeline] = useState({ attempts: [], events: [] });
    const [retrying, setRetrying] = useState(null);
    const [originalDeletionState, setOriginalDeletionState] = useState(replacementOriginal?.facebook_status || null);
    const replacementPublished = (statusData.targets || []).some((target) =>
        target.channel === 'facebook' && target.status === 'published' && Boolean(target.external_url),
    );

    const loadStatus = useCallback(async () => {
        const { data } = await axios.get(route('posts.status', post.id));
        setStatusData(data);
        return data;
    }, [post.id]);

    const loadTimeline = useCallback(async () => {
        const { data } = await axios.get(route('posts.timeline', post.id));
        setTimeline(data);
    }, [post.id]);

    useEffect(() => {
        loadStatus().catch(() => {});
        loadTimeline().catch(() => {});
    }, [loadStatus, loadTimeline]);

    useEffect(() => {
        if (!ACTIVE_STATES.includes(statusData.status) && statusData.is_terminal) {
            return undefined;
        }

        const timer = window.setInterval(async () => {
            try {
                const next = await loadStatus();
                if (next.is_terminal) {
                    await loadTimeline();
                }
            } catch {
                // Keep the page usable if a transient polling request fails.
            }
        }, 1500);

        return () => window.clearInterval(timer);
    }, [statusData.status, statusData.is_terminal, loadStatus, loadTimeline]);

    const retryChannel = async (channel) => {
        const result = await Swal.fire({
            icon: 'question',
            title: 'ลองเผยแพร่อีกครั้ง?',
            text: `ระบบจะลองเฉพาะช่องทาง ${channel === 'wordpress' ? 'WordPress' : 'Facebook'} โดยไม่สร้างโพสต์ช่องทางอื่นซ้ำ`,
            showCancelButton: true,
            confirmButtonText: 'ลองใหม่',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#000080',
            reverseButtons: true,
        });

        if (!result.isConfirmed) return;

        setRetrying(channel);

        try {
            await axios.post(route('posts.retry', [post.id, channel]));
            await loadStatus();
            await loadTimeline();

            Swal.fire({
                icon: 'success',
                title: 'ส่งเข้าคิวแล้ว',
                text: 'ระบบกำลังลองเผยแพร่ช่องทางนี้อีกครั้ง',
                toast: true,
                position: 'top-end',
                timer: 2200,
                showConfirmButton: false,
            });
        } catch (error) {
            Swal.fire({
                icon: 'error',
                title: 'ไม่สามารถลองใหม่ได้',
                text: error.response?.data?.message || 'กรุณาลองอีกครั้ง',
                confirmButtonColor: '#000080',
            });
        } finally {
            setRetrying(null);
        }
    };

    const deleteOriginal = async () => {
        const result = await Swal.fire({
            icon: 'warning',
            title: 'ลบโพสต์ Facebook เดิม?',
            html: 'โพสต์ใหม่เผยแพร่แล้ว หากยืนยันจะส่งคำสั่งลบโพสต์ Facebook เดิมจริง ยอดถูกใจ ความคิดเห็นและสถิติของโพสต์เก่าจะไม่ย้ายมายังโพสต์ใหม่',
            showCancelButton: true,
            confirmButtonText: 'ยืนยันลบโพสต์เก่า',
            cancelButtonText: 'เก็บโพสต์เก่าไว้',
            confirmButtonColor: '#b91c1c',
            reverseButtons: true,
        });

        if (!result.isConfirmed) return;

        setOriginalDeletionState('submitting');

        try {
            await axios.post(route('posts.facebook-replacement.delete-original', post.id));
            setOriginalDeletionState('deleting');
            await Swal.fire({
                icon: 'success',
                title: 'ส่งคำสั่งลบแล้ว',
                text: 'ระบบกำลังลบโพสต์ Facebook เดิม โดยไม่กระทบโพสต์ใหม่',
                confirmButtonColor: '#000080',
            });
        } catch (error) {
            setOriginalDeletionState('published');
            await Swal.fire({
                icon: 'error',
                title: 'ลบโพสต์เก่าไม่สำเร็จ',
                text: error.response?.data?.message || 'โปรดตรวจสอบสถานะก่อนลองอีกครั้ง',
                confirmButtonColor: '#000080',
            });
        }
    };

    const duplicatePost = () => {
        router.post(route('posts.duplicate', post.id));
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="flex items-center gap-2 text-sm text-slate-500">
                            <Link href={route('posts.index')} className="inline-flex items-center gap-1 hover:text-[#000080]">
                                <ArrowLeft className="h-4 w-4" />
                                กลับไปรายการโพสต์
                            </Link>
                        </div>
                        <h1 className="mt-2 text-xl font-semibold text-slate-900">{post.title}</h1>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <button
                            type="button"
                            onClick={duplicatePost}
                            className="inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                        >
                            <Copy className="h-4 w-4" />
                            ทำสำเนา
                        </button>
                        <Link
                            href={route('posts.edit', post.id)}
                            className="inline-flex items-center gap-2 rounded-xl bg-[#000080] px-3.5 py-2 text-sm font-semibold text-white hover:bg-blue-900"
                        >
                            <Pencil className="h-4 w-4" />
                            แก้ไข
                        </Link>
                    </div>
                </div>
            }
        >
            <Head title={post.title} />

            <div className="py-8">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
                            <div>
                                <p className="text-xs font-medium uppercase tracking-wide text-slate-400">สถานะ</p>
                                <p className="mt-1 font-semibold text-slate-900">
                                    {POST_STATUS_LABELS[statusData.status] || statusData.status}
                                </p>
                            </div>
                            <div>
                                <p className="text-xs font-medium uppercase tracking-wide text-slate-400">ผู้สร้าง</p>
                                <p className="mt-1 font-medium text-slate-800">{post.author?.name || '-'}</p>
                            </div>
                            <div>
                                <p className="text-xs font-medium uppercase tracking-wide text-slate-400">สร้างเมื่อ</p>
                                <p className="mt-1 font-medium text-slate-800">{formatDate(post.created_at)}</p>
                            </div>
                            <div>
                                <p className="text-xs font-medium uppercase tracking-wide text-slate-400">เผยแพร่เมื่อ</p>
                                <p className="mt-1 font-medium text-slate-800">{formatDate(post.published_at)}</p>
                            </div>
                        </div>
                    </section>

                    <section>
                        <div className="mb-3 flex items-center justify-between">
                            <div>
                                <h2 className="font-semibold text-slate-900">สถานะการเผยแพร่</h2>
                                <p className="mt-1 text-sm text-slate-500">อัปเดตอัตโนมัติระหว่างที่ระบบกำลังทำงาน</p>
                            </div>
                            {ACTIVE_STATES.includes(statusData.status) && (
                                <span className="inline-flex items-center gap-2 text-xs font-medium text-blue-700">
                                    <RefreshCw className="h-3.5 w-3.5 animate-spin" />
                                    กำลังอัปเดต
                                </span>
                            )}
                        </div>

                        <div className="grid gap-4 lg:grid-cols-2">
                            {statusData.targets.map((target) => (
                                <div key={target.id} className="space-y-3">
                                    <PublishProgress target={target} />

                                    <div className="flex flex-wrap gap-2">
                                        {target.external_url && (
                                            <a
                                                href={target.external_url}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50"
                                            >
                                                <ExternalLink className="h-3.5 w-3.5" />
                                                เปิดโพสต์จริง
                                            </a>
                                        )}

                                        {(target.status === 'failed' || target.status === 'retrying') && (
                                            <button
                                                type="button"
                                                onClick={() => retryChannel(target.channel)}
                                                disabled={retrying === target.channel}
                                                className="inline-flex items-center gap-1.5 rounded-lg bg-[#000080] px-3 py-2 text-xs font-semibold text-white hover:bg-blue-900 disabled:opacity-50"
                                            >
                                                <RefreshCw className={`h-3.5 w-3.5 ${retrying === target.channel ? 'animate-spin' : ''}`} />
                                                {retrying === target.channel ? 'กำลังส่งเข้าคิว...' : (target.status === 'retrying' ? 'ลองใหม่ทันที' : 'ลองใหม่')}
                                            </button>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </section>

                    {replacementOriginal && (
                        <section className="rounded-2xl border border-blue-200 bg-white p-6 shadow-sm">
                            <h2 className="font-semibold text-slate-900">โพสต์ Facebook เดิม</h2>
                            <p className="mt-2 text-sm text-slate-600">
                                โพสต์นี้สร้างเพื่อเปลี่ยนรูปภาพจากข่าวเดิม: {replacementOriginal.title}
                                {' '}โดยระบบจะเก็บโพสต์เก่าไว้ เว้นแต่คุณยืนยันให้ลบหลังโพสต์ใหม่สำเร็จ
                            </p>
                            <div className="mt-4 flex flex-wrap gap-3">
                                <Link href={route('posts.show', replacementOriginal.id)}
                                    className="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">
                                    ดูรายการต้นฉบับ
                                </Link>
                                {replacementOriginal.facebook_url && (
                                    <a href={replacementOriginal.facebook_url} target="_blank" rel="noreferrer"
                                        className="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">
                                        <ExternalLink className="h-4 w-4" /> เปิด Facebook เดิม
                                    </a>
                                )}
                                {replacementPublished && originalDeletionState === 'published' && (
                                    <button type="button" onClick={deleteOriginal}
                                        className="inline-flex items-center gap-2 rounded-lg border border-red-200 px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-50">
                                        <Trash2 className="h-4 w-4" /> ลบโพสต์ Facebook เดิม
                                    </button>
                                )}
                            </div>
                            {originalDeletionState === 'deleting' || originalDeletionState === 'submitting' ? (
                                <p className="mt-3 text-sm text-amber-700">กำลังดำเนินการลบโพสต์เก่าในคิว</p>
                            ) : originalDeletionState === 'detached' ? (
                                <p className="mt-3 text-sm text-slate-600">โพสต์ Facebook เดิมถูกลบออกแล้ว</p>
                            ) : !replacementPublished ? (
                                <p className="mt-3 text-sm text-blue-700">สามารถเลือกลบโพสต์เก่าได้หลังเผยแพร่ Facebook ใหม่สำเร็จเท่านั้น</p>
                            ) : (
                                <p className="mt-3 text-sm text-slate-500">การเก็บโพสต์เก่าไว้ไม่ต้องดำเนินการใด ๆ</p>
                            )}
                        </section>
                    )}

                    <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <div className="mb-4">
                            <h2 className="font-semibold text-slate-900">กิจกรรมการเผยแพร่</h2>
                            <p className="mt-1 text-sm text-slate-500">ลำดับเหตุการณ์ที่เกิดขึ้นกับ WordPress และ Facebook</p>
                        </div>
                        <PublishTimeline events={timeline.events} />
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
