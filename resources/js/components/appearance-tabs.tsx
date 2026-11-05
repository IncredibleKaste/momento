import type { LucideIcon } from 'lucide-react';
import { Check, Monitor, Moon, Sun } from 'lucide-react';
import type { HTMLAttributes } from 'react';
import type { Appearance } from '@/hooks/use-appearance';
import { useAppearance } from '@/hooks/use-appearance';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

export default function AppearanceToggleTab({
    className = '',
    ...props
}: HTMLAttributes<HTMLDivElement>) {
    const { appearance, updateAppearance } = useAppearance();

    const tabs: { value: Appearance; icon: LucideIcon; label: string }[] = [
        { value: 'light', icon: Sun, label: 'Light' },
        { value: 'dark', icon: Moon, label: 'Dark' },
        { value: 'system', icon: Monitor, label: 'System' },
    ];

    return (
        <div
            className={cn(
                'inline-flex flex-wrap gap-1 rounded-lg bg-muted p-1',
                className,
            )}
            role="group"
            aria-label="Appearance"
            {...props}
        >
            {tabs.map(({ value, icon: Icon, label }) => (
                <Button
                    key={value}
                    type="button"
                    variant="ghost"
                    aria-pressed={appearance === value}
                    onClick={() => updateAppearance(value)}
                    className={cn(
                        'min-h-11 gap-2 px-3',
                        appearance === value
                            ? 'bg-background text-foreground shadow-xs'
                            : 'text-foreground',
                    )}
                >
                    {appearance === value ? (
                        <Check aria-hidden="true" />
                    ) : (
                        <Icon aria-hidden="true" />
                    )}
                    {label}
                </Button>
            ))}
        </div>
    );
}
