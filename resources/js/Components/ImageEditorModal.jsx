import axios from 'axios';
import { resolveStorageUrl } from '@/Utils/media';
import {
    BringToFront,
    Copy,
    ImagePlus,
    Minus,
    Plus,
    Save,
    SendToBack,
    Trash2,
    Type,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import {
    Image as KonvaImage,
    Layer,
    Rect,
    Stage,
    Text,
    Transformer,
} from 'react-konva';

const CANVAS_PRESETS = [
    { label: '1:1', width: 1080, height: 1080 },
    { label: '4:5', width: 1080, height: 1350 },
    { label: 'แนวนอน', width: 1200, height: 630 },
];

const FONT_FAMILIES = ['Sarabun', 'Kanit', 'Prompt', 'Niramit', 'Chakra Petch', 'Tahoma', 'Arial'];

function createId() {
    return globalThis.crypto?.randomUUID?.() || `element-${Date.now()}-${Math.random()}`;
}
function mediaUrl(media) {
    return resolveStorageUrl(media) || null;
}

function fileToDataUrl(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result || ''));
        reader.onerror = reject;
        reader.readAsDataURL(file);
    });
}

function coverCrop(image, width, height) {
    if (!image?.naturalWidth || !image?.naturalHeight) return null;

    const imageRatio = image.naturalWidth / image.naturalHeight;
    const targetRatio = width / height;

    if (imageRatio > targetRatio) {
        const cropWidth = image.naturalHeight * targetRatio;
        return {
            x: (image.naturalWidth - cropWidth) / 2,
            y: 0,
            width: cropWidth,
            height: image.naturalHeight,
        };
    }

    const cropHeight = image.naturalWidth / targetRatio;
    return {
        x: 0,
        y: (image.naturalHeight - cropHeight) / 2,
        width: image.naturalWidth,
        height: cropHeight,
    };
}

function useCanvasImage(src) {
    const [image, setImage] = useState(null);

    useEffect(() => {
        if (!src) {
            setImage(null);
            return undefined;
        }

        const nextImage = new window.Image();
        if (/^https?:\/\//i.test(src)) {
            const sourceUrl = new URL(src, window.location.href);
            if (sourceUrl.origin !== window.location.origin) {
                nextImage.crossOrigin = 'anonymous';
            }
        }
        nextImage.onload = () => setImage(nextImage);
        nextImage.onerror = () => setImage(null);
        nextImage.src = src;

        return () => {
            nextImage.onload = null;
            nextImage.onerror = null;
        };
    }, [src]);

    return image;
}

