import { Head } from '@inertiajs/react';
import { useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import InputError from '@/components/input-error';
import { store } from '@/routes/problem-reports';

export default function ReportAProblem({ status }: { status?: string }) {
    const form = useForm({ description: '' });

    return (
        <>
            <Head title="Report a problem" />

            <div className="space-y-6 py-8">
                <div className="space-y-2">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Report a problem
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Tell the support team what happened. Only the
                        description below is sent. Do not include passwords or
                        verification codes.
                    </p>
                </div>

                {status && (
                    <p
                        className="text-sm font-medium text-green-700 dark:text-green-400"
                        role="status"
                    >
                        {status}
                    </p>
                )}

                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(store.url(), {
                            onSuccess: () => form.reset('description'),
                        });
                    }}
                    className="space-y-4"
                >
                    <div className="space-y-2">
                        <label
                            htmlFor="description"
                            className="text-sm font-medium"
                        >
                            What happened?
                        </label>
                        <textarea
                            id="description"
                            name="description"
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                            maxLength={5000}
                            required
                            aria-invalid={Boolean(form.errors.description)}
                            aria-describedby="description-help description-error"
                            className="min-h-40 w-full rounded-md border border-input bg-background px-3 py-2 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm"
                        />
                        <p
                            id="description-help"
                            className="text-sm text-muted-foreground"
                        >
                            Up to 5,000 characters.
                        </p>
                        <InputError
                            id="description-error"
                            message={form.errors.description}
                            role="alert"
                        />
                    </div>

                    <Button disabled={form.processing} className="min-h-11">
                        {form.processing ? 'Sending…' : 'Send report'}
                    </Button>
                </form>
            </div>
        </>
    );
}
