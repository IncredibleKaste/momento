import { Head, Link, usePage } from '@inertiajs/react';
import FollowAction from '@/components/follow-action';
import InputError from '@/components/input-error';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { useInitials } from '@/hooks/use-initials';
import { index } from '@/routes/following';
import { approve, destroy } from '@/routes/follows';
import { show } from '@/routes/members';

type Relationship = {
    id: number;
    status: 'pending' | 'approved';
    member: { id: number; name: string };
};

type Page = {
    data: Relationship[];
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

type Props = {
    incoming: Page;
    outgoing: Page;
    followers: Page;
    following: Page;
    status: string | null;
};

function Relationships({
    title,
    empty,
    page,
    kind,
}: {
    title: string;
    empty: string;
    page: Page;
    kind: 'incoming' | 'outgoing' | 'followers' | 'following';
}) {
    const initials = useInitials();
    const label = {
        incoming: 'Decline',
        outgoing: 'Cancel request',
        followers: 'Remove follower',
        following: 'Unfollow',
    }[kind];

    return (
        <section aria-label={title} className="min-w-0">
            <h2 className="mb-3 font-serif text-xl">
                {title}{' '}
                <span className="font-sans text-sm text-muted-foreground">
                    ({page.total})
                </span>
            </h2>
            {page.data.length === 0 ? (
                <p className="py-4 text-sm text-muted-foreground">{empty}</p>
            ) : (
                <ul className="space-y-5">
                    {page.data.map((follow) => (
                        <li
                            key={follow.id}
                            className="flex flex-wrap items-center gap-3"
                        >
                            <Avatar aria-hidden="true" className="size-10">
                                <AvatarFallback>
                                    {initials(follow.member.name)}
                                </AvatarFallback>
                            </Avatar>
                            <Link
                                href={show(follow.member.id)}
                                className="min-w-0 flex-1 break-words underline-offset-4 hover:underline"
                            >
                                {follow.member.name}
                            </Link>
                            <div className="flex w-full max-w-full flex-wrap gap-2 sm:w-auto">
                                {kind === 'incoming' && (
                                    <FollowAction
                                        form={approve.form(follow.id)}
                                        label="Approve"
                                        memberName={follow.member.name}
                                    />
                                )}
                                <FollowAction
                                    form={destroy.form(follow.id)}
                                    label={label}
                                    memberName={follow.member.name}
                                    secondary
                                />
                            </div>
                        </li>
                    ))}
                </ul>
            )}
            {(page.prev_page_url || page.next_page_url) && (
                <nav aria-label={`${title} pages`} className="mt-5 flex gap-3">
                    {page.prev_page_url && (
                        <Button asChild variant="outline">
                            <Link href={page.prev_page_url} preserveScroll>
                                Previous
                            </Link>
                        </Button>
                    )}
                    {page.next_page_url && (
                        <Button asChild variant="outline">
                            <Link href={page.next_page_url} preserveScroll>
                                Next
                            </Link>
                        </Button>
                    )}
                </nav>
            )}
        </section>
    );
}

export default function Following({
    incoming,
    outgoing,
    followers,
    following,
    status,
}: Props) {
    const { auth, errors } = usePage().props;

    return (
        <>
            <Head title="Following" />
            <div className="space-y-9 py-10">
                <header className="flex flex-wrap items-start justify-between gap-5">
                    <div className="space-y-2">
                        <h1 className="font-serif text-3xl">Following</h1>
                        <p className="max-w-lg text-sm text-muted-foreground">
                            Approve follow requests and manage the people you
                            follow.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-4 text-sm">
                        <Link
                            href={show(auth.user.id)}
                            className="underline underline-offset-4"
                        >
                            Your profile
                        </Link>
                        <Link
                            href={index()}
                            className="underline underline-offset-4"
                        >
                            Refresh
                        </Link>
                    </div>
                </header>
                <InputError
                    id="follow-error"
                    role="alert"
                    tabIndex={-1}
                    className="rounded-md bg-muted p-3"
                    message={errors.follow}
                />
                <p
                    id="follow-status"
                    role="status"
                    tabIndex={-1}
                    className={
                        status ? 'rounded-md bg-muted p-3 text-sm' : 'sr-only'
                    }
                >
                    {status}
                </p>
                <div className="grid gap-x-12 gap-y-10 lg:grid-cols-2">
                    <Relationships
                        title="Requests for you"
                        empty="No requests waiting for your approval."
                        page={incoming}
                        kind="incoming"
                    />
                    <Relationships
                        title="Your requests"
                        empty="No requests waiting for someone else’s approval."
                        page={outgoing}
                        kind="outgoing"
                    />
                    <Relationships
                        title="Your followers"
                        empty="You have no approved followers yet."
                        page={followers}
                        kind="followers"
                    />
                    <Relationships
                        title="People you follow"
                        empty="You are not following anyone yet."
                        page={following}
                        kind="following"
                    />
                </div>
            </div>
        </>
    );
}

Following.layout = { breadcrumbs: [{ title: 'Following', href: index() }] };
