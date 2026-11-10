import { Link, usePage } from '@inertiajs/react';
import {
    Bookmark,
    PanelsTopLeft,
    ImagePlus,
    MessageCircle,
    Search,
    UsersRound,
    X,
} from 'lucide-react';
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
import { index as people } from '@/routes/people';
import { index as messages } from '@/routes/messages';
import { create as createPhotoPost } from '@/routes/photo-posts';
import { index as saved } from '@/routes/saved';
import type { NavItem } from '@/types';

export function AppSidebar() {
    const { auth } = usePage().props;
    const { setOpenMobile } = useSidebar();
    const mainNavItems: NavItem[] = [
        { title: 'Feed', href: dashboard(), icon: PanelsTopLeft },
        { title: 'Search', href: people(), icon: Search },
        { title: 'Following', href: following(), icon: UsersRound },
        { title: 'Create', href: createPhotoPost(), icon: ImagePlus },
        { title: 'Saved', href: saved(), icon: Bookmark },
        { title: 'Profile', href: profile(auth.user.id) },
        { title: 'Chat', href: messages(), icon: MessageCircle },
    ];

    return (
        <Sidebar collapsible="icon" className="momento-rail">
            <div id="main-navigation" className="momento-navigation">
                <SidebarHeader className="rail-header flex-row items-center p-0">
                    <SidebarMenu>
                        <SidebarMenuItem>
                            <Button
                                variant="ghost"
                                className="rail-link rail-brand"
                                asChild
                            >
                                <Link
                                    href={dashboard()}
                                    aria-label="Momento home"
                                    onClick={() => setOpenMobile(false)}
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

                <SidebarContent className="rail-content">
                    <nav aria-label="Main navigation">
                        <NavMain items={mainNavItems} />
                    </nav>
                </SidebarContent>

                <SidebarFooter className="p-0">
                    <NavUser />
                </SidebarFooter>
            </div>
        </Sidebar>
    );
}
