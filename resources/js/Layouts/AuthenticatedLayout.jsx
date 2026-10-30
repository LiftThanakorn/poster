import ApplicationLogo from '@/Components/ApplicationLogo';
import Dropdown from '@/Components/Dropdown';
import NavLink from '@/Components/NavLink';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink';
import { Link, usePage } from '@inertiajs/react';
import {
    FileText,
    FolderOpen,
    LayoutDashboard,
    LayoutTemplate,
    Newspaper,
    Plus,
    Share2,
} from 'lucide-react';
import { useState, useEffect } from 'react';
import Swal from 'sweetalert2';

export default function AuthenticatedLayout({ header, children }) {
    const user = usePage().props.auth.user;
    const { flash } = usePage().props;
    const [showingNavigationDropdown, setShowingNavigationDropdown] =
        useState(false);

    useEffect(() => {
        if (!flash?.id) return;

        if (flash.success) {
            Swal.fire({
                icon: 'success',
                title: 'สำเร็จ',
                text: flash.success,
                toast: true,
                position: 'top-end',
                timer: 2600,
                timerProgressBar: true,
                showConfirmButton: false,
            });
            return;
        }

        if (flash.error) {
            Swal.fire({
                icon: 'error',
                title: 'เกิดข้อผิดพลาด',
                text: flash.error,
                confirmButtonColor: '#000080',
            });
        }
    }, [flash?.id]);

    return (
        <div className="min-h-screen bg-gray-100">
            <nav className="border-b border-gray-100 bg-white">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="flex h-16 justify-between">
                        <div className="flex">
                            <div className="flex shrink-0 items-center">
                                <Link href="/">
                                    <ApplicationLogo className="block h-9 w-auto fill-current text-gray-800" />
                                </Link>
                            </div>

                            <div className="hidden space-x-7 sm:-my-px sm:ms-10 sm:flex">
                                <NavLink
                                    href={route('dashboard')}
                                    active={route().current('dashboard')}
                                >
                                    <LayoutDashboard className="h-4 w-4 mr-1.5 shrink-0" />
                                    <span>แดชบอร์ด</span>
                                </NavLink>
                                <NavLink
                                    href={route('posts.index')}
                                    active={route().current('posts.*')}
                                >
                                    <Newspaper className="h-4 w-4 mr-1.5 shrink-0" />
                                    <span>ข่าวสาร (Posts)</span>
                                </NavLink>
                                <NavLink
                                    href={route('media.index')}
                                    active={route().current('media.*')}
                                >
                                    <FolderOpen className="h-4 w-4 mr-1.5 shrink-0" />
                                    <span>คลังไฟล์ (Media)</span>
                                </NavLink>
                                <NavLink
                                    href={route('announcements.index')}
                                    active={route().current('announcements.*')}
                                >
                                    <FileText className="h-4 w-4 mr-1.5 shrink-0" />
                                    <span>สารบัญประกาศ</span>
                                </NavLink>
                                <NavLink
                                    href={route('templates.index')}
                                    active={route().current('templates.*')}
                                >
                                    <LayoutTemplate className="h-4 w-4 mr-1.5 shrink-0" />
                                    <span>เทมเพลต (Templates)</span>
                                </NavLink>
                                <NavLink
                                    href={route('settings.integrations')}
                                    active={route().current('settings.*')}
                                >
                                    <Share2 className="h-4 w-4 mr-1.5 shrink-0" />
                                    <span>การเชื่อมต่อ (Integrations)</span>
                                </NavLink>
                            </div>
                        </div>

                        <div className="hidden sm:ms-6 sm:flex sm:items-center sm:gap-3">
                            <Link
                                href={route('posts.create')}
                                className="inline-flex items-center gap-1.5 rounded-lg bg-[#000080] px-3.5 py-1.5 text-xs font-semibold text-white shadow-xs transition hover:bg-blue-900"
                            >
                                <Plus className="h-3.5 w-3.5" />
                                <span>เขียนข่าวใหม่</span>
                            </Link>

                            <div className="relative ms-2">
                                <Dropdown>
                                    <Dropdown.Trigger>
                                        <span className="inline-flex rounded-md">
                                            <button
                                                type="button"
                                                className="inline-flex items-center rounded-md border border-transparent bg-white px-3 py-2 text-sm font-medium leading-4 text-gray-500 transition duration-150 ease-in-out hover:text-gray-700 focus:outline-none"
                                            >
                                                {user.name}

                                                <svg
                                                    className="-me-0.5 ms-2 h-4 w-4"
                                                    xmlns="http://www.w3.org/2000/svg"
                                                    viewBox="0 0 20 20"
                                                    fill="currentColor"
                                                >
                                                    <path
                                                        fillRule="evenodd"
                                                        d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                        clipRule="evenodd"
                                                    />
                                                </svg>
                                            </button>
                                        </span>
                                    </Dropdown.Trigger>

                                    <Dropdown.Content>
                                        <Dropdown.Link
                                            href={route('profile.edit')}
                                        >
                                            Profile
                                        </Dropdown.Link>
                                        <Dropdown.Link
                                            href={route('logout')}
                                            method="post"
                                            as="button"
                                        >
                                            Log Out
                                        </Dropdown.Link>
                                    </Dropdown.Content>
                                </Dropdown>
                            </div>
                        </div>

                        <div className="-me-2 flex items-center sm:hidden">
                            <button
                                onClick={() =>
                                    setShowingNavigationDropdown(
                                        (previousState) => !previousState,
                                    )
                                }
                                className="inline-flex items-center justify-center rounded-md p-2 text-gray-400 transition duration-150 ease-in-out hover:bg-gray-100 hover:text-gray-500 focus:bg-gray-100 focus:text-gray-500 focus:outline-none"
                            >
                                <svg
                                    className="h-6 w-6"
                                    stroke="currentColor"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        className={
                                            !showingNavigationDropdown
                                                ? 'inline-flex'
                                                : 'hidden'
                                        }
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        strokeWidth="2"
                                        d="M4 6h16M4 12h16M4 18h16"
                                    />
                                    <path
                                        className={
                                            showingNavigationDropdown
                                                ? 'inline-flex'
                                                : 'hidden'
                                        }
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        strokeWidth="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <div
                    className={
                        (showingNavigationDropdown ? 'block' : 'hidden') +
                        ' sm:hidden'
                    }
                >
                    <div className="space-y-1 pb-3 pt-2">
                        <div className="px-3 pb-2">
                            <Link
                                href={route('posts.create')}
                                className="flex w-full items-center justify-center gap-1.5 rounded-lg bg-[#000080] py-2 text-xs font-semibold text-white shadow-xs"
                            >
                                <Plus className="h-4 w-4" />
                                <span>เขียนข่าวใหม่</span>
                            </Link>
                        </div>

                        <ResponsiveNavLink
                            href={route('dashboard')}
                            active={route().current('dashboard')}
                        >
                            <span className="flex items-center gap-2">
                                <LayoutDashboard className="h-4 w-4 text-slate-500" />
                                <span>แดชบอร์ด</span>
                            </span>
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            href={route('posts.index')}
                            active={route().current('posts.*')}
                        >
                            <span className="flex items-center gap-2">
                                <Newspaper className="h-4 w-4 text-slate-500" />
                                <span>ข่าวสาร (Posts)</span>
                            </span>
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            href={route('media.index')}
                            active={route().current('media.*')}
                        >
                            <span className="flex items-center gap-2">
                                <FolderOpen className="h-4 w-4 text-slate-500" />
                                <span>คลังไฟล์ (Media)</span>
                            </span>
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            href={route('announcements.index')}
                            active={route().current('announcements.*')}
                        >
                            <span className="flex items-center gap-2">
                                <FileText className="h-4 w-4 text-slate-500" />
                                <span>สารบัญประกาศ</span>
                            </span>
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            href={route('templates.index')}
                            active={route().current('templates.*')}
                        >
                            <span className="flex items-center gap-2">
                                <LayoutTemplate className="h-4 w-4 text-slate-500" />
                                <span>เทมเพลต (Templates)</span>
                            </span>
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            href={route('settings.integrations')}
                            active={route().current('settings.*')}
                        >
                            <span className="flex items-center gap-2">
                                <Share2 className="h-4 w-4 text-slate-500" />
                                <span>การเชื่อมต่อ (Integrations)</span>
                            </span>
                        </ResponsiveNavLink>
                    </div>

                    <div className="border-t border-gray-200 pb-1 pt-4">
                        <div className="px-4">
                            <div className="text-base font-medium text-gray-800">
                                {user.name}
                            </div>
                            <div className="text-sm font-medium text-gray-500">
                                {user.email}
                            </div>
                        </div>

                        <div className="mt-3 space-y-1">
                            <ResponsiveNavLink href={route('profile.edit')}>
                                Profile
                            </ResponsiveNavLink>
                            <ResponsiveNavLink
                                method="post"
                                href={route('logout')}
                                as="button"
                            >
                                Log Out
                            </ResponsiveNavLink>
                        </div>
                    </div>
                </div>
            </nav>

            {header && (
                <header className="bg-white shadow">
                    <div className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                        {header}
                    </div>
                </header>
            )}

            <main>{children}</main>
        </div>
    );
}
