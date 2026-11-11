<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\OidcContactRequest;
use App\Models\OidcIdentity;
use App\Models\User;
use App\Notifications\OidcContactVerification;
use App\Services\OidcAuthentication;
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

class OidcAuthenticationController extends Controller
{
    public function start(Request $request, string $provider, OidcAuthentication $oidc): HttpResponse|RedirectResponse
    {
        return $this->startProvider($request, $provider, $oidc);
    }

    public function microsoftIndex(OidcAuthentication $oidc): Response|RedirectResponse
    {
        $organizations = $oidc->microsoftOrganizations();
        if (! $oidc->providerEnabled('microsoft') || $organizations === []) {
            return $this->failure();
        }

        return Inertia::render('auth/microsoft', [
            'organizations' => array_map(fn (string $key, array $organization): array => ['key' => $key, 'name' => $organization['name']],
                array_keys($organizations), array_values($organizations)),
        ]);
    }

    public function startMicrosoft(Request $request, string $organization, OidcAuthentication $oidc): HttpResponse|RedirectResponse
    {
        $request->session()->forget(['oidc.transaction', 'oidc.pending']);
        $entry = $oidc->microsoftOrganizations()[$organization] ?? null;
        if (! is_array($entry)) {
            return $this->failure();
        }

        return $this->startProvider($request, 'microsoft', $oidc, $organization, $entry['id']);
    }

    private function startProvider(Request $request, string $provider, OidcAuthentication $oidc, ?string $tenantSlug = null, ?string $tenantId = null): HttpResponse|RedirectResponse
    {
        $request->session()->forget(['oidc.transaction', 'oidc.pending']);
        try {
            if (! $oidc->providerEnabled($provider)) {
                return $this->failure();
            }
            $transaction = [
                'state' => Str::random(64),
                'nonce' => Str::random(64),
                'code_verifier' => Str::random(64),
                ...($provider === 'microsoft' ? ['tenant_slug' => $tenantSlug, 'tenant_id' => $tenantId] : []),
            ];
            $url = $oidc->authorizationUrl($provider, $transaction);
            $request->session()->put('oidc.transaction', [
                ...$transaction,
                'provider' => $provider,
                'expires_at' => now()->addMinutes(5)->timestamp,
            ]);

            return Inertia::location($url);
        } catch (Throwable) {
            return $this->failure();
        }
    }

    public function callback(Request $request, string $provider, OidcAuthentication $oidc): RedirectResponse
    {
        return $this->completeCallback($request, $provider, $oidc);
    }

    public function microsoftCallback(Request $request, OidcAuthentication $oidc): RedirectResponse
    {
        return $this->completeCallback($request, 'microsoft', $oidc);
    }

    private function completeCallback(Request $request, string $provider, OidcAuthentication $oidc): RedirectResponse
    {
        $transaction = $request->session()->pull('oidc.transaction');
        $request->session()->forget('oidc.pending');
        try {
            if (! is_array($transaction) || ($transaction['provider'] ?? null) !== $provider
                || ! is_string($transaction['state'] ?? null) || ! is_string($transaction['nonce'] ?? null)
                || ! is_string($transaction['code_verifier'] ?? null) || ! is_int($transaction['expires_at'] ?? null)
                || $transaction['expires_at'] <= now()->timestamp || ! is_string($request->query('state'))
                || ! hash_equals($transaction['state'], $request->query('state'))
                || ! Cache::add('oidc:callback:'.hash('sha256', $provider.':'.$transaction['state']), true, 600)) {
                return $this->failure();
            }
            if ($request->has('error')) {
                return $this->failure('Sign-in was cancelled or declined. Please try again.');
            }
            $code = $request->query('code');
            if (! is_string($code) || $code === '' || strlen($code) > 4096) {
                return $this->failure();
            }

            $identity = $oidc->identity($provider, $code, $transaction);
            $stored = OidcIdentity::query()->with('user')
                ->where('provider', $identity['provider'])
                ->where('issuer', $identity['issuer'])
                ->when($identity['stable_subject'] !== null,
                    fn ($query) => $query->where('stable_subject', $identity['stable_subject']),
                    fn ($query) => $query->where('subject', $identity['subject']))
                ->first();
            if ($stored !== null) {
                if ($stored->subject !== $identity['subject'] || $stored->disabled_at !== null || $stored->user->disabled_at !== null
                    || filled($stored->user->getAuthPassword())
                    || ! $oidc->providerAllowsIdentity($stored->provider, $stored->issuer, $stored->institution_domain, $stored->stable_subject)) {
                    return $this->failure();
                }
                Auth::login($stored->user);
                $request->session()->regenerate();

                return to_route($stored->user->hasVerifiedEmail() ? 'dashboard' : 'verification.notice');
            }

            $request->session()->regenerate();
            $request->session()->put('oidc.pending', [
                ...$identity,
                'expires_at' => now()->addMinutes(10)->timestamp,
                'flow' => Str::random(64),
            ]);

            return to_route('oidc.contact');
        } catch (Throwable) {
            return $this->failure();
        }
    }

