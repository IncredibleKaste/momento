import { Head, Link, usePage } from '@inertiajs/react';
import { MessageCircle } from 'lucide-react';
import { useState } from 'react';
import FollowAction from '@/components/follow-action';
import PhotoPostTime from '@/components/photo-post-time';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { dashboard } from '@/routes';
import { index } from '@/routes/following';
import { destroy, store } from '@/routes/follows';
import { edit } from '@/routes/profile';
import {
    index as messagesIndex,
    show as messagesShow,
    store as startMessage,
} from '@/routes/messages';
import { reopen as reopenMessageRequest } from '@/routes/message-requests';

type Props = {
    member: { id: number; name: string };
    follow: { id: number; status: 'pending' | 'approved' } | null;
    canMessage: boolean;
    messageAction: {
        mode:
            | 'direct'
            | 'request'
            | 'outgoing'
            | 'incoming'
            | 'closed'
            | 'reopen'
            | 'unavailable';
        requestId?: number;
        conversationId?: number;
        canReopen: boolean;
    };
    photos?: {
        data: {
            id: number;
            caption: string | null;
            location: string | null;
            createdAt: string | null;
            mediaUrl: string;
            detailUrl: string;
        }[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
};

const photoBorderColors = [
    '#648b49',
    '#a8a24d',
    '#d5ad4c',
    '#d99046',
    '#d07849',
    '#bd5755',
    '#b45c77',
    '#966092',
    '#7c69a5',
    '#617fb0',
    '#4d8da6',
    '#50998a',
    '#709f62',
    '#b39c59',
    '#c07d78',
    '#8c7faa',
];

function ProfilePhoto({
    photo,
    memberName,
    index,
}: {
    photo: NonNullable<Props['photos']>['data'][number];
    memberName: string;
    index: number;
}) {
    const [failed, setFailed] = useState(false);

    return (
        <li className="min-w-0">
            <Link
                href={photo.detailUrl}
                prefetch={false}
                aria-label={`Open photo from ${memberName}: ${photo.caption || 'Photo'}`}
                className="block aspect-square overflow-hidden rounded-[5px] border-t-2 bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                style={{
                    borderTopColor:
                        photoBorderColors[index % photoBorderColors.length],
                }}
            >
                {failed ? (
                    <span
                        role="status"
                        className="flex h-full items-center justify-center p-4 text-center text-sm text-muted-foreground"
                    >
                        This photo could not be loaded. Open it to try again.
                    </span>
                ) : (
                    <img
                        src={photo.mediaUrl}
                        alt={photo.caption || `Photo from ${memberName}`}
                        loading="lazy"
                        decoding="async"
                        onError={() => setFailed(true)}
                        className="h-full w-full object-cover"
                    />
                )}
            </Link>
            {photo.caption && <p className="sr-only">{photo.caption}</p>}
            {(photo.location || photo.createdAt) && (
                <div className="space-y-1 px-1 py-2 text-xs text-muted-foreground">
                    {photo.location && (
                        <p className="wrap-anywhere whitespace-pre-wrap">
                            {photo.location}
                        </p>
                    )}
                    <PhotoPostTime createdAt={photo.createdAt} />
                </div>
            )}
        </li>
    );
}

