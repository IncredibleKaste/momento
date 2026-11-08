<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Mail\DeliveryMailerConfiguration;
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

        if (! is_string($recipient)
            || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false
            || ! app(DeliveryMailerConfiguration::class)->isConfiguredForDelivery($mailerName)) {
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
