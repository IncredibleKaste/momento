import { Link, router } from '@inertiajs/react';
import { LogOut, Monitor, Moon, Sun } from 'lucide-react';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { useAppearance } from '@/hooks/use-appearance';
import { logout } from '@/routes';
import { edit as editAppearance } from '@/routes/appearance';
import type { User } from '@/types';

type Props = {
    user: User;
};

export function UserMenuContent({ user }: Props) {
    const cleanup = useMobileNavigation();
    const { resolvedAppearance, updateAppearance } = useAppearance();

    const handleLogout = () => {
        cleanup();
        router.flushAll();
    };

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                    <UserInfo user={user} showEmail={true} />
                </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                <DropdownMenuItem
                    onSelect={() =>
                        updateAppearance(
                            resolvedAppearance === 'dark' ? 'light' : 'dark',
                        )
                    }
                >
                    {resolvedAppearance === 'dark' ? <Sun /> : <Moon />}
                    Switch to {resolvedAppearance === 'dark'
                        ? 'light'
                        : 'dark'}{' '}
                    appearance
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <Link href={editAppearance()} onClick={cleanup}>
                        <Monitor />
                        Appearance settings
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <Link
                        className="block w-full cursor-pointer"
                        href={logout()}
                        as="button"
                        onClick={handleLogout}
                        data-test="logout-button"
                    >
                        <LogOut className="mr-2" />
                        Log out
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuGroup>
        </>
    );
}
