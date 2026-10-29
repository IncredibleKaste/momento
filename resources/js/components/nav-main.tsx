import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import {
    SidebarGroup,
    SidebarMenu,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { NavItem } from '@/types';

export function NavMain({ items }: { items: NavItem[] }) {
    const { isCurrentUrl } = useCurrentUrl();
    const { setOpenMobile } = useSidebar();

    return (
        <SidebarGroup className="p-0">
            <SidebarMenu>
                {items.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        <Button
                            asChild
                            variant="ghost"
                            className="rail-link relative h-11 w-full justify-start gap-3 overflow-hidden px-2"
                        >
                            <Link
                                href={item.href}
                                aria-label={item.title}
                                aria-current={
                                    isCurrentUrl(item.href) ? 'page' : undefined
                                }
                                onClick={() => setOpenMobile(false)}
                                prefetch
                            >
                                <span className="flex size-8 shrink-0 items-center justify-center">
                                    {item.icon && <item.icon />}
                                </span>
                                <span className="rail-label">{item.title}</span>
                            </Link>
                        </Button>
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );
}