function EditorElement({ item, selected, onSelect, onChange }) {
    const shapeRef = useRef(null);
    const transformerRef = useRef(null);
    const image = useCanvasImage(item.type === 'image' ? item.src : null);

    useEffect(() => {
        if (selected && transformerRef.current && shapeRef.current) {
            transformerRef.current.nodes([shapeRef.current]);
            transformerRef.current.getLayer()?.batchDraw();
        }
    }, [selected]);
    const commitTransform = () => {
        const node = shapeRef.current;
        if (!node) return;

        const scaleX = node.scaleX();
        const scaleY = node.scaleY();
        node.scaleX(1);
        node.scaleY(1);

        if (item.type === 'text') {
            onChange({
                ...item,
                x: node.x(),
                y: node.y(),
                width: Math.max(80, node.width() * scaleX),
                fontSize: Math.max(10, item.fontSize * scaleY),
                rotation: node.rotation(),
            });
            return;
        }

        onChange({
            ...item,
            x: node.x(),
            y: node.y(),
            width: Math.max(20, node.width() * scaleX),
            height: Math.max(20, node.height() * scaleY),
            rotation: node.rotation(),
        });
    };

    const commonProps = {
        ref: shapeRef,
        x: item.x,
        y: item.y,
        rotation: item.rotation || 0,
        opacity: item.opacity ?? 1,
        draggable: true,
        onClick: onSelect,
        onTap: onSelect,
        onDragEnd: (event) => onChange({
            ...item,
            x: event.target.x(),
            y: event.target.y(),
        }),
        onTransformEnd: commitTransform,
    };
    return (
        <>
            {item.type === 'text' ? (
                <Text
                    {...commonProps}
                    text={item.text}
                    width={item.width}
                    fontSize={item.fontSize}
                    fill={item.fill}
                    fontFamily={item.fontFamily}
                    fontStyle={item.fontStyle || 'normal'}
                    textDecoration={item.textDecoration || ''}
                    align={item.align || 'left'}
                    lineHeight={1.2}
                />
            ) : (
                <KonvaImage
                    {...commonProps}
                    image={image}
                    width={item.width}
                    height={item.height}
                />
            )}

            {selected && (
                <Transformer
                    ref={transformerRef}
                    name="editor-transformer"
                    rotateEnabled
                    flipEnabled={false}
                    keepRatio={item.type === 'image'}
                    boundBoxFunc={(oldBox, newBox) => (
                        newBox.width < 20 || newBox.height < 20 ? oldBox : newBox
                    )}
                />
            )}
        </>
    );
}
export default function ImageEditorModal({
    open,
    onClose,
    mediaList = [],
    initialImageUrl = null,
    initialData = null,
    onSave,
    onMediaUploaded,
}) {
    const stageRef = useRef(null);
    const fileInputRef = useRef(null);
    const backgroundFileInputRef = useRef(null);
    const startingWidth = Number(initialData?.canvasWidth || 1080);
    const startingHeight = Number(initialData?.canvasHeight || 1080);
    const [canvasSize, setCanvasSize] = useState({
        width: startingWidth,
        height: startingHeight,
    });
    const [backgroundColor, setBackgroundColor] = useState(
        initialData?.backgroundColor || '#ffffff',
    );
    const [backgroundImage, setBackgroundImage] = useState(
        initialData?.backgroundImage || null,
    );
    const [elements, setElements] = useState(() => {
        if (Array.isArray(initialData?.elements)) {
            return initialData.elements;
        }

        if (!initialImageUrl) return [];

        return [{
            id: createId(),
            type: 'image',
            src: initialImageUrl,
            x: 0,
            y: 0,
            width: startingWidth,
            height: startingHeight,
            rotation: 0,
            opacity: 1,
        }];
    });
    const [selectedId, setSelectedId] = useState(null);
    const [viewportWidth, setViewportWidth] = useState(window.innerWidth);
    const [isUploading, setIsUploading] = useState(false);
    const [isSaving, setIsSaving] = useState(false);
    const [localMedia, setLocalMedia] = useState(mediaList);
    const backgroundCanvasImage = useCanvasImage(backgroundImage?.src || null);
    const backgroundCrop = useMemo(
        () => coverCrop(backgroundCanvasImage, canvasSize.width, canvasSize.height),
        [backgroundCanvasImage, canvasSize.height, canvasSize.width],
    );

    useEffect(() => {
        if (!open) return undefined;

        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        const handleResize = () => setViewportWidth(window.innerWidth);
        const handleEscape = (event) => {
            if (event.key === 'Escape') onClose();
        };

        window.addEventListener('resize', handleResize);
        window.addEventListener('keydown', handleEscape);

        return () => {
            document.body.style.overflow = previousOverflow;
            window.removeEventListener('resize', handleResize);
            window.removeEventListener('keydown', handleEscape);
        };
    }, [open, onClose]);

    useEffect(() => {
        setLocalMedia(mediaList);
    }, [mediaList]);

    const selectedElement = useMemo(
        () => elements.find((item) => item.id === selectedId) || null,
        [elements, selectedId],
    );
    const availableWidth = viewportWidth >= 1024
        ? Math.max(420, viewportWidth - 470)
        : Math.max(280, viewportWidth - 32);
    const displayScale = Math.min(
        1,
        availableWidth / canvasSize.width,
        620 / canvasSize.height,
    );

    const updateElement = (nextElement) => {
        setElements((current) => current.map((item) => (
            item.id === nextElement.id ? nextElement : item
        )));
    };

    const patchSelected = (patch) => {
        if (!selectedElement) return;
        updateElement({ ...selectedElement, ...patch });
    };

    const addText = () => {
        const item = {
            id: createId(),
            type: 'text',
            text: 'ข้อความใหม่',
            x: 80,
            y: 80,
            width: Math.min(700, canvasSize.width - 160),
            fontSize: 64,
            fill: '#111827',
            fontFamily: 'Sarabun',
            fontStyle: 'normal',
            textDecoration: '',
            align: 'left',
            rotation: 0,
            opacity: 1,
        };
        setElements((current) => [...current, item]);
        setSelectedId(item.id);
    };
    const addImageFromUrl = (src, mediaId = null, forcedId = null) => new Promise((resolve, reject) => {
        if (!src) {
            reject(new Error('Missing image source'));
            return;
        }

        const image = new window.Image();
        if (/^https?:\/\//i.test(src)) {
            const sourceUrl = new URL(src, window.location.href);
            if (sourceUrl.origin !== window.location.origin) {
                image.crossOrigin = 'anonymous';
            }
        }

        image.onload = () => {
            const maxWidth = canvasSize.width * 0.68;
            const maxHeight = canvasSize.height * 0.68;
            const ratio = Math.min(
                maxWidth / image.naturalWidth,
                maxHeight / image.naturalHeight,
                1,
            );
            const width = Math.max(80, image.naturalWidth * ratio);
            const height = Math.max(80, image.naturalHeight * ratio);
            const item = {
                id: forcedId || createId(),
                type: 'image',
                src,
                mediaId,
                x: (canvasSize.width - width) / 2,
                y: (canvasSize.height - height) / 2,
                width,
                height,
                rotation: 0,
                opacity: 1,
            };

            setElements((current) => [...current, item]);
            setSelectedId(item.id);
            resolve(item);
        };
        image.onerror = () => reject(new Error('Unable to load image'));
        image.src = src;
    });

    const uploadMedia = async (file) => {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('is_featured', '1');

        const response = await axios.post(route('media.store'), formData, {
            headers: { Accept: 'application/json' },
        });

        return response.data.media;
    };
    const handleImageUpload = async (event) => {
        const file = event.target.files?.[0];
        event.target.value = '';
        if (!file) return;

        setIsUploading(true);
        const temporaryId = createId();

        try {
            const previewSrc = await fileToDataUrl(file);
            await addImageFromUrl(previewSrc, null, temporaryId);

            const media = await uploadMedia(file);
            const persistentSrc = mediaUrl(media);

            setLocalMedia((current) => [media, ...current]);
            onMediaUploaded?.(media);
            setElements((current) => current.map((item) => (
                item.id === temporaryId
                    ? { ...item, src: persistentSrc, mediaId: media.id }
                    : item
            )));
        } catch (error) {
            setElements((current) => current.filter((item) => item.id !== temporaryId));
            window.alert('ไม่สามารถเพิ่มรูปลงในเครื่องมือได้ กรุณาลองใหม่อีกครั้ง');
        } finally {
            setIsUploading(false);
        }
    };

    const setBackgroundFromMedia = (media) => {
        const src = mediaUrl(media);
        if (!src) return;

        setBackgroundImage({
            src,
            mediaId: media.id,
        });
    };

    const handleBackgroundUpload = async (event) => {
        const file = event.target.files?.[0];
        event.target.value = '';
        if (!file) return;

        setIsUploading(true);
        const previousBackground = backgroundImage;

        try {
            const previewSrc = await fileToDataUrl(file);
            setBackgroundImage({ src: previewSrc, mediaId: null });

            const media = await uploadMedia(file);
            setLocalMedia((current) => [media, ...current]);
            onMediaUploaded?.(media);
            setBackgroundImage({
                src: mediaUrl(media),
                mediaId: media.id,
            });
        } catch (error) {
            setBackgroundImage(previousBackground);
            window.alert('ไม่สามารถตั้งรูปพื้นหลังได้ กรุณาลองใหม่อีกครั้ง');
        } finally {
            setIsUploading(false);
        }
    };

    const deleteSelected = () => {
        if (!selectedId) return;
        setElements((current) => current.filter((item) => item.id !== selectedId));
        setSelectedId(null);
    };

    const duplicateSelected = () => {
        if (!selectedElement) return;

        const copy = {
            ...selectedElement,
            id: createId(),
            x: selectedElement.x + 30,
            y: selectedElement.y + 30,
        };
        setElements((current) => [...current, copy]);
        setSelectedId(copy.id);
    };

    const moveSelected = (direction) => {
        if (!selectedId) return;

        setElements((current) => {
            const index = current.findIndex((item) => item.id === selectedId);
            if (index < 0) return current;

            const next = [...current];
            const [item] = next.splice(index, 1);
            direction === 'front' ? next.push(item) : next.unshift(item);
            return next;
        });
    };
    const setPreset = (preset) => {
        setCanvasSize({ width: preset.width, height: preset.height });
    };

    const saveDesign = async () => {
        const stage = stageRef.current;
        if (!stage) return;

        setIsSaving(true);

        try {
            if (document.fonts?.load) {
                // Load fonts that might be used in the canvas before taking a snapshot
                const fontPromises = [];
                elements.forEach((el) => {
                    if (el.type === 'text' && el.fontFamily) {
                        fontPromises.push(document.fonts.load(`${Math.round(el.fontSize)}px "${el.fontFamily}"`));
                        fontPromises.push(document.fonts.load(`bold ${Math.round(el.fontSize)}px "${el.fontFamily}"`));
                        fontPromises.push(document.fonts.load(`italic ${Math.round(el.fontSize)}px "${el.fontFamily}"`));
                    }
                });
                await Promise.all(fontPromises);
                await document.fonts.ready;
            }

            const transformers = stage.find('.editor-transformer');
            transformers.forEach((node) => node.hide());
            stage.draw();

            let dataUrl;
            try {
                dataUrl = stage.toDataURL({
                    pixelRatio: Math.max(1, 1 / displayScale),
                    mimeType: 'image/png',
                    quality: 1,
                });
            } finally {
                transformers.forEach((node) => node.show());
                stage.draw();
            }

            const blob = await (await fetch(dataUrl)).blob();
            const file = new File(
                [blob],
                `template-design-${Date.now()}.png`,
                { type: 'image/png' },
            );
            const media = await uploadMedia(file);
            const editorData = {
                version: 1,
                canvasWidth: canvasSize.width,
                canvasHeight: canvasSize.height,
                backgroundColor,
                backgroundImage,
                elements,
            };

            setLocalMedia((current) => [media, ...current]);
            onMediaUploaded?.(media);
            await onSave?.({ media, editorData });
        } finally {
            setIsSaving(false);
        }
    };

    if (!open) return null;
    return (
        <div className="fixed inset-0 z-50 flex bg-slate-950/70">
            <div className="m-auto flex h-[96vh] w-[98vw] max-w-[1500px] flex-col overflow-hidden rounded-2xl bg-slate-100 shadow-2xl">
                <header className="flex items-center justify-between border-b border-slate-200 bg-white px-4 py-3 sm:px-5">
                    <div>
                        <h2 className="text-lg font-bold text-slate-900">เครื่องมือแก้ไขรูป</h2>
                        <p className="text-xs text-slate-500">
                            ลาก วาง ย่อ ขยาย หมุน และบันทึกเป็นรูปประจำเทมเพลต
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-lg p-2 text-slate-500 hover:bg-slate-100"
                        aria-label="ปิดเครื่องมือแก้ไขรูป"
                    >
                        <X className="h-5 w-5" />
                    </button>
                </header>

                <div className="flex min-h-0 flex-1 flex-col lg:flex-row">
                    <aside className="w-full shrink-0 overflow-y-auto border-b border-slate-200 bg-white p-4 lg:w-[360px] lg:border-b-0 lg:border-r">
                        <div className="grid grid-cols-1 gap-2 sm:grid-cols-3 lg:grid-cols-1">
                            <button
                                type="button"
                                onClick={addText}
                                className="inline-flex items-center justify-center gap-2 rounded-lg bg-[#000080] px-3 py-2 text-sm font-semibold text-white hover:bg-blue-900"
                            >
                                <Type className="h-4 w-4" />
                                เพิ่มข้อความ
                            </button>
                            <button
                                type="button"
                                onClick={() => fileInputRef.current?.click()}
                                disabled={isUploading}
                                className="inline-flex items-center justify-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-800 hover:bg-blue-100 disabled:opacity-50"
                            >
                                <ImagePlus className="h-4 w-4" />
                                {isUploading ? 'กำลังอัปโหลด' : 'เพิ่มรูป'}
                            </button>
                            <button
                                type="button"
                                onClick={() => backgroundFileInputRef.current?.click()}
                                disabled={isUploading}
                                className="inline-flex items-center justify-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800 hover:bg-amber-100 disabled:opacity-50"
                            >
                                <ImagePlus className="h-4 w-4" />
                                ตั้งพื้นหลัง
                            </button>
                            <input
                                ref={fileInputRef}
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                onChange={handleImageUpload}
                                className="hidden"
                            />
                            <input
                                ref={backgroundFileInputRef}
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                onChange={handleBackgroundUpload}
                                className="hidden"
                            />
                        </div>

                        <div className="mt-5">
                            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                ขนาดภาพ
                            </p>
                            <div className="mt-2 grid grid-cols-3 gap-2">
                                {CANVAS_PRESETS.map((preset) => (
                                    <button
                                        key={preset.label}
                                        type="button"
                                        onClick={() => setPreset(preset)}
                                        className={`rounded-lg border px-2 py-2 text-xs font-semibold ${
                                            canvasSize.width === preset.width && canvasSize.height === preset.height
                                                ? 'border-blue-500 bg-blue-50 text-blue-800'
                                                : 'border-slate-200 text-slate-600 hover:bg-slate-50'
                                        }`}
                                    >
                                        {preset.label}
                                    </button>
                                ))}
                            </div>
                        </div>

                        <div className="mt-5">
                            <label className="flex items-center justify-between text-xs font-semibold text-slate-600">
                                สีพื้นหลัง
                                <input
                                    type="color"
                                    value={backgroundColor}
                                    onChange={(event) => setBackgroundColor(event.target.value)}
                                    className="h-8 w-12 cursor-pointer rounded border border-slate-200 p-0.5"
                                />
                            </label>
                        </div>
                        <div className="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <div className="flex items-center justify-between gap-3">
                                <div>
                                    <p className="text-xs font-semibold text-slate-700">ภาพพื้นหลัง</p>
                                    <p className="text-[11px] text-slate-400">แสดงแบบเต็มพื้นที่ (Cover)</p>
                                </div>
                                {backgroundImage ? (
                                    <button
                                        type="button"
                                        onClick={() => setBackgroundImage(null)}
                                        className="rounded-md border border-rose-200 bg-rose-50 px-2 py-1 text-xs font-medium text-rose-700"
                                    >
                                        เอาออก
                                    </button>
                                ) : null}
                            </div>
                            {backgroundImage ? (
                                <img
                                    src={backgroundImage.src}
                                    alt=""
                                    className="mt-3 h-24 w-full rounded-lg border border-slate-200 object-cover"
                                />
                            ) : (
                                <button
                                    type="button"
                                    onClick={() => backgroundFileInputRef.current?.click()}
                                    className="mt-3 flex h-20 w-full items-center justify-center rounded-lg border border-dashed border-slate-300 bg-white text-xs text-slate-500 hover:border-amber-300 hover:bg-amber-50"
                                >
                                    เลือกรูปพื้นหลัง
                                </button>
                            )}
                        </div>

                        {selectedElement && (
                            <div className="mt-5 rounded-xl border border-slate-200 bg-slate-50 p-3">
                                <div className="flex items-center justify-between">
                                    <p className="text-sm font-semibold text-slate-800">
                                        {selectedElement.type === 'text' ? 'ตั้งค่าข้อความ' : 'ตั้งค่ารูป'}
                                    </p>
                                    <span className="text-[11px] text-slate-400">วัตถุที่เลือก</span>
                                </div>

                                {selectedElement.type === 'text' && (
                                    <div className="mt-3 space-y-3">
                                        <textarea
                                            rows="3"
                                            value={selectedElement.text}
                                            onChange={(event) => patchSelected({ text: event.target.value })}
                                            className="w-full rounded-md border-slate-300 text-sm"
                                        />
                                        {/* Font Size & Stepper Controls */}
                                        <div className="space-y-2 rounded-lg border border-slate-200 bg-slate-50 p-2.5">
                                            <div className="flex items-center justify-between text-xs font-semibold text-slate-700">
                                                <span>ขนาดตัวอักษร</span>
                                                <span className="rounded bg-blue-100 px-1.5 py-0.5 font-mono text-[11px] text-blue-700">
                                                    {Math.round(selectedElement.fontSize)} px
                                                </span>
                                            </div>

                                            {/* Stepper Buttons + Slider + Number Input */}
                                            <div className="flex items-center gap-1.5">
                                                <button
                                                    type="button"
                                                    onClick={() => patchSelected({
                                                        fontSize: Math.max(10, Math.round(selectedElement.fontSize) - (selectedElement.fontSize > 40 ? 4 : 2)),
                                                    })}
                                                    className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-slate-300 bg-white text-slate-700 shadow-xs hover:bg-slate-100 active:scale-95"
                                                    title="ลดขนาดตัวอักษร (-)"
                                                >
                                                    <Minus className="h-4 w-4" />
                                                </button>

                                                <input
                                                    type="range"
                                                    min="12"
                                                    max="200"
                                                    step="1"
                                                    value={Math.round(selectedElement.fontSize)}
                                                    onChange={(event) => patchSelected({
                                                        fontSize: Math.max(10, Number(event.target.value) || 10),
                                                    })}
                                                    className="h-2 flex-1 cursor-pointer accent-blue-600"
                                                    title="เลื่อนเพื่อปรับขนาด"
                                                />

                                                <button
                                                    type="button"
                                                    onClick={() => patchSelected({
                                                        fontSize: Math.min(300, Math.round(selectedElement.fontSize) + (selectedElement.fontSize >= 40 ? 4 : 2)),
                                                    })}
                                                    className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-slate-300 bg-white text-slate-700 shadow-xs hover:bg-slate-100 active:scale-95"
                                                    title="เพิ่มขนาดตัวอักษร (+)"
                                                >
                                                    <Plus className="h-4 w-4" />
                                                </button>

                                                <input
                                                    type="number"
                                                    min="10"
                                                    max="300"
                                                    value={Math.round(selectedElement.fontSize)}
                                                    onChange={(event) => patchSelected({
                                                        fontSize: Math.max(10, Number(event.target.value) || 10),
                                                    })}
                                                    className="h-8 w-16 shrink-0 rounded-md border border-slate-300 bg-white px-1 text-center font-mono text-xs text-slate-800 shadow-xs focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                                                />
                                            </div>

                                            {/* Quick Preset Chips */}
                                            <div className="flex items-center gap-1 pt-1">
                                                <span className="text-[10px] text-slate-400 shrink-0">ลัด:</span>
                                                <div className="flex flex-wrap gap-1">
                                                    {[
                                                        { label: 'เล็ก', size: 24 },
                                                        { label: 'ปกติ', size: 36 },
                                                        { label: 'กลาง', size: 48 },
                                                        { label: 'ใหญ่', size: 64 },
                                                        { label: 'หัวข้อ', size: 84 },
                                                    ].map((preset) => (
                                                        <button
                                                            key={preset.size}
                                                            type="button"
                                                            onClick={() => patchSelected({ fontSize: preset.size })}
                                                            className={`rounded px-1.5 py-0.5 text-[11px] font-medium transition ${
                                                                Math.round(selectedElement.fontSize) === preset.size
                                                                    ? 'bg-blue-600 text-white shadow-xs'
                                                                    : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-100'
                                                            }`}
                                                        >
                                                            {preset.label} ({preset.size})
                                                        </button>
                                                    ))}
                                                </div>
                                            </div>
                                        </div>

                                        {/* Color & Font Row */}
                                        <div className="grid grid-cols-2 gap-2">
                                            <label className="block text-xs text-slate-600">
                                                สีข้อความ
                                                <div className="mt-1 flex items-center gap-1.5">
                                                    <input
                                                        type="color"
                                                        value={selectedElement.fill}
                                                        onChange={(event) => patchSelected({ fill: event.target.value })}
                                                        className="h-8 w-9 cursor-pointer rounded border border-slate-300 p-0.5"
                                                    />
                                                    <input
                                                        type="text"
                                                        value={selectedElement.fill}
                                                        onChange={(event) => patchSelected({ fill: event.target.value })}
                                                        className="h-8 w-full rounded-md border border-slate-300 px-2 font-mono text-xs text-slate-700"
                                                    />
                                                </div>
                                            </label>

                                            <label className="block text-xs text-slate-600">
                                                ฟอนต์
                                                <select
                                                    value={selectedElement.fontFamily}
                                                    onChange={(event) => patchSelected({ fontFamily: event.target.value })}
                                                    className="mt-1 h-8 w-full rounded-md border-slate-300 text-xs"
                                                >
                                                    {FONT_FAMILIES.map((font) => (
                                                        <option key={font} value={font}>{font}</option>
                                                    ))}
                                                </select>
                                            </label>
                                        </div>

                                        <div className="grid grid-cols-2 gap-2">
                                            <button
                                                type="button"
                                                onClick={() => patchSelected({
                                                    fontStyle: selectedElement.fontStyle?.includes('bold')
                                                        ? selectedElement.fontStyle.replace('bold', '').trim() || 'normal'
                                                        : `${selectedElement.fontStyle === 'normal' ? '' : selectedElement.fontStyle || ''} bold`.trim(),
                                                })}
                                                className="rounded-md border border-slate-200 bg-white px-2 py-2 text-xs font-bold text-slate-700"
                                            >
                                                ตัวหนา
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => patchSelected({
                                                    fontStyle: selectedElement.fontStyle?.includes('italic')
                                                        ? selectedElement.fontStyle.replace('italic', '').trim() || 'normal'
                                                        : `${selectedElement.fontStyle === 'normal' ? '' : selectedElement.fontStyle || ''} italic`.trim(),
                                                })}
                                                className="rounded-md border border-slate-200 bg-white px-2 py-2 text-xs italic text-slate-700"
                                            >
                                                ตัวเอียง
                                            </button>
                                        </div>
                                        <div className="grid grid-cols-3 gap-1">
                                            {['left', 'center', 'right'].map((align) => (
                                                <button
                                                    key={align}
                                                    type="button"
                                                    onClick={() => patchSelected({ align })}
                                                    className={`rounded-md border px-2 py-1.5 text-xs ${
                                                        selectedElement.align === align
                                                            ? 'border-blue-400 bg-blue-50 text-blue-700'
                                                            : 'border-slate-200 bg-white text-slate-600'
                                                    }`}
                                                >
                                                    {align === 'left' ? 'ซ้าย' : align === 'center' ? 'กลาง' : 'ขวา'}
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                )}

                                <label className="mt-3 block text-xs text-slate-600">
                                    ความทึบ {Math.round((selectedElement.opacity ?? 1) * 100)}%
                                    <input
                                        type="range"
                                        min="0.1"
                                        max="1"
                                        step="0.05"
                                        value={selectedElement.opacity ?? 1}
                                        onChange={(event) => patchSelected({ opacity: Number(event.target.value) })}
                                        className="mt-1 w-full"
                                    />
                                </label>

                                <div className="mt-3 grid grid-cols-2 gap-2">
                                    <button
                                        type="button"
                                        onClick={duplicateSelected}
                                        className="inline-flex items-center justify-center gap-1 rounded-md border border-slate-200 bg-white px-2 py-2 text-xs text-slate-700"
                                    >
                                        <Copy className="h-3.5 w-3.5" /> ทำสำเนา
                                    </button>
                                    <button
                                        type="button"
                                        onClick={deleteSelected}
                                        className="inline-flex items-center justify-center gap-1 rounded-md border border-rose-200 bg-rose-50 px-2 py-2 text-xs text-rose-700"
                                    >
                                        <Trash2 className="h-3.5 w-3.5" /> ลบ
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => moveSelected('front')}
                                        className="inline-flex items-center justify-center gap-1 rounded-md border border-slate-200 bg-white px-2 py-2 text-xs text-slate-700"
                                    >
                                        <BringToFront className="h-3.5 w-3.5" /> ไว้ด้านหน้า
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => moveSelected('back')}
                                        className="inline-flex items-center justify-center gap-1 rounded-md border border-slate-200 bg-white px-2 py-2 text-xs text-slate-700"
                                    >
                                        <SendToBack className="h-3.5 w-3.5" /> ไว้ด้านหลัง
                                    </button>
                                </div>
                            </div>
                        )}

                        <div className="mt-5">
                            <div className="flex items-center justify-between">
                                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    คลังรูปภาพ
                                </p>
                                <span className="text-[11px] text-slate-400">{localMedia.length} รูป</span>
                            </div>
                            <div className="mt-2 grid max-h-72 grid-cols-2 gap-2 overflow-y-auto">
                                {localMedia
                                    .filter((media) => media.mime_type?.startsWith('image/'))
                                    .map((media) => (
                                        <div
                                            key={media.id}
                                            className="overflow-hidden rounded-lg border border-slate-200 bg-white"
                                        >
                                            <img
                                                src={mediaUrl(media)}
                                                alt=""
                                                className="aspect-square w-full object-cover"
                                                loading="lazy"
                                            />
                                            <div className="grid grid-cols-2 gap-1 p-1.5">
                                                <button
                                                    type="button"
                                                    onClick={() => addImageFromUrl(mediaUrl(media), media.id)}
                                                    className="rounded bg-blue-50 px-2 py-1.5 text-[11px] font-semibold text-blue-700 hover:bg-blue-100"
                                                >
                                                    เพิ่มในภาพ
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => setBackgroundFromMedia(media)}
                                                    className="rounded bg-amber-50 px-2 py-1.5 text-[11px] font-semibold text-amber-700 hover:bg-amber-100"
                                                >
                                                    พื้นหลัง
                                                </button>
                                            </div>
                                        </div>
                                    ))}
                            </div>
                        </div>
                    </aside>
                    <main className="flex min-h-0 flex-1 flex-col">
                        <div className="flex-1 overflow-auto p-3 sm:p-5">
                            <div
                                className="mx-auto w-fit rounded-xl bg-slate-300 p-3 shadow-inner"
                                onMouseDown={(event) => {
                                    if (event.target === event.currentTarget) setSelectedId(null);
                                }}
                            >
                                <Stage
                                    ref={stageRef}
                                    width={canvasSize.width * displayScale}
                                    height={canvasSize.height * displayScale}
                                    scaleX={displayScale}
                                    scaleY={displayScale}
                                    onMouseDown={(event) => {
                                        if (event.target === event.target.getStage()) {
                                            setSelectedId(null);
                                        }
                                    }}
                                    onTouchStart={(event) => {
                                        if (event.target === event.target.getStage()) {
                                            setSelectedId(null);
                                        }
                                    }}
                                >
                                    <Layer>
                                        <Rect
                                            x={0}
                                            y={0}
                                            width={canvasSize.width}
                                            height={canvasSize.height}
                                            fill={backgroundColor}
                                            listening={false}
                                        />
                                        {backgroundCanvasImage && backgroundCrop && (
                                            <KonvaImage
                                                image={backgroundCanvasImage}
                                                x={0}
                                                y={0}
                                                width={canvasSize.width}
                                                height={canvasSize.height}
                                                crop={backgroundCrop}
                                                listening={false}
                                            />
                                        )}
                                        {elements.map((item) => (
                                            <EditorElement
                                                key={item.id}
                                                item={item}
                                                selected={item.id === selectedId}
                                                onSelect={() => setSelectedId(item.id)}
                                                onChange={updateElement}
                                            />
                                        ))}
                                    </Layer>
                                </Stage>
                            </div>
                        </div>

                        <footer className="flex flex-col gap-3 border-t border-slate-200 bg-white px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                            <div className="text-xs text-slate-500">
                                Canvas {canvasSize.width} × {canvasSize.height}px • ลากวัตถุเพื่อจัดตำแหน่ง
                            </div>
                            <div className="flex justify-end gap-2">
                                <button
                                    type="button"
                                    onClick={onClose}
                                    className="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                                >
                                    ยกเลิก
                                </button>
                                <button
                                    type="button"
                                    onClick={saveDesign}
                                    disabled={isSaving || isUploading}
                                    className="inline-flex items-center gap-2 rounded-md bg-[#000080] px-5 py-2 text-sm font-bold text-white hover:bg-blue-900 disabled:opacity-50"
                                >
                                    <Save className="h-4 w-4" />
                                    {isSaving ? 'กำลังบันทึก...' : 'บันทึกรูป'}
                                </button>
                            </div>
                        </footer>
                    </main>
                </div>
            </div>
        </div>
    );
}
