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
            <div className="space-y-6 py-6">
                <header className="space-y-2">
                    <h1 className="font-serif text-2xl tracking-tight">
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
                                    className="h-[54px] rounded-[5px] border border-b-2 border-border border-b-[color:var(--navigation-gold)] p-[15px] text-[17px] leading-5 shadow-none md:text-[17px]"
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
                                        <Link
                                            href={people.prev_page_url}
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
            </div>
        </>
    );
}

People.layout = {
    breadcrumbs: [{ title: 'Find people', href: index() }],
};
