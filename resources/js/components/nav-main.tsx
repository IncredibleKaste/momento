import { Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import {
    SidebarGroup,
    SidebarMenu,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useInitials } from '@/hooks/use-initials';
import type { NavItem } from '@/types';

export function NavMain({ items }: { items: NavItem[] }) {
    const { isCurrentUrl } = useCurrentUrl();
    const { setOpenMobile } = useSidebar();
    const { auth } = usePage().props;
    const getInitials = useInitials();

    return (
        <SidebarGroup className="p-0">
            <SidebarMenu className="rail-menu">
                {items.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        <Button asChild variant="ghost" className="rail-link">
                            <Link
                                href={item.href}
                                aria-label={item.title}
                                data-navigation-destination={item.title.toLowerCase()}
                                aria-current={
                                    isCurrentUrl(item.href) ? 'page' : undefined
                                }
                                onClick={() => setOpenMobile(false)}
                            >
                                <span className="rail-icon" aria-hidden="true">
                                    {item.title === 'Profile' ? (
                                        <span className="rail-profile-avatar">
                                            {getInitials(auth.user.name)}
                                        </span>
                                    ) : (
                                        item.icon && <item.icon />
                                    )}
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
