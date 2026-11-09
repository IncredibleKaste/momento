<?php

namespace App\Enums;

enum PhotoPostStatus: string
{
    case Staging = 'staging';
    case Queued = 'queued';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
    case CancelRequested = 'cancel_requested';
    case Cancelled = 'cancelled';
    case Deleted = 'deleted';

    public function isActive(): bool
    {
        return in_array($this, [self::Staging, self::Queued, self::Processing, self::CancelRequested], true);
    }

    public function isCancellable(): bool
    {
        return in_array($this, [self::Staging, self::Queued, self::Processing], true);
    }

    public function isFailedOrCancelled(): bool
    {
        return in_array($this, [self::Failed, self::Cancelled], true);
    }
}
