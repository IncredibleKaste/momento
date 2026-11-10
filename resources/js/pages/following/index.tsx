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
    const isFollowing = kind === 'following';
    const label = {
        incoming: 'Decline',
        outgoing: 'Cancel request',
        followers: 'Remove follower',
        following: 'Unfollow',
    }[kind];

    return (
        <section
            aria-label={title}
            className={isFollowing ? 'min-w-0 space-y-5' : 'min-w-0 space-y-4'}
        >
            <h2
                id={isFollowing ? 'people-you-follow' : undefined}
                className={
                    isFollowing
                        ? 'font-serif text-2xl sm:text-3xl'
                        : 'font-serif text-xl'
                }
            >
                {title}{' '}
                <span className="font-sans text-sm font-normal text-muted-foreground">
                    ({page.total})
                </span>
            </h2>
            {page.data.length === 0 ? (
                <p
                    className={
                        isFollowing
                            ? 'border border-border bg-card px-5 py-8 text-sm text-muted-foreground sm:px-7'
                            : 'text-sm text-muted-foreground'
                    }
                >
                    {empty}
                </p>
            ) : (
                <ul
                    className={
                        isFollowing
                            ? 'grid grid-cols-2 gap-[18px] min-[541px]:grid-cols-3'
                            : 'space-y-4'
                    }
                >
                    {page.data.map((follow) => (
                        <li
                            key={follow.id}
                            className={
                                isFollowing
                                    ? 'flex min-w-0 flex-col items-center gap-3 bg-transparent p-2 text-center'
                                    : 'flex min-w-0 flex-wrap items-center gap-3 border-b border-border/80 pb-4'
                            }
                        >
                            <div
                                className={
                                    isFollowing
                                        ? 'flex w-full min-w-0 flex-col items-center gap-2'
                                        : 'flex min-w-0 items-center gap-3'
                                }
                            >
                                <Avatar
                                    aria-hidden="true"
                                    className={
                                        isFollowing
                                            ? 'size-[72px] shrink-0 rounded-full border-0 min-[541px]:size-[88px]'
                                            : 'size-10 shrink-0 border border-border'
                                    }
                                >
                                    <AvatarFallback
                                        className={
                                            isFollowing
                                                ? 'bg-secondary font-serif text-2xl text-foreground sm:text-3xl'
                                                : 'bg-secondary font-serif text-foreground'
                                        }
                                    >
                                        {initials(follow.member.name)}
                                    </AvatarFallback>
                                </Avatar>
                                <Link
                                    href={show(follow.member.id)}
                                    prefetch={false}
                                    aria-label={follow.member.name}
                                    className={
                                        isFollowing
                                            ? 'inline-flex min-h-11 w-full min-w-0 items-center justify-center text-center text-xs font-medium underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring'
                                            : 'inline-flex min-h-11 min-w-0 flex-1 items-center text-base font-medium break-all underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring'
                                    }
                                >
                                    {isFollowing ? (
                                        <span className="line-clamp-2 w-full min-w-0 break-words">
                                            {follow.member.name}
                                        </span>
                                    ) : (
                                        follow.member.name
                                    )}
                                </Link>
                            </div>
                            <div
                                className={
                                    isFollowing
                                        ? 'flex flex-wrap justify-center gap-2 pt-1'
                                        : 'flex w-full max-w-full flex-wrap gap-2 sm:w-auto'
                                }
                            >
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
                <nav aria-label={`${title} pages`} className="flex gap-3">
                    {page.prev_page_url && (
                        <Button asChild variant="outline" className="min-h-11">
                            <Link href={page.prev_page_url} preserveScroll>
                                Previous
                            </Link>
                        </Button>
                    )}
                    {page.next_page_url && (
                        <Button asChild variant="outline" className="min-h-11">
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
            <div className="space-y-10 py-10">
                <header className="flex flex-wrap items-end justify-between gap-5">
                    <div className="space-y-2">
                        <h1 className="font-serif text-4xl tracking-tight sm:text-5xl">
                            Following
                        </h1>
                        <p className="max-w-lg text-base leading-relaxed text-muted-foreground">
                            Your people, requests, and approved connections.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-x-5 gap-y-2 text-sm">
                        <Link
                            href={show(auth.user.id)}
                            prefetch={false}
                            className="inline-flex min-h-11 items-center underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                        >
                            Your profile
                        </Link>
                        <Link
                            href={index()}
                            className="inline-flex min-h-11 items-center underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
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
                <div className="space-y-10">
                    <Relationships
                        title="People you follow"
                        empty="You are not following anyone yet. Visit a member’s profile to send a request."
                        page={following}
                        kind="following"
                    />
                    <div className="grid gap-x-12 gap-y-10 border-t border-border pt-8 lg:grid-cols-2">
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
                    </div>
                </div>
            </div>
        </>
    );
}

Following.layout = { breadcrumbs: [{ title: 'Following', href: index() }] };
