import TemplateForm from '@/Components/TemplateForm';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';

export default function Create({ auth, mediaList, pdfList = [], googleDriveConfig = {} }) {
    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-gray-800 leading-tight">สร้างเทมเพลตใหม่</h2>}
        >
            <Head title="Create Template" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    <TemplateForm mode="create" mediaList={mediaList} pdfList={pdfList} googleDriveConfig={googleDriveConfig} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
