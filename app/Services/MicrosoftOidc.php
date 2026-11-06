<?php

namespace App\Services;

use Facile\JoseVerifier\JWK\JwksProviderBuilder;
use Facile\OpenIDClient\Client\ClientBuilder;
use Facile\OpenIDClient\Client\ClientInterface;
use Facile\OpenIDClient\Client\Metadata\ClientMetadata;
use Facile\OpenIDClient\Issuer\IssuerBuilder;
use Facile\OpenIDClient\Issuer\Metadata\Provider\MetadataProviderBuilder;
use Facile\OpenIDClient\Service\Builder\AuthorizationServiceBuilder;
use Facile\OpenIDClient\Session\AuthSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Psr\Http\Client\ClientInterface as HttpClient;
use RuntimeException;

class MicrosoftOidc
{
    public const CONSUMER_TENANT = '9188040d-6c67-4c5b-b112-36a304b66dad';

    public function __construct(private HttpClient $http) {}

    /** @return array<string, array{id: string, name: string}> */
    public function organizations(): array
    {
        $organizations = config('services.microsoft.tenants', []);
        $clientId = config('services.microsoft.client_id');
        $secret = config('services.microsoft.client_secret');
        if (! is_array($organizations) || ! is_string($clientId) || ! Str::isUuid($clientId)
            || ! is_string($secret) || blank($secret)) {
            return [];
        }

        return array_filter($organizations, fn ($organization, $key): bool => is_string($key)
            && preg_match('/\A[a-z0-9-]{1,40}\z/', $key)
            && is_array($organization)
            && is_string($organization['id'] ?? null) && Str::isUuid($organization['id'])
            && strtolower($organization['id']) !== self::CONSUMER_TENANT
            && strtolower($organization['id']) !== strtolower($clientId)
            && is_string($organization['name'] ?? null) && filled($organization['name']), ARRAY_FILTER_USE_BOTH);
    }

    public function allowsTenant(string $tenant): bool
    {
        return in_array($tenant, array_map(fn (array $organization): string => strtolower($organization['id']), $this->organizations()), true);
    }

    /** @param array{state: string, nonce: string, code_verifier: string} $transaction */
    public function authorizationUrl(string $tenant, array $transaction): string
    {
        return (new AuthorizationServiceBuilder)->build()->getAuthorizationUri($this->client($tenant), [
            'prompt' => 'select_account', 'scope' => 'openid profile email', 'response_type' => 'code', 'response_mode' => 'query',
            'state' => $transaction['state'], 'nonce' => $transaction['nonce'],
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $transaction['code_verifier'], true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * @param  array{state: string, nonce: string, code_verifier: string}  $transaction
     * @return array{tenant_id: string, object_id: string, subject: string, name: string}
     */
    public function identity(string $tenant, string $code, array $transaction): array
    {
        $builder = new AuthorizationServiceBuilder;
        $builder->setHttpClient($this->http);
        $tokens = $builder->build()
            ->callback($this->client($tenant), ['code' => $code], $this->redirectUri(), AuthSession::fromArray($transaction));
        $claims = $tokens->claims();
        if (! $tokens->getIdToken() || ($claims['tid'] ?? null) !== $tenant
            || ! is_string($claims['oid'] ?? null) || ! Str::isUuid($claims['oid'])
            || ! is_string($claims['sub'] ?? null) || blank($claims['sub']) || strlen($claims['sub']) > 255
            || ($claims['ver'] ?? null) !== '2.0'
            || ! is_string($claims['idp'] ?? $claims['iss'] ?? null)
            || ($claims['idp'] ?? null) === 'live.com'
            || str_contains($claims['idp'] ?? '', self::CONSUMER_TENANT)) {
            throw new RuntimeException('Invalid Microsoft identity.');
        }

        return [
            'tenant_id' => $tenant, 'object_id' => strtolower($claims['oid']), 'subject' => $claims['sub'],
            'name' => is_string($claims['name'] ?? null) ? Str::limit($claims['name'], 255, '') : '',
        ];
    }

    /** @return non-empty-string */
    private function redirectUri(): string
    {
        $uri = config('services.microsoft.redirect_uri');
        $parts = is_string($uri) ? parse_url($uri) : false;
        if (! is_string($uri) || $uri === '' || ! $parts || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ($parts['path'] ?? '') !== '/auth/microsoft/callback'
            || (($parts['scheme'] ?? '') !== 'https' && ! (app()->environment('local', 'testing')
                && ($parts['scheme'] ?? '') === 'http' && in_array($parts['host'] ?? '', ['localhost', '127.0.0.1'], true)))) {
            throw new RuntimeException('Invalid Microsoft callback configuration.');
        }

        return $uri;
    }

    private function client(string $tenant): ClientInterface
    {
        if (! $this->allowsTenant($tenant)) {
            throw new RuntimeException('Microsoft organization is unavailable.');
        }
        $authority = 'https://login.microsoftonline.com/'.$tenant.'/v2.0';
        $cache = Cache::store();
        $issuer = (new IssuerBuilder)
            ->setMetadataProviderBuilder((new MetadataProviderBuilder)->setHttpClient($this->http)->setCache($cache)->setCacheTtl(3600))
            ->setJwksProviderBuilder((new JwksProviderBuilder)->withHttpClient($this->http)->withCache($cache)->withCacheTtl(300))
            ->build($authority.'/.well-known/openid-configuration');
        $metadata = $issuer->getMetadata();
        if ($metadata->getIssuer() !== $authority) {
            throw new RuntimeException('Invalid Microsoft issuer configuration.');
        }
        foreach ([$metadata->getAuthorizationEndpoint(), $metadata->getTokenEndpoint(), $metadata->getJwksUri()] as $endpoint) {
            $parts = parse_url($endpoint ?? '');
            if (! $parts || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'login.microsoftonline.com'
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])) {
                throw new RuntimeException('Invalid Microsoft endpoint configuration.');
            }
        }

        $clientId = config('services.microsoft.client_id');
        $secret = config('services.microsoft.client_secret');
        if (! is_string($clientId) || $clientId === '' || ! is_string($secret) || $secret === '') {
            throw new RuntimeException('Invalid Microsoft client configuration.');
        }

        return (new ClientBuilder)->setIssuer($issuer)->setHttpClient($this->http)
            ->setClientMetadata(ClientMetadata::fromArray([
                'client_id' => $clientId, 'client_secret' => $secret,
                'redirect_uris' => [$this->redirectUri()], 'token_endpoint_auth_method' => 'client_secret_post',
                'id_token_signed_response_alg' => 'RS256', 'response_types' => ['code'],
            ]))->build();
    }
}
