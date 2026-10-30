import React, { useEffect, useCallback } from 'react';
import { useEditor, EditorContent } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import {
    Bold,
    Italic,
    Underline as UnderlineIcon,
    Heading2,
    Heading3,
    List,
    ListOrdered,
    Quote,
    Link as LinkIcon,
    Undo,
    Redo,
    RemoveFormatting,
    Unlink
} from 'lucide-react';

const MenuBar = ({ editor }) => {
    const setLink = useCallback(() => {
        if (!editor) return;

        const previousUrl = editor.getAttributes('link').href;
        const url = window.prompt('URL', previousUrl);

        // cancelled
        if (url === null) {
            return;
        }

        // empty
        if (url === '') {
            editor.chain().focus().extendMarkRange('link').unsetLink().run();
            return;
        }

        // update link
        editor.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
    }, [editor]);

    if (!editor) {
        return null;
    }

    const btnClass = "p-1.5 text-gray-600 hover:text-blue-600 hover:bg-blue-50 rounded transition-colors";
    const activeClass = "p-1.5 text-blue-700 bg-blue-100 rounded transition-colors";

    return (
        <div className="flex flex-wrap items-center gap-1 p-2 border-b border-gray-200 bg-gray-50 rounded-t-md">
            <button
                type="button"
                onClick={() => editor.chain().focus().toggleHeading({ level: 2 }).run()}
                className={editor.isActive('heading', { level: 2 }) ? activeClass : btnClass}
                title="หัวข้อขนาดใหญ่ (Heading 2)"
            >
                <Heading2 size={18} />
            </button>
            <button
                type="button"
                onClick={() => editor.chain().focus().toggleHeading({ level: 3 }).run()}
                className={editor.isActive('heading', { level: 3 }) ? activeClass : btnClass}
                title="หัวข้อย่อย (Heading 3)"
            >
                <Heading3 size={18} />
            </button>
            <div className="w-px h-6 bg-gray-300 mx-1"></div>
            <button
                type="button"
                onClick={() => editor.chain().focus().toggleBold().run()}
                disabled={!editor.can().chain().focus().toggleBold().run()}
                className={editor.isActive('bold') ? activeClass : btnClass}
                title="ตัวหนา (Bold)"
            >
                <Bold size={18} />
            </button>
            <button
                type="button"
                onClick={() => editor.chain().focus().toggleItalic().run()}
                disabled={!editor.can().chain().focus().toggleItalic().run()}
                className={editor.isActive('italic') ? activeClass : btnClass}
                title="ตัวเอียง (Italic)"
            >
                <Italic size={18} />
            </button>
            <button
                type="button"
                onClick={() => editor.chain().focus().toggleUnderline().run()}
                className={editor.isActive('underline') ? activeClass : btnClass}
                title="ขีดเส้นใต้ (Underline)"
            >
                <UnderlineIcon size={18} />
            </button>
            <div className="w-px h-6 bg-gray-300 mx-1"></div>
            <button
                type="button"
                onClick={() => editor.chain().focus().toggleBulletList().run()}
                className={editor.isActive('bulletList') ? activeClass : btnClass}
                title="รายการแบบสัญลักษณ์ (Bullet List)"
            >
                <List size={18} />
            </button>
            <button
                type="button"
                onClick={() => editor.chain().focus().toggleOrderedList().run()}
                className={editor.isActive('orderedList') ? activeClass : btnClass}
                title="รายการแบบตัวเลข (Numbered List)"
            >
                <ListOrdered size={18} />
            </button>
            <button
                type="button"
                onClick={() => editor.chain().focus().toggleBlockquote().run()}
                className={editor.isActive('blockquote') ? activeClass : btnClass}
                title="ข้อความอ้างอิง (Blockquote)"
            >
                <Quote size={18} />
            </button>
            <div className="w-px h-6 bg-gray-300 mx-1"></div>
            <button
                type="button"
                onClick={setLink}
                className={editor.isActive('link') ? activeClass : btnClass}
                title="แทรกลิงก์ (Link)"
            >
                <LinkIcon size={18} />
            </button>
            <button
                type="button"
                onClick={() => editor.chain().focus().unsetLink().run()}
                disabled={!editor.isActive('link')}
                className={btnClass}
                title="ลบลิงก์ (Unlink)"
            >
                <Unlink size={18} />
            </button>
            <div className="w-px h-6 bg-gray-300 mx-1"></div>
            <button
                type="button"
                onClick={() => editor.chain().focus().clearNodes().unsetAllMarks().run()}
                className={btnClass}
                title="ล้างรูปแบบข้อความ (Clear Formatting)"
            >
                <RemoveFormatting size={18} />
            </button>
            <div className="flex-1"></div>
            <button
                type="button"
                onClick={() => editor.chain().focus().undo().run()}
                disabled={!editor.can().chain().focus().undo().run()}
                className={btnClass}
                title="เลิกทำ (Undo)"
            >
                <Undo size={18} />
            </button>
            <button
                type="button"
                onClick={() => editor.chain().focus().redo().run()}
                disabled={!editor.can().chain().focus().redo().run()}
                className={btnClass}
                title="ทำซ้ำ (Redo)"
            >
                <Redo size={18} />
            </button>
        </div>
    );
};

export default function RichTextEditor({ value, onChange }) {
    const editor = useEditor({
        extensions: [
            StarterKit.configure({
                link: {
                    openOnClick: false,
                    HTMLAttributes: {
                        class: 'text-blue-600 underline',
                    },
                },
            }),
        ],
        content: value,
        editorProps: {
            attributes: {
                class: 'prose prose-slate prose-sm sm:prose-base max-w-none focus:outline-none min-h-[220px] p-4 bg-white text-slate-800 leading-relaxed [&_h2]:text-xl [&_h2]:font-bold [&_h2]:mt-4 [&_h2]:mb-2 [&_h3]:text-lg [&_h3]:font-semibold [&_h3]:mt-3 [&_h3]:mb-1.5 [&_p]:my-2.5 [&_p]:text-sm sm:[&_p]:text-base [&_p]:leading-relaxed',
            },
        },
        onUpdate: ({ editor }) => {
            onChange(editor.getHTML());
        },
    });

    // Update content when value from prop changes and it's different from editor state
    useEffect(() => {
        if (editor && value !== editor.getHTML()) {
            editor.commands.setContent(value, false);
        }
    }, [value, editor]);

    return (
        <div className="border border-gray-300 rounded-md shadow-sm overflow-hidden flex flex-col">
            <MenuBar editor={editor} />
            <div className="flex-1 overflow-y-auto max-h-[500px]">
                <EditorContent editor={editor} />
            </div>
        </div>
    );
}
