import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    ArrowUp,
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    Copy,
    CornerDownRight,
    Download,
    Edit2,
    Eye,
    File,
    FileText,
    Filter,
    Folder,
    FolderInput,
    FolderPlus,
    HardDrive,
    Home,
    Image as ImageIcon,
    LayoutGrid,
    List,
    MoreHorizontal,
    MoreVertical,
    PanelLeftClose,
    PanelLeftOpen,
    Plus,
    RefreshCw,
    Search,
    SlidersHorizontal,
    Tag,
    Trash2,
    UploadCloud,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import Swal from 'sweetalert2';
import { resolveStorageUrl } from '@/Utils/media';

const ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

const FOLDER_COLORS = [
    { label: 'เหลือง (มาตรฐาน)', hex: '#EAB308' },
    { label: 'น้ำเงิน', hex: '#2563EB' },
    { label: 'เขียว', hex: '#16A34A' },
    { label: 'แดง', hex: '#DC2626' },
    { label: 'ม่วง', hex: '#9333EA' },
    { label: 'ฟ้า', hex: '#0284C7' },
    { label: 'เทาเข้ม', hex: '#475569' },
];

function formatBytes(bytes) {
    if (!bytes || bytes === 0) return '0 KB';
    const units = ['B', 'KB', 'MB', 'GB'];
    const index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
    const val = (bytes / (1024 ** index));
    return `${val < 10 && index > 0 ? val.toFixed(1) : Math.round(val)} ${units[index]}`;
}

function formatDate(value) {
    if (!value) return '-';
    const date = new Date(value);
    if (isNaN(date.getTime())) return '-';
    
    // Windows Explorer date format: D/M/YYYY H:mm (BE year in Thai)
    const day = date.getDate();
    const month = date.getMonth() + 1;
    const year = date.getFullYear() + 543;
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');

    return `${day}/${month}/${year} ${hours}:${minutes}`;
}

function getFileTypeLabel(mimeType, fileName = '') {
    if (mimeType === 'application/pdf' || fileName.toLowerCase().endsWith('.pdf')) {
        return 'เอกสาร PDF';
    }
    if (mimeType === 'image/png' || fileName.toLowerCase().endsWith('.png')) {
        return 'รูปภาพ PNG';
    }
    if (mimeType === 'image/jpeg' || fileName.toLowerCase().endsWith('.jpg') || fileName.toLowerCase().endsWith('.jpeg')) {
        return 'รูปภาพ JPEG';
    }
    if (mimeType === 'image/webp' || fileName.toLowerCase().endsWith('.webp')) {
        return 'รูปภาพ WEBP';
    }
    if (mimeType?.startsWith('image/')) {
        return 'รูปภาพ';
    }
    return 'ไฟล์';
}

function isImage(item) {
    return item.mime_type?.startsWith('image/');
}

// Tree builder for left navigation pane
function buildFolderHierarchy(foldersList) {
    const map = {};
    const roots = [];

    foldersList.forEach((folder) => {
        map[folder.id] = { ...folder, children: [] };
    });

    foldersList.forEach((folder) => {
        if (folder.parent_id && map[folder.parent_id]) {
            map[folder.parent_id].children.push(map[folder.id]);
        } else {
            roots.push(map[folder.id]);
        }
    });

    return roots;
}

