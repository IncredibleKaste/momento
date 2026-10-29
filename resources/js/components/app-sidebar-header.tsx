import { Breadcrumbs } from '@/components/breadcrumbs';
import { SidebarTrigger, useSidebar } from '@/components/ui/sidebar';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { isMobile, open, openMobile } = useSidebar();

    return (
        <header className="flex min-h-24 shrink-0 items-center justify-between gap-4 py-6">
            <div className="flex items-center gap-2">
                <SidebarTrigger
                    className="size-11"
                    aria-label="Toggle navigation"
                    aria-expanded={isMobile ? openMobile : open}
                    aria-controls="main-navigation"
                />
                <span className="font-serif text-3xl tracking-tight">
                    Momento
                </span>
            </div>
            <div className="hidden sm:block">
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
        </header>
    );
}
