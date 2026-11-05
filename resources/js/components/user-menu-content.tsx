import { Link, router } from '@inertiajs/react';
import { LogOut, Monitor, Moon, Sun } from 'lucide-react';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { useAppearance } from '@/hooks/use-appearance';
import { logout } from '@/routes';
import { edit as editAppearance } from '@/routes/appearance';
import type { User } from '@/types';

type Props = {
    user: User;
    onNavigate?: () => void;
};

export function UserMenuContent({ user, onNavigate }: Props) {
    const { resolvedAppearance, updateAppearance } = useAppearance();

    const handleLogout = () => {
        onNavigate?.();
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
                    className="min-h-11"
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
                <DropdownMenuItem className="min-h-11" asChild>
                    <Link href={editAppearance()} onClick={onNavigate}>
                        <Monitor />
                        Appearance settings
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                <DropdownMenuItem
                    className="min-h-11"
                    variant="destructive"
                    asChild
                >
                    <Link
                        className="w-full cursor-pointer"
                        href={logout()}
                        as="button"
                        onClick={handleLogout}
                        data-test="logout-button"
                    >
                        <LogOut />
                        Log out
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuGroup>
        </>
    );
}
