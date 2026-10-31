import { Head, Link, usePage } from '@inertiajs/react';
import FollowAction from '@/components/follow-action';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { dashboard } from '@/routes';
import { index } from '@/routes/following';
import { destroy, store } from '@/routes/follows';

type Props = {
    member: { id: number; name: string };
    follow: { id: number; status: 'pending' | 'approved' } | null;
};

export default function Member({ member, follow }: Props) {
    const { auth } = usePage().props;
    const initials = useInitials();
    const own = auth.user.id === member.id;

    return (
        <>
            <Head title={member.name} />
            <section
                aria-labelledby="profile-name"
                className="max-w-xl space-y-8 py-10"
            >
                <nav aria-label="Profile navigation" className="flex gap-6">
                    <Link
                        href={dashboard()}
                        className="inline-flex min-h-11 items-center text-sm underline underline-offset-4"
                    >
                        Home
                    </Link>
                    <Link
                        href={index()}
                        className="inline-flex min-h-11 items-center text-sm underline underline-offset-4"
                    >
                        Following
                    </Link>
                </nav>
                <header className="flex items-center gap-5">
                    <Avatar
                        aria-hidden="true"
                        className="size-16 shrink-0 text-xl"
                    >
                        <AvatarFallback>{initials(member.name)}</AvatarFallback>
                    </Avatar>
                    <h1
                        id="profile-name"
                        className="min-w-0 font-serif text-3xl break-words"
                    >
                        {member.name}
                    </h1>
                </header>
                {own ? (
                    <p className="text-sm text-muted-foreground">
                        This is your profile. Share this page’s address so other
                        Momento members can request to follow you.
                    </p>
                ) : (
                    <>
                        <p className="text-sm text-muted-foreground">
                            {follow?.status === 'approved'
                                ? 'You follow this member.'
                                : follow
                                  ? 'Your request is waiting for approval.'
                                  : 'Send a request to follow this member.'}
                        </p>
                        {follow?.status !== 'approved' && (
                            <p className="text-sm text-muted-foreground">
                                Photos and captions are private until this
                                member approves your request.
                            </p>
                        )}
                        {follow ? (
                            <FollowAction
                                form={destroy.form(follow.id)}
                                label={
                                    follow.status === 'approved'
                                        ? 'Unfollow'
                                        : 'Cancel request'
                                }
                                memberName={member.name}
                                secondary
                            />
                        ) : (
                            <FollowAction
                                form={store.form(member.id)}
                                label="Request to follow"
                                memberName={member.name}
                            />
                        )}
                    </>
                )}
            </section>
        </>
    );
}

Member.layout = {
    breadcrumbs: [{ title: 'Profile', href: '#profile-name' }],
};
