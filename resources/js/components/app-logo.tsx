import { usePage } from '@inertiajs/react';

import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    const { name } = usePage().props;

    return (
        <>
            <AppLogoIcon className="size-8 shrink-0" />
            <span className="rail-label truncate font-serif text-2xl">
                {name}
            </span>
        </>
    );
}
