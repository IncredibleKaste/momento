import { useEffect, useMemo, useState } from 'react';

export default function PhotoPostTime({
    createdAt,
    compact = false,
    className,
}: {
    createdAt: string | null;
    compact?: boolean;
    className?: string;
}) {
    const [localLabel, setLocalLabel] = useState<string | null>(null);
    const [localTitle, setLocalTitle] = useState<string | null>(null);
    const date = useMemo(
        () => (createdAt === null ? null : new Date(createdAt)),
        [createdAt],
    );
    const validDate = date !== null && Number.isFinite(date.getTime());
    const dateTime =
        validDate && date !== null ? date.toISOString() : undefined;
    const initialLabel =
        validDate && date !== null
            ? new Intl.DateTimeFormat('en-US', {
                  ...(compact
                      ? { month: 'short', day: 'numeric' }
                      : { year: 'numeric', month: 'short', day: 'numeric' }),
                  hour: '2-digit',
                  minute: '2-digit',
                  timeZone: 'UTC',
                  timeZoneName: 'short',
              }).format(date)
            : null;
    const initialTitle =
        validDate && date !== null
            ? new Intl.DateTimeFormat('en-US', {
                  weekday: 'long',
                  year: 'numeric',
                  month: 'long',
                  day: 'numeric',
                  hour: 'numeric',
                  minute: '2-digit',
                  timeZone: 'UTC',
                  timeZoneName: 'short',
              }).format(date)
            : undefined;

    useEffect(() => {
        if (!validDate || date === null) {
            setLocalLabel(null);
            setLocalTitle(null);

            return;
        }

        setLocalLabel(
            new Intl.DateTimeFormat(
                undefined,
                compact
                    ? {
                          month: 'short',
                          day: 'numeric',
                          hour: '2-digit',
                          minute: '2-digit',
                      }
                    : { dateStyle: 'medium', timeStyle: 'short' },
            ).format(date),
        );
        setLocalTitle(
            new Intl.DateTimeFormat(undefined, {
                dateStyle: 'full',
                timeStyle: 'short',
            }).format(date),
        );
    }, [compact, createdAt, date, validDate]);

    return (
        <time
            dateTime={dateTime}
            title={localTitle ?? initialTitle}
            className={className}
        >
            {validDate
                ? (localLabel ?? initialLabel ?? 'Date unavailable')
                : 'Date unavailable'}
        </time>
    );
}
