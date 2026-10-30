import React, { lazy, Suspense, useEffect, useRef, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import axios from 'axios';
import Swal from 'sweetalert2';
import { FacebookIcon, GoogleDriveIcon, WordPressIcon } from '@/Components/ChannelIcons';
import { Plus, Trash2, FileText, Link as LinkIcon, ExternalLink, Upload, Paperclip, Sparkles, FolderTree, CheckCircle2, AlertCircle } from 'lucide-react';
import PostSubmitProgressModal from '@/Components/PostSubmitProgressModal';
import { resolveStorageUrl } from '@/Utils/media';

const RichTextEditor = lazy(() => import('@/Components/RichTextEditor'));
const DateTimePicker = lazy(() => import('@/Components/DateTimePicker'));
const PostPreviewModal = lazy(() => import('@/Components/PostPreviewModal'));
const ImageEditorModal = lazy(() => import('@/Components/ImageEditorModal'));

export default function PostForm({ 
    mode = 'create', 
    initialData, 
    templates, 
    mediaList, 
    folders = [],
    categories = [],
    wpCategories, 
    googleDriveConfig = {},
    initialPdfMediaId = null,
    submitUrl, 
    submitMethod = 'post',
    postId = null,
    allowAutosave = true,
}) {
    const today = new Date().toISOString().slice(0, 10);
    const defaultCategory = wpCategories?.find(c => c.name.includes('รับสมัครงาน'))?.id || null;
    const [previewUrl, setPreviewUrl] = useState(null);
    const [pdfPreviewUrl, setPdfPreviewUrl] = useState(null);
    const [isPreviewOpen, setIsPreviewOpen] = useState(false);
    const [isImageEditorOpen, setIsImageEditorOpen] = useState(false);
    const [localMedia, setLocalMedia] = useState(mediaList || []);
    const [selectedTemplateId, setSelectedTemplateId] = useState('');
    const [selectedFolderFilter, setSelectedFolderFilter] = useState('all');
    const [selectedCategoryFilter, setSelectedCategoryFilter] = useState('all');
    const [autosavePostId, setAutosavePostId] = useState(postId);
    const [autosaveState, setAutosaveState] = useState('idle');
    const [lastSavedAt, setLastSavedAt] = useState(null);
    const [submitModalOpen, setSubmitModalOpen] = useState(false);
    const [submitStatus, setSubmitStatus] = useState('uploading');
    const [submitProgress, setSubmitProgress] = useState(0);
    const [submitActionType, setSubmitActionType] = useState('publish');
    const [liveTargets, setLiveTargets] = useState([]);
    const [submitErrorMessage, setSubmitErrorMessage] = useState('');
    const [redirectUrl, setRedirectUrl] = useState(null);
    const [customErrors, setCustomErrors] = useState({});
    const pollIntervalRef = useRef(null);
    const fileInputRef = useRef(null);
    const pdfFileInputRef = useRef(null);
    const autosaveReadyRef = useRef(false);
    const lastAutosaveSnapshotRef = useRef('');

    const { data, setData, post, put, processing, errors, transform } = useForm({
        title: initialData?.title || '',
        wp_content: initialData?.wp_content || '',
        fb_caption: initialData?.fb_caption || '',
        fb_comment: initialData?.fb_comment || '',
        fb_comment_2: initialData?.fb_comment_2 || '',
        action_buttons: initialData?.action_buttons || [],
        pdf_media_id: initialData?.pdf_media_id || initialPdfMediaId || null,
        pdf_file: null,
        drive_sync_enabled: initialData?.drive_sync_enabled !== undefined
            ? Boolean(initialData.drive_sync_enabled)
            : Boolean(googleDriveConfig?.is_connected),
        drive_employee_category: initialData?.drive_employee_category || googleDriveConfig?.default_category || 'พนักงานรายวัน',
        drive_announcement_stage: initialData?.drive_announcement_stage || googleDriveConfig?.default_stage || 'รับสมัคร',
        drive_document_date: initialData?.drive_document_date || today,
        drive_position_title: initialData?.drive_position_title || '',
        drive_custom_filename: initialData?.drive_custom_filename || '',
        channels: initialData?.channels || ['wordpress', 'facebook'],
        publish_action: initialData?.publish_action || 'draft',
        scheduled_at: initialData?.scheduled_at || '',
        featured_image_id: initialData?.featured_image_id || null,
        image_editor_data: initialData?.image_editor_data || null,
        wp_category_id: initialData?.wp_category_id || defaultCategory || '',
        file: null,
    });

    const displayErrors = { ...errors, ...customErrors };

    const isWpSelected = data.channels.includes('wordpress');
    const isFbSelected = data.channels.includes('facebook');

    useEffect(() => () => {
        if (previewUrl) {
            URL.revokeObjectURL(previewUrl);
        }
    }, [previewUrl]);

    useEffect(() => () => {
        if (pdfPreviewUrl) {
            URL.revokeObjectURL(pdfPreviewUrl);
        }
    }, [pdfPreviewUrl]);

    useEffect(() => () => {
        if (pollIntervalRef.current) {
            window.clearInterval(pollIntervalRef.current);
        }
    }, []);

    const autosaveSnapshot = JSON.stringify({
        title: data.title,
        wp_content: data.wp_content,
        fb_caption: data.fb_caption,
        fb_comment: data.fb_comment,
        fb_comment_2: data.fb_comment_2,
        action_buttons: data.action_buttons,
        pdf_media_id: data.pdf_media_id || null,
        drive_sync_enabled: data.drive_sync_enabled,
        drive_employee_category: data.drive_employee_category,
        drive_announcement_stage: data.drive_announcement_stage,
        drive_document_date: data.drive_document_date,
        drive_position_title: data.drive_position_title,
        channels: data.channels,
        wp_category_id: data.wp_category_id || null,
        featured_image_id: data.featured_image_id || null,
        image_editor_data: data.image_editor_data || null,
    });

    useEffect(() => {
        if (!allowAutosave || processing || submitModalOpen) return undefined;

        if (!autosaveReadyRef.current) {
            autosaveReadyRef.current = true;
            lastAutosaveSnapshotRef.current = autosaveSnapshot;
            return undefined;
        }

        const hasDraftContent = data.title.trim()
            || data.wp_content.trim()
            || data.fb_caption.trim()
            || data.fb_comment.trim()
            || data.fb_comment_2.trim()
            || (data.action_buttons && data.action_buttons.length > 0)
            || Boolean(data.pdf_media_id);

        if (!hasDraftContent || autosaveSnapshot === lastAutosaveSnapshotRef.current) {
            return undefined;
        }

        const timer = window.setTimeout(async () => {
            setAutosaveState('saving');

            const payload = {
                title: data.title,
                wp_content: data.wp_content,
                fb_caption: data.fb_caption,
                fb_comment: data.fb_comment,
                fb_comment_2: data.fb_comment_2,
                action_buttons: data.action_buttons,
                pdf_media_id: data.pdf_media_id || null,
                drive_sync_enabled: data.drive_sync_enabled,
                drive_employee_category: data.drive_employee_category,
                drive_announcement_stage: data.drive_announcement_stage,
                drive_document_date: data.drive_document_date,
                drive_position_title: data.drive_position_title,
                channels: data.channels,
                wp_category_id: data.wp_category_id || null,
                featured_image_id: data.featured_image_id || null,
                image_editor_data: data.image_editor_data || null,
            };

            try {
                const response = autosavePostId
                    ? await axios.put(route('posts.autosave.update', autosavePostId), payload)
                    : await axios.post(route('posts.autosave'), payload);

                if (!autosavePostId && response.data?.post_id) {
                    setAutosavePostId(response.data.post_id);
                }

                lastAutosaveSnapshotRef.current = autosaveSnapshot;
                setLastSavedAt(response.data?.saved_at || new Date().toISOString());
                setAutosaveState('saved');
            } catch {
                setAutosaveState('error');
            }
        }, 2500);

        return () => window.clearTimeout(timer);
    }, [allowAutosave, autosavePostId, autosaveSnapshot, processing]);

    const submitAction = async (action) => {
        if (pollIntervalRef.current) {
            window.clearInterval(pollIntervalRef.current);
            pollIntervalRef.current = null;
        }

        const isReplacement = action === 'replace_facebook';
        const formChannels = isReplacement ? ['facebook'] : data.channels;
        const isPut = !isReplacement && (submitMethod === 'put' || Boolean(mode === 'create' && autosavePostId));
        const targetUrl = isReplacement
            ? route('posts.facebook-replacement', postId)
            : ((mode === 'create' && autosavePostId) ? route('posts.update', autosavePostId) : submitUrl);

        setSubmitActionType(action);
        setSubmitProgress(10);
        setSubmitStatus('uploading');
        setLiveTargets(formChannels.map(ch => ({ channel: ch, status: 'pending', progress: 10 })));
        setSubmitErrorMessage('');
        setCustomErrors({});
        setSubmitModalOpen(true);

        const formData = new FormData();
        formData.append('title', data.title || '');
        formData.append('wp_content', data.wp_content || '');
        formData.append('fb_caption', data.fb_caption || '');
        if (data.fb_comment) formData.append('fb_comment', data.fb_comment);
        if (data.fb_comment_2) formData.append('fb_comment_2', data.fb_comment_2);
        formData.append('publish_action', isReplacement ? 'draft' : action);
        if (data.scheduled_at) formData.append('scheduled_at', data.scheduled_at);
        if (data.wp_category_id) formData.append('wp_category_id', data.wp_category_id);
        formData.append('featured_image_id', data.featured_image_id ? data.featured_image_id : '');
        formData.append(
            'image_editor_data',
            data.image_editor_data ? JSON.stringify(data.image_editor_data) : '',
        );
        formData.append('pdf_media_id', data.pdf_media_id ? data.pdf_media_id : '');
        formData.append('drive_sync_enabled', data.drive_sync_enabled ? '1' : '0');
        if (data.drive_employee_category) formData.append('drive_employee_category', data.drive_employee_category);
        if (data.drive_announcement_stage) formData.append('drive_announcement_stage', data.drive_announcement_stage);
        if (data.drive_document_date) formData.append('drive_document_date', data.drive_document_date);
        if (data.drive_position_title) formData.append('drive_position_title', data.drive_position_title);
        if (data.drive_custom_filename) formData.append('drive_custom_filename', data.drive_custom_filename);
        if (data.file) formData.append('file', data.file);
        if (data.pdf_file) formData.append('pdf_file', data.pdf_file);

        formChannels.forEach(ch => {
            formData.append('channels[]', ch);
        });

        if (Array.isArray(data.action_buttons) && data.action_buttons.length > 0) {
            data.action_buttons.forEach((btn, index) => {
                if ((btn.label || '').trim() || (btn.url || '').trim()) {
                    formData.append(`action_buttons[${index}][label]`, btn.label || '');
                    formData.append(`action_buttons[${index}][url]`, btn.url || '');
                }
            });
        }

        if (isPut) {
            formData.append('_method', 'put');
        }

        try {
            const response = await axios.post(targetUrl, formData, {
                headers: {
                    'Content-Type': 'multipart/form-data',
                    'Accept': 'application/json',
                },
                onUploadProgress: (progressEvent) => {
                    if (progressEvent.total) {
                        const percent = Math.round((progressEvent.loaded * 100) / progressEvent.total);
                        setSubmitProgress(Math.min(100, Math.max(10, percent)));
                    }
                },
            });

            const createdPostId = response.data?.post_id;
            const destination = response.data?.redirect_url || route('posts.index');
            setRedirectUrl(destination);

            if (action !== 'publish' || !createdPostId) {
                setSubmitStatus('success');
                window.setTimeout(() => {
                    router.visit(destination);
                }, 1500);
                return;
            }

            // Live polling for actual external publishing process completion
            setSubmitStatus('publishing');

            let pollAttempts = 0;
            const maxPollAttempts = 60;

            pollIntervalRef.current = window.setInterval(async () => {
                pollAttempts += 1;
                try {
                    const statusRes = await axios.get(route('posts.status', createdPostId));
                    const statusData = statusRes.data;

                    if (Array.isArray(statusData.targets)) {
                        setLiveTargets(statusData.targets);
                    }

                    if (statusData.is_terminal) {
                        if (pollIntervalRef.current) {
                            window.clearInterval(pollIntervalRef.current);
                            pollIntervalRef.current = null;
                        }

                        if (statusData.status === 'published') {
                            setSubmitStatus('success');
                            window.setTimeout(() => {
                                router.visit(destination);
                            }, 2500);
                        } else if (statusData.status === 'partially_published') {
                            setSubmitStatus('partial');
                            window.setTimeout(() => {
                                router.visit(destination);
                            }, 3500);
                        } else if (statusData.status === 'failed') {
                            setSubmitStatus('error');
                            const failedTarget = (statusData.targets || []).find(t => t.status === 'failed' && t.error);
                            setSubmitErrorMessage(failedTarget?.error || 'การเผยแพร่ไปยังระบบปลายทางล้มเหลว');
                        } else {
                            setSubmitStatus('success');
                            window.setTimeout(() => {
                                router.visit(destination);
                            }, 2000);
                        }
                    } else if (pollAttempts >= maxPollAttempts) {
                        if (pollIntervalRef.current) {
                            window.clearInterval(pollIntervalRef.current);
                            pollIntervalRef.current = null;
                        }
                        setSubmitStatus('success');
                        window.setTimeout(() => {
                            router.visit(destination);
                        }, 2000);
                    }
                } catch {
                    // Ignore transient polling errors
                }
            }, 1000);
        } catch (err) {
            setSubmitStatus('error');
            if (err.response?.status === 422 && err.response?.data?.errors) {
                setCustomErrors(err.response.data.errors);
                const firstErr = Object.values(err.response.data.errors)[0];
                setSubmitErrorMessage(Array.isArray(firstErr) ? firstErr[0] : (err.response.data.message || 'ข้อมูลไม่ถูกต้อง'));
            } else {
                setSubmitErrorMessage(err.response?.data?.message || 'เกิดข้อผิดพลาดในการเชื่อมต่อ กรุณาลองใหม่อีกครั้ง');
            }
        }
    };

    const submit = (e) => {
        e.preventDefault();
        // default to whatever current publish_action is (usually overridden by buttons)
        submitAction(data.publish_action);
    };

    const handleChannelChange = (e) => {
        const value = e.target.value;
        if (initialData?.is_facebook_replacement) return;
        let newChannels = [...data.channels];
        if (e.target.checked) {
            newChannels.push(value);
        } else {
            newChannels = newChannels.filter((c) => c !== value);
        }
        setData('channels', newChannels);
    };

    const applyTemplate = (e) => {
        const tId = e.target.value;
        setSelectedTemplateId(tId);
        if (!tId) return;
        const tmpl = (templates || []).find(t => String(t.id) === String(tId));
        
        if (tmpl) {
            const hasContent = (data.wp_content || '').trim()
                || (data.fb_caption || '').trim()
                || (data.fb_comment_2 && data.fb_comment_2.trim())
                || (data.action_buttons && data.action_buttons.length > 0)
                || data.pdf_media_id
                || data.pdf_file
                || data.featured_image_id
                || data.file;
            if (hasContent) {
                if (!window.confirm('การใช้เทมเพลตจะเขียนทับเนื้อหา รูปประกอบ และเอกสารแนบปัจจุบัน ต้องการดำเนินการต่อหรือไม่')) {
                    setSelectedTemplateId('');
                    return;
                }
            }

            if (tmpl.featured_image) {
                rememberMedia(tmpl.featured_image);
            }
            if (tmpl.pdf_media) {
                rememberMedia(tmpl.pdf_media);
            }
            
            const nextTitle = data.title || tmpl.name || '';
            let parsedWp = tmpl.wp_template || '';
            let parsedFb = tmpl.fb_template || '';
            let parsedFbComment = tmpl.fb_comment || '';
            let parsedFbComment2 = tmpl.fb_comment_2 || '';
            if (nextTitle) {
                parsedWp = parsedWp.replace(/{{title}}/g, nextTitle);
                parsedFb = parsedFb.replace(/{{title}}/g, nextTitle);
                parsedFbComment = parsedFbComment.replace(/{{title}}/g, nextTitle);
                parsedFbComment2 = parsedFbComment2.replace(/{{title}}/g, nextTitle);
            }
            setData(prev => ({
                ...prev,
                title: data.title ? data.title : (tmpl.name || ''),
                wp_content: parsedWp,
                fb_caption: parsedFb,
                fb_comment: parsedFbComment || prev.fb_comment,
                fb_comment_2: parsedFbComment2,
                action_buttons: Array.isArray(tmpl.action_buttons) ? tmpl.action_buttons : [],
                pdf_media_id: tmpl.pdf_media_id || null,
                pdf_file: null,
                drive_sync_enabled: tmpl.drive_sync_enabled !== null && tmpl.drive_sync_enabled !== undefined
                    ? Boolean(tmpl.drive_sync_enabled)
                    : prev.drive_sync_enabled,
                drive_employee_category: tmpl.drive_employee_category || prev.drive_employee_category,
                drive_announcement_stage: tmpl.drive_announcement_stage || prev.drive_announcement_stage,
                featured_image_id: tmpl.featured_image_id || null,
                image_editor_data: tmpl.image_editor_data || null,
                file: null,
            }));
            setPreviewUrl(null);
            if (pdfPreviewUrl) {
                URL.revokeObjectURL(pdfPreviewUrl);
                setPdfPreviewUrl(null);
            }
            if (fileInputRef.current) fileInputRef.current.value = '';
            if (pdfFileInputRef.current) pdfFileInputRef.current.value = '';
        }
    };

    const insertWpUrl = () => {
        const urlPlaceholder = '{wordpress_url}';
        setData('fb_caption', data.fb_caption + (data.fb_caption ? ' ' : '') + urlPlaceholder);
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
        const file = e.target.files[0];
        if (!file) return;

        if (file.type !== 'application/pdf') {
            alert('กรุณาเลือกไฟล์ PDF เท่านั้น');
            e.target.value = '';
            return;
        }

        setData(prev => ({
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
        setData(prev => ({
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

    const addDriveUrlToButtons = () => {
        addActionButton('ดาวน์โหลดประกาศ (Google Drive)', '{drive_url}');
    };

    const insertDriveUrlIntoComment1 = () => {
        const cur = data.fb_comment || '';
        if (!cur.includes('{drive_url}')) {
            setData('fb_comment', cur ? `${cur}\n{drive_url}` : '{drive_url}');
        }
    };

    const applyDefaultDriveComment = () => {
        const tmpl = googleDriveConfig?.default_comment_template || "📄 เอกสารประกาศฉบับเต็ม:\n{drive_url}";
        setData('fb_comment', tmpl);
    };

    const calculatedDriveFilename = () => {
        if (data.drive_custom_filename && data.drive_custom_filename.trim()) {
            const trimmed = data.drive_custom_filename.trim();
            return trimmed.endsWith('.pdf') ? trimmed : `${trimmed}.pdf`;
        }
        const docDate = data.drive_document_date || today;
        const stage = data.drive_announcement_stage || googleDriveConfig?.default_stage || 'รับสมัคร';
        const cat = data.drive_employee_category || googleDriveConfig?.default_category || 'พนักงานรายวัน';
        const pos = data.drive_position_title ? `-${data.drive_position_title.trim()}` : '';
        return `${docDate} ${stage}-${cat}${pos}.pdf`;
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

    const confirmReplacement = async () => {
        const result = await Swal.fire({
            icon: 'warning',
            title: 'สร้างโพสต์ Facebook ใหม่?',
            html: 'ระบบจะสร้าง<strong>ฉบับร่างใหม่สำหรับ Facebook เท่านั้น</strong> พร้อมรูปและข้อความที่แก้ไข เพื่อให้ตรวจสอบก่อนเผยแพร่<br/><br/>โพสต์ Facebook เดิมและข่าว WordPress จะยังคงอยู่ และไม่ถูกแก้ไขหรือลบ',
            showCancelButton: true,
            confirmButtonText: 'สร้างร่างโพสต์ใหม่',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#000080',
            reverseButtons: true,
        });

        if (result.isConfirmed) {
            submitAction('replace_facebook');
        }
    };

    const confirmPublish = async () => {
        if (hasPublishedFacebookTarget && imageChanged) {
            await confirmReplacement();
            return;
        }

        const channelNames = data.channels.map((channel) => channel === 'wordpress' ? 'WordPress' : 'Facebook').join(' และ ');
        
        let title = 'ยืนยันเผยแพร่ทันที?';
        let text = `ระบบจะส่งโพสต์ไปยัง ${channelNames || 'ช่องทางที่เลือก'} การดูตัวอย่างด้านบนไม่ได้เป็นการเผยแพร่จริง`;
        let confirmText = 'เผยแพร่จริง';

        if (mode === 'edit' && (initialData?.status === 'published' || initialData?.status === 'partially_published')) {
            title = 'อัปเดตโพสต์ไปยังระบบปลายทางด้วยหรือไม่?';
            text = `ระบบจะส่งเนื้อหาที่แก้ไขนี้ไปอัปเดตบน ${channelNames || 'ช่องทางที่เลือก'} ด้วย`;
            confirmText = 'อัปเดตระบบปลายทาง';

            if (hasPublishedFacebookTarget && imageChanged) {
                text = 'WordPress จะอัปเดตรูปใหม่ตามที่เลือก แต่ Facebook post เดิมไม่สามารถสลับไฟล์รูปผ่านการอัปเดตนี้ได้ จึงจะอัปเดตเฉพาะข้อความบน Facebook';
            }
        }

        const result = await Swal.fire({
            icon: 'warning',
            title: title,
            text: text,
            showCancelButton: true,
            confirmButtonText: confirmText,
            cancelButtonText: 'กลับไปตรวจสอบ',
            confirmButtonColor: '#000080',
        });

        if (result.isConfirmed) {
            submitAction('publish');
        }
    };

    const confirmDraft = async () => {
        if (hasPublishedFacebookTarget && imageChanged) {
            await confirmReplacement();
            return;
        }

        if (mode === 'edit' && (initialData?.status === 'published' || initialData?.status === 'partially_published')) {
            const result = await Swal.fire({
                icon: 'info',
                title: 'บันทึกเฉพาะในระบบเรา?',
                text: 'โพสต์นี้เผยแพร่ไปแล้ว การบันทึกร่างจะทำให้เนื้อหาในระบบเราเปลี่ยนไป แต่บน Facebook/WordPress จะยังคงเป็นเนื้อหาเดิม ต้องการดำเนินการต่อหรือไม่?',
                showCancelButton: true,
                confirmButtonText: 'บันทึกแค่ในระบบ',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#3085d6',
            });
            if (result.isConfirmed) {
                submitAction('draft');
            }
        } else {
            submitAction('draft');
        }
    };

    const imageMediaList = (localMedia || []).filter(m => m.mime_type?.startsWith('image/'));
    const filteredImages = imageMediaList.filter(m => {
        const matchesFolder = selectedFolderFilter === 'all'
            ? true
            : (selectedFolderFilter === 'root' ? !m.folder_id : String(m.folder_id) === String(selectedFolderFilter));
        const matchesCategory = selectedCategoryFilter === 'all'
            ? true
            : (selectedCategoryFilter === 'none' ? !m.category : m.category === selectedCategoryFilter);
        return matchesFolder && matchesCategory;
    });
    const selectedMedia = data.featured_image_id
        ? ((imageMediaList || []).find(m => String(m.id) === String(data.featured_image_id))
            || (templates || []).find(t => String(t.featured_image_id) === String(data.featured_image_id))?.featured_image
            || (String(initialData?.featured_image_id) === String(data.featured_image_id) ? initialData?.featuredImage : null))
        : null;
    const selectedImageUrl = previewUrl
        || (selectedMedia ? resolveStorageUrl(selectedMedia) : null);
    const selectedCategory = (wpCategories || []).find(c => String(c.id) === String(data.wp_category_id));
    const pdfMediaList = (localMedia || []).filter(m => m.mime_type === 'application/pdf');
    const selectedPdfMedia = data.pdf_media_id
        ? ((localMedia || []).find(m => String(m.id) === String(data.pdf_media_id))
            || (templates || []).find(t => String(t.pdf_media_id) === String(data.pdf_media_id))?.pdf_media
            || (String(initialData?.pdf_media_id) === String(data.pdf_media_id) ? initialData?.pdfMedia : null))
        : null;
    const selectedPdfUrl = pdfPreviewUrl
        || (selectedPdfMedia ? resolveStorageUrl(selectedPdfMedia) : null);
    const selectedPdfName = data.pdf_file ? data.pdf_file.name : (selectedPdfMedia ? (selectedPdfMedia?.original_name || (selectedPdfMedia?.file_path ? selectedPdfMedia.file_path.split('/').pop() : null)) : null);

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
            file: null,
        }));
        setPreviewUrl(null);
        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
        setIsImageEditorOpen(false);
    };

    const hasPublishedFacebookTarget = mode === 'edit'
        && isFbSelected
        && Boolean(initialData?.facebook_target_published);

    const imageChanged = mode === 'edit' && (
        Boolean(data.file)
        || String(data.featured_image_id || '') !== String(initialData?.featured_image_id || '')
    );

    return (
        <form onSubmit={submit} className="flex flex-col gap-6">
            
            {/* Header / Info Section */}
            <div className="bg-white shadow-sm sm:rounded-lg p-6 flex flex-col md:flex-row gap-6">
                <div className="flex-1 space-y-4">
                    <div className="flex flex-col gap-1 border-b pb-2 sm:flex-row sm:items-center sm:justify-between">
                        <h3 className="text-lg font-medium text-[#000080]">ข้อมูลข่าว</h3>
                        {allowAutosave && (
                            <span className={`text-xs font-medium ${
                                autosaveState === 'error'
                                    ? 'text-rose-600'
                                    : autosaveState === 'saving'
                                      ? 'text-blue-600'
                                      : 'text-slate-500'
                            }`}>
                                {autosaveState === 'saving' && 'กำลังบันทึกร่างอัตโนมัติ...'}
                                {autosaveState === 'saved' && `บันทึกร่างแล้ว ${lastSavedAt ? new Date(lastSavedAt).toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' }) : ''}`}
                                {autosaveState === 'error' && 'บันทึกร่างอัตโนมัติไม่สำเร็จ'}
                                {autosaveState === 'idle' && 'บันทึกร่างอัตโนมัติเมื่อมีการแก้ไข'}
                            </span>
                        )}
                    </div>
                    
                    <div>
                        <label className="block text-sm font-medium text-gray-700">หัวข้อข่าว (Title)</label>
                        <input
                            type="text"
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                            value={data.title}
                            onChange={e => setData('title', e.target.value)}
                            required
                        />
                        {displayErrors.title && <p className="text-red-500 text-xs mt-1">{displayErrors.title}</p>}
                    </div>

                    <div className="flex flex-col md:flex-row gap-4">
                        <div className="flex-1">
                            <label className="block text-sm font-medium text-gray-700">ดึงข้อมูลจากเทมเพลต (Template)</label>
                            <select 
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                value={selectedTemplateId}
                                onChange={applyTemplate}
                            >
                                <option value="">-- เลือกเทมเพลต --</option>
                                {templates && templates.map(t => (
                                    <option key={t.id} value={t.id}>{t.name}</option>
                                ))}
                            </select>
                            {selectedTemplateId && (
                                <div className="mt-1.5 flex items-center justify-between text-xs text-blue-800 bg-blue-50 px-2.5 py-1 rounded border border-blue-200">
                                    <span>กำลังใช้เทมเพลต: <strong>{templates?.find(t => String(t.id) === String(selectedTemplateId))?.name}</strong></span>
                                    <button
                                        type="button"
                                        onClick={() => setSelectedTemplateId('')}
                                        className="text-slate-400 hover:text-slate-600 ml-2 underline"
                                        title="ยกเลิกการเลือกเทมเพลต"
                                    >
                                        ล้างการเลือก
                                    </button>
                                </div>
                            )}
                        </div>
                        {isWpSelected && (
                            <div className="flex-1">
                                <label className="block text-sm font-medium text-gray-700">หมวดหมู่ข่าว (WordPress Category)</label>
                                <select 
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                    value={data.wp_category_id || ''}
                                    onChange={e => setData('wp_category_id', e.target.value)}
                                >
                                    <option value="">-- ไม่ระบุหมวดหมู่ --</option>
                                    {wpCategories && wpCategories.map(c => (
                                        <option key={c.id} value={c.id}>{c.name}</option>
                                    ))}
                                </select>
                            </div>
                        )}
                    </div>
                </div>
            </div>

            {/* Split Editor UI */}
            <div className="flex flex-col xl:flex-row gap-6">
                
                {/* WordPress Editor */}
                <div className={`flex-1 bg-white shadow-sm sm:rounded-lg flex flex-col transition-opacity ${!isWpSelected ? 'opacity-50 grayscale pointer-events-none' : ''}`}>
                    <div className="p-6 flex-1 flex flex-col">
                        <div className="flex items-center justify-between mb-4 border-b pb-2">
                            <h3 className="text-lg font-medium text-slate-900 flex items-center">
                                <WordPressIcon className="w-5 h-5 mr-2 text-[#21759B]" />
                                เว็บไซต์ WordPress
                            </h3>
                            {!isWpSelected && <span className="text-xs bg-gray-200 text-gray-600 px-2 py-1 rounded font-medium">Disabled</span>}
                        </div>
                        <div className="flex-1 flex flex-col">
                            {isWpSelected ? (
                                <>
                                    <Suspense fallback={<div className="min-h-[240px] rounded-md border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">กำลังโหลดเครื่องมือเขียนข่าว...</div>}>
                                        <RichTextEditor
                                            value={data.wp_content}
                                            onChange={(html) => setData('wp_content', html)}
                                        />
                                    </Suspense>
                                    {displayErrors.wp_content && <p className="text-red-500 text-xs mt-2">{displayErrors.wp_content}</p>}
                                </>
                            ) : (
                                <div className="flex min-h-[240px] items-center justify-center rounded-md border border-dashed border-slate-300 bg-slate-50 p-6 text-center text-sm text-slate-500">
                                    เลือกช่องทาง WordPress เพื่อเปิดเครื่องมือเขียนบทความ
                                </div>
                            )}

                            {/* WordPress Action / Application Buttons */}
                            {isWpSelected && (
                                <div className="mt-6 border-t pt-5">
                                    <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-3">
                                        <div>
                                            <label className="text-sm font-semibold text-slate-900 flex items-center gap-1.5">
                                                <LinkIcon className="w-4 h-4 text-[#000080]" />
                                                ปุ่มและลิงก์ใบสมัคร (Action Buttons)
                                            </label>
                                            <p className="text-xs text-slate-500 mt-0.5">
                                                จะแสดงเป็นปุ่มกดท้ายบทความบน WordPress เสมอ สามารถตั้งค่าได้หลายปุ่ม
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
                                            <p className="text-xs text-slate-500">ยังไม่มีการเพิ่มปุ่มใบสมัคร</p>
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
                                </div>
                            )}

                            {/* PDF Announcement & Google Drive Attachment Card */}
                            <div className="mt-6 border-t pt-5">
                                <div className="mb-3 flex items-start justify-between gap-2">
                                    <div>
                                        <label className="text-sm font-semibold text-slate-900 flex items-center gap-1.5">
                                            <FileText className="w-4 h-4 text-red-600" />
                                            เอกสารประกาศ (PDF) & Google Drive
                                        </label>
                                        <p className="text-xs text-slate-500 mt-0.5">
                                            แนบไฟล์ประกาศ PDF เพื่ออัปโหลดจัดระเบียบลง Google Drive อัตโนมัติ และ/หรือฝัง Viewer บนหน้าเว็บ WordPress
                                        </p>
                                    </div>
                                    {googleDriveConfig?.is_connected ? (
                                        <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700">
                                            <GoogleDriveIcon className="h-3 w-3" />
                                            Drive เชื่อมต่อแล้ว
                                        </span>
                                    ) : (
                                        <span className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-700">
                                            <GoogleDriveIcon className="h-3 w-3" />
                                            ยังไม่ต่อ Drive
                                        </span>
                                    )}
                                </div>

                                {selectedPdfName ? (
                                    <div className="space-y-3">
                                        <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 p-3.5 rounded-xl border border-red-200 bg-red-50/40">
                                            <div className="flex items-center gap-2.5 min-w-0">
                                                <div className="p-2 rounded-lg bg-red-100 text-red-600 shrink-0">
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
                                                        {selectedPdfMedia?.drive_web_view_link && (
                                                            <a
                                                                href={selectedPdfMedia.drive_web_view_link}
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                                className="text-emerald-700 hover:underline inline-flex items-center gap-0.5"
                                                            >
                                                                <GoogleDriveIcon className="w-3 h-3" /> ดูบน Drive
                                                            </a>
                                                        )}
                                                    </p>
                                                </div>
                                            </div>

                                            <div className="flex items-center gap-2 shrink-0 self-end sm:self-auto">
                                                <button
                                                    type="button"
                                                    onClick={insertDriveUrlIntoComment1}
                                                    className="inline-flex items-center gap-1 text-[11px] font-medium text-[#000080] bg-blue-50 hover:bg-blue-100 border border-blue-200 px-2.5 py-1.5 rounded-lg shadow-2xs"
                                                    title="แทรก {drive_url} ในคอมเมนต์แรก Facebook"
                                                >
                                                    <Sparkles className="w-3 h-3" />
                                                    ใส่ใน Comment #1
                                                </button>
                                                {isWpSelected && (
                                                    <button
                                                        type="button"
                                                        onClick={addDriveUrlToButtons}
                                                        className="inline-flex items-center gap-1 text-[11px] font-medium text-slate-700 bg-white hover:bg-slate-50 border border-slate-300 px-2.5 py-1.5 rounded-lg shadow-2xs"
                                                        title="สร้างปุ่มดาวน์โหลดจากลิงก์ Google Drive บนเว็บ"
                                                    >
                                                        <Plus className="w-3 h-3 text-[#000080]" />
                                                        เพิ่มปุ่มเว็บ
                                                    </button>
                                                )}
                                                <button
                                                    type="button"
                                                    onClick={removeAttachedPdf}
                                                    className="p-1.5 text-slate-400 hover:text-red-600 rounded-lg hover:bg-red-50 transition-colors"
                                                    title="ยกเลิกการแนบไฟล์นี้"
                                                >
                                                    <Trash2 className="w-4 h-4" />
                                                </button>
                                            </div>
                                        </div>

                                        {/* Google Drive Configuration for this PDF */}
                                        <div className="rounded-xl border border-blue-100 bg-blue-50/40 p-4 space-y-3">
                                            <div className="flex items-center justify-between">
                                                <label className="flex items-center gap-2 text-xs font-semibold text-slate-800 cursor-pointer">
                                                    <input
                                                        type="checkbox"
                                                        checked={data.drive_sync_enabled}
                                                        onChange={(e) => setData('drive_sync_enabled', e.target.checked)}
                                                        className="rounded text-[#000080] focus:ring-[#000080]"
                                                    />
                                                    <GoogleDriveIcon className="h-4 w-4" />
                                                    <span>ซิงค์ไฟล์นี้ขึ้น Google Drive ตามหมวดหมู่อัตโนมัติ</span>
                                                </label>
                                                {data.drive_sync_enabled && (
                                                    <span className="text-[11px] text-slate-500 font-medium">
                                                        สร้างชื่อไฟล์ตามสูตร มหาวิทยาลัย
                                                    </span>
                                                )}
                                            </div>

                                            {data.drive_sync_enabled && (
                                                <div className="space-y-3 pt-2 border-t border-blue-100">
                                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                                        <div>
                                                            <label className="block text-[11px] font-medium text-slate-700 mb-1">
                                                                ประเภทบุคลากร (โฟลเดอร์หลัก) *
                                                            </label>
                                                            <select
                                                                value={data.drive_employee_category}
                                                                onChange={(e) => setData('drive_employee_category', e.target.value)}
                                                                className="w-full text-xs rounded-lg border-slate-300 py-1.5 focus:border-[#000080] focus:ring-[#000080]"
                                                            >
                                                                {(googleDriveConfig?.categories || ['พนักงานรายวัน', 'พนักงานประจำตามสัญญา', 'พนักงานมหาวิทยาลัย', 'งบประเภทอื่น ๆ']).map((cat) => (
                                                                    <option key={cat} value={cat}>
                                                                        {cat}
                                                                    </option>
                                                                ))}
                                                            </select>
                                                        </div>

                                                        <div>
                                                            <label className="block text-[11px] font-medium text-slate-700 mb-1">
                                                                ขั้นตอนประกาศ (โฟลเดอร์ย่อย) *
                                                            </label>
                                                            <select
                                                                value={data.drive_announcement_stage}
                                                                onChange={(e) => setData('drive_announcement_stage', e.target.value)}
                                                                className="w-full text-xs rounded-lg border-slate-300 py-1.5 focus:border-[#000080] focus:ring-[#000080]"
                                                            >
                                                                {(googleDriveConfig?.stages || ['รับสมัคร', 'ประกาศรายชื่อ', 'ผลการคัดเลือก']).map((stg) => (
                                                                    <option key={stg} value={stg}>
                                                                        {stg}
                                                                    </option>
                                                                ))}
                                                            </select>
                                                        </div>
                                                    </div>

                                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                                        <div>
                                                            <label className="block text-[11px] font-medium text-slate-700 mb-1">
                                                                วันที่เอกสารประกาศ *
                                                            </label>
                                                            <input
                                                                type="date"
                                                                value={data.drive_document_date}
                                                                onChange={(e) => setData('drive_document_date', e.target.value)}
                                                                className="w-full text-xs rounded-lg border-slate-300 py-1.5 focus:border-[#000080] focus:ring-[#000080]"
                                                            />
                                                        </div>

                                                        <div>
                                                            <label className="block text-[11px] font-medium text-slate-700 mb-1">
                                                                ตำแหน่งหรือรายละเอียด (ต่อท้ายชื่อไฟล์)
                                                            </label>
                                                            <input
                                                                type="text"
                                                                value={data.drive_position_title}
                                                                onChange={(e) => setData('drive_position_title', e.target.value)}
                                                                placeholder="เช่น พนักงานขับรถ, แม่บ้าน"
                                                                className="w-full text-xs rounded-lg border-slate-300 py-1.5 focus:border-[#000080] focus:ring-[#000080]"
                                                            />
                                                        </div>
                                                    </div>

                                                    <div className="rounded-lg bg-white/80 p-2.5 border border-blue-100 text-[11px]">
                                                        <span className="font-semibold text-slate-700">สูตรชื่อไฟล์บน Google Drive:</span>
                                                        <code className="mt-0.5 block font-mono text-[#000080] font-semibold break-all">
                                                            {calculatedDriveFilename()}
                                                        </code>
                                                        <span className="text-slate-500 mt-1 block">
                                                            จัดเก็บใน: <span className="font-medium text-slate-700">{data.drive_employee_category} &gt; {data.drive_announcement_stage}</span>
                                                        </span>
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                ) : (
                                    <div className="flex flex-col sm:flex-row gap-3">
                                        <div
                                            onClick={() => pdfFileInputRef.current?.click()}
                                            className="flex-1 flex items-center justify-center gap-2 p-3.5 rounded-lg border border-dashed border-slate-300 bg-slate-50 hover:bg-slate-100 text-slate-600 cursor-pointer transition-colors text-xs font-medium"
                                        >
                                            <Upload className="w-4 h-4 text-slate-500" />
                                            <span>อัปโหลดไฟล์ PDF ใหม่</span>
                                        </div>
                                        <input
                                            type="file"
                                            ref={pdfFileInputRef}
                                            className="hidden"
                                            accept="application/pdf"
                                            onChange={handlePdfFileUpload}
                                        />

                                        {pdfMediaList.length > 0 && (
                                            <div className="sm:w-5/12">
                                                <select
                                                    value={data.pdf_media_id || ''}
                                                    onChange={(e) => {
                                                        const val = e.target.value ? Number(e.target.value) : null;
                                                        setData(prev => ({
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
                                                    {pdfMediaList.map(m => (
                                                        <option key={m.id} value={m.id}>
                                                            {m.drive_file_name || m.original_name || m.file_path.split('/').pop()}
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                </div>

                {/* Facebook Editor */}
                <div className={`flex-1 bg-white shadow-sm sm:rounded-lg flex flex-col transition-opacity ${!isFbSelected ? 'opacity-50 grayscale pointer-events-none' : ''}`}>
                    <div className="p-6 flex-1 flex flex-col">
                        <div className="flex items-center justify-between mb-4 border-b pb-2">
                            <h3 className="text-lg font-medium text-slate-900 flex items-center">
                                <FacebookIcon className="w-5 h-5 mr-2 text-[#1877F2]" />
                                Facebook Page
                            </h3>
                            {!isFbSelected && <span className="text-xs bg-gray-200 text-gray-600 px-2 py-1 rounded font-medium">Disabled</span>}
                        </div>
                        
                        <div className="mb-4 flex-1 flex flex-col">
                            <label className="block text-sm font-medium text-gray-700 mb-1">ข้อความโพสต์</label>
                            <textarea
                                rows="8"
                                className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm resize-y"
                                placeholder="ข้อความแคปชั่น Facebook..."
                                value={data.fb_caption}
                                onChange={e => setData('fb_caption', e.target.value)}
                            ></textarea>
                            <div className="mt-2 flex items-center justify-between">
                                <button type="button" onClick={insertWpUrl} className="text-xs bg-blue-50 text-blue-700 hover:bg-blue-100 px-2 py-1 rounded border border-blue-200 flex items-center">
                                    <svg className="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path></svg>
                                    แทรก {'{wordpress_url}'}
                                </button>
                            </div>
                            {displayErrors.fb_caption && <p className="text-red-500 text-xs mt-1">{displayErrors.fb_caption}</p>}
                        </div>

                        <div className="border-t pt-4">
                            <div className="flex items-center justify-between mb-1">
                                <label className="block text-sm font-medium text-gray-700">คอมเมนต์แรกใต้โพสต์ (First Comment)</label>
                                <span className="text-[11px] bg-emerald-50 text-emerald-700 px-2 py-0.5 rounded font-medium border border-emerald-200">
                                    Comment #1
                                </span>
                            </div>
                            <textarea
                                rows="3"
                                className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm resize-y"
                                placeholder="ข้อความคอมเมนต์แรก..."
                                value={data.fb_comment}
                                onChange={e => setData('fb_comment', e.target.value)}
                            ></textarea>
                            <div className="mt-2 flex flex-wrap items-center justify-between gap-2">
                                <div className="flex flex-wrap items-center gap-1.5">
                                    <button
                                        type="button"
                                        onClick={insertDriveUrlIntoComment1}
                                        className="text-xs bg-emerald-50 text-emerald-700 hover:bg-emerald-100 px-2 py-1 rounded border border-emerald-200 flex items-center gap-1 transition-colors"
                                        title="แทรก {drive_url} ในคอมเมนต์นี้"
                                    >
                                        <GoogleDriveIcon className="w-3.5 h-3.5" />
                                        แทรก {'{drive_url}'}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={applyDefaultDriveComment}
                                        className="text-xs bg-blue-50 text-blue-700 hover:bg-blue-100 px-2 py-1 rounded border border-blue-200 flex items-center gap-1 transition-colors"
                                        title="ใช้ข้อความเทมเพลตเริ่มต้นของ Google Drive"
                                    >
                                        <Sparkles className="w-3 h-3" />
                                        ใช้ข้อความ Drive เริ่มต้น
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setData('fb_comment', data.fb_comment ? `${data.fb_comment} {wordpress_url}` : '{wordpress_url}')}
                                        className="text-xs bg-slate-50 text-slate-700 hover:bg-slate-100 px-2 py-1 rounded border border-slate-200 flex items-center gap-1 transition-colors"
                                    >
                                        <LinkIcon className="w-3 h-3" />
                                        แทรก {'{wordpress_url}'}
                                    </button>
                                </div>
                            </div>
                            <p className="text-xs text-gray-500 mt-1">
                                โพสต์เป็นคอมเมนต์แรกทันทีเมื่อโพสต์ Facebook สำเร็จ ลิงก์ <code className="text-[#000080] font-semibold">{`{drive_url}`}</code> จะถูกแทนที่ด้วยลิงก์สาธารณะจาก Google Drive
                            </p>
                        </div>

                        {/* Facebook Second Comment (Comment #2 - Application & Info) */}
                        <div className="border-t pt-4 mt-4">
                            <div className="flex items-center justify-between mb-1">
                                <label className="block text-sm font-medium text-slate-900">
                                    คอมเมนต์ที่ 2 ใต้โพสต์ (ช่องใส่ใบสมัครและรายละเอียด)
                                </label>
                                <span className="text-[11px] bg-blue-50 text-blue-700 px-2 py-0.5 rounded font-medium border border-blue-200">
                                    Comment #2
                                </span>
                            </div>
                            <textarea
                                rows="3"
                                className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm resize-y"
                                placeholder="ข้อความคอมเมนต์ที่ 2 เช่น สนใจสมัครงานดาวน์โหลดใบสมัครได้ที่นี่..."
                                value={data.fb_comment_2 || ''}
                                onChange={e => setData('fb_comment_2', e.target.value)}
                            ></textarea>
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
                                จะโพสต์เป็นคอมเมนต์ที่ 2 ต่อจากคอมเมนต์แรก เหมาะสำหรับระบุลิงก์ใบสมัครหรือช่องทางติดต่อ
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            {/* Media Section */}
            <div className="bg-white shadow-sm sm:rounded-lg p-6">
                <div className="mb-4 flex flex-col gap-3 border-b pb-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 className="text-lg font-medium text-[#000080]">รูปประกอบข่าว</h3>
                        <p className="mt-1 text-xs text-slate-500">
                            เลือกรูปจากคลัง หรือเปิดเครื่องมือแก้ไขรูปเพื่อใส่ข้อความ รูปเพิ่ม พื้นหลัง และจัดวางแบบลากได้
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
                
                <div className="flex flex-col md:flex-row gap-6">
                    <div className="md:w-1/3">
                        <label className="block text-sm font-medium text-gray-700 mb-2">อัปโหลดรูปภาพใหม่</label>
                        <div className="border-2 border-dashed border-gray-300 rounded-lg p-6 flex flex-col items-center justify-center text-center bg-gray-50 hover:bg-gray-100 transition-colors cursor-pointer" onClick={() => fileInputRef.current?.click()}>
                            <svg className="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48" aria-hidden="true">
                                <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
                            </svg>
                            <div className="mt-2 flex text-sm text-gray-600">
                                <span className="relative rounded-md font-medium text-indigo-600 hover:text-indigo-500 focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-indigo-500">
                                    เลือกไฟล์
                                </span>
                            </div>
                            <p className="text-xs text-gray-500 mt-1">PNG, JPG, WEBP</p>
                        </div>
                        <input 
                            type="file" 
                            ref={fileInputRef}
                            className="hidden"
                            accept="image/jpeg,image/png,image/webp"
                            onChange={e => {
                                const file = e.target.files[0];
                                if (!file) return;
                                
                                if (file.type === 'application/pdf') {
                                    alert('Facebook ไม่รองรับการโพสต์ PDF เป็นรูปภาพ กรุณาเลือกไฟล์รูปภาพ (JPG/PNG)');
                                    e.target.value = '';
                                    return;
                                }

                                setData(data => ({
                                    ...data,
                                    file: file,
                                    featured_image_id: null,
                                    image_editor_data: null,
                                }));
                                if (file && file.type.startsWith('image/')) {
                                    setPreviewUrl(URL.createObjectURL(file));
                                } else {
                                    setPreviewUrl(null);
                                }
                            }}
                        />
                        {errors.file && <p className="text-red-500 text-xs mt-1">{errors.file}</p>}
                    </div>

                    <div className="md:w-2/3">
                        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-2">
                            <label className="block text-sm font-medium text-gray-700">หรือ เลือกจากคลังรูปภาพ</label>
                            <div className="flex flex-wrap items-center gap-2 text-xs">
                                {folders && folders.length > 0 && (
                                    <div className="flex items-center gap-1">
                                        <span className="text-gray-500 font-medium">โฟลเดอร์:</span>
                                        <select
                                            value={selectedFolderFilter}
                                            onChange={(e) => setSelectedFolderFilter(e.target.value)}
                                            className="rounded-lg border-gray-300 py-1 pl-2 pr-7 text-xs font-medium focus:border-indigo-500 focus:ring-indigo-500 bg-white shadow-sm"
                                        >
                                            <option value="all">ทุกโฟลเดอร์</option>
                                            <option value="root">คลังหลัก (นอกโฟลเดอร์)</option>
                                            {folders.map(f => (
                                                <option key={f.id} value={f.id}>{f.name}</option>
                                            ))}
                                        </select>
                                    </div>
                                )}
                                {categories && categories.length > 0 && (
                                    <div className="flex items-center gap-1">
                                        <span className="text-gray-500 font-medium">หมวดหมู่:</span>
                                        <select
                                            value={selectedCategoryFilter}
                                            onChange={(e) => setSelectedCategoryFilter(e.target.value)}
                                            className="rounded-lg border-gray-300 py-1 pl-2 pr-7 text-xs font-medium focus:border-indigo-500 focus:ring-indigo-500 bg-white shadow-sm"
                                        >
                                            <option value="all">ทุกหมวดหมู่</option>
                                            <option value="none">ยังไม่ระบุหมวด</option>
                                            {categories.map(c => (
                                                <option key={c} value={c}>{c}</option>
                                            ))}
                                        </select>
                                    </div>
                                )}
                            </div>
                        </div>
                        <div className="border border-gray-200 rounded-lg p-4 bg-gray-50 h-[200px] overflow-y-auto">
                            <div className="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-5 gap-3">
                                {filteredImages.map(m => (
                                    <div 
                                        key={m.id} 
                                        onClick={() => {
                                            setData(data => ({
                                                ...data,
                                                featured_image_id: m.id,
                                                image_editor_data: null,
                                                file: null,
                                            }));
                                            setPreviewUrl(null);
                                            if (fileInputRef.current) fileInputRef.current.value = '';
                                        }}
                                        className={`relative cursor-pointer rounded-lg overflow-hidden border-2 transition-all aspect-square ${data.featured_image_id === m.id ? 'border-blue-500 ring-2 ring-blue-200' : 'border-transparent hover:border-gray-300'}`}
                                    >
                                        <img src={resolveStorageUrl(m)} className="w-full h-full object-cover" loading="lazy" alt="Media" />
                                        {data.featured_image_id === m.id && (
                                            <div className="absolute inset-0 bg-blue-500 bg-opacity-20 flex items-center justify-center">
                                                <div className="bg-blue-500 text-white rounded-full p-1">
                                                    <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="3" d="M5 13l4 4L19 7"></path></svg>
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                ))}
                            </div>
                            {filteredImages.length === 0 && (
                                <div className="h-full flex items-center justify-center text-gray-400 text-sm">ไม่มีรูปภาพในหมวดหมู่นี้</div>
                            )}
                        </div>
                    </div>
                </div>

                {hasPublishedFacebookTarget && imageChanged && (
                    <div className="mt-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                        <strong>รูป Facebook เปลี่ยนย้อนหลังไม่ได้</strong> — ให้สร้างร่างโพสต์ Facebook ใหม่พร้อมรูปที่แก้ไข แล้วเผยแพร่เมื่อพร้อม โพสต์เดิมและ WordPress จะยังอยู่
                    </div>
                )}

                {/* Selected Preview */}
                {(previewUrl || data.featured_image_id) && (
                    <div className="mt-6">
                        <label className="block text-sm font-medium text-gray-700 mb-2">รูปภาพที่เลือก</label>
                        <div className="relative inline-block border rounded-lg overflow-hidden bg-gray-100 max-w-sm">
                            <button 
                                type="button"
                                onClick={() => {
                                    setData(d => ({
                                        ...d,
                                        file: null,
                                        featured_image_id: null,
                                        image_editor_data: null,
                                    }));
                                    setPreviewUrl(null);
                                    if (fileInputRef.current) fileInputRef.current.value = '';
                                }}
                                className="absolute top-2 right-2 bg-red-600 text-white rounded-full p-1.5 shadow hover:bg-red-700 transition-colors z-10"
                                title="ลบรูปภาพ"
                            >
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                            </button>
                            {selectedImageUrl && (
                                <img src={selectedImageUrl} className="w-full h-auto object-contain max-h-64" alt="Preview" />
                            )}
                        </div>
                    </div>
                )}
            </div>

            {/* Publishing Section */}
            <div className="bg-white shadow-sm sm:rounded-lg p-6 sticky bottom-4 z-20 border border-gray-200">
                <div className="flex flex-col md:flex-row justify-between items-start md:items-center gap-6">
                    <div className="flex-1 w-full flex flex-col sm:flex-row sm:items-center gap-6">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-2">ช่องทางเผยแพร่</label>
                            <div className="flex gap-4 bg-gray-50 px-3 py-2 rounded-md border border-gray-200">
                                <label className="inline-flex items-center cursor-pointer">
                                    <input type="checkbox" value="wordpress" checked={isWpSelected} disabled={Boolean(initialData?.is_facebook_replacement)} onChange={handleChannelChange} className="rounded border-gray-300 text-[#000080] shadow-sm focus:ring-[#000080] w-4 h-4" />
                                    <WordPressIcon className="h-4 w-4 ml-2 mr-1 text-[#21759B]" />
                                    <span className="text-sm font-medium text-slate-800">WordPress</span>
                                </label>
                                <label className="inline-flex items-center cursor-pointer">
                                    <input type="checkbox" value="facebook" checked={isFbSelected} disabled={Boolean(initialData?.is_facebook_replacement)} onChange={handleChannelChange} className="rounded border-gray-300 text-[#000080] shadow-sm focus:ring-[#000080] w-4 h-4" />
                                    <FacebookIcon className="h-4 w-4 ml-2 mr-1 text-[#1877F2]" />
                                    <span className="text-sm font-medium text-slate-800">Facebook</span>
                                </label>
                            </div>
                            {initialData?.is_facebook_replacement && <p className="mt-1 text-xs text-blue-700">โพสต์นี้เผยแพร่เฉพาะ Facebook เพื่อไม่สร้างข่าว WordPress ซ้ำ</p>}
                            {displayErrors.channels && <p className="text-red-500 text-xs mt-1">{displayErrors.channels}</p>}
                        </div>

                        {data.publish_action === 'schedule' && (
                            <div className="flex-1 bg-blue-50 px-4 py-3 rounded-md border border-blue-100 shadow-inner">
                                <label className="block text-sm font-semibold text-blue-800 mb-2">ตั้งเวลาเผยแพร่ (24 ชม.)</label>
                                <Suspense fallback={<div className="h-10 animate-pulse rounded-md bg-blue-100" />}>
                                    <DateTimePicker
                                        value={data.scheduled_at}
                                        onChange={val => setData('scheduled_at', val)}
                                    />
                                </Suspense>
                                {displayErrors.scheduled_at && <p className="text-red-500 text-xs mt-1">{displayErrors.scheduled_at}</p>}
                            </div>
                        )}
                    </div>

                    <div className="w-full md:w-auto">
                        {mode === 'edit' && isFbSelected && ['published', 'partially_published'].includes(initialData?.status) && (
                            <p className="mb-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-right text-xs text-amber-800">
                                Facebook: รูปภาพและคอมเมนต์เดิมแก้ไขย้อนหลังไม่ได้ หากเปลี่ยนรูปให้สร้างโพสต์ใหม่โดยเก็บโพสต์เดิมไว้
                            </p>
                        )}
                        <p className="mb-2 text-right text-xs text-slate-500">
                            แนะนำให้ดูตัวอย่างก่อนเผยแพร่ • การดูตัวอย่างไม่ส่งโพสต์จริง
                        </p>
                        <div className="flex flex-wrap items-center justify-end gap-3">
                        <button
                            type="button"
                            onClick={() => setIsPreviewOpen(true)}
                            className="border border-blue-200 bg-blue-50 px-4 py-2 text-sm font-semibold text-blue-800 shadow-sm transition-colors hover:bg-blue-100 disabled:cursor-not-allowed disabled:opacity-50"
                            disabled={processing || submitModalOpen || data.channels.length === 0}
                        >
                            ดูตัวอย่าง
                        </button>

                        <button
                            type="button"
                            onClick={confirmDraft}
                            className="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-md shadow-sm hover:bg-gray-50 font-medium text-sm transition-colors"
                            disabled={processing || submitModalOpen}
                        >
                            บันทึกแค่ในระบบ (Draft)
                        </button>
                        
                        <button
                            type="button"
                            onClick={() => {
                                if (data.publish_action !== 'schedule') {
                                    setData('publish_action', 'schedule');
                                } else if (data.scheduled_at) {
                                    submitAction('schedule');
                                }
                            }}
                            className={`${data.publish_action === 'schedule' && data.scheduled_at ? 'bg-blue-600 text-white hover:bg-blue-700' : 'bg-white border border-gray-300 text-gray-700 hover:bg-gray-50'} px-4 py-2 rounded-md shadow-sm font-medium text-sm transition-colors`}
                            disabled={processing || submitModalOpen}
                        >
                            {data.publish_action === 'schedule' && data.scheduled_at ? 'ยืนยันตั้งเวลา' : 'ตั้งเวลาเผยแพร่'}
                        </button>

                        <button
                            type="button"
                            onClick={confirmPublish}
                            className="bg-[#000080] text-white px-6 py-2 rounded-md shadow-sm hover:bg-blue-900 font-bold text-sm transition-colors"
                            disabled={processing || submitModalOpen || data.channels.length === 0}
                        >
                            {submitModalOpen ? 'กำลังส่งข้อมูล...' : processing ? 'กำลังดำเนินการ...' : (hasPublishedFacebookTarget && imageChanged ? 'สร้างร่างโพสต์ Facebook ใหม่' : (mode === 'edit' && (initialData?.status === 'published' || initialData?.status === 'partially_published') ? 'อัปเดตระบบปลายทาง' : 'เผยแพร่ทันที'))}
                        </button>
                        </div>
                    </div>
                </div>
            </div>

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
                        channels={data.channels}
                        title={data.title}
                        wpContent={data.wp_content}
                        fbCaption={data.fb_caption}
                        fbComment={data.fb_comment}
                        fbComment2={data.fb_comment_2}
                        actionButtons={data.action_buttons}
                        pdfUrl={selectedPdfUrl}
                        pdfName={selectedPdfName}
                        imageUrl={selectedImageUrl}
                        categoryName={selectedCategory?.name || ''}
                    />
                </Suspense>
            )}

            <PostSubmitProgressModal
                isOpen={submitModalOpen}
                status={submitStatus}
                progress={submitProgress}
                channels={submitActionType === 'replace_facebook' ? ['facebook'] : data.channels}
                targets={liveTargets}
                actionType={submitActionType === 'replace_facebook' ? 'draft' : submitActionType}
                errorMessage={submitErrorMessage}
                onClose={() => {
                    if (pollIntervalRef.current) {
                        window.clearInterval(pollIntervalRef.current);
                        pollIntervalRef.current = null;
                    }
                    setSubmitModalOpen(false);
                }}
                onNavigateNow={() => {
                    if (pollIntervalRef.current) {
                        window.clearInterval(pollIntervalRef.current);
                        pollIntervalRef.current = null;
                    }
                    router.visit(redirectUrl || route('posts.index'));
                }}
            />
        </form>
    );
}
