import TemplateForm from '@/Components/TemplateForm';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';

export default function Edit({ auth, template, mediaList, pdfList = [], googleDriveConfig = {} }) {
    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-gray-800 leading-tight">แก้ไขเทมเพลต: {template.name}</h2>}
        >
            <Head title="Edit Template" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    <TemplateForm mode="edit" template={template} mediaList={mediaList} pdfList={pdfList} googleDriveConfig={googleDriveConfig} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
