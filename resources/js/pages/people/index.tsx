import { useState, type MouseEvent } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Search } from 'lucide-react';
import PhotoPostCard, {
    type PhotoCardPost,
} from '@/components/photo-post-card';
import InputError from '@/components/input-error';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useInitials } from '@/hooks/use-initials';
import { index } from '@/routes/people';
import { show } from '@/routes/members';

type Member = { id: number; name: string };

type Results<T> = {
    data: T[];
    prev_page_url: string | null;
    next_page_url: string | null;
};

type Props = {
    q: string | null;
    people: Results<Member> | null;
    photos: Results<PhotoCardPost> | null;
};

export default function People({ q, people, photos }: Props) {
    const initials = useInitials();
    const { data, setData, get, processing, errors } = useForm({ q: q ?? '' });
    const [requestError, setRequestError] = useState<string | null>(null);
    const visitEvents = {
        onStart: () => setRequestError(null),
        onNetworkError: () => {
            setRequestError(
                'Search could not connect. Check your connection and try again.',
            );
            return false;
        },
        onHttpException: (response: { status: number }) => {
            if (response.status === 429 || response.status >= 500) {
                setRequestError(
                    response.status === 429
                        ? 'Too many searches. Wait a moment and try again.'
                        : 'Search is temporarily unavailable. Try again.',
                );
                return false;
            }
        },
    };

    const visitSearchPage = (event: MouseEvent) => {
        if (
            event.defaultPrevented ||
            event.button !== 0 ||
            event.metaKey ||
            event.ctrlKey ||
            event.shiftKey ||
            event.altKey ||
            !(event.currentTarget instanceof HTMLAnchorElement)
        ) {
            return;
        }

        event.preventDefault();
        router.get(
            event.currentTarget.href,
            {},
            {
                ...visitEvents,
                preserveScroll: true,
            },
        );
    };

    return (
        <>
            <Head title="Search" />
            <div className="space-y-6 py-6">
                <header className="space-y-2">
                    <h1 className="font-serif text-2xl tracking-tight">
                        Search
                    </h1>
                    <p className="max-w-lg text-base leading-relaxed text-muted-foreground">
                        Find people by display name and photos by caption.
                        Photos are limited to yours and people who approve your
                        follow request.
                    </p>
                </header>

                <form
                    {...index.form()}
                    onSubmit={(event) => {
                        event.preventDefault();
                        get(index.url(), {
                            ...visitEvents,
                            preserveScroll: true,
                            preserveState: 'errors',
                        });
                    }}
                    className="space-y-4"
                >
                    <div className="grid gap-2">
                        <Label htmlFor="q">Display name or photo caption</Label>
                        <Input
                            id="q"
                            name="q"
                            type="search"
                            value={data.q}
                            onChange={(event) =>
                                setData('q', event.target.value)
                            }
                            maxLength={80}
                            className="h-[54px] rounded-[5px] border border-b-2 border-border border-b-[color:var(--navigation-gold)] p-[15px] text-[17px] leading-5 shadow-none md:text-[17px]"
                            aria-invalid={Boolean(errors.q)}
                            aria-describedby={errors.q ? 'q-error' : 'q-hint'}
                        />
                        <p
                            id="q-hint"
                            className="text-sm text-muted-foreground"
                        >
                            Enter 2–80 characters. Search matches literal text,
                            including punctuation.
                        </p>
                        <InputError
                            id="q-error"
                            message={errors.q}
                            role="alert"
                        />
                    </div>
                    <p role="status" aria-live="polite" className="sr-only">
                        {processing ? 'Searching.' : ''}
                    </p>
                    <div className="flex flex-wrap items-center gap-3">
                        <Button disabled={processing} className="min-h-11">
                            <Search aria-hidden="true" />
                            {processing ? 'Searching…' : 'Search'}
                        </Button>
                        <Button asChild variant="outline" className="min-h-11">
                            <Link
                                href={index()}
                                onClick={visitSearchPage}
                                prefetch={false}
                            >
                                Clear
                            </Link>
                        </Button>
                    </div>
                </form>

                {requestError && (
                    <p role="alert" className="text-sm text-destructive">
                        {requestError}
                    </p>
                )}

                {q && (
                    <h2 className="font-serif text-xl break-all">
                        Results for “{q}”
                    </h2>
                )}

                {!q ? (
                    <p className="border border-border bg-card px-5 py-8 text-sm text-muted-foreground sm:px-7">
                        Enter a display name or caption to search.
                    </p>
                ) : (
                    <section aria-label="People" className="space-y-4">
                        <h2 className="font-serif text-xl">People</h2>
                        {people?.data.length === 0 ? (
                            <p
                                role="status"
                                className="border border-border bg-card px-5 py-8 text-sm text-muted-foreground sm:px-7"
                            >
                                No verified members match that name.
                            </p>
                        ) : (
                            <ul className="grid grid-cols-2 gap-[18px] min-[541px]:grid-cols-3">
                                {people?.data.map((member) => (
                                    <li key={member.id} className="min-w-0">
                                        <Link
                                            href={show(member.id)}
                                            prefetch={false}
                                            aria-label={member.name}
                                            className="flex min-h-11 w-full min-w-0 flex-col items-center gap-2 rounded-full text-center focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                        >
                                            <Avatar
                                                aria-hidden="true"
                                                className="size-[72px] shrink-0 rounded-full border-0 min-[541px]:size-[88px]"
                                            >
                                                <AvatarFallback className="bg-secondary font-serif text-2xl text-foreground sm:text-3xl">
                                                    {initials(member.name)}
                                                </AvatarFallback>
                                            </Avatar>
                                            <span className="line-clamp-2 w-full text-center text-xs leading-4 font-medium break-words">
                                                {member.name}
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {(people?.prev_page_url || people?.next_page_url) && (
                            <nav
                                aria-label="People result pages"
                                className="flex gap-3"
                            >
                                {people.prev_page_url && (
                                    <Button
                                        asChild
                                        variant="outline"
                                        className="min-h-11"
                                    >
                                        <Link
                                            href={people.prev_page_url}
                                            onClick={visitSearchPage}
                                            prefetch={false}
                                        >
                                            Previous
                                        </Link>
                                    </Button>
                                )}
                                {people.next_page_url && (
                                    <Button
                                        asChild
                                        variant="outline"
                                        className="min-h-11"
                                    >
                                        <Link
                                            href={people.next_page_url}
                                            onClick={visitSearchPage}
                                            prefetch={false}
                                        >
                                            Next
                                        </Link>
                                    </Button>
                                )}
                            </nav>
                        )}
                    </section>
                )}
                {q && photos && (
                    <section
                        aria-labelledby="photos-heading"
                        className="space-y-4"
                    >
                        <h2 id="photos-heading" className="font-serif text-xl">
                            Photos
                        </h2>
                        {photos.data.length === 0 ? (
                            <p
                                role="status"
                                className="border border-border bg-card px-5 py-8 text-sm text-muted-foreground sm:px-7"
                            >
                                No accessible photos match that caption. Try
                                another search.
                            </p>
                        ) : (
                            <div className="space-y-7">
                                {photos.data.map((post) => (
                                    <PhotoPostCard key={post.id} post={post} />
                                ))}
                            </div>
                        )}
                        {(photos.prev_page_url || photos.next_page_url) && (
                            <nav
                                aria-label="Photo result pages"
                                className="flex flex-wrap gap-3"
                            >
                                {photos.prev_page_url && (
                                    <Button
                                        asChild
                                        variant="outline"
                                        className="min-h-11"
                                    >
                                        <Link
                                            href={photos.prev_page_url}
                                            prefetch={false}
                                            onClick={visitSearchPage}
                                        >
                                            Newer photos
                                        </Link>
                                    </Button>
                                )}
                                {photos.next_page_url && (
                                    <Button
                                        asChild
                                        variant="outline"
                                        className="min-h-11"
                                    >
                                        <Link
                                            href={photos.next_page_url}
                                            prefetch={false}
                                            onClick={visitSearchPage}
                                        >
                                            Older photos
                                        </Link>
                                    </Button>
                                )}
                            </nav>
                        )}
                    </section>
                )}
            </div>
        </>
    );
}

People.layout = {
    breadcrumbs: [{ title: 'Search', href: index() }],
};
