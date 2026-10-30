import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { ExternalLink, FileText, MessageSquare } from 'lucide-react';
import { resolveStorageUrl } from '@/Utils/media';

export default function Index({ auth, templates }) {
    const { delete: destroy } = useForm();

    const handleDelete = (id) => {
        if (confirm('คุณแน่ใจหรือไม่ที่จะลบเทมเพลตนี้?')) {
            destroy(route('templates.destroy', id));
        }
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-gray-800 leading-tight">จัดการเทมเพลต (Templates)</h2>}
        >
            <Head title="Templates" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div className="p-6 text-gray-900">
                            <div className="flex justify-between items-center mb-6">
                                <div>
                                    <h3 className="text-lg font-medium text-gray-900">รายการเทมเพลตทั้งหมด</h3>
                                </div>
                                <Link
                                    href={route('templates.create')}
                                    className="bg-[#000080] hover:bg-blue-900 text-white font-bold py-2 px-4 rounded"
                                >
                                    + สร้างเทมเพลตใหม่
                                </Link>
                            </div>

                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-gray-200">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">รูป</th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ชื่อเทมเพลต</th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">จัดการ</th>
                                        </tr>
                                    </thead>
                                    <tbody className="bg-white divide-y divide-gray-200">
                                        {templates.map((template) => (
                                            <tr key={template.id}>
                                                <td className="px-6 py-3">
                                                    {resolveStorageUrl(template.featured_image) ? (
                                                        <img
                                                            src={resolveStorageUrl(template.featured_image)}
                                                            alt=""
                                                            className="h-14 w-14 rounded-lg border border-slate-200 object-cover"
                                                        />
                                                    ) : (
                                                        <div className="flex h-14 w-14 items-center justify-center rounded-lg border border-dashed border-slate-300 bg-slate-50 text-[10px] text-slate-400">
                                                            ไม่มีรูป
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="px-6 py-4">
                                                    <div className="text-sm font-semibold text-slate-900">{template.name}</div>
                                                    <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
                                                        {Array.isArray(template.action_buttons) && template.action_buttons.length > 0 && (
                                                            <span className="inline-flex items-center gap-1 rounded bg-blue-50 px-2 py-0.5 text-[11px] font-medium text-blue-700 border border-blue-200">
                                                                <ExternalLink className="h-3 w-3" />
                                                                {template.action_buttons.length} ปุ่มใบสมัคร
                                                            </span>
                                                        )}
                                                        {template.pdf_media && (
                                                            <span
                                                                className="inline-flex items-center gap-1 rounded bg-red-50 px-2 py-0.5 text-[11px] font-medium text-red-700 border border-red-200"
                                                                title={template.pdf_media.original_name}
                                                            >
                                                                <FileText className="h-3 w-3" />
                                                                PDF: {template.pdf_media.original_name || 'เอกสารแนบ'}
                                                            </span>
                                                        )}
                                                        {template.fb_comment_2 && (
                                                            <span className="inline-flex items-center gap-1 rounded bg-indigo-50 px-2 py-0.5 text-[11px] font-medium text-indigo-700 border border-indigo-200">
                                                                <MessageSquare className="h-3 w-3" />
                                                                คอมเมนต์ #2
                                                            </span>
                                                        )}
                                                    </div>
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm font-medium space-x-3">
                                                    <Link href={route('templates.edit', template.id)} className="text-indigo-600 hover:text-indigo-900">
                                                        แก้ไข
                                                    </Link>
                                                    <button onClick={() => handleDelete(template.id)} className="text-red-600 hover:text-red-900">
                                                        ลบ
                                                    </button>
                                                </td>
                                            </tr>
                                        ))}
                                        {templates.length === 0 && (
                                            <tr>
                                                <td colSpan="3" className="px-6 py-4 text-center text-sm text-gray-500">
                                                    ยังไม่มีข้อมูลเทมเพลต
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
