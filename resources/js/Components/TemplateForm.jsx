import { Link, useForm } from '@inertiajs/react';
import { ExternalLink, FileText, Link as LinkIcon, Plus, Sparkles, Trash2, Upload } from 'lucide-react';
import { lazy, Suspense, useRef, useState } from 'react';
import { FacebookIcon, GoogleDriveIcon, WordPressIcon } from '@/Components/ChannelIcons';
import { resolveStorageUrl } from '@/Utils/media';

const RichTextEditor = lazy(() => import('@/Components/RichTextEditor'));
const PostPreviewModal = lazy(() => import('@/Components/PostPreviewModal'));
const ImageEditorModal = lazy(() => import('@/Components/ImageEditorModal'));

const SAMPLE_TITLE = 'ตัวอย่างหัวข้อข่าว';

function applyPreviewVariables(value = '') {
    return (value || '').replaceAll('{{title}}', SAMPLE_TITLE);
}

export default function TemplateForm({
    mode = 'create',
    template = null,
    mediaList = [],
    pdfList = [],
    googleDriveConfig = {},
}) {
    const [isPreviewOpen, setIsPreviewOpen] = useState(false);
    const [isImageEditorOpen, setIsImageEditorOpen] = useState(false);
    const [localMedia, setLocalMedia] = useState(mediaList);
    const [localPdfList, setLocalPdfList] = useState(pdfList);
    const [pdfPreviewUrl, setPdfPreviewUrl] = useState(null);
    const pdfFileInputRef = useRef(null);

    const { data, setData, post, put, transform, processing, errors } = useForm({
        name: template?.name || '',
        wp_template: template?.wp_template || '',
        fb_template: template?.fb_template || '',
        fb_comment: template?.fb_comment || '',
        fb_comment_2: template?.fb_comment_2 || '',
        drive_sync_enabled: template?.drive_sync_enabled !== null && template?.drive_sync_enabled !== undefined
            ? Boolean(template.drive_sync_enabled)
            : true,
        drive_employee_category: template?.drive_employee_category || googleDriveConfig?.default_category || 'พนักงานรายวัน',
        drive_announcement_stage: template?.drive_announcement_stage || googleDriveConfig?.default_stage || 'รับสมัคร',
        action_buttons: template?.action_buttons || [],
        pdf_media_id: template?.pdf_media_id || null,
        pdf_file: null,
        featured_image_id: template?.featured_image_id || null,
        image_editor_data: template?.image_editor_data || null,
    });

    const submit = (event) => {
        event.preventDefault();

        if (mode === 'edit') {
            if (data.pdf_file) {
                transform((formData) => ({
                    ...formData,
                    _method: 'put',
                }));
                post(route('templates.update', template.id));
                return;
            }
            put(route('templates.update', template.id));
            return;
        }

        post(route('templates.store'));
    };

    const appendWordPressVariable = (variable) => {
        const addition = `<p>${variable}</p>`;
        setData('wp_template', `${data.wp_template || ''}${addition}`);
    };

    const appendFacebookVariable = (variable) => {
        const separator = data.fb_template?.trim() ? '\n' : '';
        setData('fb_template', `${data.fb_template || ''}${separator}${variable}`);
    };

    const addActionButton = (label = '', url = '') => {
        setData('action_buttons', [
            ...(data.action_buttons || []),
            { label, url },
        ]);
    };

    const updateActionButton = (index, field, value) => {
        const updated = [...(data.action_buttons || [])];
        if (updated[index]) {
            updated[index] = { ...updated[index], [field]: value };
            setData('action_buttons', updated);
        }
    };

    const removeActionButton = (index) => {
        const updated = (data.action_buttons || []).filter((_, i) => i !== index);
        setData('action_buttons', updated);
    };

    const handlePdfFileUpload = (e) => {
        const file = e.target.files?.[0];
        if (!file) return;

        if (file.type !== 'application/pdf') {
            alert('กรุณาเลือกไฟล์ PDF เท่านั้น');
            e.target.value = '';
            return;
        }

        setData((prev) => ({
            ...prev,
            pdf_file: file,
            pdf_media_id: null,
        }));

        if (pdfPreviewUrl) {
            URL.revokeObjectURL(pdfPreviewUrl);
        }
        setPdfPreviewUrl(URL.createObjectURL(file));
    };

    const removeAttachedPdf = () => {
        setData((prev) => ({
            ...prev,
            pdf_file: null,
            pdf_media_id: null,
        }));
        if (pdfPreviewUrl) {
            URL.revokeObjectURL(pdfPreviewUrl);
            setPdfPreviewUrl(null);
        }
        if (pdfFileInputRef.current) {
            pdfFileInputRef.current.value = '';
        }
    };

    const addPdfToButtons = () => {
        addActionButton('ดาวน์โหลดใบสมัคร (PDF)', '{pdf_url}');
    };

    const insertApplicationLinksIntoComment2 = () => {
        const validBtns = (data.action_buttons || []).filter(b => (b.label || '').trim() || (b.url || '').trim());
        let textToInsert = '';
        if (validBtns.length > 0) {
            textToInsert = 'ช่องทางการสมัครและเอกสารแนบ:\n' + validBtns.map((b, i) => `${i + 1}. ${b.label || 'ใบสมัคร'}: ${b.url || '#'}`).join('\n');
        } else {
            textToInsert = '{application_links}';
        }
        setData('fb_comment_2', data.fb_comment_2 ? `${data.fb_comment_2}\n\n${textToInsert}` : textToInsert);
    };

    const insertPdfUrlIntoComment2 = () => {
        setData('fb_comment_2', data.fb_comment_2 ? `${data.fb_comment_2} {pdf_url}` : '{pdf_url}');
    };

    const insertWpUrlIntoComment2 = () => {
        setData('fb_comment_2', data.fb_comment_2 ? `${data.fb_comment_2} {wordpress_url}` : '{wordpress_url}');
    };

    const previewWpContent = applyPreviewVariables(data.wp_template);
    const previewFbCaption = applyPreviewVariables(data.fb_template);
    const previewFbComment2 = applyPreviewVariables(data.fb_comment_2);

    const selectedMedia = localMedia.find(
        (media) => String(media.id) === String(data.featured_image_id),
    );
    const selectedImageUrl = resolveStorageUrl(selectedMedia) || null;

    const selectedPdfMedia = data.pdf_media_id
        ? ((localPdfList || []).find(
            (item) => String(item.id) === String(data.pdf_media_id),
        ) || (String(template?.pdf_media_id) === String(data.pdf_media_id) ? template?.pdf_media : null))
        : null;

    const selectedPdfUrl = pdfPreviewUrl
        || (selectedPdfMedia ? resolveStorageUrl(selectedPdfMedia) : null);

    const selectedPdfName = data.pdf_file?.name
        || (selectedPdfMedia ? (selectedPdfMedia?.original_name || (selectedPdfMedia?.file_path ? selectedPdfMedia.file_path.split('/').pop() : null)) : null);

    const rememberMedia = (media) => {
        setLocalMedia((current) => [
            media,
            ...current.filter((item) => String(item.id) !== String(media.id)),
        ]);
    };

    const saveEditedImage = ({ media, editorData }) => {
        rememberMedia(media);
        setData((current) => ({
            ...current,
            featured_image_id: media.id,
            image_editor_data: editorData,
        }));
        setIsImageEditorOpen(false);
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-6">
            <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div className="flex flex-col gap-1 border-b border-slate-100 pb-4">
                    <h3 className="text-lg font-semibold text-[#000080]">ข้อมูลเทมเพลต</h3>
                    <p className="text-sm text-slate-500">
                        สร้างโครงเนื้อหาที่สามารถเลือกใช้ซ้ำได้จากหน้า Posts Create
                    </p>
                </div>

                <div className="mt-5">
                    <label className="block text-sm font-medium text-slate-700">
                        ชื่อเทมเพลต
                    </label>
                    <input
                        type="text"
                        value={data.name}
                        onChange={(event) => setData('name', event.target.value)}
                        className="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                        placeholder="เช่น ประกาศรับสมัครงาน"
                        required
                    />
                    {errors.name && <p className="mt-1 text-xs text-rose-600">{errors.name}</p>}
                </div>
            </section>

            <div className="grid gap-6 xl:grid-cols-2">
                {/* WordPress Template Column */}
                <section className="flex flex-col rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div className="mb-4 flex items-start justify-between gap-3 border-b border-slate-100 pb-3">
                        <div className="flex items-center gap-2.5">
                            <WordPressIcon className="h-5 w-5 text-[#21759B] shrink-0" />
                            <div>
                                <h3 className="text-lg font-semibold text-slate-900">เว็บไซต์ WordPress</h3>
                                <p className="mt-0.5 text-xs text-slate-500">
                                    ใช้เครื่องมือ Rich Text Editor ชุดเดียวกับหน้า Posts Create
                                </p>
                            </div>
                        </div>
                        <span className="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700">
                            Rich Text
                        </span>
                    </div>

                    <Suspense fallback={<div className="min-h-[240px] animate-pulse rounded-md bg-slate-100" />}>
                        <RichTextEditor
                            value={data.wp_template}
                            onChange={(html) => setData('wp_template', html)}
                        />
                    </Suspense>

                    <div className="mt-3 flex flex-wrap items-center gap-2">
                        <span className="text-xs text-slate-500">ตัวแปร:</span>
                        <button
                            type="button"
                            onClick={() => appendWordPressVariable('{{title}}')}
                            className="rounded-md border border-slate-200 bg-slate-50 px-2.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-100"
                        >
                            แทรก {'{{title}}'}
                        </button>
                    </div>
                    {errors.wp_template && <p className="mt-2 text-xs text-rose-600">{errors.wp_template}</p>}

                    {/* Action Buttons Section */}
                    <div className="mt-6 border-t border-slate-100 pt-5">
                        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-3">
                            <div>
                                <label className="text-sm font-semibold text-slate-900 flex items-center gap-1.5">
                                    <ExternalLink className="w-4 h-4 text-[#000080]" />
                                    ปุ่มดำเนินการ / ลิงก์ใบสมัคร (Action Buttons)
                                </label>
                                <p className="text-xs text-slate-500 mt-0.5">
                                    เพิ่มปุ่มประจำเทมเพลต เช่น &quot;ดาวน์โหลดใบสมัคร&quot;, &quot;สมัครออนไลน์&quot; (รองรับ {'{pdf_url}'})
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => addActionButton()}
                                className="inline-flex items-center gap-1.5 text-xs font-semibold bg-blue-50 text-[#000080] hover:bg-blue-100 border border-blue-200 px-3 py-1.5 rounded-md transition-colors shadow-2xs self-start sm:self-auto"
                            >
                                <Plus className="w-3.5 h-3.5" />
                                เพิ่มปุ่ม
                            </button>
                        </div>

                        {(data.action_buttons || []).length > 0 ? (
                            <div className="space-y-2.5">
                                {(data.action_buttons || []).map((btn, index) => (
                                    <div key={index} className="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 p-2.5 rounded-lg border border-slate-200 bg-slate-50">
                                        <div className="sm:w-5/12">
                                            <input
                                                type="text"
                                                placeholder="ชื่อปุ่ม เช่น ดาวน์โหลดใบสมัคร"
                                                value={btn.label || ''}
                                                onChange={(e) => updateActionButton(index, 'label', e.target.value)}
                                                className="block w-full text-xs rounded-md border-gray-300 shadow-2xs focus:border-indigo-500 focus:ring-indigo-500"
                                            />
                                        </div>
                                        <div className="sm:w-6/12">
                                            <input
                                                type="text"
                                                placeholder="URL ปลายทาง (https://... หรือ {pdf_url})"
                                                value={btn.url || ''}
                                                onChange={(e) => updateActionButton(index, 'url', e.target.value)}
                                                className="block w-full text-xs rounded-md border-gray-300 shadow-2xs focus:border-indigo-500 focus:ring-indigo-500"
                                            />
                                        </div>
                                        <div className="sm:w-1/12 flex items-center justify-end">
                                            <button
                                                type="button"
                                                onClick={() => removeActionButton(index)}
                                                className="p-1.5 text-slate-400 hover:text-red-600 rounded hover:bg-red-50 transition-colors"
                                                title="ลบปุ่มนี้"
                                            >
                                                <Trash2 className="w-4 h-4" />
                                            </button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="rounded-lg border border-dashed border-slate-300 bg-slate-50/60 p-4 text-center">
                                <p className="text-xs text-slate-500">ยังไม่มีการเพิ่มปุ่มใบสมัครในเทมเพลตนี้</p>
                                <div className="mt-2 flex flex-wrap items-center justify-center gap-2">
                                    <button
                                        type="button"
                                        onClick={() => addActionButton('ดาวน์โหลดใบสมัคร (PDF)', '{pdf_url}')}
                                        className="inline-flex items-center gap-1 text-[11px] font-medium text-slate-600 bg-white border border-slate-200 px-2.5 py-1 rounded hover:bg-slate-50 shadow-2xs"
                                    >
                                        <Plus className="w-3 h-3 text-[#000080]" />
                                        ปุ่มดาวน์โหลดใบสมัคร
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => addActionButton('สมัครออนไลน์ (Google Forms)', 'https://')}
                                        className="inline-flex items-center gap-1 text-[11px] font-medium text-slate-600 bg-white border border-slate-200 px-2.5 py-1 rounded hover:bg-slate-50 shadow-2xs"
                                    >
                                        <Plus className="w-3 h-3 text-[#000080]" />
                                        ปุ่มสมัครออนไลน์
                                    </button>
                                </div>
                            </div>
                        )}
                        {errors.action_buttons && (
                            <p className="mt-2 text-xs text-rose-600">{errors.action_buttons}</p>
                        )}
                    </div>

                    {/* WordPress Embedded PDF Viewer & Attachment */}
                    <div className="mt-6 border-t border-slate-100 pt-5">
                        <div className="mb-3">
                            <label className="text-sm font-semibold text-slate-900 flex items-center gap-1.5">
                                <FileText className="w-4 h-4 text-red-600" />
                                แนบไฟล์ PDF ประจำเทมเพลต (Embedded PDF Viewer)
                            </label>
                            <p className="text-xs text-slate-500 mt-0.5">
                                เมื่อเลือกใช้เทมเพลตนี้ ระบบจะแนบไฟล์ PDF และแสดง Viewer ให้ผู้เข้าชมอ่านเอกสารได้ทันทีบนหน้าเว็บ
                            </p>
                        </div>

                        {selectedPdfName ? (
                            <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 p-3.5 rounded-lg border border-red-200 bg-red-50/40">
                                <div className="flex items-center gap-2.5 min-w-0">
                                    <div className="p-2 rounded-md bg-red-100 text-red-600 shrink-0">
                                        <FileText className="w-5 h-5" />
                                    </div>
                                    <div className="min-w-0">
                                        <p className="text-xs font-semibold text-slate-900 truncate">
                                            {selectedPdfName}
                                        </p>
                                        <p className="text-[11px] text-slate-500 flex items-center gap-2 mt-0.5">
                                            <span>เอกสารแนบ PDF</span>
                                            {data.pdf_file && <span>({(data.pdf_file.size / 1024 / 1024).toFixed(2)} MB)</span>}
                                            {selectedPdfUrl && (
                                                <a
                                                    href={selectedPdfUrl}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="text-blue-600 hover:underline inline-flex items-center gap-0.5"
                                                >
                                                    <ExternalLink className="w-3 h-3" /> เปิดดู
                                                </a>
                                            )}
                                        </p>
                                    </div>
                                </div>

                                <div className="flex items-center gap-2 shrink-0 self-end sm:self-auto">
                                    <button
                                        type="button"
                                        onClick={addPdfToButtons}
                                        className="inline-flex items-center gap-1 text-[11px] font-medium text-slate-700 bg-white hover:bg-slate-50 border border-slate-300 px-2.5 py-1.5 rounded shadow-2xs"
                                        title="สร้างปุ่มดาวน์โหลดจากไฟล์ PDF นี้"
                                    >
                                        <Plus className="w-3 h-3 text-[#000080]" />
                                        เพิ่มเป็นปุ่มใบสมัคร
                                    </button>
                                    <button
                                        type="button"
                                        onClick={removeAttachedPdf}
                                        className="p-1.5 text-slate-400 hover:text-red-600 rounded hover:bg-red-50 transition-colors"
                                        title="ยกเลิกการแนบไฟล์นี้"
                                    >
                                        <Trash2 className="w-4 h-4" />
                                    </button>
                                </div>
                            </div>
                        ) : (
                            <div className="flex flex-col sm:flex-row gap-3">
                                <div
                                    onClick={() => pdfFileInputRef.current?.click()}
                                    className="flex-1 flex items-center justify-center gap-2 p-3.5 rounded-lg border border-dashed border-slate-300 bg-slate-50 hover:bg-slate-100 text-slate-600 cursor-pointer transition-colors text-xs font-medium"
                                >
                                    <Upload className="w-4 h-4 text-slate-500" />
                                    <span>อัปโหลดไฟล์ PDF ประจำเทมเพลต</span>
                                </div>
                                <input
                                    type="file"
                                    ref={pdfFileInputRef}
                                    className="hidden"
                                    accept="application/pdf"
                                    onChange={handlePdfFileUpload}
                                />

                                {localPdfList.length > 0 && (
                                    <div className="sm:w-5/12">
                                        <select
                                            value={data.pdf_media_id || ''}
                                            onChange={(e) => {
                                                const val = e.target.value ? Number(e.target.value) : null;
                                                setData((prev) => ({
                                                    ...prev,
                                                    pdf_media_id: val,
                                                    pdf_file: null,
                                                }));
                                                if (pdfPreviewUrl) {
                                                    URL.revokeObjectURL(pdfPreviewUrl);
                                                    setPdfPreviewUrl(null);
                                                }
                                            }}
                                            className="block w-full text-xs rounded-lg border-gray-300 shadow-2xs focus:border-indigo-500 focus:ring-indigo-500 py-3"
                                        >
                                            <option value="">-- หรือเลือกจากคลัง PDF --</option>
                                            {localPdfList.map((m) => (
                                                <option key={m.id} value={m.id}>
                                                    {m.original_name || m.file_path.split('/').pop()}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                )}
                            </div>
                        )}
                        {errors.pdf_file && (
                            <p className="mt-2 text-xs text-rose-600">{errors.pdf_file}</p>
                        )}
                        {errors.pdf_media_id && (
                            <p className="mt-2 text-xs text-rose-600">{errors.pdf_media_id}</p>
                        )}
                    </div>
                </section>

                {/* Facebook Template Column */}
                <section className="flex flex-col rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div className="mb-4 flex items-start justify-between gap-3 border-b border-slate-100 pb-3">
                        <div className="flex items-center gap-2.5">
                            <FacebookIcon className="h-5 w-5 text-[#1877F2] shrink-0" />
                            <div>
                                <h3 className="text-lg font-semibold text-slate-900">Facebook Page</h3>
                                <p className="mt-0.5 text-xs text-slate-500">
                                    รูปแบบข้อความจะถูกนำไปใส่ในช่อง Caption ของ Posts Create
                                </p>
                            </div>
                        </div>
                        <span className="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700">
                            Caption
                        </span>
                    </div>

                    <textarea
                        rows="12"
                        value={data.fb_template}
                        onChange={(event) => setData('fb_template', event.target.value)}
                        className="block min-h-[240px] w-full resize-y rounded-md border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                        placeholder="เขียนโครงข้อความ Facebook..."
                    />

                    <div className="mt-3 flex flex-wrap items-center gap-2">
                        <span className="text-xs text-slate-500">ตัวแปร:</span>
                        <button
                            type="button"
                            onClick={() => appendFacebookVariable('{{title}}')}
                            className="rounded-md border border-slate-200 bg-slate-50 px-2.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-100"
                        >
                            แทรก {'{{title}}'}
                        </button>
                        <button
                            type="button"
                            onClick={() => appendFacebookVariable('{wordpress_url}')}
                            className="rounded-md border border-blue-200 bg-blue-50 px-2.5 py-1.5 text-xs font-medium text-blue-700 hover:bg-blue-100"
                        >
                            แทรก {'{wordpress_url}'}
                        </button>
                    </div>
                    {errors.fb_template && <p className="mt-2 text-xs text-rose-600">{errors.fb_template}</p>}

                    {/* Facebook First Comment (Comment #1 - Drive & Details) */}
                    <div className="border-t border-slate-100 pt-4 mt-6">
                        <div className="flex items-center justify-between mb-1">
                            <label className="block text-sm font-medium text-slate-900">
                                คอมเมนต์แรกใต้โพสต์ (First Comment)
                            </label>
                            <span className="text-[11px] bg-emerald-50 text-emerald-700 px-2 py-0.5 rounded font-medium border border-emerald-200">
                                Comment #1
                            </span>
                        </div>
                        <textarea
                            rows="3"
                            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm resize-y"
                            placeholder="ข้อความคอมเมนต์แรก เช่น 📄 เอกสารประกาศฉบับเต็ม:\n{drive_url}"
                            value={data.fb_comment || ''}
                            onChange={(e) => setData('fb_comment', e.target.value)}
                        />
                        <div className="mt-2 flex flex-wrap items-center gap-1.5">
                            <button
                                type="button"
                                onClick={() => setData('fb_comment', data.fb_comment ? `${data.fb_comment}\n{drive_url}` : '{drive_url}')}
                                className="text-xs bg-emerald-50 text-emerald-700 hover:bg-emerald-100 px-2 py-1 rounded border border-emerald-200 flex items-center gap-1 transition-colors"
                            >
                                <GoogleDriveIcon className="w-3.5 h-3.5" />
                                แทรก {'{drive_url}'}
                            </button>
                            <button
                                type="button"
                                onClick={() => setData('fb_comment', data.fb_comment ? `${data.fb_comment} {wordpress_url}` : '{wordpress_url}')}
                                className="text-xs bg-blue-50 text-blue-700 hover:bg-blue-100 px-2 py-1 rounded border border-blue-200 flex items-center gap-1 transition-colors"
                            >
                                <LinkIcon className="w-3 h-3" />
                                แทรก {'{wordpress_url}'}
                            </button>
                        </div>
                    </div>

                    {/* Facebook Second Comment (Comment #2 - Application & Info) */}
                    <div className="border-t border-slate-100 pt-4 mt-6">
                        <div className="flex items-center justify-between mb-1">
                            <label className="block text-sm font-medium text-slate-900">
                                คอมเมนต์ที่ 2 ใต้โพสต์ (ช่องใส่ใบสมัครและรายละเอียด)
                            </label>
                            <span className="text-[11px] bg-blue-50 text-blue-700 px-2 py-0.5 rounded font-medium border border-blue-200">
                                Comment #2
                            </span>
                        </div>
                        <textarea
                            rows="4"
                            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm resize-y"
                            placeholder="ข้อความคอมเมนต์ที่ 2 เช่น สนใจสมัครงานดาวน์โหลดใบสมัครได้ที่นี่..."
                            value={data.fb_comment_2 || ''}
                            onChange={(e) => setData('fb_comment_2', e.target.value)}
                        />
                        <div className="mt-2 flex flex-wrap items-center justify-between gap-2">
                            <div className="flex flex-wrap items-center gap-1.5">
                                <button
                                    type="button"
                                    onClick={insertApplicationLinksIntoComment2}
                                    className="text-xs bg-indigo-50 text-indigo-700 hover:bg-indigo-100 px-2 py-1 rounded border border-indigo-200 flex items-center gap-1 transition-colors"
                                    title="นำรายการปุ่มใบสมัครมาใส่ในคอมเมนต์นี้โดยอัตโนมัติ"
                                >
                                    <FileText className="w-3 h-3" />
                                    แทรกรายการใบสมัครทั้งหมด
                                </button>
                                <button
                                    type="button"
                                    onClick={insertPdfUrlIntoComment2}
                                    className="text-xs bg-red-50 text-red-700 hover:bg-red-100 px-2 py-1 rounded border border-red-200 flex items-center gap-1 transition-colors"
                                >
                                    <ExternalLink className="w-3 h-3" />
                                    แทรกลิงก์ PDF
                                </button>
                                <button
                                    type="button"
                                    onClick={insertWpUrlIntoComment2}
                                    className="text-xs bg-blue-50 text-blue-700 hover:bg-blue-100 px-2 py-1 rounded border border-blue-200 flex items-center gap-1 transition-colors"
                                >
                                    <LinkIcon className="w-3 h-3" />
                                    แทรก {'{wordpress_url}'}
                                </button>
                            </div>
                        </div>
                        <p className="text-xs text-gray-500 mt-1">
                            จะถูกนำไปกรอกในช่องคอมเมนต์ที่ 2 ของโพสต์ Facebook โดยอัตโนมัติเมื่อเลือกเทมเพลตนี้
                        </p>
                        {errors.fb_comment_2 && (
                            <p className="mt-1 text-xs text-rose-600">{errors.fb_comment_2}</p>
                        )}
                    </div>
                </section>
            </div>

            <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h3 className="text-lg font-semibold text-[#000080]">รูปประจำเทมเพลต</h3>
                        <p className="mt-1 text-sm text-slate-500">
                            สร้างรูปด้วยเครื่องมือแก้ไข แล้วระบบจะนำรูปนี้ไปใช้เมื่อเลือกเทมเพลตใน Posts Create
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={() => setIsImageEditorOpen(true)}
                        className="rounded-md bg-[#000080] px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-900"
                    >
                        {selectedImageUrl ? 'แก้ไขรูป' : 'สร้างรูป'}
                    </button>
                </div>

                <div className="mt-5">
                    {selectedImageUrl ? (
                        <div className="flex flex-col gap-4 sm:flex-row sm:items-start">
                            <div className="overflow-hidden rounded-xl border border-slate-200 bg-slate-100">
                                <img
                                    src={selectedImageUrl}
                                    alt="รูปประจำเทมเพลต"
                                    className="max-h-80 w-full object-contain sm:w-[360px]"
                                />
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    onClick={() => setIsImageEditorOpen(true)}
                                    className="rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-sm font-medium text-blue-800 hover:bg-blue-100"
                                >
                                    เปิดเครื่องมือแก้ไขรูป
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setData((current) => ({
                                        ...current,
                                        featured_image_id: null,
                                        image_editor_data: null,
                                    }))}
                                    className="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-medium text-rose-700 hover:bg-rose-100"
                                >
                                    เอารูปออก
                                </button>
                            </div>
                        </div>
                    ) : (
                        <button
                            type="button"
                            onClick={() => setIsImageEditorOpen(true)}
                            className="flex min-h-40 w-full items-center justify-center rounded-xl border-2 border-dashed border-slate-300 bg-slate-50 px-6 text-center text-sm text-slate-500 hover:border-blue-300 hover:bg-blue-50"
                        >
                            คลิกเพื่อสร้างรูป เพิ่มข้อความ เพิ่มรูป และลากจัดวาง
                        </button>
                    )}
                    {errors.featured_image_id && (
                        <p className="mt-2 text-xs text-rose-600">{errors.featured_image_id}</p>
                    )}
                </div>
            </section>

            <section className="sticky bottom-4 z-20 rounded-2xl border border-slate-200 bg-white p-4 shadow-lg">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p className="text-sm font-medium text-slate-800">ตรวจสอบก่อนบันทึก</p>
                        <p className="text-xs text-slate-500">
                            Preview ใช้รูปแบบเดียวกับ Posts Create และไม่เผยแพร่ข้อมูลจริง
                        </p>
                    </div>

                    <div className="flex flex-wrap justify-end gap-3">
                        <Link
                            href={route('templates.index')}
                            className="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50"
                        >
                            ยกเลิก
                        </Link>
                        <button
                            type="button"
                            onClick={() => setIsPreviewOpen(true)}
                            className="rounded-md border border-blue-200 bg-blue-50 px-4 py-2 text-sm font-semibold text-blue-800 shadow-sm hover:bg-blue-100"
                        >
                            ดูตัวอย่าง
                        </button>
                        <button
                            type="submit"
                            disabled={processing}
                            className="rounded-md bg-[#000080] px-6 py-2 text-sm font-bold text-white shadow-sm transition-colors hover:bg-blue-900 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {processing
                                ? 'กำลังบันทึก...'
                                : mode === 'edit'
                                  ? 'บันทึกการแก้ไข'
                                  : 'บันทึกเทมเพลต'}
                        </button>
                    </div>
                </div>
            </section>

            {isImageEditorOpen && (
                <Suspense fallback={<div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 text-sm font-medium text-white">กำลังโหลดเครื่องมือแก้ไขรูป...</div>}>
                    <ImageEditorModal
                        open={isImageEditorOpen}
                        onClose={() => setIsImageEditorOpen(false)}
                        mediaList={localMedia}
                        initialImageUrl={selectedImageUrl}
                        initialData={data.image_editor_data}
                        onMediaUploaded={rememberMedia}
                        onSave={saveEditedImage}
                    />
                </Suspense>
            )}

            {isPreviewOpen && (
                <Suspense fallback={<div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 text-sm font-medium text-white">กำลังเตรียมตัวอย่าง...</div>}>
                    <PostPreviewModal
                        open={isPreviewOpen}
                        onClose={() => setIsPreviewOpen(false)}
                        channels={['wordpress', 'facebook']}
                        title={SAMPLE_TITLE}
                        wpContent={previewWpContent}
                        fbCaption={previewFbCaption}
                        fbComment=""
                        fbComment2={previewFbComment2}
                        actionButtons={data.action_buttons || []}
                        pdfUrl={selectedPdfUrl}
                        pdfName={selectedPdfName}
                        imageUrl={selectedImageUrl}
                        categoryName="ตัวอย่างหมวดหมู่"
                    />
                </Suspense>
            )}
        </form>
    );
}
