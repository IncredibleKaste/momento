<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\MicrosoftContactRequest;
use App\Models\MicrosoftIdentity;
use App\Models\User;
use App\Notifications\MicrosoftContactVerification;
use App\Services\MicrosoftOidc;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
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
        $pending = $this->pendingIdentity($request, $oidc);
        if (! $pending) {
            return $this->failure();
        }

        return Inertia::render('auth/microsoft-contact', [
            'suggestedName' => $pending['name'],
            'status' => $request->session()->get('status'),
        ]);
    }

    public function storeContact(MicrosoftContactRequest $request, MicrosoftOidc $oidc): RedirectResponse
    {
        $pending = $this->pendingIdentity($request, $oidc);
        if (! $pending) {
            return $this->failure();
        }

        $contact = $request->validated();
        if ($this->contactEmailExists($contact['email']) || MicrosoftIdentity::query()
            ->where('tenant_id', $pending['tenant_id'])
            ->where('object_id', $pending['object_id'])
            ->exists()) {
            return to_route('microsoft.contact')->with('status', $this->verificationStatus());
        }

        $pending['contact'] = $contact;
        $pending['proof'] = Str::random(64);
        $request->session()->put('microsoft.pending', $pending);

        $expiresAt = min($pending['expires_at'], now()->addMinutes(10)->timestamp);
        $url = URL::temporarySignedRoute(
            'microsoft.contact.verify',
            now()->setTimestamp($expiresAt),
            ['proof' => $pending['proof']],
        );

        try {
            Notification::route('mail', $contact['email'])->notify(new MicrosoftContactVerification($url));
        } catch (TransportExceptionInterface $exception) {
            report($exception);

            return back()->withErrors(['email' => 'The verification email could not be sent. Please try again in a moment.']);
        }

        return to_route('microsoft.contact')->with('status', $this->verificationStatus());
    }

    public function verifyContact(Request $request, string $proof, MicrosoftOidc $oidc): Response|RedirectResponse
    {
        $pending = $this->pendingContact($request, $oidc, $proof);
        if (! $pending) {
            return $this->failure();
        }

        return Inertia::render('auth/microsoft-contact-confirm', [
            'email' => $pending['contact']['email'],
            'confirmUrl' => $request->fullUrl(),
        ]);
    }

    public function completeContact(Request $request, string $proof, MicrosoftOidc $oidc): RedirectResponse
    {
        $pending = $this->pendingContact($request, $oidc, $proof);
        if (! $pending) {
            return $this->failure();
        }

        if (! Cache::add('microsoft:contact-proof:'.hash('sha256', $pending['transaction'].':'.$proof), true, 600)) {
            return $this->failure();
        }

        try {
            if ($this->contactEmailExists($pending['contact']['email'])) {
                $this->clearPendingContact($request, $pending);

                return to_route('microsoft.contact')->with('status', $this->verificationStatus());
            }

            $user = DB::transaction(function () use ($pending): User {
                $user = User::create([
                    ...$pending['contact'],
                    'password' => null,
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();

                $identity = new MicrosoftIdentity;
                $identity->forceFill([
                    'user_id' => $user->id,
                    'tenant_id' => $pending['tenant_id'],
                    'object_id' => $pending['object_id'],
                    'subject' => $pending['subject'],
                ])->save();

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            $this->clearPendingContact($request, $pending);

            return to_route('microsoft.contact')->with('status', $this->verificationStatus());
        } catch (Throwable) {
            return $this->failure();
        }

        $request->session()->forget('microsoft.pending');
        Auth::login($user);
        $request->session()->regenerate();
        event(new Registered($user));

        return to_route('dashboard');
    }

    /** @return array<string, mixed>|null */
    private function pendingIdentity(Request $request, MicrosoftOidc $oidc): ?array
    {
        $pending = $request->session()->get('microsoft.pending');

        if (! is_array($pending) || ! is_string($pending['tenant_id'] ?? null)
            || ! is_string($pending['object_id'] ?? null) || ! is_string($pending['subject'] ?? null)
            || ! is_string($pending['transaction'] ?? null) || ! is_int($pending['expires_at'] ?? null)
            || $pending['expires_at'] <= now()->timestamp || ! $oidc->allowsTenant($pending['tenant_id'])) {
            return null;
        }

        return $pending;
    }

    /** @return array<string, mixed>|null */
    private function pendingContact(Request $request, MicrosoftOidc $oidc, string $proof): ?array
    {
        $pending = $this->pendingIdentity($request, $oidc);
        $contact = $pending['contact'] ?? null;
        if (! $pending || ! is_array($contact) || ! is_string($contact['name'] ?? null)
            || ! is_string($contact['email'] ?? null) || ! is_string($pending['proof'] ?? null)
            || ! hash_equals($pending['proof'], $proof)) {
            return null;
        }

        return $pending;
    }

    private function contactEmailExists(string $email): bool
    {
        return User::query()->whereRaw('lower(email) = lower(?)', [$email])->exists();
    }

    /** @param array<string, mixed> $pending */
    private function clearPendingContact(Request $request, array $pending): void
    {
        unset($pending['contact'], $pending['proof']);
        $request->session()->put('microsoft.pending', $pending);
    }

    private function verificationStatus(): string
    {
        return 'If this address is eligible, check its inbox for a verification link. Open it in this browser session; submit the form again if you need a fresh link.';
    }

    private function failure(string $message = 'Microsoft sign-in could not be completed. Please try again.'): RedirectResponse
    {
        return to_route('login')->withErrors(['microsoft' => $message]);
    }
}
