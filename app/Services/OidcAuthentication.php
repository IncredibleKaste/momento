<?php

namespace App\Services;

use Facile\JoseVerifier\JWK\JwksProviderBuilder;
use Facile\OpenIDClient\Client\ClientBuilder;
use Facile\OpenIDClient\Client\ClientInterface;
use Facile\OpenIDClient\Client\Metadata\ClientMetadata;
use Facile\OpenIDClient\Issuer\IssuerBuilder;
use Facile\OpenIDClient\Issuer\Metadata\Provider\MetadataProviderBuilder;
use Facile\OpenIDClient\Service\Builder\AuthorizationServiceBuilder;
use Facile\OpenIDClient\Token\IdTokenVerifierBuilder as SwitchIdTokenVerifierBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Psr\Http\Client\ClientInterface as HttpClient;
use RuntimeException;

class OidcAuthentication
{
    private const GOOGLE_ISSUER = 'https://accounts.google.com';

    private const MICROSOFT_CONSUMER_TENANT = '9188040d-6c67-4c5b-b112-36a304b66dad';

    private const SWITCH_ISSUERS = [
        'production' => 'https://login.eduid.ch/',
        'test' => 'https://login.test.eduid.ch/',
    ];

    public function __construct(private HttpClient $http) {}

    /** @return list<string> */
    public function availableProviders(): array
    {
        return array_values(array_filter(['google', 'switch', 'microsoft'], fn (string $provider): bool => $this->providerEnabled($provider)));
    }

    public function providerEnabled(string $provider): bool
    {
        $configuration = config('services.'.$provider);
        if (! is_array($configuration) || ! is_string($configuration['client_id'] ?? null) || blank($configuration['client_id'])
            || ! is_string($configuration['client_secret'] ?? null) || blank($configuration['client_secret'])
            || ! $this->validRedirectUri($provider, $configuration['redirect_uri'] ?? null)) {
            return false;
        }

        if ($provider === 'google') {
            return true;
        }
        if ($provider === 'microsoft') {
            return Str::isUuid($configuration['client_id']) && $this->microsoftOrganizations() !== [];
        }
        $environment = $configuration['environment'] ?? null;
        if ($provider !== 'switch' || ! is_string($environment) || ! isset(self::SWITCH_ISSUERS[$environment])) {
            return false;
        }

        return $this->institutionDomains() !== [];
    }

    public function providerAllowsIdentity(string $provider, string $issuer, ?string $institutionDomain, ?string $stableSubject = null): bool
    {
        if (! $this->providerEnabled($provider)) {
            return false;
        }
        if ($provider === 'google') {
            return $issuer === self::GOOGLE_ISSUER && $institutionDomain === null && $stableSubject === null;
        }

        if ($provider === 'microsoft') {
            $tenantId = $this->tenantIdFromIssuer($issuer);

            return $tenantId !== null && $institutionDomain === null && is_string($stableSubject)
                && Str::isUuid($stableSubject) && $this->allowsMicrosoftTenant($tenantId);
        }

        return $provider === 'switch'
            && $issuer === self::SWITCH_ISSUERS[config('services.switch.environment')]
            && is_string($institutionDomain)
            && $stableSubject === null
            && in_array($institutionDomain, $this->institutionDomains(), true);
    }

