<?php

namespace App\Providers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
