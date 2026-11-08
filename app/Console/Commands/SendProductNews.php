<?php

namespace App\Console\Commands;

use App\Jobs\SendProductNews as SendProductNewsJob;
use App\Mail\ProductNewsMailer;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class SendProductNews extends Command
{
    protected $signature = 'momento:email-updates {subject} {body}';

    protected $description = 'Queue an operator-written product news email for opted-in accounts';

    public function handle(): int
    {
        $subject = trim((string) $this->argument('subject'));
        $body = trim((string) $this->argument('body'));

        if ($subject === '' || mb_strlen($subject) > 255 || preg_match('/[\r\n]/', $subject) === 1) {
            $this->components->error('The subject must be one line and no longer than 255 characters.');

            return self::FAILURE;
        }

        if ($body === '' || mb_strlen($body) > 20000) {
            $this->components->error('The plain-text body is required and must not exceed 20,000 characters.');

            return self::FAILURE;
        }

        if (app(ProductNewsMailer::class)->configuredMailerName() === null) {
            $this->components->error('Product news delivery is not configured with an approved mailer.');

            return self::FAILURE;
        }

        $queued = 0;

        User::query()
            ->where('product_news_opt_in', true)
            ->whereNotNull('email_verified_at')
            ->chunkById(100, function (Collection $users) use ($subject, $body, &$queued): void {
                foreach ($users as $user) {
                    SendProductNewsJob::dispatch($user->id, $subject, $body);
                    $queued++;
                }
            });

        $this->components->info("Queued {$queued} product news messages. Delivery is handled by the queue worker.");

        return self::SUCCESS;
    }
}
