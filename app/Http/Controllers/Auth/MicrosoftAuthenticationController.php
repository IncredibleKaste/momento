<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\MicrosoftContactRequest;
use App\Models\MicrosoftIdentity;
use App\Models\User;
use App\Services\MicrosoftOidc;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

class MicrosoftAuthenticationController extends Controller
{
    public function index(MicrosoftOidc $oidc): Response|RedirectResponse
    {
        if ($oidc->organizations() === []) {
            return $this->failure();
        }

        return Inertia::render('auth/microsoft', [
            'organizations' => array_map(fn ($key, $organization): array => ['key' => $key, 'name' => $organization['name']],
                array_keys($oidc->organizations()), array_values($oidc->organizations())),
        ]);
    }

    public function start(Request $request, string $organization, MicrosoftOidc $oidc): HttpResponse
    {
        $request->session()->forget(['microsoft.transaction', 'microsoft.pending']);
        try {
            $tenant = strtolower($oidc->organizations()[$organization]['id'] ?? '');
            $transaction = ['state' => Str::random(64), 'nonce' => Str::random(64), 'code_verifier' => Str::random(64)];
            $url = $oidc->authorizationUrl($tenant, $transaction);
            $request->session()->put('microsoft.transaction', [...$transaction, 'tenant_id' => $tenant, 'expires_at' => now()->addMinutes(5)->timestamp]);

            return Inertia::location($url);
        } catch (Throwable) {
            return $this->failure();
        }
    }

    public function callback(Request $request, MicrosoftOidc $oidc): RedirectResponse
    {
        $transaction = $request->session()->pull('microsoft.transaction');
        $request->session()->forget('microsoft.pending');
        try {
            if (! is_array($transaction) || ! is_string($transaction['state'] ?? null)
                || ! is_string($transaction['nonce'] ?? null) || ! is_string($transaction['code_verifier'] ?? null)
                || ! is_string($transaction['tenant_id'] ?? null) || ($transaction['expires_at'] ?? 0) <= now()->timestamp
                || ! is_string($request->query('state')) || ! hash_equals($transaction['state'], $request->query('state'))
                || ! Cache::add('microsoft:callback:'.hash('sha256', $transaction['state']), true, 600)) {
                return $this->failure();
            }
            if ($request->has('error')) {
                return $this->failure('Microsoft sign-in was cancelled or declined. You can try again.');
            }
            $code = $request->query('code');
            if (! is_string($code) || blank($code) || strlen($code) > 4096) {
                return $this->failure();
            }
            $identity = $oidc->identity($transaction['tenant_id'], $code, $transaction);
            $stored = MicrosoftIdentity::query()->with('user')->where('tenant_id', $identity['tenant_id'])->where('object_id', $identity['object_id'])->first();
            if ($stored) {
                if ($stored->subject !== $identity['subject'] || $stored->disabled_at || $stored->user->disabled_at || filled($stored->user->password)) {
                    return $this->failure();
                }
                Auth::login($stored->user);
                $request->session()->regenerate();

                return to_route($stored->user->hasVerifiedEmail() ? 'dashboard' : 'verification.notice');
            }
            $request->session()->regenerate();
            $request->session()->put('microsoft.pending', [...$identity, 'expires_at' => now()->addMinutes(10)->timestamp, 'transaction' => Str::random(64)]);

            return to_route('microsoft.contact');
        } catch (Throwable) {
            return $this->failure();
        }
    }

    public function contact(Request $request, MicrosoftOidc $oidc): Response|RedirectResponse
    {
        $pending = $request->session()->get('microsoft.pending');
        if (! is_array($pending) || ($pending['expires_at'] ?? 0) <= now()->timestamp || ! $oidc->allowsTenant($pending['tenant_id'])) {
            return $this->failure();
        }

        return Inertia::render('auth/microsoft-contact', ['suggestedName' => $pending['name']]);
    }

    public function storeContact(MicrosoftContactRequest $request, MicrosoftOidc $oidc): RedirectResponse
    {
        $pending = $request->session()->pull('microsoft.pending');
        try {
            if (! is_array($pending) || ($pending['expires_at'] ?? 0) <= now()->timestamp || ! $oidc->allowsTenant($pending['tenant_id'])
                || ! Cache::add('microsoft:onboarding:'.hash('sha256', $pending['transaction']), true, 1200)) {
                return $this->failure();
            }
            $user = DB::transaction(function () use ($request, $pending): User {
                $user = User::create([...$request->validated(), 'password' => null]);
                $identity = new MicrosoftIdentity;
                $identity->forceFill(['user_id' => $user->id, 'tenant_id' => $pending['tenant_id'], 'object_id' => $pending['object_id'], 'subject' => $pending['subject']])->save();

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->failure('This account or contact address is already registered. Sign in with its existing method; accounts are not linked automatically.');
        } catch (Throwable) {
            return $this->failure();
        }
        Auth::login($user);
        $request->session()->regenerate();
        event(new Registered($user));

        return to_route('verification.notice');
    }

    private function failure(string $message = 'Microsoft sign-in could not be completed. Please try again.'): RedirectResponse
    {
        return to_route('login')->withErrors(['microsoft' => $message]);
    }
}
