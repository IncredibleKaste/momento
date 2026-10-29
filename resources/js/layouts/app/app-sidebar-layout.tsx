import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <a
                href="#main-content"
                className="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-md focus:bg-background focus:px-4 focus:py-3 focus:text-foreground"
            >
                Skip to content
            </a>
            <AppSidebar />
            <AppContent
                variant="sidebar"
                id="main-content"
                tabIndex={-1}
                className="min-w-0 overflow-x-clip"
            >
                <div className="mx-auto flex min-h-svh w-full max-w-4xl flex-col px-5 md:px-10">
                    <AppSidebarHeader breadcrumbs={breadcrumbs} />
                    <div className="flex flex-1 flex-col">{children}</div>
                    <footer className="py-6 text-xs text-muted-foreground">
                        © {new Date().getFullYear()} Momento
                    </footer>
                </div>
            </AppContent>
        </AppShell>
    );
}
