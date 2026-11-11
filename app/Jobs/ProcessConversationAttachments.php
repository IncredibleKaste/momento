<?php

namespace App\Jobs;

use App\Actions\ManageConversationAttachments;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessConversationAttachments implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 0;

    public function __construct(public readonly int $sendId, public readonly string $attemptToken) {}

    public function handle(ManageConversationAttachments $attachments): void
    {
        if (! $attachments->process($this->sendId, $this->attemptToken)) {
            $this->release(10);
        }
    }
}
