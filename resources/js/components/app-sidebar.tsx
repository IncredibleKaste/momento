import { Link, usePage } from '@inertiajs/react';
import { House, UserRound, UsersRound, X } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Button } from '@/components/ui/button';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as following } from '@/routes/following';
import { show as profile } from '@/routes/members';
import type { NavItem } from '@/types';

export function AppSidebar() {
    const { auth } = usePage().props;
    const { setOpenMobile } = useSidebar();
    const mainNavItems: NavItem[] = [
        { title: 'Home', href: dashboard(), icon: House },
        { title: 'Profile', href: profile(auth.user.id), icon: UserRound },
        { title: 'Following', href: following(), icon: UsersRound },
    ];

    return (
        <Sidebar collapsible="icon" className="momento-rail">
            <SidebarHeader className="flex-row items-center p-0">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <Button
                            variant="ghost"
                            className="rail-link relative h-11 w-full justify-start gap-3 overflow-hidden px-2"
                            asChild
                        >
                            <Link
                                href={dashboard()}
                                aria-label="Momento home"
                                onClick={() => setOpenMobile(false)}
                                prefetch
                            >
                                <AppLogo />
                            </Link>
                        </Button>
                    </SidebarMenuItem>
                </SidebarMenu>
                <Button
                    variant="ghost"
                    className="size-11 shrink-0 md:hidden"
                    aria-label="Close navigation"
                    onClick={() => setOpenMobile(false)}
                >
                    <X />
                </Button>
            </SidebarHeader>

            <SidebarContent className="pt-10">
                <nav aria-label="Main navigation" id="main-navigation">
                    <NavMain items={mainNavItems} />
                </nav>
            </SidebarContent>

            <SidebarFooter className="p-0">
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
