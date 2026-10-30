import { Form } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import type { RouteFormDefinition } from '@/wayfinder';

type Props = {
    form: RouteFormDefinition<'post'>;
    label: string;
    memberName: string;
    secondary?: boolean;
};

export default function FollowAction({
    form,
    label,
    memberName,
    secondary = false,
}: Props) {
    return (
        <Form
            {...form}
            options={{ preserveScroll: true }}
            onSuccess={() => document.getElementById('follow-status')?.focus()}
            onError={() => document.getElementById('follow-error')?.focus()}
            className="flex max-w-full flex-col items-start gap-2"
        >
            {({ processing, errors }) => (
                <>
                    <Button
                        type="submit"
                        variant={secondary ? 'outline' : 'default'}
                        disabled={processing}
                        aria-label={`${label} ${memberName}`}
                        className="min-h-11 whitespace-normal"
                    >
                        {processing ? 'Updating…' : label}
                    </Button>
                    <InputError message={errors.follow} />
                </>
            )}
        </Form>
    );
}
