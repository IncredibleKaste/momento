import { Head, Link, usePage } from '@inertiajs/react';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { dashboard } from '@/routes';
import { index as following } from '@/routes/following';
import { show as profile } from '@/routes/members';

type FollowedPerson = { id: number; name: string };

type Props = {
    followedPeople: FollowedPerson[];
};

export default function Dashboard({ followedPeople }: Props) {
    const { auth } = usePage().props;
    const initials = useInitials();

    return (
        <>
            <Head title="Home" />
            <section className="flex flex-col gap-3 py-10">
                <h1 className="font-serif text-3xl">Home</h1>
                <p className="text-muted-foreground">
                    Welcome, {auth.user?.name}.
                </p>
            </section>
            <section
                aria-labelledby="followed-people-heading"
                className="min-w-0 space-y-4 pb-10"
            >
                <header className="flex flex-wrap items-end justify-between gap-4">
                    <h2
                        id="followed-people-heading"
                        className="font-serif text-2xl"
                    >
                        People you follow
                    </h2>
                    <Link
                        href={`${following.url()}#people-you-follow`}
                        prefetch={false}
                        className="min-h-11 rounded-sm px-2 py-2 text-sm underline decoration-border underline-offset-4 hover:decoration-current focus-visible:outline-2 focus-visible:outline-offset-2"
                    >
                        View all
                    </Link>
                </header>
                {followedPeople.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Visit someone’s profile and choose Follow to see them
                        here.
                    </p>
                ) : (
                    <ul
                        aria-label="People you follow"
                        className="flex w-full gap-2 overflow-x-auto overscroll-x-contain pb-3"
                    >
                        {followedPeople.map((person) => (
                            <li key={person.id} className="w-24 shrink-0">
                                <Link
                                    href={profile(person.id)}
                                    prefetch={false}
                                    className="group flex min-h-28 flex-col items-center gap-2 rounded-lg p-2 text-center hover:bg-muted/60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                >
                                    <Avatar
                                        aria-hidden="true"
                                        className="size-14 border border-border"
                                    >
                                        <AvatarFallback className="font-serif text-base">
                                            {initials(person.name)}
                                        </AvatarFallback>
                                    </Avatar>
                                    <span className="line-clamp-2 w-full text-sm leading-snug break-words">
                                        {person.name}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Home',
            href: dashboard(),
        },
    ],
};
