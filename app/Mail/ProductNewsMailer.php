<?php

namespace App\Mail;

use Illuminate\Mail\Message;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class ProductNewsMailer
{
    public function configuredMailerName(): ?string
    {
        $mailerName = config('mail.product_news_mailer');

        return app(DeliveryMailerConfiguration::class)->isConfiguredForDelivery($mailerName)
            ? $mailerName
            : null;
    }

    public function send(string $mailerName, string $recipient, string $subject, string $body): SentMessage
    {
        if ($this->configuredMailerName() !== $mailerName) {
            throw new RuntimeException('Product news delivery is not configured.');
        }

        try {
            $sentMessage = Mail::mailer($mailerName)->raw($body, function (Message $message) use ($recipient, $subject): void {
                $message->to($recipient)->subject($subject);
            });
        } catch (Throwable) {
            throw new RuntimeException('Product news transport failed.');
        }

        if (! $sentMessage instanceof SentMessage) {
            throw new RuntimeException('Product news message was not accepted by the transport.');
        }

        return $sentMessage;
    }
}
