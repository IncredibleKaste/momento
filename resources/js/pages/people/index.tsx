import { Form, Head, Link } from '@inertiajs/react';
import { Search } from 'lucide-react';
import InputError from '@/components/input-error';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useInitials } from '@/hooks/use-initials';
import { index } from '@/routes/people';
import { show } from '@/routes/members';

type Member = { id: number; name: string };

type Results = {
    data: Member[];
    prev_page_url: string | null;
    next_page_url: string | null;
};

type Props = {
    q: string | null;
    people: Results | null;
};

export default function People({ q, people }: Props) {
    const initials = useInitials();

    return (
        <>
            <Head title="Find people" />
            <div className="space-y-8 py-10">
                <header className="space-y-2">
                    <h1 className="font-serif text-4xl tracking-tight sm:text-5xl">
                        Find people
                    </h1>
                    <p className="max-w-lg text-base leading-relaxed text-muted-foreground">
                        Search verified members by display name.
                    </p>
                </header>

                <Form
                    {...index.form()}
                    options={{
                        preserveScroll: true,
                        preserveState: 'errors',
                    }}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="q">Display name</Label>
                                <Input
                                    id="q"
                                    name="q"
                                    type="search"
                                    defaultValue={q ?? ''}
                                    maxLength={80}
                                    aria-invalid={Boolean(errors.q)}
                                    aria-describedby={
                                        errors.q ? 'q-error' : 'q-hint'
                                    }
                                />
                                <p
                                    id="q-hint"
                                    className="text-sm text-muted-foreground"
                                >
                                    Enter at least two characters. Email
                                    addresses are not searched.
                                </p>
                                <InputError
                                    id="q-error"
                                    message={errors.q}
                                    role="alert"
                                />
                            </div>
                            <div className="flex flex-wrap items-center gap-3">
                                <Button
                                    disabled={processing}
                                    className="min-h-11"
                                >
                                    <Search aria-hidden="true" />
                                    Search
                                </Button>
                                <Button
                                    asChild
                                    variant="outline"
                                    className="min-h-11"
                                >
                                    <Link href={index()} prefetch={false}>
                                        Clear
                                    </Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                {q && (
                    <h2 className="font-serif text-xl break-all">
                        Results for “{q}”
                    </h2>
                )}

                {!q ? (
                    <p className="border border-border bg-card px-5 py-8 text-sm text-muted-foreground sm:px-7">
                        Search by display name to find a member.
                    </p>
                ) : people?.data.length === 0 ? (
                    <p
                        role="status"
                        className="border border-border bg-card px-5 py-8 text-sm text-muted-foreground sm:px-7"
                    >
                        No verified members match that name. Try another search.
                    </p>
                ) : (
                    <section aria-label="Search results" className="space-y-4">
                        <ul className="divide-y divide-border border-y border-border">
                            {people?.data.map((member) => (
                                <li
                                    key={member.id}
                                    className="flex min-w-0 items-center gap-3 py-3"
                                >
                                    <Avatar
                                        aria-hidden="true"
                                        className="size-10 shrink-0 border border-border"
                                    >
                                        <AvatarFallback className="bg-secondary font-serif text-foreground">
                                            {initials(member.name)}
                                        </AvatarFallback>
                                    </Avatar>
                                    <Link
                                        href={show(member.id)}
                                        className="inline-flex min-h-11 min-w-0 flex-1 items-center text-base font-medium break-all underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                    >
                                        {member.name}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                        {(people?.prev_page_url || people?.next_page_url) && (
                            <nav
                                aria-label="Search result pages"
                                className="flex gap-3"
                            >
                                {people.prev_page_url && (
                                    <Button
                                        asChild
                                        variant="outline"
                                        className="min-h-11"
                                    >
                                        <Link href={people.prev_page_url}>
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
                                        <Link href={people.next_page_url}>
                                            Next
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
    breadcrumbs: [{ title: 'Find people', href: index() }],
};
