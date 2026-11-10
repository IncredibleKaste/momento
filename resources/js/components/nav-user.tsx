import { usePage } from '@inertiajs/react';
import { useId } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { UserMenuContent } from '@/components/user-menu-content';

export function NavUser() {
    const { auth } = usePage().props;
    const { isMobile, setOpenMobile } = useSidebar();
    const spectrumId = useId();

    if (!auth.user) {
        return null;
    }

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            variant="ghost"
                            className="rail-link rail-user"
                            aria-label={`Settings, ${auth.user.name}'s account menu`}
                            data-test="sidebar-menu-button"
                        >
                            <span className="rail-icon" aria-hidden="true">
                                <svg
                                    viewBox="0 0 24 24"
                                    className="rail-settings-icon"
                                >
                                    <defs>
                                        <linearGradient id={spectrumId}>
                                            <stop
                                                offset="0"
                                                stopColor="#648b49"
                                            />
                                            <stop
                                                offset=".2"
                                                stopColor="#d9b350"
                                            />
                                            <stop
                                                offset=".4"
                                                stopColor="#d87c38"
                                            />
                                            <stop
                                                offset=".6"
                                                stopColor="#be5350"
                                            />
                                            <stop
                                                offset=".8"
                                                stopColor="#87619b"
                                            />
                                            <stop
                                                offset="1"
                                                stopColor="#4c85a7"
                                            />
                                        </linearGradient>
                                    </defs>
                                    <path
                                        d="M4 6h16M4 12h16M4 18h16"
                                        stroke={`url(#${spectrumId})`}
                                    />
                                </svg>
                            </span>
                            <span className="rail-label">Settings</span>
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="w-(--radix-dropdown-menu-trigger-width) min-w-56 rounded-lg"
                        align="end"
                        side={isMobile ? 'bottom' : 'right'}
                    >
                        <UserMenuContent
                            user={auth.user}
                            onNavigate={() => setOpenMobile(false)}
                        />
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
