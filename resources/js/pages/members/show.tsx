import { Head, Link, usePage } from '@inertiajs/react';
import FollowAction from '@/components/follow-action';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
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
            <section className="max-w-xl space-y-6 py-10">
                <Link
                    href={index()}
                    className="text-sm underline underline-offset-4"
                >
                    Following
                </Link>
                <Avatar aria-hidden="true" className="size-16 text-xl">
                    <AvatarFallback>{initials(member.name)}</AvatarFallback>
                </Avatar>
                <h1 className="font-serif text-3xl break-words">
                    {member.name}
                </h1>
                {own ? (
                    <p className="text-sm text-muted-foreground">
                        This is your member link. Only verified Momento members
                        can open it.
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

Member.layout = { breadcrumbs: [{ title: 'Following', href: index() }] };
