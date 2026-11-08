<?php

namespace App\Mail;

use Illuminate\Support\Arr;
use Illuminate\Support\ConfigurationUrlParser;
use Throwable;

class DeliveryMailerConfiguration
{
    private const ACCEPTED_TRANSPORTS = ['smtp', 'ses', 'ses-v2', 'sendmail', 'mailgun', 'postmark', 'resend'];

    public function isConfiguredForDelivery(mixed $mailerName): bool
    {
        if (! is_string($mailerName) || $mailerName === '') {
            return false;
        }

        $mailers = config('mail.mailers');
        $mailerConfig = is_array($mailers) ? ($mailers[$mailerName] ?? null) : null;

        if (! is_array($mailerConfig)) {
            return false;
        }

        try {
            if (isset($mailerConfig['url'])) {
                $mailerConfig = array_merge(
                    $mailerConfig,
                    (new ConfigurationUrlParser)->parseConfiguration($mailerConfig),
                );
                $transport = Arr::pull($mailerConfig, 'driver');
            } else {
                $transport = $mailerConfig['transport'] ?? null;
            }
        } catch (Throwable) {
            return false;
        }

        return is_string($transport) && in_array($transport, self::ACCEPTED_TRANSPORTS, true);
    }
}