    public function contact(Request $request, OidcAuthentication $oidc): Response|RedirectResponse
    {
        $pending = $this->pendingIdentity($request, $oidc);
        if ($pending === null) {
            return $this->failure();
        }

        return Inertia::render('auth/oidc-contact', [
            'suggestedName' => $pending['name'],
            'status' => $request->session()->get('status'),
        ]);
    }

    public function storeContact(OidcContactRequest $request, OidcAuthentication $oidc): RedirectResponse
    {
        $pending = $this->pendingIdentity($request, $oidc);
        if ($pending === null) {
            return $this->failure();
        }
        $contact = $request->validated();
        if ($this->contactEmailExists($contact['email']) || $this->identityExists($pending)) {
            return to_route('oidc.contact')->with('status', $this->verificationStatus());
        }

        $pending['contact'] = $contact;
        $pending['proof'] = Str::random(64);
        $request->session()->put('oidc.pending', $pending);
        $expiresAt = min($pending['expires_at'], now()->addMinutes(10)->timestamp);
        $url = URL::temporarySignedRoute('oidc.contact.verify', now()->setTimestamp($expiresAt), ['proof' => $pending['proof']]);

        try {
            Notification::route('mail', $contact['email'])->notify(new OidcContactVerification($url));
        } catch (TransportExceptionInterface $exception) {
            report($exception);

            return back()->withErrors(['email' => 'The verification email could not be sent. Please try again in a moment.']);
        }

        return to_route('oidc.contact')->with('status', $this->verificationStatus());
    }

    public function verifyContact(Request $request, string $proof, OidcAuthentication $oidc): Response|RedirectResponse
    {
        $pending = $this->pendingContact($request, $oidc, $proof);
        if ($pending === null) {
            return $this->failure();
        }

        return Inertia::render('auth/oidc-contact-confirm', [
            'email' => $pending['contact']['email'],
            'confirmUrl' => $request->fullUrl(),
        ]);
    }