export default function Member({
    member,
    follow,
    messageAction,
    photos,
}: Props) {
    const { auth } = usePage().props;
    const initials = useInitials();
    const own = auth.user.id === member.id;

    return (
        <>
            <Head title={member.name} />
            <section aria-labelledby="profile-name" className="min-w-0 pb-5">
                <header className="flex items-start gap-[13px] py-[30px] min-[541px]:items-center min-[541px]:gap-[25px]">
                    <Avatar
                        aria-hidden="true"
                        className="size-[70px] shrink-0 border-0 bg-transparent font-serif text-[28px] min-[541px]:size-[104px] min-[541px]:text-4xl"
                    >
                        <AvatarFallback>{initials(member.name)}</AvatarFallback>
                    </Avatar>
                    <div className="min-w-0 flex-1">
                        <h1
                            id="profile-name"
                            className="font-serif text-[28px] leading-tight font-medium wrap-anywhere min-[541px]:text-[34px]"
                        >
                            {member.name}
                        </h1>
                        {own ? (
                            <div className="mt-2 space-y-2">
                                <p className="text-xs leading-relaxed text-muted-foreground">
                                    This is your profile. Share this page’s
                                    address so other Momento members can request
                                    to follow you.
                                </p>
                                <Link
                                    href={edit()}
                                    prefetch={false}
                                    className="inline-flex min-h-11 items-center text-sm underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                >
                                    Edit profile
                                </Link>
                            </div>
                        ) : (
                            <div className="mt-2 space-y-2">
                                <p className="text-xs leading-relaxed text-muted-foreground">
                                    {follow?.status === 'approved'
                                        ? 'You follow this member.'
                                        : follow
                                          ? 'Your request is waiting for approval.'
                                          : 'Send a request to follow this member.'}
                                </p>
                                {follow?.status !== 'approved' && (
                                    <p className="text-xs leading-relaxed text-muted-foreground">
                                        Photos and captions are private until
                                        this member approves your request.
                                    </p>
                                )}
                                <div className="flex flex-wrap items-start gap-[7px] pt-1 min-[541px]:gap-2.5 min-[541px]:pt-2.5">
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
                                    {messageAction.mode === 'direct' && (
                                        <Link
                                            href={
                                                messageAction.conversationId
                                                    ? messagesShow(
                                                          messageAction.conversationId,
                                                      )
                                                    : startMessage(member.id)
                                            }
                                            method={
                                                messageAction.conversationId
                                                    ? 'get'
                                                    : 'post'
                                            }
                                            as="button"
                                            prefetch={false}
                                            className="inline-flex min-h-11 min-w-11 items-center justify-center gap-2 rounded-[5px] border border-border bg-card px-3 py-2 text-[13px] font-semibold hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring min-[541px]:px-5"
                                        >
                                            <MessageCircle
                                                aria-hidden="true"
                                                className="size-[17px]"
                                            />
                                            Message
                                        </Link>
                                    )}
                                    {messageAction.mode === 'request' && (
                                        <Link
                                            href={messagesIndex.url({
                                                query: { recipient: member.id },
                                            })}
                                            prefetch={false}
                                            className="inline-flex min-h-11 min-w-11 items-center justify-center gap-2 rounded-[5px] border border-border bg-card px-3 py-2 text-[13px] font-semibold hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring min-[541px]:px-5"
                                        >
                                            <MessageCircle
                                                aria-hidden="true"
                                                className="size-[17px]"
                                            />
                                            Send message request
                                        </Link>
                                    )}
                                    {messageAction.mode === 'reopen' &&
                                        messageAction.canReopen &&
                                        messageAction.requestId && (
                                            <Link
                                                href={reopenMessageRequest(
                                                    messageAction.requestId,
                                                )}
                                                method="post"
                                                as="button"
                                                prefetch={false}
                                                className="inline-flex min-h-11 min-w-11 items-center justify-center gap-2 rounded-[5px] border border-border bg-card px-3 py-2 text-[13px] font-semibold hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring min-[541px]:px-5"
                                            >
                                                <MessageCircle
                                                    aria-hidden="true"
                                                    className="size-[17px]"
                                                />
                                                Allow a new request
                                            </Link>
                                        )}
                                    {[
                                        'outgoing',
                                        'incoming',
                                        'closed',
                                    ].includes(messageAction.mode) && (
                                        <Link
                                            href={messagesIndex.url({
                                                query: { recipient: member.id },
                                            })}
                                            prefetch={false}
                                            className="inline-flex min-h-11 min-w-11 items-center justify-center gap-2 rounded-[5px] border border-border bg-card px-3 py-2 text-[13px] font-semibold hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring min-[541px]:px-5"
                                        >
                                            <MessageCircle
                                                aria-hidden="true"
                                                className="size-[17px]"
                                            />
                                            {messageAction.mode === 'outgoing'
                                                ? 'Request pending'
                                                : messageAction.mode ===
                                                    'incoming'
                                                  ? 'View message request'
                                                  : 'View messaging status'}
                                        </Link>
                                    )}
                                </div>
                            </div>
                        )}
                    </div>
                </header>
                {photos && (
                    <section
                        aria-labelledby="profile-photos-heading"
                        className="space-y-[18px] pt-[26px]"
                    >
                        <h2
                            id="profile-photos-heading"
                            className="font-serif text-2xl leading-none font-medium"
                        >
                            Photos
                        </h2>
                        {photos.data.length === 0 ? (
                            <p className="border border-border bg-card px-5 py-8 text-sm text-muted-foreground">
                                No photos have been shared here yet.
                            </p>
                        ) : (
                            <ul
                                aria-label={`${member.name} photos`}
                                className="grid grid-cols-2 gap-[5px] min-[541px]:grid-cols-3 min-[541px]:gap-1.5"
                            >
                                {photos.data.map((photo, index) => (
                                    <ProfilePhoto
                                        key={photo.id}
                                        photo={photo}
                                        memberName={member.name}
                                        index={index}
                                    />
                                ))}
                            </ul>
                        )}
                        {(photos.prev_page_url || photos.next_page_url) && (
                            <nav
                                aria-label={`${member.name} photo pages`}
                                className="flex flex-wrap gap-3"
                            >
                                {photos.prev_page_url && (
                                    <Link
                                        href={photos.prev_page_url}
                                        prefetch={false}
                                        preserveScroll
                                        className="inline-flex min-h-11 items-center rounded-md border border-input px-4 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                    >
                                        Previous photos
                                    </Link>
                                )}
                                {photos.next_page_url && (
                                    <Link
                                        href={photos.next_page_url}
                                        prefetch={false}
                                        preserveScroll
                                        className="inline-flex min-h-11 items-center rounded-md border border-input px-4 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                    >
                                        Next photos
                                    </Link>
                                )}
                            </nav>
                        )}
                    </section>
                )}
                <nav
                    aria-label="Profile navigation"
                    className="mt-6 flex flex-wrap gap-6"
                >
                    <Link
                        href={dashboard()}
                        prefetch={false}
                        className="inline-flex min-h-11 items-center text-sm underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                    >
                        Home
                    </Link>
                    <Link
                        href={index()}
                        prefetch={false}
                        className="inline-flex min-h-11 items-center text-sm underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                    >
                        Following
                    </Link>
                </nav>
            </section>
        </>
    );
}

Member.layout = {
    breadcrumbs: [{ title: 'Profile', href: '#profile-name' }],
};
