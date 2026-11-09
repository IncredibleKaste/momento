import type { CSSProperties, ReactNode } from 'react';
import { SidebarProvider } from '@/components/ui/sidebar';
import type { AppVariant } from '@/types';

type Props = {
    children: ReactNode;
    variant?: AppVariant;
};

export function AppShell({ children, variant = 'sidebar' }: Props) {
    if (variant === 'header') {
        return (
            <div className="flex min-h-screen w-full flex-col">{children}</div>
        );
    }

    return (
        <SidebarProvider
            defaultOpen={false}
            className="momento-shell"
            style={
                {
                    '--sidebar-width': '58px',
                    '--sidebar-width-icon': '58px',
                } as CSSProperties
            }
        >
            {children}
        </SidebarProvider>
    );
}
