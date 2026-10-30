import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import PostForm from '@/Components/PostForm';

export default function Edit({ auth, post, templates, mediaList, folders = [], categories = [], wpCategories, googleDriveConfig = {} }) {
    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-gray-800 leading-tight">แก้ไขโพสต์</h2>}
        >
            <Head title="Edit Post" />
            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    <PostForm 
                        mode="edit"
                        initialData={{
                            title: post.title || '',
                            wp_content: post.wp_content || '',
                            fb_caption: post.fb_caption || '',
                            fb_comment: post.fb_comment || '',
                            fb_comment_2: post.fb_comment_2 || '',
                            action_buttons: post.action_buttons || [],
                            pdf_media_id: post.pdf_media_id || null,
                            pdfMedia: post.pdf_media || null,
                            drive_sync_enabled: Boolean(post.drive_sync_enabled),
                            drive_employee_category: post.drive_employee_category || post.pdf_media?.drive_employee_category || '',
                            drive_announcement_stage: post.drive_announcement_stage || post.pdf_media?.drive_announcement_stage || '',
                            drive_document_date: post.drive_document_date || post.pdf_media?.drive_document_date || '',
                            drive_position_title: post.drive_position_title || post.pdf_media?.drive_position_title || '',
                            channels: post.targets ? post.targets.map(t => t.channel) : ['wordpress', 'facebook'],
                            publish_action: post.status === 'scheduled' ? 'schedule' : (post.status === 'draft' ? 'draft' : 'publish'),
                            scheduled_at: post.scheduled_at ? new Date(post.scheduled_at).toISOString().slice(0,16) : '',
                            featured_image_id: post.featured_image_id || null,
                            image_editor_data: post.image_editor_data || null,
                            wp_category_id: post.wp_category_id || '',
                            status: post.status,
                            is_facebook_replacement: Boolean(post.replacement_of_post_id),
                            facebook_target_published: (post.targets || []).some(
                                (target) => target.channel === 'facebook' && Boolean(target.external_id),
                            ),
                        }}
                        templates={templates}
                        mediaList={mediaList}
                        folders={folders}
                        categories={categories}
                        wpCategories={wpCategories}
                        googleDriveConfig={googleDriveConfig}
                        submitUrl={route('posts.update', post.id)}
                        submitMethod="put"
                        postId={post.id}
                        allowAutosave={post.status === 'draft'}
                    />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
