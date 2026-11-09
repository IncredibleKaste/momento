import { Link } from '@inertiajs/react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import MomentoWordmark from '@/components/momento-wordmark';
import { SidebarTrigger, useSidebar } from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { openMobile } = useSidebar();

    return (
        <header className="momento-header flex shrink-0 items-center justify-between gap-4">
            <div className="flex items-center gap-2">
                <SidebarTrigger
                    className="size-11 md:hidden"
                    aria-label="Toggle navigation"
                    aria-expanded={openMobile}
                    aria-controls={openMobile ? 'main-navigation' : undefined}
                />
                <Link href={dashboard()} aria-label="Momento home">
                    <MomentoWordmark className="momento-wordmark" />
                </Link>
            </div>
            <div className="hidden sm:block">
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
        </header>
    );
}
