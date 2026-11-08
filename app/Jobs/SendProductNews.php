<?php

namespace App\Jobs;

use App\Mail\ProductNewsMailer;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class SendProductNews implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public int $userId,
        public string $subject,
        public string $body,
    ) {}

    public function handle(): void
    {
        $user = User::query()->find($this->userId);

        if ($user === null || ! $user->hasVerifiedEmail() || ! $user->product_news_opt_in) {
            return;
        }

        $mailer = app(ProductNewsMailer::class);
        $mailerName = $mailer->configuredMailerName();

        if ($mailerName === null) {
            throw new \RuntimeException('Product news delivery is not configured with an approved mailer.');
        }

        $unsubscribeUrl = URL::temporarySignedRoute(
            'email-updates.unsubscribe.confirm',
            now()->addYears(5),
            ['user' => $user->getKey()],
        );

        $mailer->send(
            $mailerName,
            $user->email,
            $this->subject,
            $this->body."\n\nTo stop these product updates, unsubscribe here:\n{$unsubscribeUrl}",
        );
    }
}
