import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import PostForm from '@/Components/PostForm';

export default function Create({ auth, templates, mediaList, folders = [], categories = [], wpCategories, googleDriveConfig = {}, initialPdfMediaId = null }) {
    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-gray-800 leading-tight">สร้างโพสต์ใหม่</h2>}
        >
            <Head title="Create Post" />
            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    <PostForm 
                        mode="create"
                        templates={templates}
                        mediaList={mediaList}
                        folders={folders}
                        categories={categories}
                        wpCategories={wpCategories}
                        googleDriveConfig={googleDriveConfig}
                        initialPdfMediaId={initialPdfMediaId}
                        submitUrl={route('posts.store')}
                        submitMethod="post"
                    />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}