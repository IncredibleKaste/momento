import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    const [decorationPaused, setDecorationPaused] = useState(false);
    const isChat = usePage().component === 'messages/index';

    return (
        <AppShell variant="sidebar">
            <a
                href="#main-content"
                className="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-md focus:bg-background focus:px-4 focus:py-3 focus:text-foreground"
            >
                Skip to content
            </a>
            <AppSidebar />
            <div
                className="app-corner-ribbon"
                data-paused={decorationPaused}
                aria-hidden="true"
            >
                <div className="app-ribbon-art">
                    <span className="app-ribbon-track">
                        <span className="app-ribbon-band" />
                    </span>
                    <span className="app-ribbon-track">
                        <span className="app-ribbon-band" />
                    </span>
                    <span className="app-ribbon-track">
                        <span className="app-ribbon-band" />
                    </span>
                    <span className="app-ribbon-track">
                        <span className="app-ribbon-band" />
                    </span>
                    <span className="app-ribbon-track">
                        <span className="app-ribbon-band" />
                    </span>
                </div>
            </div>
            <AppContent
                variant="sidebar"
                id="main-content"
                tabIndex={-1}
                className={`min-w-0 overflow-x-clip ${isChat ? 'momento-chat-main' : ''}`}
            >
                <div
                    className={`momento-page mx-auto flex min-h-svh w-full flex-col ${isChat ? 'momento-chat-page' : ''}`}
                >
                    <AppSidebarHeader breadcrumbs={breadcrumbs} />
                    <div className="momento-page-body flex flex-1 flex-col">
                        {children}
                    </div>
                    <footer className="momento-footer flex flex-wrap items-center justify-between gap-4 text-xs text-muted-foreground">
                        <span>© {new Date().getFullYear()} Momento</span>
                        <button
                            type="button"
                            className="decoration-toggle min-h-11 underline underline-offset-4"
                            aria-pressed={decorationPaused}
                            onClick={() =>
                                setDecorationPaused(!decorationPaused)
                            }
                        >
                            {decorationPaused
                                ? 'Resume decoration'
                                : 'Pause decoration'}
                        </button>
                    </footer>
                </div>
            </AppContent>
        </AppShell>
    );
}
