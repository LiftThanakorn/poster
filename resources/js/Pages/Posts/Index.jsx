import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { FacebookIcon, WordPressIcon } from '@/Components/ChannelIcons';
import Dropdown from '@/Components/Dropdown';
import { Eye, Edit2, Trash2, MoreVertical } from 'lucide-react';
import { useEffect } from 'react';
import Swal from 'sweetalert2';

export default function Index({ auth, posts, currentStatus = 'all' }) {
    useEffect(() => {
        const hasActivePublishing = posts.data.some((post) =>
            ['publishing', 'queued', 'processing', 'retrying'].includes(post.status)
            || post.targets.some((target) => ['pending', 'queued', 'processing', 'retrying'].includes(target.status))
        );

        if (!hasActivePublishing) return;

        const timer = window.setInterval(() => {
            router.reload({ only: ['posts'], preserveScroll: true, preserveState: true });
        }, 1500);

        return () => window.clearInterval(timer);
    }, [posts.data]);

    const handleDelete = (post) => {
        const isPublished = post.targets.some(t => t.status === 'published');
        
        if (isPublished) {
            Swal.fire({
                title: 'ยืนยันการลบโพสต์?',
                text: 'โพสต์นี้ถูกเผยแพร่ไปแล้ว ต้องการลบจากระบบปลายทางด้วยหรือไม่?',
                icon: 'warning',
                showCancelButton: true,
                showDenyButton: true,
                confirmButtonColor: '#d33',
                denyButtonColor: '#f59e0b',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'ลบทั้งหมด (รวม FB/WP)',
                denyButtonText: 'ลบแค่ในระบบเรา',
                cancelButtonText: 'ยกเลิก',
            }).then((result) => {
                if (result.isConfirmed) {
                    router.delete(route('posts.destroy', { post: post.id, delete_external: 1 }), {
                        data: { delete_external: 1 },
                    });
                } else if (result.isDenied) {
                    router.delete(route('posts.destroy', { post: post.id, delete_external: 0 }), {
                        data: { delete_external: 0 },
                    });
                }
            });
        } else {
            Swal.fire({
                title: 'ยืนยันการลบ?',
                text: 'คุณแน่ใจหรือไม่ที่จะลบโพสต์นี้?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'ใช่, ลบเลย',
                cancelButtonText: 'ยกเลิก'
            }).then((result) => {
                if (result.isConfirmed) {
                    router.delete(route('posts.destroy', post.id));
                }
            });
        }
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-gray-800 leading-tight">จัดการโพสต์ (Posts)</h2>}
        >
            <Head title="Posts" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div className="p-6 text-gray-900">
                            
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-4">
                                <div>
                                    <h3 className="text-lg font-medium text-gray-900">ข่าวสารทั้งหมด</h3>
                                </div>
                                <div>
                                    <Link
                                        href={route('posts.create')}
                                        className="inline-flex items-center gap-1.5 bg-[#000080] text-white px-4 py-2 rounded-lg text-xs font-semibold shadow-xs hover:bg-blue-900"
                                    >
                                        + สร้างโพสต์ใหม่
                                    </Link>
                                </div>
                            </div>

                            {/* Status Filter Tabs */}
                            <div className="flex flex-wrap items-center gap-1.5 border-b border-gray-100 pb-3 mb-4">
                                {[
                                    ['all', 'ทั้งหมด'],
                                    ['published', 'เผยแพร่แล้ว'],
                                    ['scheduled', 'ตั้งเวลาไว้'],
                                    ['draft', 'ฉบับร่าง'],
                                    ['failed', 'ต้องตรวจสอบ / ล้มเหลว'],
                                ].map(([val, label]) => (
                                    <Link
                                        key={val}
                                        href={route('posts.index', val === 'all' ? {} : { status: val })}
                                        className={`rounded-lg px-3 py-1 text-xs font-semibold transition ${
                                            currentStatus === val
                                                ? 'bg-[#000080] text-white shadow-xs'
                                                : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                                        }`}
                                    >
                                        {label}
                                    </Link>
                                ))}
                            </div>

                            <div className="overflow-x-auto min-h-[340px]">
                                <table className="min-w-full divide-y divide-gray-200">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Targets</th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created At</th>
                                            <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">จัดการ</th>
                                        </tr>
                                    </thead>
                                    <tbody className="bg-white divide-y divide-gray-200">
                                        {posts.data.length > 0 ? posts.data.map((post) => (
                                            <tr key={post.id} className="hover:bg-slate-50/70 transition-colors">
                                                <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{post.title}</td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <span className={`px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${post.status === 'published' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'}`}>
                                                        {post.status}
                                                    </span>
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <div className="flex flex-wrap items-center gap-1.5">
                                                        {post.targets.map(t => (
                                                            <span
                                                                key={t.id}
                                                                className={`inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-medium ${
                                                                    t.status === 'published'
                                                                        ? 'bg-emerald-50 text-emerald-700'
                                                                        : t.status === 'failed'
                                                                          ? 'bg-rose-50 text-rose-700'
                                                                          : t.status === 'processing' || t.status === 'queued'
                                                                            ? 'bg-blue-50 text-blue-700'
                                                                            : 'bg-slate-100 text-slate-700'
                                                                }`}
                                                            >
                                                                {t.channel === 'facebook' ? (
                                                                    <FacebookIcon className="h-3.5 w-3.5 text-[#1877F2]" />
                                                                ) : (
                                                                    <WordPressIcon className="h-3.5 w-3.5 text-[#21759B]" />
                                                                )}
                                                                <span>{t.channel === 'facebook' ? 'Facebook' : 'WordPress'}</span>
                                                                <span className="text-[10px] opacity-75">({t.status})</span>
                                                            </span>
                                                        ))}
                                                    </div>
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    {new Date(post.created_at).toLocaleDateString('th-TH')}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-right">
                                                    <div className="flex items-center justify-end gap-1.5">
                                                        <Link
                                                            href={route('posts.show', post.id)}
                                                            className="inline-flex items-center gap-1 rounded-md bg-blue-50 px-2.5 py-1.5 text-xs font-medium text-[#000080] hover:bg-blue-100 transition-colors"
                                                            title="ดูข้อมูล"
                                                        >
                                                            <Eye className="h-3.5 w-3.5" />
                                                            <span>ดูข้อมูล</span>
                                                        </Link>
                                                        <Link
                                                            href={route('posts.edit', post.id)}
                                                            className="inline-flex items-center gap-1 rounded-md bg-indigo-50 px-2.5 py-1.5 text-xs font-medium text-indigo-700 hover:bg-indigo-100 transition-colors"
                                                            title="แก้ไข"
                                                        >
                                                            <Edit2 className="h-3.5 w-3.5" />
                                                            <span>แก้ไข</span>
                                                        </Link>
                                                        <Dropdown>
                                                            <Dropdown.Trigger>
                                                                <button
                                                                    type="button"
                                                                    className="inline-flex items-center justify-center p-1.5 rounded-md text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors"
                                                                    title="เมนูเพิ่มเติม"
                                                                >
                                                                    <MoreVertical className="h-4 w-4" />
                                                                </button>
                                                            </Dropdown.Trigger>
                                                            <Dropdown.Content align="right" width="48" contentClasses="py-1 bg-white shadow-xl border border-slate-200 rounded-lg">
                                                                <Dropdown.Link
                                                                    href={route('posts.show', post.id)}
                                                                    className="flex items-center gap-2 px-3.5 py-2 text-xs text-slate-700 hover:bg-slate-50 hover:text-[#000080]"
                                                                >
                                                                    <Eye className="h-3.5 w-3.5 text-[#000080]" />
                                                                    <span>ดูข้อมูล</span>
                                                                </Dropdown.Link>
                                                                <Dropdown.Link
                                                                    href={route('posts.edit', post.id)}
                                                                    className="flex items-center gap-2 px-3.5 py-2 text-xs text-slate-700 hover:bg-slate-50 hover:text-indigo-600"
                                                                >
                                                                    <Edit2 className="h-3.5 w-3.5 text-indigo-600" />
                                                                    <span>แก้ไข</span>
                                                                </Dropdown.Link>
                                                                <div className="border-t border-slate-100 my-1" />
                                                                <button
                                                                    type="button"
                                                                    onClick={() => handleDelete(post)}
                                                                    className="flex w-full items-center gap-2 px-3.5 py-2 text-xs text-rose-600 hover:bg-rose-50 text-left transition-colors"
                                                                >
                                                                    <Trash2 className="h-3.5 w-3.5 text-rose-500" />
                                                                    <span>ลบ</span>
                                                                </button>
                                                            </Dropdown.Content>
                                                        </Dropdown>
                                                    </div>
                                                </td>
                                            </tr>
                                        )) : (
                                            <tr>
                                                <td colSpan="5" className="px-6 py-4 text-center text-sm text-gray-500">
                                                    ยังไม่มีข้อมูลโพสต์
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                            
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
