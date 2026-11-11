<?php

namespace App\Providers;

use App\Auth\LocalCredentialUserProvider;
use App\Models\User;
use App\Services\OidcAuthentication;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpFoundation\Response;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(OidcAuthentication::class, fn (): OidcAuthentication => new OidcAuthentication(new Client([
            'connect_timeout' => 3,
            'timeout' => 10,
            'allow_redirects' => false,
        ])));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::provider('local-credentials', fn ($app, array $config) => new LocalCredentialUserProvider($app['hash'], $config['model']));
        $this->configureDefaults();
        RateLimiter::for('follow-requests', function (Request $request): array {
            $actor = $request->user()->getAuthIdentifier();
            $member = $request->route('member');
            $target = $member instanceof User ? $member->id : (string) $member;

            return [
                Limit::perMinute(20)->by('follow-account:'.$actor)->response($this->followLimitResponse(...)),
                Limit::perMinute(5)->by('follow-pair:'.$actor.':'.$target)->response($this->followLimitResponse(...)),
            ];
        });
        RateLimiter::for('follow-actions', fn (Request $request): Limit => Limit::perMinute(60)
            ->by('follow-actions:'.$request->user()->getAuthIdentifier())->response($this->followLimitResponse(...)));
        RateLimiter::for('message-send', fn (Request $request): Limit => Limit::perMinute(60)
            ->by('message-send:'.$request->user()->getAuthIdentifier())
            ->response(function (Request $request, array $headers): Response {
                $message = 'Too many messages. Wait a minute, then retry your draft.';
                $conversation = $request->route('conversation');
                if ($request->header('X-Inertia') && is_numeric($conversation)) {
                    return to_route('messages.show', $conversation)->withErrors(['body' => $message])->withHeaders($headers);
                }

                return response()->json(['message' => $message], 429, $headers);
            }));
        RateLimiter::for('message-request-actions', fn (Request $request): Limit => Limit::perMinute(60)
            ->by('message-request-actions:'.$request->user()->getAuthIdentifier())
            ->response($this->messageRequestActionLimitResponse(...)));
        RateLimiter::for('people-search', fn (Request $request): Limit => Limit::perMinute(60)
            ->by('people-search:'.$request->user()->getAuthIdentifier()));
        RateLimiter::for('photo-posts', fn (Request $request): Limit => Limit::perMinute(5)
            ->by('photo-posts:'.$request->user()->getAuthIdentifier())
            ->response(fn (Request $request, array $headers): Response => to_route('photo-posts.create')
                ->withErrors(['photo' => 'Too many photo uploads. Wait a minute, then try again.'])
                ->withHeaders($headers)));
        RateLimiter::for('problem-reports', fn (Request $request): Limit => Limit::perMinute(3)
            ->by('problem-reports:'.$request->user()->getAuthIdentifier())
            ->response(fn (Request $request, array $headers): Response => to_route('problem-reports.create')
                ->withErrors(['description' => 'Too many reports. Wait a minute, then try again. Your draft is still here.'])
                ->withHeaders($headers)));
    }

    /** @param array<string, int|string> $headers */
    private function followLimitResponse(Request $request, array $headers): Response
    {
        $message = 'Too many follow changes. Wait a minute, then refresh and try again.';
        if ($request->header('X-Inertia')) {
            return to_route('following.index')->withErrors(['follow' => $message])->withHeaders($headers);
        }

        return response()->json(['message' => $message], 429, $headers);
    }

    /** @param array<string, int|string> $headers */
    private function messageRequestActionLimitResponse(Request $request, array $headers): Response
    {
        $message = 'Too many request changes. Wait a minute, then try again.';
        if ($request->header('X-Inertia')) {
            return to_route('messages.index')->withErrors(['request' => $message])->withHeaders($headers);
        }

        return response()->json(['message' => $message], 429, $headers);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
