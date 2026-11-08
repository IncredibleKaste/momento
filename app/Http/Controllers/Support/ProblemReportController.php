<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Message;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ProblemReportController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('support/report-a-problem', [
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'description' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        $recipient = config('services.problem_reports.recipient');
        $mailerName = config('services.problem_reports.mailer');
        $mailers = config('mail.mailers');
        $mailerConfig = is_string($mailerName) && is_array($mailers)
            ? ($mailers[$mailerName] ?? null)
            : null;
        $transport = is_array($mailerConfig) ? ($mailerConfig['transport'] ?? null) : null;
        $acceptedTransports = ['smtp', 'ses', 'ses-v2', 'sendmail', 'mailgun', 'postmark', 'resend'];

        if (! is_string($recipient)
            || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false
            || ! is_string($mailerName)
            || ! is_string($transport)
            || ! in_array($transport, $acceptedTransports, true)) {
            return back()->withErrors([
                'description' => 'Reports cannot be sent right now. Your draft is still here. Please try again later.',
            ]);
        }

        try {
            $sentMessage = Mail::mailer($mailerName)->raw($validated['description'], function (Message $message) use ($recipient): void {
                $message->to($recipient)->subject('Momento problem report');
            });
        } catch (Throwable) {
            return back()->withErrors([
                'description' => 'We could not send your report. Your draft is still here. Please try again later.',
            ]);
        }

        if (! $sentMessage instanceof SentMessage) {
            return back()->withErrors([
                'description' => 'We could not send your report. Your draft is still here. Please try again later.',
            ]);
        }

        return to_route('problem-reports.create')->with('status', 'Your report was sent to the support team.');
    }
}