    public function completeContact(Request $request, string $proof, OidcAuthentication $oidc): RedirectResponse
    {
        $pending = $this->pendingContact($request, $oidc, $proof);
        if ($pending === null || ! Cache::add('oidc:contact:'.hash('sha256', $pending['flow'].':'.$proof), true, 600)) {
            return $this->failure();
        }

        try {
            if ($this->contactEmailExists($pending['contact']['email']) || $this->identityExists($pending)) {
                $this->clearPendingContact($request, $pending);

                return to_route('oidc.contact')->with('status', $this->verificationStatus());
            }
            $user = DB::transaction(function () use ($pending): User {
                $user = User::create([...$pending['contact'], 'password' => null]);
                $user->forceFill(['email_verified_at' => now()])->save();
                $identity = new OidcIdentity;
                $identity->forceFill([
                    'user_id' => $user->id,
                    'provider' => $pending['provider'],
                    'issuer' => $pending['issuer'],
                    'subject' => $pending['subject'],
                    'stable_subject' => $pending['stable_subject'],
                    'institution_domain' => $pending['institution_domain'],
                ])->save();

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            $this->clearPendingContact($request, $pending);

            return to_route('oidc.contact')->with('status', $this->verificationStatus());
        } catch (Throwable) {
            return $this->failure();
        }

        $request->session()->forget('oidc.pending');
        Auth::login($user);
        $request->session()->regenerate();
        event(new Registered($user));

        return to_route('dashboard');
    }

    /** @return array{provider: string, issuer: string, subject: string, stable_subject: string|null, institution_domain: string|null, name: string, expires_at: int, flow: string}|null */
    private function pendingIdentity(Request $request, OidcAuthentication $oidc): ?array
    {
        $pending = $request->session()->get('oidc.pending');
        if (! is_array($pending) || ! is_string($pending['provider'] ?? null) || ! is_string($pending['issuer'] ?? null)
            || ! is_string($pending['subject'] ?? null) || ! is_string($pending['name'] ?? null)
            || ! (is_string($pending['stable_subject'] ?? null) || ($pending['stable_subject'] ?? null) === null)
            || ! (is_string($pending['institution_domain'] ?? null) || ($pending['institution_domain'] ?? null) === null)
            || ! is_string($pending['flow'] ?? null) || ! is_int($pending['expires_at'] ?? null)
            || $pending['expires_at'] <= now()->timestamp
            || ! $oidc->providerAllowsIdentity($pending['provider'], $pending['issuer'], $pending['institution_domain'], $pending['stable_subject'] ?? null)) {
            return null;
        }

        return [
            'provider' => $pending['provider'],
            'issuer' => $pending['issuer'],
            'subject' => $pending['subject'],
            'stable_subject' => $pending['stable_subject'] ?? null,
            'institution_domain' => $pending['institution_domain'],
            'name' => $pending['name'],
            'expires_at' => $pending['expires_at'],
            'flow' => $pending['flow'],
        ];
    }

    /** @return array{provider: string, issuer: string, subject: string, stable_subject: string|null, institution_domain: string|null, name: string, expires_at: int, flow: string, contact: array{name: string, email: string}, proof: string}|null */
    private function pendingContact(Request $request, OidcAuthentication $oidc, string $proof): ?array
    {
        $pending = $this->pendingIdentity($request, $oidc);
        if ($pending === null) {
            return null;
        }

        $storedPending = $request->session()->get('oidc.pending');
        if (! is_array($storedPending)) {
            return null;
        }
        $contact = $storedPending['contact'] ?? null;
        $storedProof = $storedPending['proof'] ?? null;
        if (! is_array($contact) || ! is_string($contact['name'] ?? null)
            || ! is_string($contact['email'] ?? null) || ! is_string($storedProof)
            || ! hash_equals($storedProof, $proof)) {
            return null;
        }

        return [
            ...$pending,
            'contact' => ['name' => $contact['name'], 'email' => $contact['email']],
            'proof' => $storedProof,
        ];
    }

    private function contactEmailExists(string $email): bool
    {
        return User::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->exists();
    }

    /** @param array<string, mixed> $pending */
    private function identityExists(array $pending): bool
    {
        return OidcIdentity::query()->where('provider', $pending['provider'])
            ->where('issuer', $pending['issuer'])
            ->where(fn ($query) => $query->where('subject', $pending['subject'])
                ->when($pending['stable_subject'] !== null, fn ($query) => $query->orWhere('stable_subject', $pending['stable_subject'])))
            ->exists();
    }

    /** @param array<string, mixed> $pending */
    private function clearPendingContact(Request $request, array $pending): void
    {
        unset($pending['contact'], $pending['proof']);
        $request->session()->put('oidc.pending', $pending);
    }

    private function verificationStatus(): string
    {
        return 'If this address is eligible, check its inbox for a verification link. Open it in this browser session; submit the form again if you need a fresh link.';
    }

    private function failure(string $message = 'Sign-in could not be completed. Please try again.'): RedirectResponse
    {
        return to_route('login')->withErrors(['oidc' => $message]);
    }
}