    /** @param array{state: string, nonce: string, code_verifier: string, tenant_slug?: string, tenant_id?: string} $transaction */
    public function authorizationUrl(string $provider, array $transaction): string
    {
        $tenantId = $provider === 'microsoft'
            ? $this->microsoftTenantForTransaction($transaction)
            : null;
        $client = $this->client($provider, $tenantId);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $transaction['code_verifier'], true)), '+/', '-_'), '=');
        $parameters = [
            'response_type' => 'code',
            'response_mode' => 'query',
            'state' => $transaction['state'],
            'nonce' => $transaction['nonce'],
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];

        if ($provider === 'google') {
            $parameters['prompt'] = 'select_account';
            $parameters['scope'] = 'openid profile';
        } elseif ($provider === 'microsoft') {
            $parameters['prompt'] = 'select_account';
            $parameters['scope'] = 'openid profile email';
        } else {
            $parameters['scope'] = 'openid';
            $parameters['claims'] = ['id_token' => [
                'eduid_idp' => ['essential' => true, 'values' => $this->institutionDomains()],
            ]];
        }

        return (new AuthorizationServiceBuilder)->build()->getAuthorizationUri($client, $parameters);
    }

    /**
     * @param  array{state: string, nonce: string, code_verifier: string, tenant_slug?: string, tenant_id?: string}  $transaction
     * @return array{provider: string, issuer: string, subject: string, stable_subject: string|null, institution_domain: string|null, name: string}
     */
    public function identity(string $provider, string $code, array $transaction): array
    {
        $tenantId = $provider === 'microsoft'
            ? $this->microsoftTenantForTransaction($transaction)
            : null;
        $client = $this->client($provider, $tenantId);
        $builder = new AuthorizationServiceBuilder;
        $builder->setHttpClient($this->http);
        $tokens = $builder->build()->grant($client, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri($provider),
            'code_verifier' => $transaction['code_verifier'],
        ]);
        $idToken = $tokens->getIdToken();
        if (! is_string($idToken)) {
            throw new RuntimeException('Invalid OIDC identity.');
        }
        $verifierBuilder = $provider === 'google' ? new GoogleAliasIdTokenVerifierBuilder : new SwitchIdTokenVerifierBuilder;
        $claims = $verifierBuilder->build($client)
            ->withState($transaction['state'])
            ->withCode($code)
            ->withAccessToken($tokens->getAccessToken())
            ->withNonce($transaction['nonce'])
            ->verify($idToken);
        if (! $this->validAudience($claims['aud'] ?? null, $this->clientId($provider))) {
            throw new RuntimeException('Invalid OIDC identity.');
        }
        if (array_key_exists('azp', $claims) && (! is_string($claims['azp']) || $claims['azp'] !== $this->clientId($provider))) {
            throw new RuntimeException('Invalid OIDC identity.');
        }

        $verifiedIssuer = $claims['iss'] ?? null;
        $issuer = match ($provider) {
            'google' => self::GOOGLE_ISSUER,
            'switch' => self::SWITCH_ISSUERS[config('services.switch.environment')],
            'microsoft' => $this->microsoftIssuer($tenantId),
            default => throw new RuntimeException('OIDC provider is unavailable.'),
        };
        $issuerMatches = $provider === 'google'
            ? in_array($verifiedIssuer, [self::GOOGLE_ISSUER, 'accounts.google.com'], true)
            : $verifiedIssuer === $issuer;
        if (! $issuerMatches || ! is_string($claims['sub'] ?? null)
            || trim($claims['sub']) === '' || strlen($claims['sub']) > 255) {
            throw new RuntimeException('Invalid OIDC identity.');
        }
        $institutionDomain = null;
        $stableSubject = null;
        if ($provider === 'switch') {
            $institutionDomain = $claims['eduid_idp'] ?? null;
            if (! is_string($institutionDomain) || $institutionDomain !== strtolower($institutionDomain)
                || ! in_array($institutionDomain, $this->institutionDomains(), true)) {
                throw new RuntimeException('Invalid OIDC identity.');
            }
        } elseif ($provider === 'microsoft') {
            $tenant = $claims['tid'] ?? null;
            $object = $claims['oid'] ?? null;
            $identityProvider = $claims['idp'] ?? null;
            if ($tenant !== $tenantId || ! is_string($object) || ! Str::isUuid($object)
                || ($claims['ver'] ?? null) !== '2.0'
                || (is_string($identityProvider) && (strtolower($identityProvider) === 'live.com'
                    || str_contains(strtolower($identityProvider), self::MICROSOFT_CONSUMER_TENANT)))) {
                throw new RuntimeException('Invalid OIDC identity.');
            }
            $stableSubject = strtolower($object);
        }

        return [
            'provider' => $provider,
            'issuer' => $issuer,
            'subject' => $claims['sub'],
            'stable_subject' => $stableSubject,
            'institution_domain' => $institutionDomain,
            'name' => in_array($provider, ['google', 'microsoft'], true) && is_string($claims['name'] ?? null) ? Str::limit(trim($claims['name']), 255, '') : '',
        ];
    }

    /** @return array<string, array{id: string, name: string}> */
    public function microsoftOrganizations(): array
    {
        $organizations = config('services.microsoft.tenants', []);
        $clientId = config('services.microsoft.client_id');
        if (! is_array($organizations) || ! is_string($clientId) || ! Str::isUuid($clientId)) {
            return [];
        }

        $valid = [];
        foreach ($organizations as $slug => $organization) {
            if (! is_string($slug) || preg_match('/\A[a-z0-9-]{1,40}\z/', $slug) !== 1 || ! is_array($organization)
                || ! is_string($organization['id'] ?? null) || ! Str::isUuid($organization['id'])
                || strtolower($organization['id']) === self::MICROSOFT_CONSUMER_TENANT
                || strtolower($organization['id']) === strtolower($clientId)
                || ! is_string($organization['name'] ?? null) || blank($organization['name'])) {
                continue;
            }
            $valid[$slug] = ['id' => strtolower($organization['id']), 'name' => Str::limit(trim($organization['name']), 100, '')];
        }

        return $valid;
    }

    /** @param array<string, mixed> $transaction */
    private function microsoftTenantForTransaction(array $transaction): string
    {
        $slug = $transaction['tenant_slug'] ?? null;
        $id = $transaction['tenant_id'] ?? null;
        $organization = is_string($slug) ? ($this->microsoftOrganizations()[$slug] ?? null) : null;
        if (! is_array($organization) || ! is_string($id) || ! hash_equals($organization['id'], strtolower($id))) {
            throw new RuntimeException('Microsoft organization is unavailable.');
        }

        return $organization['id'];
    }

    private function allowsMicrosoftTenant(string $tenantId): bool
    {
        foreach ($this->microsoftOrganizations() as $organization) {
            if (hash_equals($organization['id'], strtolower($tenantId))) {
                return true;
            }
        }

        return false;
    }

    private function tenantIdFromIssuer(string $issuer): ?string
    {
        if (preg_match('#\Ahttps://login\.microsoftonline\.com/([0-9a-f-]{36})/v2\.0\z#D', $issuer, $matches) !== 1
            || ! Str::isUuid($matches[1])) {
            return null;
        }

        return strtolower($matches[1]);
    }

    private function microsoftIssuer(?string $tenantId): string
    {
        if (! is_string($tenantId) || ! Str::isUuid($tenantId)) {
            throw new RuntimeException('Microsoft organization is unavailable.');
        }

        return 'https://login.microsoftonline.com/'.strtolower($tenantId).'/v2.0';
    }

    private function client(string $provider, ?string $tenantId = null): ClientInterface
    {
        if (! $this->providerEnabled($provider)) {
            throw new RuntimeException('OIDC provider is unavailable.');
        }

        [$discovery, $expectedIssuer] = match ($provider) {
            'google' => [
                'https://accounts.google.com/.well-known/openid-configuration',
                self::GOOGLE_ISSUER,
            ],
            'switch' => [
                self::SWITCH_ISSUERS[config('services.switch.environment')].'.well-known/openid-configuration',
                self::SWITCH_ISSUERS[config('services.switch.environment')],
            ],
            'microsoft' => [
                $this->microsoftIssuer($tenantId).'/.well-known/openid-configuration',
                $this->microsoftIssuer($tenantId),
            ],
            default => throw new RuntimeException('OIDC provider is unavailable.'),
        };
        $issuer = (new IssuerBuilder)
            ->setMetadataProviderBuilder((new MetadataProviderBuilder)
                ->setHttpClient($this->http)->setCache(Cache::store())->setCacheTtl(3600))
            ->setJwksProviderBuilder((new JwksProviderBuilder)
                ->withHttpClient($this->http)->withCache(Cache::store())->withCacheTtl(300))
            ->build($discovery);
        $metadata = $issuer->getMetadata();
        if ($metadata->getIssuer() !== $expectedIssuer) {
            throw new RuntimeException('Invalid OIDC issuer metadata.');
        }
        $this->validateEndpoints($provider, $metadata->getAuthorizationEndpoint(), $metadata->getTokenEndpoint(), $metadata->getJwksUri(), $tenantId);

        return (new ClientBuilder)->setIssuer($issuer)->setHttpClient($this->http)->setClientMetadata(ClientMetadata::fromArray([
            'client_id' => $this->clientId($provider),
            'client_secret' => $this->clientSecret($provider),
            'redirect_uris' => [$this->redirectUri($provider)],
            'token_endpoint_auth_method' => 'client_secret_post',
            'id_token_signed_response_alg' => 'RS256',
            'response_types' => ['code'],
        ]))->build();
    }

    private function validateEndpoints(string $provider, string $authorization, ?string $token, string $jwks, ?string $tenantId = null): void
    {
        $endpoints = [$authorization, $token, $jwks];
        if (in_array(null, $endpoints, true)) {
            throw new RuntimeException('Invalid OIDC endpoint metadata.');
        }
        if ($provider === 'google') {
            if ($endpoints !== [
                'https://accounts.google.com/o/oauth2/v2/auth',
                'https://oauth2.googleapis.com/token',
                'https://www.googleapis.com/oauth2/v3/certs',
            ]) {
                throw new RuntimeException('Invalid Google endpoint metadata.');
            }

            return;
        }

        if ($provider === 'microsoft') {
            $authority = 'https://login.microsoftonline.com/'.strtolower((string) $tenantId);
            if ($endpoints !== [
                $authority.'/oauth2/v2.0/authorize',
                $authority.'/oauth2/v2.0/token',
                $authority.'/discovery/v2.0/keys',
            ]) {
                throw new RuntimeException('Invalid Microsoft endpoint metadata.');
            }

            return;
        }

        $host = parse_url(self::SWITCH_ISSUERS[config('services.switch.environment')], PHP_URL_HOST);
        foreach ($endpoints as $endpoint) {
            $parts = parse_url($endpoint);
            if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== $host
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])) {
                throw new RuntimeException('Invalid SWITCH endpoint metadata.');
            }
        }
    }

    /** @return list<string> */
    private function institutionDomains(): array
    {
        $configured = config('services.switch.institutional_domains', []);
        if (! is_array($configured)) {
            return [];
        }

        $domains = [];
        foreach ($configured as $domain) {
            if (! is_string($domain)) {
                continue;
            }
            $domain = strtolower(trim($domain));
            if ($domain !== 'eduid.ch' && preg_match('/\A(?=.{3,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/D', $domain) === 1) {
                $domains[] = $domain;
            }
        }

        return array_values(array_unique($domains));
    }

    private function validRedirectUri(string $provider, mixed $uri): bool
    {
        $parts = is_string($uri) ? parse_url($uri) : false;
        $expectedPath = $provider === 'microsoft' ? '/auth/microsoft/callback' : '/auth/oidc/'.$provider.'/callback';
        if (! is_string($uri) || ! is_array($parts) || ! is_string($parts['host'] ?? null) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ($parts['path'] ?? '') !== $expectedPath) {
            return false;
        }

        $scheme = $parts['scheme'] ?? '';
        $host = $parts['host'];
        $port = $parts['port'] ?? null;
        $loopbackHttp = app()->environment('local', 'testing') && $scheme === 'http'
            && in_array($host, ['localhost', '127.0.0.1'], true)
            && ($port === null || $port >= 1);

        return ($scheme === 'https' && $port === null) || $loopbackHttp;
    }

    /** @return non-empty-string */
    private function redirectUri(string $provider): string
    {
        $uri = config('services.'.$provider.'.redirect_uri');
        if (! is_string($uri) || $uri === '' || ! $this->validRedirectUri($provider, $uri)) {
            throw new RuntimeException('Invalid OIDC callback configuration.');
        }

        return $uri;
    }

    /** @return non-empty-string */
    private function clientId(string $provider): string
    {
        $clientId = config('services.'.$provider.'.client_id');
        if (! is_string($clientId) || $clientId === '') {
            throw new RuntimeException('Invalid OIDC client configuration.');
        }

        return $clientId;
    }

    /** @return non-empty-string */
    private function clientSecret(string $provider): string
    {
        $clientSecret = config('services.'.$provider.'.client_secret');
        if (! is_string($clientSecret) || $clientSecret === '') {
            throw new RuntimeException('Invalid OIDC client configuration.');
        }

        return $clientSecret;
    }

    private function validAudience(mixed $audience, string $clientId): bool
    {
        return $audience === $clientId || (is_array($audience) && array_values($audience) === [$clientId]);
    }
}
