import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import EmailUpdatesController from '@/actions/App/Http/Controllers/Settings/EmailUpdatesController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';

export default function EmailUpdates({
    productNewsOptIn,
}: {
    productNewsOptIn: boolean;
}) {
    const form = useForm({ product_news_opt_in: productNewsOptIn });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.patch(EmailUpdatesController.update.url(), {
            preserveScroll: true,
        });
    }

    return (
        <>
            <Head title="Email updates" />

            <h1 className="sr-only">Email updates</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Email updates"
                    description="Choose whether to receive occasional product news written by the Momento team."
                />

                <form onSubmit={submit} className="space-y-6">
                    <div className="flex items-start gap-3">
                        <Checkbox
                            id="product_news_opt_in"
                            checked={form.data.product_news_opt_in}
                            onCheckedChange={(checked) =>
                                form.setData(
                                    'product_news_opt_in',
                                    checked === true,
                                )
                            }
                            aria-invalid={Boolean(
                                form.errors.product_news_opt_in,
                            )}
                            aria-describedby={
                                form.errors.product_news_opt_in
                                    ? 'product-news-error'
                                    : 'product-news-description'
                            }
                        />
                        <div className="grid gap-1.5">
                            <Label htmlFor="product_news_opt_in">
                                Occasional product news from Momento
                            </Label>
                            <p
                                id="product-news-description"
                                className="text-sm text-muted-foreground"
                            >
                                This is off by default. Unsubscribe at any time.
                                Account verification and recovery emails are
                                unaffected.
                            </p>
                            <InputError
                                id="product-news-error"
                                message={form.errors.product_news_opt_in}
                                role="alert"
                            />
                        </div>
                    </div>

                    <div className="flex items-center gap-4">
                        <Button disabled={form.processing} className="min-h-11">
                            Save
                        </Button>
                        {form.recentlySuccessful && (
                            <p
                                role="status"
                                className="text-sm text-green-700 dark:text-green-400"
                            >
                                Preference saved.
                            </p>
                        )}
                    </div>
                </form>
            </div>
        </>
    );
}