export default function Index({
    media,
    subfolders = [],
    allFolders = [],
    categories = [],
    breadcrumbs = [],
    currentFolder = null,
    filters = {},
    stats = {},
}) {
    const fileInput = useRef(null);
    const [search, setSearch] = useState(filters?.q || '');
    const [viewMode, setViewMode] = useState('list'); // 'list' (Windows Details table default) | 'grid'
    const [selectedItemId, setSelectedItemId] = useState(null);
    const [previewItem, setPreviewItem] = useState(null);
    const [isSidebarOpen, setIsSidebarOpen] = useState(true);

    // Sorting state for Windows Explorer table
    const [sortColumn, setSortColumn] = useState('date'); // 'name', 'category', 'date', 'type', 'size'
    const [sortDirection, setSortDirection] = useState('desc'); // 'asc' | 'desc'

    // Drag-over folder state for HTML5 Drag & Drop
    const [dragOverFolderId, setDragOverFolderId] = useState(null);

    // Expanded nodes in Left Tree
    const [expandedFolderIds, setExpandedFolderIds] = useState(() => {
        const initial = new Set();
        // Expand ancestors of currentFolder
        if (breadcrumbs && breadcrumbs.length > 0) {
            breadcrumbs.forEach((b) => {
                if (typeof b.id === 'number') initial.add(b.id);
            });
        }
        return initial;
    });

    // Modals
    const [isFolderModalOpen, setIsFolderModalOpen] = useState(false);
    const [editingFolder, setEditingFolder] = useState(null);
    const [folderForm, setFolderForm] = useState({
        name: '',
        color: '#EAB308',
        parent_id: '',
    });

    const [movingItem, setMovingItem] = useState(null);
    const [moveForm, setMoveForm] = useState({
        folder_id: '',
        category: '',
    });

    const [editingMediaItem, setEditingMediaItem] = useState(null);
    const [mediaEditForm, setMediaEditForm] = useState({
        original_name: '',
        category: '',
        customCategory: '',
    });

    // Context menu for folders
    const [activeFolderMenuId, setActiveFolderMenuId] = useState(null);

    const activeFolderParam = filters?.folder_id || (currentFolder ? String(currentFolder.id) : 'root');
    const activeCategoryParam = filters?.category || 'all';
    const currentType = filters?.type || 'all';

    // File upload form
    const {
        data: uploadData,
        setData: setUploadData,
        post: postUpload,
        processing: uploadProcessing,
        progress: uploadProgress,
        errors: uploadErrors,
        reset: resetUpload,
        clearErrors: clearUploadErrors,
    } = useForm({
        file: null,
        folder_id: currentFolder?.id ? String(currentFolder.id) : '',
        category: '',
        customCategory: '',
    });

    useEffect(() => {
        setUploadData((prev) => ({
            ...prev,
            folder_id: currentFolder?.id ? String(currentFolder.id) : '',
        }));
        setSelectedItemId(null);
    }, [currentFolder]);

    useEffect(() => {
        if (currentFolder?.id) {
            setExpandedFolderIds((prev) => new Set([...prev, currentFolder.id, currentFolder.parent_id].filter(Boolean)));
        }
    }, [currentFolder]);

    // Close folder context menu on global click
    useEffect(() => {
        const handleClickOutside = () => setActiveFolderMenuId(null);
        window.addEventListener('click', handleClickOutside);
        return () => window.removeEventListener('click', handleClickOutside);
    }, []);

    // Build Folder Tree Hierarchy
    const folderTree = useMemo(() => buildFolderHierarchy(allFolders), [allFolders]);

    const toggleFolderExpand = (folderId, e) => {
        e.stopPropagation();
        setExpandedFolderIds((prev) => {
            const next = new Set(prev);
            if (next.has(folderId)) {
                next.delete(folderId);
            } else {
                next.add(folderId);
            }
            return next;
        });
    };

    // Navigation helpers
    const navigateToFolder = (folderParam) => {
        router.get(
            route('media.index'),
            {
                folder_id: folderParam,
                category: filters?.category === 'all' ? undefined : filters?.category,
                type: filters?.type === 'all' ? undefined : filters?.type,
            },
            { preserveState: true, preserveScroll: true },
        );
    };

    const navigateUp = () => {
        if (currentFolder?.parent_id) {
            navigateToFolder(currentFolder.parent_id);
        } else if (currentFolder) {
            navigateToFolder('root');
        } else if (activeFolderParam === 'all') {
            navigateToFolder('root');
        }
    };

    const canNavigateUp = Boolean(currentFolder || activeFolderParam === 'all');

    const refreshCurrentView = () => {
        router.reload({ preserveScroll: true });
    };

    const applyFilters = (overrides = {}) => {
        const nextType = overrides.type !== undefined ? overrides.type : (filters?.type || 'all');
        const nextCategory = overrides.category !== undefined ? overrides.category : (filters?.category || 'all');
        const nextFolder = overrides.folder_id !== undefined ? overrides.folder_id : filters?.folder_id;

        router.get(
            route('media.index'),
            {
                q: search || undefined,
                type: nextType === 'all' ? undefined : nextType,
                folder_id: nextFolder || undefined,
                category: nextCategory === 'all' ? undefined : nextCategory,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const handleSearch = (event) => {
        event.preventDefault();
        applyFilters();
    };

    const clearSearch = () => {
        setSearch('');
        router.get(
            route('media.index'),
            {
                folder_id: filters?.folder_id,
                category: filters?.category === 'all' ? undefined : filters?.category,
                type: filters?.type === 'all' ? undefined : filters?.type,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    // Upload handling
    const selectFile = (file) => {
        clearUploadErrors();
        if (!file) return;

        if (!ACCEPTED_TYPES.includes(file.type)) {
            Swal.fire({
                icon: 'error',
                title: 'ไฟล์ไม่รองรับ',
                text: 'รองรับเฉพาะ JPG, PNG, WEBP และ PDF',
                confirmButtonColor: '#000080',
            });
            return;
        }

        if (file.size > 10 * 1024 * 1024) {
            Swal.fire({
                icon: 'error',
                title: 'ไฟล์มีขนาดใหญ่เกินไป',
                text: 'ขนาดไฟล์สูงสุดคือ 10 MB',
                confirmButtonColor: '#000080',
            });
            return;
        }

        setUploadData((prev) => ({ ...prev, file }));
    };

    const clearSelectedFile = () => {
        resetUpload('file', 'category', 'customCategory');
        if (fileInput.current) fileInput.current.value = '';
    };

    const submitUpload = (event) => {
        event.preventDefault();
        if (!uploadData.file) return;

        const effectiveCategory = uploadData.category === '__custom__'
            ? uploadData.customCategory.trim()
            : uploadData.category;

        router.post(route('media.store'), {
            file: uploadData.file,
            folder_id: uploadData.folder_id || null,
            category: effectiveCategory || null,
        }, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                clearSelectedFile();
                Swal.fire({
                    icon: 'success',
                    title: 'อัปโหลดเรียบร้อยแล้ว',
                    toast: true,
                    position: 'top-end',
                    timer: 2000,
                    showConfirmButton: false,
                });
            },
        });
    };

    // Folder Modals
    const openCreateFolder = (presetParentId = null) => {
        setEditingFolder(null);
        setFolderForm({
            name: '',
            color: '#EAB308',
            parent_id: presetParentId !== null
                ? String(presetParentId)
                : (currentFolder?.id ? String(currentFolder.id) : ''),
        });
        setIsFolderModalOpen(true);
    };

    const openEditFolder = (folder) => {
        setEditingFolder(folder);
        setFolderForm({
            name: folder.name,
            color: folder.color || '#EAB308',
            parent_id: folder.parent_id ? String(folder.parent_id) : '',
        });
        setActiveFolderMenuId(null);
        setIsFolderModalOpen(true);
    };

    const handleFolderSubmit = (e) => {
        e.preventDefault();
        if (!folderForm.name.trim()) return;

        const payload = {
            name: folderForm.name.trim(),
            color: folderForm.color,
            parent_id: folderForm.parent_id ? Number(folderForm.parent_id) : null,
        };

        if (editingFolder) {
            router.put(route('media.folders.update', editingFolder.id), payload, {
                preserveScroll: true,
                onSuccess: () => {
                    setIsFolderModalOpen(false);
                    Swal.fire({
                        icon: 'success',
                        title: 'แก้ไขโฟลเดอร์เรียบร้อย',
                        toast: true,
                        position: 'top-end',
                        timer: 2000,
                        showConfirmButton: false,
                    });
                },
            });
        } else {
            router.post(route('media.folders.store'), payload, {
                preserveScroll: true,
                onSuccess: () => {
                    setIsFolderModalOpen(false);
                    Swal.fire({
                        icon: 'success',
                        title: 'สร้างโฟลเดอร์เรียบร้อย',
                        toast: true,
                        position: 'top-end',
                        timer: 2000,
                        showConfirmButton: false,
                    });
                },
            });
        }
    };

    const handleDeleteFolder = async (folder) => {
        setActiveFolderMenuId(null);
        const result = await Swal.fire({
            icon: 'warning',
            title: `ลบโฟลเดอร์ "${folder.name}"?`,
            text: 'ไฟล์และโฟลเดอร์ย่อยข้างในจะถูกย้ายขึ้นมาที่โฟลเดอร์แม่โดยอัตโนมัติ (ไฟล์จะไม่สูญหาย)',
            showCancelButton: true,
            confirmButtonText: 'ยืนยันการลบ',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#dc2626',
        });

        if (result.isConfirmed) {
            router.delete(route('media.folders.destroy', folder.id), {
                preserveScroll: true,
            });
        }
    };

    // Move File
    const openMoveModal = (item) => {
        setMovingItem(item);
        setMoveForm({
            folder_id: item.folder_id ? String(item.folder_id) : '',
            category: item.category || '',
        });
    };

    const handleConfirmMove = () => {
        if (!movingItem) return;

        router.post(route('media.move'), {
            media_ids: [movingItem.id],
            folder_id: moveForm.folder_id ? Number(moveForm.folder_id) : null,
            category: moveForm.category || null,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setMovingItem(null);
                Swal.fire({
                    icon: 'success',
                    title: 'ย้ายตำแหน่งไฟล์เรียบร้อยแล้ว',
                    toast: true,
                    position: 'top-end',
                    timer: 2000,
                    showConfirmButton: false,
                });
            },
        });
    };

    // Edit Media Item Metadata
    const openEditMediaModal = (item) => {
        setEditingMediaItem(item);
        const isStandard = categories.includes(item.category);
        setMediaEditForm({
            original_name: item.original_name,
            category: item.category ? (isStandard ? item.category : '__custom__') : '',
            customCategory: item.category && !isStandard ? item.category : '',
        });
    };

    const handleSaveMediaEdit = (e) => {
        e.preventDefault();
        if (!editingMediaItem) return;

        const effectiveCategory = mediaEditForm.category === '__custom__'
            ? mediaEditForm.customCategory.trim()
            : mediaEditForm.category;

        router.put(route('media.update', editingMediaItem.id), {
            original_name: mediaEditForm.original_name.trim(),
            category: effectiveCategory || null,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setEditingMediaItem(null);
                Swal.fire({
                    icon: 'success',
                    title: 'บันทึกข้อมูลไฟล์เรียบร้อย',
                    toast: true,
                    position: 'top-end',
                    timer: 2000,
                    showConfirmButton: false,
                });
            },
        });
    };

    // Drag and Drop to Move File onto Folder
    const handleDragStartFile = (e, fileItem) => {
        e.dataTransfer.setData('text/plain', JSON.stringify({ fileId: fileItem.id, fileName: fileItem.original_name }));
    };

    const handleDropOnFolder = (e, targetFolderId) => {
        e.preventDefault();
        setDragOverFolderId(null);
        const rawData = e.dataTransfer.getData('text/plain');
        if (!rawData) return;

        try {
            const parsed = JSON.parse(rawData);
            if (!parsed.fileId) return;

            router.post(route('media.move'), {
                media_ids: [parsed.fileId],
                folder_id: targetFolderId ? Number(targetFolderId) : null,
            }, {
                preserveScroll: true,
                onSuccess: () => {
                    Swal.fire({
                        icon: 'success',
                        title: 'ย้ายไฟล์เข้าโฟลเดอร์สำเร็จ',
                        text: `ย้าย "${parsed.fileName}" เรียบร้อยแล้ว`,
                        toast: true,
                        position: 'top-end',
                        timer: 2000,
                        showConfirmButton: false,
                    });
                },
            });
        } catch {
            // Ignore non-json drag
        }
    };

    const handleDeleteFile = async (item) => {
        if (item.used_by_posts_count > 0 || item.used_by_templates_count > 0) {
            Swal.fire({
                icon: 'error',
                title: 'ไม่สามารถลบไฟล์นี้ได้',
                text: 'ไฟล์นี้กำลังถูกใช้งานอยู่ในข่าวหรือเทมเพลต กรุณาเปลี่ยนรูปก่อนลบ',
                confirmButtonColor: '#000080',
            });
            return;
        }

        const result = await Swal.fire({
            icon: 'warning',
            title: 'ลบไฟล์นี้หรือไม่?',
            text: item.original_name,
            showCancelButton: true,
            confirmButtonText: 'ลบไฟล์',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#dc2626',
        });

        if (result.isConfirmed) {
            router.delete(route('media.destroy', item.id), {
                preserveScroll: true,
            });
        }
    };

    const copyUrl = async (item) => {
        try {
            await navigator.clipboard.writeText(new URL(item.url, window.location.origin).href);
            Swal.fire({
                icon: 'success',
                title: 'คัดลอกลิงก์แล้ว',
                toast: true,
                position: 'top-end',
                timer: 1800,
                showConfirmButton: false,
            });
        } catch {
            Swal.fire({
                icon: 'error',
                title: 'คัดลอกลิงก์ไม่สำเร็จ',
                confirmButtonColor: '#000080',
            });
        }
    };

    // Sort Handler
    const handleSort = (column) => {
        if (sortColumn === column) {
            setSortDirection((prev) => (prev === 'asc' ? 'desc' : 'asc'));
        } else {
            setSortColumn(column);
            setSortDirection('asc');
        }
    };

    // Sorted Media List
    const sortedMediaList = useMemo(() => {
        const list = [...(media.data || [])];
        list.sort((a, b) => {
            let valA, valB;
            switch (sortColumn) {
                case 'name':
                    valA = (a.original_name || '').toLowerCase();
                    valB = (b.original_name || '').toLowerCase();
                    break;
                case 'category':
                    valA = (a.category || '').toLowerCase();
                    valB = (b.category || '').toLowerCase();
                    break;
                case 'type':
                    valA = getFileTypeLabel(a.mime_type, a.original_name).toLowerCase();
                    valB = getFileTypeLabel(b.mime_type, b.original_name).toLowerCase();
                    break;
                case 'size':
                    valA = a.file_size || 0;
                    valB = b.file_size || 0;
                    break;
                case 'date':
                default:
                    valA = new Date(a.created_at || 0).getTime();
                    valB = new Date(b.created_at || 0).getTime();
                    break;
            }

            if (valA < valB) return sortDirection === 'asc' ? -1 : 1;
            if (valA > valB) return sortDirection === 'asc' ? 1 : -1;
            return 0;
        });
        return list;
    }, [media.data, sortColumn, sortDirection]);

    const selectedItem = useMemo(() => {
        return (media.data || []).find((item) => item.id === selectedItemId) || null;
    }, [media.data, selectedItemId]);

    const isViewingAll = activeFolderParam === 'all';
    const isViewingRoot = activeFolderParam === 'root' && !currentFolder;

    // Render tree node component
    const renderFolderTreeNode = (node, depth = 0) => {
        const isCurrent = currentFolder && currentFolder.id === node.id;
        const isExpanded = expandedFolderIds.has(node.id);
        const hasChildren = node.children && node.children.length > 0;
        const isDragTarget = dragOverFolderId === node.id;

        return (
            <div key={node.id} className="select-none">
                <div
                    onDragOver={(e) => {
                        e.preventDefault();
                        setDragOverFolderId(node.id);
                    }}
                    onDragLeave={() => setDragOverFolderId(null)}
                    onDrop={(e) => handleDropOnFolder(e, node.id)}
                    onClick={() => navigateToFolder(node.id)}
                    className={`group relative flex items-center justify-between py-1.5 px-2 rounded-md cursor-pointer transition text-xs font-medium ${
                        isCurrent
                            ? 'bg-blue-100/90 text-blue-900 font-semibold shadow-xs'
                            : isDragTarget
                            ? 'bg-blue-200 text-blue-900 ring-2 ring-blue-500'
                            : 'text-slate-700 hover:bg-slate-200/60'
                    }`}
                    style={{ paddingLeft: `${depth * 14 + 8}px` }}
                >
                    <div className="flex items-center gap-1.5 min-w-0 flex-1">
                        {/* Expand / Collapse Caret */}
                        {hasChildren ? (
                            <button
                                type="button"
                                onClick={(e) => toggleFolderExpand(node.id, e)}
                                className="p-0.5 rounded hover:bg-black/10 text-slate-500"
                            >
                                {isExpanded ? (
                                    <ChevronDown className="h-3.5 w-3.5" />
                                ) : (
                                    <ChevronRight className="h-3.5 w-3.5" />
                                )}
                            </button>
                        ) : (
                            <span className="w-4.5 inline-block" />
                        )}

                        {/* Folder Icon */}
                        <Folder
                            className="h-4 w-4 shrink-0 fill-current"
                            style={{ color: node.color || '#EAB308' }}
                        />

                        {/* Folder Name */}
                        <span className="truncate" title={node.name}>
                            {node.name}
                        </span>
                    </div>

                    <div className="flex items-center gap-1 shrink-0">
                        {/* Media count */}
                        <span className="text-[10px] text-slate-400 group-hover:text-slate-600 px-1">
                            {node.media_count ?? 0}
                        </span>

                        {/* Folder action kebab */}
                        <div
                            className="relative"
                            onClick={(e) => e.stopPropagation()}
                        >
                            <button
                                type="button"
                                onClick={() => setActiveFolderMenuId(activeFolderMenuId === node.id ? null : node.id)}
                                className="p-0.5 rounded text-slate-400 opacity-0 group-hover:opacity-100 hover:bg-black/10 hover:text-slate-700 transition"
                            >
                                <MoreHorizontal className="h-3.5 w-3.5" />
                            </button>

                            {activeFolderMenuId === node.id && (
                                <div className="absolute right-0 top-full z-30 mt-1 w-36 rounded-lg border border-slate-200 bg-white py-1 shadow-lg animate-in fade-in">
                                    <button
                                        type="button"
                                        onClick={() => openCreateFolder(node.id)}
                                        className="flex w-full items-center gap-2 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50"
                                    >
                                        <FolderPlus className="h-3.5 w-3.5 text-blue-600" />
                                        <span>สร้างโฟลเดอร์ย่อย</span>
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => openEditFolder(node)}
                                        className="flex w-full items-center gap-2 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50"
                                    >
                                        <Edit2 className="h-3.5 w-3.5 text-slate-500" />
                                        <span>เปลี่ยนชื่อ / สี</span>
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => handleDeleteFolder(node)}
                                        className="flex w-full items-center gap-2 px-3 py-1.5 text-xs text-rose-600 hover:bg-rose-50"
                                    >
                                        <Trash2 className="h-3.5 w-3.5 text-rose-500" />
                                        <span>ลบโฟลเดอร์</span>
                                    </button>
                                </div>
                            )}
                        </div>
                    </div>
                </div>

                {/* Subfolder children */}
                {hasChildren && isExpanded && (
                    <div className="space-y-0.5">
                        {node.children.map((child) => renderFolderTreeNode(child, depth + 1))}
                    </div>
                )}
            </div>
        );
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="flex items-center gap-2">
                            <h1 className="text-xl font-bold text-slate-900">คลังไฟล์และโฟลเดอร์</h1>
                            <span className="rounded-md bg-blue-50 px-2 py-0.5 text-xs font-semibold text-blue-800 border border-blue-200">
                                Windows File Explorer Mode
                            </span>
                        </div>
                        <p className="mt-0.5 text-xs text-slate-500">
                            จัดการโครงสร้างโฟลเดอร์ซ้อนกัน จัดหมวดหมู่ไฟล์ และค้นหาได้สะดวกรวดเร็ว
                        </p>
                    </div>

                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            onClick={() => openCreateFolder(currentFolder?.id || null)}
                            className="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 hover:border-slate-300"
                        >
                            <FolderPlus className="h-4 w-4 text-amber-500" />
                            <span>{currentFolder ? 'สร้างโฟลเดอร์ย่อย' : 'สร้างโฟลเดอร์ใหม่'}</span>
                        </button>
                        <button
                            type="button"
                            onClick={() => fileInput.current?.click()}
                            className="inline-flex items-center gap-1.5 rounded-xl bg-[#000080] px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-blue-900"
                        >
                            <UploadCloud className="h-4 w-4" />
                            <span>อัปโหลดไฟล์</span>
                        </button>
                    </div>
                </div>
            }
        >
            <Head title="คลังไฟล์และโฟลเดอร์ (File Explorer)" />

            <div className="py-5">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-3">

                    {/* =========================================================
                        WINDOWS EXPLORER WINDOW FRAME
                        ========================================================= */}
                    <div className="overflow-hidden rounded-xl border border-slate-300/80 bg-white shadow-sm">
                        
                        {/* 1. TOP COMMAND BAR / RIBBON (WINDOWS 11 STYLE) */}
                        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 bg-slate-50/90 px-3 py-2 text-xs">
                            {/* Left Command Actions */}
                            <div className="flex flex-wrap items-center gap-1">
                                <button
                                    type="button"
                                    onClick={() => openCreateFolder(currentFolder?.id || null)}
                                    className="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 font-medium text-slate-700 hover:bg-slate-200/80 hover:text-slate-900 transition"
                                    title="สร้างโฟลเดอร์ใหม่ในโฟลเดอร์ปัจจุบัน"
                                >
                                    <FolderPlus className="h-4 w-4 text-amber-500" />
                                    <span>สร้างโฟลเดอร์ใหม่</span>
                                </button>

                                <button
                                    type="button"
                                    onClick={() => fileInput.current?.click()}
                                    className="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 font-medium text-slate-700 hover:bg-slate-200/80 hover:text-slate-900 transition"
                                    title="อัปโหลดไฟล์ใหม่"
                                >
                                    <UploadCloud className="h-4 w-4 text-blue-600" />
                                    <span>อัปโหลด</span>
                                </button>

                                <div className="h-4 w-px bg-slate-300 mx-1" />

                                {/* Contextual Actions (Enable when an item is selected) */}
                                <button
                                    type="button"
                                    onClick={() => selectedItem && openMoveModal(selectedItem)}
                                    disabled={!selectedItem}
                                    className="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 font-medium transition disabled:opacity-40 disabled:hover:bg-transparent text-slate-700 hover:bg-slate-200/80"
                                    title="ย้ายไฟล์ไปยังโฟลเดอร์อื่น"
                                >
                                    <FolderInput className="h-4 w-4 text-blue-600" />
                                    <span>ย้าย</span>
                                </button>

                                <button
                                    type="button"
                                    onClick={() => selectedItem && openEditMediaModal(selectedItem)}
                                    disabled={!selectedItem}
                                    className="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 font-medium transition disabled:opacity-40 disabled:hover:bg-transparent text-slate-700 hover:bg-slate-200/80"
                                    title="เปลี่ยนชื่อไฟล์หรือจัดหมวดหมู่"
                                >
                                    <Tag className="h-4 w-4 text-emerald-600" />
                                    <span>จัดหมวดหมู่</span>
                                </button>

                                <button
                                    type="button"
                                    onClick={() => selectedItem && copyUrl(selectedItem)}
                                    disabled={!selectedItem}
                                    className="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 font-medium transition disabled:opacity-40 disabled:hover:bg-transparent text-slate-700 hover:bg-slate-200/80"
                                    title="คัดลอกลิงก์ไฟล์"
                                >
                                    <Copy className="h-4 w-4 text-slate-600" />
                                    <span>คัดลอกลิงก์</span>
                                </button>

                                <button
                                    type="button"
                                    onClick={() => selectedItem && setPreviewItem(selectedItem)}
                                    disabled={!selectedItem}
                                    className="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 font-medium transition disabled:opacity-40 disabled:hover:bg-transparent text-slate-700 hover:bg-slate-200/80"
                                    title="ดูตัวอย่างภาพหรือเปิดอ่านเอกสาร"
                                >
                                    <Eye className="h-4 w-4 text-slate-600" />
                                    <span>ดูตัวอย่าง</span>
                                </button>

                                <button
                                    type="button"
                                    onClick={() => selectedItem && handleDeleteFile(selectedItem)}
                                    disabled={!selectedItem || selectedItem.used_by_posts_count > 0 || selectedItem.used_by_templates_count > 0}
                                    className="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 font-medium transition disabled:opacity-40 disabled:hover:bg-transparent text-rose-600 hover:bg-rose-100"
                                    title="ลบไฟล์"
                                >
                                    <Trash2 className="h-4 w-4" />
                                    <span>ลบ</span>
                                </button>
                            </div>

                            {/* Right View Switcher & Sidebar Toggle */}
                            <div className="flex items-center gap-2">
                                {/* Type Filters */}
                                <div className="flex items-center rounded-lg border border-slate-300 bg-white p-0.5 text-[11px]">
                                    {[
                                        ['all', 'ทั้งหมด'],
                                        ['image', 'รูปภาพ'],
                                        ['pdf', 'PDF'],
                                    ].map(([val, label]) => (
                                        <button
                                            key={val}
                                            type="button"
                                            onClick={() => applyFilters({ type: val })}
                                            className={`rounded px-2 py-0.5 font-medium transition ${
                                                currentType === val
                                                    ? 'bg-[#000080] text-white shadow-xs'
                                                    : 'text-slate-600 hover:bg-slate-100'
                                            }`}
                                        >
                                            {label}
                                        </button>
                                    ))}
                                </div>

                                <div className="h-4 w-px bg-slate-300" />

                                {/* View Mode Toggles */}
                                <div className="flex items-center rounded-lg border border-slate-300 bg-white p-0.5">
                                    <button
                                        type="button"
                                        onClick={() => setViewMode('list')}
                                        className={`rounded p-1 transition ${
                                            viewMode === 'list'
                                                ? 'bg-slate-200 text-slate-900 shadow-xs'
                                                : 'text-slate-500 hover:text-slate-800'
                                        }`}
                                        title="รายละเอียด (Details View)"
                                    >
                                        <List className="h-3.5 w-3.5" />
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setViewMode('grid')}
                                        className={`rounded p-1 transition ${
                                            viewMode === 'grid'
                                                ? 'bg-slate-200 text-slate-900 shadow-xs'
                                                : 'text-slate-500 hover:text-slate-800'
                                        }`}
                                        title="ไอคอนขนาดใหญ่ (Large Icons / Grid)"
                                    >
                                        <LayoutGrid className="h-3.5 w-3.5" />
                                    </button>
                                </div>

                                {/* Sidebar toggle */}
                                <button
                                    type="button"
                                    onClick={() => setIsSidebarOpen(!isSidebarOpen)}
                                    className="rounded-lg border border-slate-300 bg-white p-1 text-slate-600 hover:bg-slate-100"
                                    title={isSidebarOpen ? 'ซ่อนแถบโฟลเดอร์ด้านข้าง' : 'แสดงแถบโฟลเดอร์ด้านข้าง'}
                                >
                                    {isSidebarOpen ? (
                                        <PanelLeftClose className="h-3.5 w-3.5" />
                                    ) : (
                                        <PanelLeftOpen className="h-3.5 w-3.5" />
                                    )}
                                </button>
                            </div>
                        </div>

                        {/* 2. WINDOWS NAVIGATION & ADDRESS BAR (แถบที่อยู่และค้นหาเหมือน Windows) */}
                        <div className="flex flex-col gap-2 border-b border-slate-200 bg-white p-2.5 sm:flex-row sm:items-center">
                            {/* Navigation Buttons: Back, Forward, Up */}
                            <div className="flex items-center gap-1 shrink-0">
                                <button
                                    type="button"
                                    onClick={() => window.history.back()}
                                    className="rounded p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-800 transition"
                                    title="ย้อนกลับ (Back)"
                                >
                                    <ArrowLeft className="h-4 w-4" />
                                </button>
                                <button
                                    type="button"
                                    onClick={() => window.history.forward()}
                                    className="rounded p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-800 transition"
                                    title="ไปข้างหน้า (Forward)"
                                >
                                    <ArrowRight className="h-4 w-4" />
                                </button>
                                <button
                                    type="button"
                                    onClick={navigateUp}
                                    disabled={!canNavigateUp}
                                    className="rounded p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-800 transition disabled:opacity-30 disabled:hover:bg-transparent"
                                    title="ขึ้นไปยังโฟลเดอร์ระดับบน (Up one level)"
                                >
                                    <ArrowUp className="h-4 w-4" />
                                </button>
                            </div>

                            {/* Windows Breadcrumbs Address Bar */}
                            <div className="flex flex-1 items-center justify-between overflow-x-auto rounded-md border border-slate-300 bg-white px-2 py-1 shadow-inner text-xs">
                                <div className="flex items-center gap-1 min-w-0">
                                    {breadcrumbs.map((crumb, idx) => {
                                        const isLast = idx === breadcrumbs.length - 1;

                                        return (
                                            <div key={crumb.id || idx} className="flex items-center gap-1 shrink-0">
                                                {idx > 0 && (
                                                    <ChevronRight className="h-3.5 w-3.5 text-slate-400 shrink-0" />
                                                )}

                                                <button
                                                    type="button"
                                                    onClick={() => navigateToFolder(crumb.id)}
                                                    className={`inline-flex items-center gap-1.5 rounded px-1.5 py-0.5 transition ${
                                                        isLast
                                                            ? 'font-bold text-slate-900 bg-slate-100'
                                                            : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'
                                                    }`}
                                                >
                                                    {crumb.id === 'all' ? (
                                                        <HardDrive className="h-3.5 w-3.5 text-blue-600 shrink-0" />
                                                    ) : crumb.id === 'root' ? (
                                                        <Home className="h-3.5 w-3.5 text-blue-600 shrink-0" />
                                                    ) : (
                                                        <Folder
                                                            className="h-3.5 w-3.5 fill-current shrink-0"
                                                            style={{ color: crumb.color || '#EAB308' }}
                                                        />
                                                    )}
                                                    <span className="truncate max-w-[160px]">{crumb.name}</span>
                                                </button>
                                            </div>
                                        );
                                    })}
                                </div>

                                <button
                                    type="button"
                                    onClick={refreshCurrentView}
                                    className="p-1 rounded text-slate-400 hover:text-slate-700 hover:bg-slate-100 ml-2 shrink-0"
                                    title="รีเฟรช (Refresh)"
                                >
                                    <RefreshCw className="h-3.5 w-3.5" />
                                </button>
                            </div>

                            {/* Search Box on the Right */}
                            <form onSubmit={handleSearch} className="relative w-full sm:w-64 shrink-0">
                                <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" />
                                <input
                                    type="text"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder={`ค้นหาใน ${currentFolder ? currentFolder.name : 'คลังไฟล์'}...`}
                                    className="w-full rounded-md border border-slate-300 bg-white py-1 pl-8 pr-7 text-xs focus:border-[#000080] focus:ring-1 focus:ring-[#000080]"
                                />
                                {search && (
                                    <button
                                        type="button"
                                        onClick={clearSearch}
                                        className="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
                                    >
                                        <X className="h-3 w-3" />
                                    </button>
                                )}
                            </form>
                        </div>

                        {/* 3. CATEGORY CHIP BAR (Modern Enhancement - จัดหมวดหมู่ได้ง่ายและชัดเจน) */}
                        <div className="flex items-center gap-1.5 overflow-x-auto border-b border-slate-100 bg-slate-50/50 px-3 py-1.5 text-xs">
                            <div className="flex items-center gap-1 text-slate-500 font-semibold shrink-0 pr-1 border-r border-slate-200">
                                <Tag className="h-3 w-3 text-blue-600" />
                                <span className="text-[11px]">หมวดหมู่:</span>
                            </div>

                            <button
                                type="button"
                                onClick={() => applyFilters({ category: 'all' })}
                                className={`shrink-0 rounded-md px-2 py-0.5 text-[11px] font-medium transition ${
                                    activeCategoryParam === 'all'
                                        ? 'bg-blue-600 text-white shadow-xs'
                                        : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-100'
                                }`}
                            >
                                ทุกหมวดหมู่
                            </button>

                            {categories.map((catName) => (
                                <button
                                    key={catName}
                                    type="button"
                                    onClick={() => applyFilters({ category: catName })}
                                    className={`shrink-0 rounded-md px-2 py-0.5 text-[11px] font-medium transition ${
                                        activeCategoryParam === catName
                                            ? 'bg-blue-600 text-white shadow-xs'
                                            : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-100'
                                    }`}
                                >
                                    {catName}
                                </button>
                            ))}
                        </div>

                        {/* Hidden Native File Input */}
                        <input
                            ref={fileInput}
                            type="file"
                            className="hidden"
                            accept="image/jpeg,image/png,image/webp,application/pdf"
                            onChange={(event) => selectFile(event.target.files?.[0])}
                        />

                        {/* Pending Upload Box */}
                        {uploadData.file && (
                            <div className="border-b border-blue-200 bg-blue-50/80 p-3 text-xs animate-in fade-in">
                                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div className="flex items-center gap-3">
                                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white shadow-xs">
                                            {uploadData.file.type.startsWith('image/') ? (
                                                <ImageIcon className="h-5 w-5 text-blue-600" />
                                            ) : (
                                                <FileText className="h-5 w-5 text-rose-500" />
                                            )}
                                        </div>
                                        <div className="space-y-1">
                                            <div className="flex items-center gap-2">
                                                <span className="font-bold text-slate-900">{uploadData.file.name}</span>
                                                <span className="text-[11px] text-slate-500">({formatBytes(uploadData.file.size)})</span>
                                            </div>

                                            <div className="flex flex-wrap items-center gap-3">
                                                {/* Target Folder */}
                                                <div className="flex items-center gap-1.5">
                                                    <span className="text-slate-600">ไปยังโฟลเดอร์:</span>
                                                    <select
                                                        value={uploadData.folder_id}
                                                        onChange={(e) => setUploadData('folder_id', e.target.value)}
                                                        className="rounded border-slate-300 py-0.5 pl-2 pr-6 text-xs bg-white focus:border-[#000080] focus:ring-[#000080]"
                                                    >
                                                        <option value="">คลังหลัก (นอกโฟลเดอร์)</option>
                                                        {allFolders.map((f) => (
                                                            <option key={f.id} value={f.id}>
                                                                {f.full_path}
                                                            </option>
                                                        ))}
                                                    </select>
                                                </div>

                                                {/* Target Category */}
                                                <div className="flex items-center gap-1.5">
                                                    <span className="text-slate-600">หมวดหมู่:</span>
                                                    <select
                                                        value={uploadData.category}
                                                        onChange={(e) => setUploadData('category', e.target.value)}
                                                        className="rounded border-slate-300 py-0.5 pl-2 pr-6 text-xs bg-white focus:border-[#000080] focus:ring-[#000080]"
                                                    >
                                                        <option value="">ไม่ระบุหมวดหมู่</option>
                                                        {categories.map((c) => (
                                                            <option key={c} value={c}>{c}</option>
                                                        ))}
                                                        <option value="__custom__">+ กำหนดหมวดหมู่ใหม่...</option>
                                                    </select>

                                                    {uploadData.category === '__custom__' && (
                                                        <input
                                                            type="text"
                                                            placeholder="พิมพ์หมวดหมู่..."
                                                            value={uploadData.customCategory}
                                                            onChange={(e) => setUploadData('customCategory', e.target.value)}
                                                            className="rounded border-slate-300 py-0.5 px-2 text-xs focus:border-[#000080] focus:ring-[#000080]"
                                                        />
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div className="flex items-center gap-2 shrink-0">
                                        <button
                                            type="button"
                                            onClick={clearSelectedFile}
                                            className="rounded-lg border border-slate-300 bg-white px-3 py-1 font-semibold text-slate-700 hover:bg-slate-50"
                                        >
                                            ยกเลิก
                                        </button>
                                        <button
                                            type="button"
                                            onClick={submitUpload}
                                            disabled={uploadProcessing}
                                            className="inline-flex items-center gap-1.5 rounded-lg bg-[#000080] px-3.5 py-1 font-semibold text-white hover:bg-blue-900 disabled:opacity-50 shadow-xs"
                                        >
                                            <UploadCloud className="h-3.5 w-3.5" />
                                            <span>{uploadProcessing ? 'กำลังอัปโหลด...' : 'เริ่มอัปโหลด'}</span>
                                        </button>
                                    </div>
                                </div>

                                {uploadProgress && (
                                    <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-blue-200">
                                        <div
                                            className="h-full rounded-full bg-[#000080] transition-all"
                                            style={{ width: `${uploadProgress.percentage}%` }}
                                        />
                                    </div>
                                )}
                            </div>
                        )}

                        {/* 4. SPLIT PANE: LEFT NAVIGATION TREE & RIGHT FILE EXPLORER PANE */}
                        <div className="flex min-h-[580px] divide-x divide-slate-200">

                            {/* LEFT NAVIGATION PANE (TREE VIEW) */}
                            {isSidebarOpen && (
                                <aside className="w-64 shrink-0 bg-slate-50/70 p-2.5 flex flex-col justify-between overflow-y-auto">
                                    <div className="space-y-3">
                                        {/* Group 1: Quick Access */}
                                        <div>
                                            <p className="px-2 pb-1 text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                                                การเข้าถึงด่วน
                                            </p>
                                            <div className="space-y-0.5">
                                                <button
                                                    type="button"
                                                    onClick={() => navigateToFolder('root')}
                                                    className={`w-full flex items-center justify-between rounded-md px-2 py-1.5 text-xs transition ${
                                                        isViewingRoot
                                                            ? 'bg-blue-100/90 text-blue-900 font-semibold shadow-xs'
                                                            : 'text-slate-700 hover:bg-slate-200/60'
                                                    }`}
                                                >
                                                    <div className="flex items-center gap-2 truncate">
                                                        <Home className="h-4 w-4 text-blue-600 shrink-0" />
                                                        <span className="truncate">คลังหลัก</span>
                                                    </div>
                                                    <span className="text-[10px] text-slate-400">
                                                        {stats?.uncategorized ?? 0}
                                                    </span>
                                                </button>

                                                <button
                                                    type="button"
                                                    onClick={() => navigateToFolder('all')}
                                                    className={`w-full flex items-center justify-between rounded-md px-2 py-1.5 text-xs transition ${
                                                        isViewingAll
                                                            ? 'bg-blue-100/90 text-blue-900 font-semibold shadow-xs'
                                                            : 'text-slate-700 hover:bg-slate-200/60'
                                                    }`}
                                                >
                                                    <div className="flex items-center gap-2 truncate">
                                                        <HardDrive className="h-4 w-4 text-blue-600 shrink-0" />
                                                        <span className="truncate">ไฟล์ทั้งหมดในระบบ</span>
                                                    </div>
                                                    <span className="text-[10px] text-slate-400">
                                                        {stats?.total ?? 0}
                                                    </span>
                                                </button>
                                            </div>
                                        </div>

                                        {/* Group 2: Hierarchical Folders Tree */}
                                        <div>
                                            <div className="flex items-center justify-between px-2 pb-1">
                                                <p className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                                                    โฟลเดอร์ ({allFolders.length})
                                                </p>
                                                <button
                                                    type="button"
                                                    onClick={() => openCreateFolder(null)}
                                                    className="p-0.5 rounded text-slate-400 hover:text-blue-700 hover:bg-slate-200/60"
                                                    title="สร้างโฟลเดอร์ใหม่"
                                                >
                                                    <FolderPlus className="h-3.5 w-3.5" />
                                                </button>
                                            </div>

                                            {folderTree.length > 0 ? (
                                                <div className="space-y-0.5">
                                                    {folderTree.map((folder) => renderFolderTreeNode(folder))}
                                                </div>
                                            ) : (
                                                <div className="p-3 text-center">
                                                    <p className="text-[11px] text-slate-400">ยังไม่มีโฟลเดอร์</p>
                                                    <button
                                                        type="button"
                                                        onClick={() => openCreateFolder(null)}
                                                        className="mt-1.5 text-xs font-semibold text-blue-700 hover:underline inline-flex items-center gap-1"
                                                    >
                                                        <Plus className="h-3 w-3" />
                                                        <span>สร้างโฟลเดอร์</span>
                                                    </button>
                                                </div>
                                            )}
                                        </div>
                                    </div>

                                    {/* Storage Stats Box at Bottom of Sidebar */}
                                    <div className="mt-4 rounded-lg border border-slate-200/80 bg-white p-2.5 text-[11px] text-slate-500 space-y-1">
                                        <div className="flex items-center justify-between font-semibold text-slate-700">
                                            <span>พื้นที่ใช้งาน</span>
                                            <span>{formatBytes(stats?.bytes)}</span>
                                        </div>
                                        <div className="flex items-center justify-between text-slate-400">
                                            <span>รูปภาพ ({stats?.images ?? 0})</span>
                                            <span>PDF ({stats?.pdfs ?? 0})</span>
                                        </div>
                                    </div>
                                </aside>
                            )}

                            {/* RIGHT MAIN EXPLORER CONTENT PANE */}
                            <main className="flex-1 flex flex-col justify-between bg-white overflow-x-auto min-w-0">
                                <div>

                                    {/* SUBFOLDERS SECTION (แสดงโฟลเดอร์ย่อยในมุมมองปัจจุบัน) */}
                                    {!isViewingAll && subfolders.length > 0 && (
                                        <div className="border-b border-slate-100 p-4 bg-slate-50/30">
                                            <div className="flex items-center justify-between mb-2.5">
                                                <h3 className="text-xs font-bold text-slate-600">
                                                    โฟลเดอร์ ({subfolders.length})
                                                </h3>
                                                <span className="text-[11px] text-slate-400">
                                                    ดับเบิ้ลคลิกเพื่อเปิด • ลากไฟล์มาวางเพื่อย้าย
                                                </span>
                                            </div>

                                            <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                                                {subfolders.map((folder) => {
                                                    const isDragTarget = dragOverFolderId === folder.id;

                                                    return (
                                                        <div
                                                            key={folder.id}
                                                            onDragOver={(e) => {
                                                                e.preventDefault();
                                                                setDragOverFolderId(folder.id);
                                                            }}
                                                            onDragLeave={() => setDragOverFolderId(null)}
                                                            onDrop={(e) => handleDropOnFolder(e, folder.id)}
                                                            onDoubleClick={() => navigateToFolder(folder.id)}
                                                            onClick={() => navigateToFolder(folder.id)}
                                                            className={`group relative flex items-center justify-between rounded-lg border p-2 transition cursor-pointer select-none ${
                                                                isDragTarget
                                                                    ? 'border-blue-500 bg-blue-100 ring-2 ring-blue-400'
                                                                    : 'border-slate-200 bg-white hover:border-blue-300 hover:bg-blue-50/50'
                                                            }`}
                                                        >
                                                            <div className="flex items-center gap-2.5 min-w-0 flex-1">
                                                                <Folder
                                                                    className="h-6 w-6 shrink-0 fill-current drop-shadow-xs"
                                                                    style={{ color: folder.color || '#EAB308' }}
                                                                />
                                                                <div className="min-w-0 flex-1">
                                                                    <p className="truncate text-xs font-semibold text-slate-800" title={folder.name}>
                                                                        {folder.name}
                                                                    </p>
                                                                    <p className="text-[10px] text-slate-400">
                                                                        {folder.media_count ?? 0} รายการ
                                                                    </p>
                                                                </div>
                                                            </div>

                                                            {/* Folder Menu */}
                                                            <div
                                                                className="relative"
                                                                onClick={(e) => e.stopPropagation()}
                                                            >
                                                                <button
                                                                    type="button"
                                                                    onClick={() => setActiveFolderMenuId(activeFolderMenuId === folder.id ? null : folder.id)}
                                                                    className="p-1 rounded text-slate-400 opacity-0 group-hover:opacity-100 hover:bg-slate-100 hover:text-slate-700 transition"
                                                                >
                                                                    <MoreVertical className="h-3.5 w-3.5" />
                                                                </button>

                                                                {activeFolderMenuId === folder.id && (
                                                                    <div className="absolute right-0 top-full z-20 mt-1 w-36 rounded-lg border border-slate-200 bg-white py-1 shadow-lg animate-in fade-in">
                                                                        <button
                                                                            type="button"
                                                                            onClick={() => openEditFolder(folder)}
                                                                            className="flex w-full items-center gap-2 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50"
                                                                        >
                                                                            <Edit2 className="h-3.5 w-3.5 text-slate-400" />
                                                                            <span>เปลี่ยนชื่อ / สี</span>
                                                                        </button>
                                                                        <button
                                                                            type="button"
                                                                            onClick={() => handleDeleteFolder(folder)}
                                                                            className="flex w-full items-center gap-2 px-3 py-1.5 text-xs text-rose-600 hover:bg-rose-50"
                                                                        >
                                                                            <Trash2 className="h-3.5 w-3.5 text-rose-500" />
                                                                            <span>ลบโฟลเดอร์</span>
                                                                        </button>
                                                                    </div>
                                                                )}
                                                            </div>
                                                        </div>
                                                    );
                                                })}

                                                {/* Add Subfolder Button Card */}
                                                <button
                                                    type="button"
                                                    onClick={() => openCreateFolder(currentFolder?.id || null)}
                                                    className="flex items-center gap-2 rounded-lg border border-dashed border-slate-200 bg-white p-2 text-xs font-semibold text-slate-500 hover:border-blue-400 hover:text-blue-700 hover:bg-blue-50/30 transition"
                                                >
                                                    <FolderPlus className="h-5 w-5 text-slate-400" />
                                                    <span className="truncate">+ สร้างโฟลเดอร์ย่อย</span>
                                                </button>
                                            </div>
                                        </div>
                                    )}

                                    {/* FILES CONTENT */}
                                    {sortedMediaList.length > 0 ? (
                                        viewMode === 'list' ? (
                                            /* =========================================================
                                               WINDOWS EXPLORER DETAILS LIST VIEW (เหมือนรูป Screenshot เป๊ะๆ)
                                               ========================================================= */
                                            <div className="overflow-x-auto select-none">
                                                <table className="w-full text-left text-xs border-collapse">
                                                    <thead>
                                                        <tr className="border-b border-slate-200 bg-slate-50/80 text-slate-600 font-semibold sticky top-0 z-10">
                                                            {/* Column: Name */}
                                                            <th
                                                                onClick={() => handleSort('name')}
                                                                className="py-2 px-3 hover:bg-slate-200/60 cursor-pointer transition w-[40%]"
                                                            >
                                                                <div className="flex items-center gap-1.5">
                                                                    <span>ชื่อไฟล์</span>
                                                                    {sortColumn === 'name' && (
                                                                        <span className="text-[10px] text-blue-600">
                                                                            {sortDirection === 'asc' ? '▲' : '▼'}
                                                                        </span>
                                                                    )}
                                                                </div>
                                                            </th>

                                                            {/* Column: Category */}
                                                            <th
                                                                onClick={() => handleSort('category')}
                                                                className="py-2 px-3 hover:bg-slate-200/60 cursor-pointer transition"
                                                            >
                                                                <div className="flex items-center gap-1.5">
                                                                    <span>หมวดหมู่</span>
                                                                    {sortColumn === 'category' && (
                                                                        <span className="text-[10px] text-blue-600">
                                                                            {sortDirection === 'asc' ? '▲' : '▼'}
                                                                        </span>
                                                                    )}
                                                                </div>
                                                            </th>

                                                            {/* Column: Date Modified */}
                                                            <th
                                                                onClick={() => handleSort('date')}
                                                                className="py-2 px-3 hover:bg-slate-200/60 cursor-pointer transition"
                                                            >
                                                                <div className="flex items-center gap-1.5">
                                                                    <span>วันที่แก้ไข</span>
                                                                    {sortColumn === 'date' && (
                                                                        <span className="text-[10px] text-blue-600">
                                                                            {sortDirection === 'asc' ? '▲' : '▼'}
                                                                        </span>
                                                                    )}
                                                                </div>
                                                            </th>

                                                            {/* Column: Type */}
                                                            <th
                                                                onClick={() => handleSort('type')}
                                                                className="py-2 px-3 hover:bg-slate-200/60 cursor-pointer transition"
                                                            >
                                                                <div className="flex items-center gap-1.5">
                                                                    <span>ประเภท</span>
                                                                    {sortColumn === 'type' && (
                                                                        <span className="text-[10px] text-blue-600">
                                                                            {sortDirection === 'asc' ? '▲' : '▼'}
                                                                        </span>
                                                                    )}
                                                                </div>
                                                            </th>

                                                            {/* Column: Size */}
                                                            <th
                                                                onClick={() => handleSort('size')}
                                                                className="py-2 px-3 hover:bg-slate-200/60 cursor-pointer transition"
                                                            >
                                                                <div className="flex items-center gap-1.5">
                                                                    <span>ขนาด</span>
                                                                    {sortColumn === 'size' && (
                                                                        <span className="text-[10px] text-blue-600">
                                                                            {sortDirection === 'asc' ? '▲' : '▼'}
                                                                        </span>
                                                                    )}
                                                                </div>
                                                            </th>

                                                            {/* Column: Actions */}
                                                            <th className="py-2 px-3 text-right">
                                                                <span>การจัดการ</span>
                                                            </th>
                                                        </tr>
                                                    </thead>
                                                    <tbody className="divide-y divide-slate-100">
                                                        {sortedMediaList.map((item) => {
                                                            const isSelected = selectedItemId === item.id;

                                                            return (
                                                                <tr
                                                                    key={item.id}
                                                                    draggable
                                                                    onDragStart={(e) => handleDragStartFile(e, item)}
                                                                    onClick={() => setSelectedItemId(item.id)}
                                                                    onDoubleClick={() => setPreviewItem(item)}
                                                                    className={`cursor-pointer transition group ${
                                                                        isSelected
                                                                            ? 'bg-blue-100/90 text-blue-950 font-medium'
                                                                            : 'hover:bg-blue-50/40 text-slate-700'
                                                                    }`}
                                                                >
                                                                    {/* Name with File Icon */}
                                                                    <td className="py-2 px-3 max-w-xs">
                                                                        <div className="flex items-center gap-2.5">
                                                                            {/* File Icon matching Windows style */}
                                                                            {isImage(item) ? (
                                                                                <div className="h-6 w-6 shrink-0 overflow-hidden rounded bg-slate-100 border border-slate-200">
                                                                                    <img
                                                                                        src={resolveStorageUrl(item)}
                                                                                        alt=""
                                                                                        className="h-full w-full object-cover"
                                                                                        loading="lazy"
                                                                                    />
                                                                                </div>
                                                                            ) : (
                                                                                <div className="h-6 w-6 shrink-0 flex items-center justify-center rounded bg-rose-50 border border-rose-200 text-rose-600">
                                                                                    <FileText className="h-3.5 w-3.5" />
                                                                                </div>
                                                                            )}

                                                                            <span
                                                                                className="truncate"
                                                                                title={item.original_name}
                                                                            >
                                                                                {item.original_name}
                                                                            </span>

                                                                            {item.used_by_posts_count > 0 && (
                                                                                <span className="shrink-0 rounded bg-emerald-100 text-emerald-800 text-[10px] font-semibold px-1 py-0.2">
                                                                                    ใช้งาน
                                                                                </span>
                                                                            )}
                                                                        </div>
                                                                    </td>

                                                                    {/* Category */}
                                                                    <td className="py-2 px-3">
                                                                        {item.category ? (
                                                                            <span className="inline-flex items-center gap-1 rounded bg-slate-100 border border-slate-200 px-2 py-0.5 text-[11px] font-medium text-slate-800">
                                                                                <Tag className="h-2.5 w-2.5 text-blue-600" />
                                                                                <span>{item.category}</span>
                                                                            </span>
                                                                        ) : (
                                                                            <span className="text-slate-400">-</span>
                                                                        )}
                                                                    </td>

                                                                    {/* Date Modified */}
                                                                    <td className="py-2 px-3 text-slate-500 whitespace-nowrap">
                                                                        {formatDate(item.created_at)}
                                                                    </td>

                                                                    {/* Type */}
                                                                    <td className="py-2 px-3 text-slate-500 whitespace-nowrap">
                                                                        {getFileTypeLabel(item.mime_type, item.original_name)}
                                                                    </td>

                                                                    {/* Size */}
                                                                    <td className="py-2 px-3 text-slate-500 whitespace-nowrap">
                                                                        {formatBytes(item.file_size)}
                                                                    </td>

                                                                    {/* Actions */}
                                                                    <td className="py-2 px-3 text-right whitespace-nowrap">
                                                                        <div className="flex items-center justify-end gap-1 opacity-70 group-hover:opacity-100 transition">
                                                                            <button
                                                                                type="button"
                                                                                onClick={(e) => {
                                                                                    e.stopPropagation();
                                                                                    openEditMediaModal(item);
                                                                                }}
                                                                                className="rounded p-1 text-slate-500 hover:bg-slate-200 hover:text-slate-800"
                                                                                title="แก้ไขชื่อและจัดหมวดหมู่"
                                                                            >
                                                                                <Tag className="h-3.5 w-3.5 text-blue-600" />
                                                                            </button>
                                                                            <button
                                                                                type="button"
                                                                                onClick={(e) => {
                                                                                    e.stopPropagation();
                                                                                    openMoveModal(item);
                                                                                }}
                                                                                className="rounded p-1 text-slate-500 hover:bg-slate-200 hover:text-slate-800"
                                                                                title="ย้ายโฟลเดอร์"
                                                                            >
                                                                                <FolderInput className="h-3.5 w-3.5 text-blue-600" />
                                                                            </button>
                                                                            <button
                                                                                type="button"
                                                                                onClick={(e) => {
                                                                                    e.stopPropagation();
                                                                                    copyUrl(item);
                                                                                }}
                                                                                className="rounded p-1 text-slate-500 hover:bg-slate-200 hover:text-slate-800"
                                                                                title="คัดลอกลิงก์"
                                                                            >
                                                                                <Copy className="h-3.5 w-3.5" />
                                                                            </button>
                                                                            <button
                                                                                type="button"
                                                                                onClick={(e) => {
                                                                                    e.stopPropagation();
                                                                                    setPreviewItem(item);
                                                                                }}
                                                                                className="rounded p-1 text-slate-500 hover:bg-slate-200 hover:text-slate-800"
                                                                                title="ดูตัวอย่าง"
                                                                            >
                                                                                <Eye className="h-3.5 w-3.5" />
                                                                            </button>
                                                                            <button
                                                                                type="button"
                                                                                onClick={(e) => {
                                                                                    e.stopPropagation();
                                                                                    handleDeleteFile(item);
                                                                                }}
                                                                                disabled={item.used_by_posts_count > 0 || item.used_by_templates_count > 0}
                                                                                className="rounded p-1 text-rose-500 hover:bg-rose-100 disabled:opacity-20 disabled:hover:bg-transparent"
                                                                                title="ลบไฟล์"
                                                                            >
                                                                                <Trash2 className="h-3.5 w-3.5" />
                                                                            </button>
                                                                        </div>
                                                                    </td>
                                                                </tr>
                                                            );
                                                        })}
                                                    </tbody>
                                                </table>
                                            </div>
                                        ) : (
                                            /* =========================================================
                                               LARGE ICONS / GRID VIEW
                                               ========================================================= */
                                            <div className="p-4 grid gap-3 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4">
                                                {sortedMediaList.map((item) => {
                                                    const isSelected = selectedItemId === item.id;

                                                    return (
                                                        <div
                                                            key={item.id}
                                                            draggable
                                                            onDragStart={(e) => handleDragStartFile(e, item)}
                                                            onClick={() => setSelectedItemId(item.id)}
                                                            onDoubleClick={() => setPreviewItem(item)}
                                                            className={`group flex flex-col justify-between overflow-hidden rounded-xl border transition cursor-pointer select-none ${
                                                                isSelected
                                                                    ? 'border-blue-500 bg-blue-50/70 ring-2 ring-blue-400'
                                                                    : 'border-slate-200 bg-white hover:border-blue-300 hover:shadow-xs'
                                                            }`}
                                                        >
                                                            <div>
                                                                <div className="relative aspect-[4/3] w-full overflow-hidden bg-slate-100">
                                                                    {isImage(item) ? (
                                                                        <img
                                                                            src={resolveStorageUrl(item)}
                                                                            loading="lazy"
                                                                            decoding="async"
                                                                            alt={item.original_name}
                                                                            className="h-full w-full object-cover transition duration-150 group-hover:scale-105"
                                                                        />
                                                                    ) : (
                                                                        <div className="flex h-full flex-col items-center justify-center text-slate-500">
                                                                            <div className="rounded-xl bg-rose-50 p-3 text-rose-600">
                                                                                <FileText className="h-8 w-8" />
                                                                            </div>
                                                                            <span className="mt-1 text-[11px] font-semibold">PDF Document</span>
                                                                        </div>
                                                                    )}

                                                                    {/* Badges Overlay */}
                                                                    <div className="absolute left-2 top-2 flex flex-col gap-1 max-w-[85%]">
                                                                        {item.category && (
                                                                            <span className="inline-flex items-center gap-1 truncate rounded bg-slate-900/80 px-1.5 py-0.5 text-[10px] font-medium text-white shadow-xs">
                                                                                <Tag className="h-2.5 w-2.5 shrink-0" />
                                                                                <span className="truncate">{item.category}</span>
                                                                            </span>
                                                                        )}
                                                                        {item.folder_name && isViewingAll && (
                                                                            <span
                                                                                className="inline-flex items-center gap-1 truncate rounded px-1.5 py-0.5 text-[10px] font-medium text-white shadow-xs"
                                                                                style={{ backgroundColor: item.folder_color || '#EAB308' }}
                                                                            >
                                                                                <Folder className="h-2.5 w-2.5 fill-current shrink-0" />
                                                                                <span className="truncate">{item.folder_name}</span>
                                                                            </span>
                                                                        )}
                                                                    </div>
                                                                </div>

                                                                <div className="p-2.5">
                                                                    <p
                                                                        className="truncate text-xs font-semibold text-slate-800"
                                                                        title={item.original_name}
                                                                    >
                                                                        {item.original_name}
                                                                    </p>
                                                                    <div className="mt-1 flex items-center justify-between text-[11px] text-slate-400">
                                                                        <span>{formatBytes(item.file_size)}</span>
                                                                        <span>{formatDate(item.created_at)}</span>
                                                                    </div>
                                                                </div>
                                                            </div>

                                                            {/* Card Footer Toolbar */}
                                                            <div className="flex items-center justify-between border-t border-slate-100 bg-slate-50/60 px-2 py-1.5 text-xs">
                                                                <div className="flex items-center gap-1">
                                                                    <button
                                                                        type="button"
                                                                        onClick={(e) => {
                                                                            e.stopPropagation();
                                                                            openEditMediaModal(item);
                                                                        }}
                                                                        className="rounded p-1 text-slate-500 hover:bg-slate-200 hover:text-slate-800"
                                                                        title="หมวดหมู่"
                                                                    >
                                                                        <Tag className="h-3 w-3 text-blue-600" />
                                                                    </button>
                                                                    <button
                                                                        type="button"
                                                                        onClick={(e) => {
                                                                            e.stopPropagation();
                                                                            openMoveModal(item);
                                                                        }}
                                                                        className="rounded p-1 text-slate-500 hover:bg-slate-200 hover:text-slate-800"
                                                                        title="ย้าย"
                                                                    >
                                                                        <FolderInput className="h-3 w-3 text-blue-600" />
                                                                    </button>
                                                                    <button
                                                                        type="button"
                                                                        onClick={(e) => {
                                                                            e.stopPropagation();
                                                                            copyUrl(item);
                                                                        }}
                                                                        className="rounded p-1 text-slate-500 hover:bg-slate-200 hover:text-slate-800"
                                                                        title="คัดลอกลิงก์"
                                                                    >
                                                                        <Copy className="h-3 w-3" />
                                                                    </button>
                                                                    <button
                                                                        type="button"
                                                                        onClick={(e) => {
                                                                            e.stopPropagation();
                                                                            setPreviewItem(item);
                                                                        }}
                                                                        className="rounded p-1 text-slate-500 hover:bg-slate-200 hover:text-slate-800"
                                                                        title="ดูตัวอย่าง"
                                                                    >
                                                                        <Eye className="h-3 w-3" />
                                                                    </button>
                                                                </div>

                                                                <button
                                                                    type="button"
                                                                    onClick={(e) => {
                                                                        e.stopPropagation();
                                                                        handleDeleteFile(item);
                                                                    }}
                                                                    disabled={item.used_by_posts_count > 0 || item.used_by_templates_count > 0}
                                                                    className="rounded p-1 text-rose-500 hover:bg-rose-100 disabled:opacity-20"
                                                                    title="ลบไฟล์"
                                                                >
                                                                    <Trash2 className="h-3 w-3" />
                                                                </button>
                                                            </div>
                                                        </div>
                                                    );
                                                })}
                                            </div>
                                        )
                                    ) : (
                                        /* Empty State */
                                        <div className="p-12 text-center select-none">
                                            <Folder className="mx-auto h-12 w-12 text-slate-300 fill-current" />
                                            <p className="mt-3 text-sm font-semibold text-slate-700">โฟลเดอร์นี้ว่างเปล่า</p>
                                            <p className="mt-1 text-xs text-slate-400">
                                                กดปุ่ม "อัปโหลดไฟล์" หรือลากไฟล์มาวางเพื่อเพิ่มไฟล์ลงในโฟลเดอร์นี้
                                            </p>
                                            <button
                                                type="button"
                                                onClick={() => fileInput.current?.click()}
                                                className="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-[#000080] px-4 py-1.5 text-xs font-semibold text-white hover:bg-blue-900 shadow-xs"
                                            >
                                                <UploadCloud className="h-4 w-4" />
                                                <span>อัปโหลดไฟล์ที่นี่</span>
                                            </button>
                                        </div>
                                    )}

                                    {/* Pagination Links */}
                                    {media.links && media.links.length > 3 && (
                                        <div className="flex flex-wrap items-center justify-center gap-1 border-t border-slate-100 p-3">
                                            {media.links.map((link, index) => (
                                                <Link
                                                    key={index}
                                                    href={link.url || '#'}
                                                    className={`rounded px-2.5 py-1 text-xs font-medium transition ${
                                                        link.active
                                                            ? 'bg-[#000080] text-white shadow-xs'
                                                            : link.url
                                                            ? 'text-slate-600 hover:bg-slate-100'
                                                            : 'cursor-not-allowed text-slate-300'
                                                    }`}
                                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                                />
                                            ))}
                                        </div>
                                    )}
                                </div>

                                {/* =========================================================
                                    5. WINDOWS STATUS BAR (แถบสถานะด้านล่าง สไตล์ Windows Explorer)
                                   ========================================================= */}
                                <footer className="border-t border-slate-200 bg-slate-50/90 px-3 py-1.5 text-[11px] text-slate-500 flex items-center justify-between select-none">
                                    <div className="flex items-center gap-3">
                                        <span>
                                            {media.total || sortedMediaList.length} รายการ
                                        </span>
                                        {selectedItem && (
                                            <>
                                                <span className="text-slate-300">|</span>
                                                <span className="font-semibold text-blue-900">
                                                    เลือก 1 รายการ ({formatBytes(selectedItem.file_size)})
                                                </span>
                                            </>
                                        )}
                                        {activeCategoryParam !== 'all' && (
                                            <>
                                                <span className="text-slate-300">|</span>
                                                <span className="text-slate-600">
                                                    กรองตามหมวดหมู่: <strong className="text-slate-900">{activeCategoryParam}</strong>
                                                </span>
                                            </>
                                        )}
                                    </div>

                                    <div className="flex items-center gap-2">
                                        <button
                                            type="button"
                                            onClick={() => setViewMode('list')}
                                            className={`p-1 rounded transition ${
                                                viewMode === 'list' ? 'bg-slate-200 text-slate-800' : 'text-slate-400 hover:text-slate-700'
                                            }`}
                                            title="รายละเอียด (Details)"
                                        >
                                            <List className="h-3.5 w-3.5" />
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => setViewMode('grid')}
                                            className={`p-1 rounded transition ${
                                                viewMode === 'grid' ? 'bg-slate-200 text-slate-800' : 'text-slate-400 hover:text-slate-700'
                                            }`}
                                            title="ไอคอนขนาดใหญ่ (Large Icons)"
                                        >
                                            <LayoutGrid className="h-3.5 w-3.5" />
                                        </button>
                                    </div>
                                </footer>
                            </main>
                        </div>
                    </div>
                </div>
            </div>

            {/* CREATE / EDIT FOLDER MODAL */}
            {isFolderModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4 backdrop-blur-xs animate-in fade-in">
                    <div className="w-full max-w-md rounded-xl bg-white p-5 shadow-2xl">
                        <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                            <h3 className="text-sm font-bold text-slate-900">
                                {editingFolder ? 'แก้ไขโฟลเดอร์' : 'สร้างโฟลเดอร์ใหม่'}
                            </h3>
                            <button
                                type="button"
                                onClick={() => setIsFolderModalOpen(false)}
                                className="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                            >
                                <X className="h-4 w-4" />
                            </button>
                        </div>

                        <form onSubmit={handleFolderSubmit} className="mt-4 space-y-4">
                            <div>
                                <label className="block text-xs font-semibold text-slate-700">ชื่อโฟลเดอร์</label>
                                <input
                                    type="text"
                                    required
                                    autoFocus
                                    value={folderForm.name}
                                    onChange={(e) => setFolderForm({ ...folderForm, name: e.target.value })}
                                    placeholder="เช่น เอกสารคำสั่ง, ภาพกิจกรรม, ประชาสัมพันธ์"
                                    className="mt-1 w-full rounded-lg border-slate-300 text-xs focus:border-[#000080] focus:ring-[#000080]"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">ตำแหน่งโฟลเดอร์แม่ (สร้างซ้อนใน)</label>
                                <select
                                    value={folderForm.parent_id}
                                    onChange={(e) => setFolderForm({ ...folderForm, parent_id: e.target.value })}
                                    className="w-full rounded-lg border-slate-300 text-xs focus:border-[#000080] focus:ring-[#000080]"
                                >
                                    <option value="">คลังหลัก (โฟลเดอร์ระดับบนสุด)</option>
                                    {allFolders
                                        .filter((f) => !editingFolder || f.id !== editingFolder.id)
                                        .map((f) => (
                                             <option key={f.id} value={f.id}>
                                                {f.full_path}
                                            </option>
                                        ))}
                                </select>
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-2">สีของโฟลเดอร์</label>
                                <div className="flex items-center gap-2">
                                    {FOLDER_COLORS.map((color) => (
                                        <button
                                            key={color.hex}
                                            type="button"
                                            onClick={() => setFolderForm({ ...folderForm, color: color.hex })}
                                            className={`h-6 w-6 rounded-full border-2 transition ${
                                                folderForm.color === color.hex ? 'border-slate-800 scale-110 shadow-xs' : 'border-transparent hover:scale-105'
                                            }`}
                                            style={{ backgroundColor: color.hex }}
                                            title={color.label}
                                        />
                                    ))}
                                </div>
                            </div>

                            <div className="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                                <button
                                    type="button"
                                    onClick={() => setIsFolderModalOpen(false)}
                                    className="rounded-lg border border-slate-200 px-3.5 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50"
                                >
                                    ยกเลิก
                                </button>
                                <button
                                    type="submit"
                                    className="rounded-lg bg-[#000080] px-4 py-1.5 text-xs font-semibold text-white hover:bg-blue-900 shadow-xs"
                                >
                                    {editingFolder ? 'บันทึกการแก้ไข' : 'สร้างโฟลเดอร์'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* EDIT FILE METADATA MODAL (Change Name & Category) */}
            {editingMediaItem && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4 backdrop-blur-xs animate-in fade-in">
                    <div className="w-full max-w-md rounded-xl bg-white p-5 shadow-2xl">
                        <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                            <h3 className="text-sm font-bold text-slate-900">แก้ไขชื่อและจัดหมวดหมู่ไฟล์</h3>
                            <button
                                type="button"
                                onClick={() => setEditingMediaItem(null)}
                                className="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                            >
                                <X className="h-4 w-4" />
                            </button>
                        </div>

                        <form onSubmit={handleSaveMediaEdit} className="mt-4 space-y-4">
                            <div>
                                <label className="block text-xs font-semibold text-slate-700">ชื่อไฟล์</label>
                                <input
                                    type="text"
                                    required
                                    value={mediaEditForm.original_name}
                                    onChange={(e) => setMediaEditForm({ ...mediaEditForm, original_name: e.target.value })}
                                    className="mt-1 w-full rounded-lg border-slate-300 text-xs focus:border-[#000080] focus:ring-[#000080]"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">หมวดหมู่ของไฟล์</label>
                                <select
                                    value={mediaEditForm.category}
                                    onChange={(e) => setMediaEditForm({ ...mediaEditForm, category: e.target.value })}
                                    className="w-full rounded-lg border-slate-300 text-xs focus:border-[#000080] focus:ring-[#000080]"
                                >
                                    <option value="">ไม่ระบุหมวดหมู่</option>
                                    {categories.map((c) => (
                                        <option key={c} value={c}>{c}</option>
                                    ))}
                                    <option value="__custom__">+ กำหนดหมวดหมู่ใหม่...</option>
                                </select>

                                {mediaEditForm.category === '__custom__' && (
                                    <input
                                        type="text"
                                        placeholder="พิมพ์ชื่อหมวดหมู่ที่ต้องการ..."
                                        value={mediaEditForm.customCategory}
                                        onChange={(e) => setMediaEditForm({ ...mediaEditForm, customCategory: e.target.value })}
                                        className="mt-2 w-full rounded-lg border-slate-300 text-xs focus:border-[#000080] focus:ring-[#000080]"
                                    />
                                )}
                            </div>

                            <div className="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                                <button
                                    type="button"
                                    onClick={() => setEditingMediaItem(null)}
                                    className="rounded-lg border border-slate-200 px-3.5 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50"
                                >
                                    ยกเลิก
                                </button>
                                <button
                                    type="submit"
                                    className="rounded-lg bg-[#000080] px-4 py-1.5 text-xs font-semibold text-white hover:bg-blue-900 shadow-xs"
                                >
                                    บันทึกข้อมูล
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* MOVE FILE TO FOLDER MODAL */}
            {movingItem && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4 backdrop-blur-xs animate-in fade-in">
                    <div className="w-full max-w-md rounded-xl bg-white p-5 shadow-2xl">
                        <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                            <h3 className="text-sm font-bold text-slate-900">ย้ายไฟล์ไปยังโฟลเดอร์...</h3>
                            <button
                                type="button"
                                onClick={() => setMovingItem(null)}
                                className="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                            >
                                <X className="h-4 w-4" />
                            </button>
                        </div>

                        <div className="mt-3">
                            <p className="text-xs text-slate-500 truncate mb-3">
                                ไฟล์ที่เลือก: <strong className="text-slate-800">{movingItem.original_name}</strong>
                            </p>

                            <div className="space-y-1 max-h-60 overflow-y-auto border border-slate-200 rounded-lg p-2 bg-slate-50">
                                {/* Root Folder */}
                                <label
                                    className={`flex items-center gap-2.5 rounded-lg p-2 text-xs font-medium cursor-pointer transition ${
                                        moveForm.folder_id === '' ? 'bg-blue-100 text-blue-900 font-bold' : 'hover:bg-white text-slate-700'
                                    }`}
                                >
                                    <input
                                        type="radio"
                                        name="destination_folder"
                                        value=""
                                        checked={moveForm.folder_id === ''}
                                        onChange={() => setMoveForm({ ...moveForm, folder_id: '' })}
                                        className="text-[#000080] focus:ring-[#000080]"
                                    />
                                    <Home className="h-4 w-4 text-blue-600" />
                                    <span>คลังหลัก (นอกโฟลเดอร์)</span>
                                </label>

                                {allFolders.map((folder) => (
                                    <label
                                        key={folder.id}
                                        className={`flex items-center gap-2.5 rounded-lg p-2 text-xs font-medium cursor-pointer transition ${
                                            moveForm.folder_id === String(folder.id) ? 'bg-blue-100 text-blue-900 font-bold' : 'hover:bg-white text-slate-700'
                                        }`}
                                    >
                                        <input
                                            type="radio"
                                            name="destination_folder"
                                            value={String(folder.id)}
                                            checked={moveForm.folder_id === String(folder.id)}
                                            onChange={() => setMoveForm({ ...moveForm, folder_id: String(folder.id) })}
                                            className="text-[#000080] focus:ring-[#000080]"
                                        />
                                        <Folder className="h-4 w-4 fill-current" style={{ color: folder.color || '#EAB308' }} />
                                        <span className="truncate">{folder.full_path}</span>
                                    </label>
                                ))}
                            </div>
                        </div>

                        <div className="mt-4 flex items-center justify-end gap-2 border-t border-slate-100 pt-3">
                            <button
                                type="button"
                                onClick={() => setMovingItem(null)}
                                className="rounded-lg border border-slate-200 px-3.5 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50"
                            >
                                ยกเลิก
                            </button>
                            <button
                                type="button"
                                onClick={handleConfirmMove}
                                className="rounded-lg bg-[#000080] px-4 py-1.5 text-xs font-semibold text-white hover:bg-blue-900 shadow-xs"
                            >
                                ย้ายไฟล์มาที่นี่
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* PREVIEW FILE MODAL (Enhanced Modern Windows Preview) */}
            {previewItem && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 p-4 backdrop-blur-xs animate-in fade-in"
                    onClick={() => setPreviewItem(null)}
                >
                    <div
                        className="relative max-h-[90vh] max-w-4xl w-full overflow-hidden rounded-xl bg-white shadow-2xl"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <div className="flex items-center justify-between border-b border-slate-200 px-4 py-3 bg-slate-50">
                            <div className="flex items-center gap-2 pr-4 min-w-0">
                                <span className="font-bold text-slate-900 truncate text-sm">
                                    {previewItem.original_name}
                                </span>
                                {previewItem.category && (
                                    <span className="inline-flex items-center gap-1 rounded bg-blue-100 text-blue-800 px-2 py-0.5 text-[11px] font-semibold shrink-0">
                                        <Tag className="h-2.5 w-2.5" />
                                        <span>{previewItem.category}</span>
                                    </span>
                                )}
                            </div>
                            <button
                                type="button"
                                onClick={() => setPreviewItem(null)}
                                className="rounded p-1 text-slate-400 hover:bg-slate-200 hover:text-slate-700"
                            >
                                <X className="h-5 w-5" />
                            </button>
                        </div>

                        <div className="p-4 flex items-center justify-center max-h-[75vh] overflow-auto bg-slate-100">
                            {isImage(previewItem) ? (
                                <img
                                    src={resolveStorageUrl(previewItem)}
                                    alt={previewItem.original_name}
                                    className="max-h-[70vh] rounded object-contain shadow-xs"
                                />
                            ) : (
                                <div className="text-center p-8 bg-white rounded-xl shadow-xs border border-slate-200 max-w-md w-full">
                                    <FileText className="mx-auto h-16 w-16 text-rose-500" />
                                    <p className="mt-3 text-sm font-bold text-slate-800">{previewItem.original_name}</p>
                                    <p className="mt-1 text-xs text-slate-500">เอกสาร PDF พร้อมสำหรับเปิดดูหรือดาวน์โหลด</p>
                                    <div className="mt-5 flex items-center justify-center gap-2">
                                        <a
                                            href={previewItem.url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="inline-flex items-center gap-1.5 rounded-lg bg-[#000080] px-4 py-2 text-xs font-semibold text-white hover:bg-blue-900 shadow-xs"
                                        >
                                            <FileText className="h-4 w-4" />
                                            <span>เปิดอ่านเอกสาร PDF</span>
                                        </a>
                                    </div>
                                </div>
                            )}
                        </div>

                        <div className="flex items-center justify-between border-t border-slate-200 bg-white px-4 py-3 text-xs text-slate-500">
                            <div>
                                <span>{formatBytes(previewItem.file_size)}</span>
                                <span className="mx-2">•</span>
                                <span>{formatDate(previewItem.created_at)}</span>
                            </div>
                            <div className="flex items-center gap-2">
                                <button
                                    type="button"
                                    onClick={() => {
                                        const item = previewItem;
                                        setPreviewItem(null);
                                        openEditMediaModal(item);
                                    }}
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 font-semibold text-slate-700 hover:bg-slate-50"
                                >
                                    <Tag className="h-3.5 w-3.5 text-blue-600" />
                                    <span>จัดหมวดหมู่</span>
                                </button>
                                <button
                                    type="button"
                                    onClick={() => copyUrl(previewItem)}
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 font-semibold text-slate-700 hover:bg-slate-50"
                                >
                                    <Copy className="h-3.5 w-3.5" />
                                    <span>คัดลอกลิงก์</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
