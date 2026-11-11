<?php

namespace App\Services;

use Facile\JoseVerifier\Builder\IdTokenVerifierBuilder as JoseIdTokenVerifierBuilder;
use Facile\JoseVerifier\IdTokenVerifierInterface;
use Facile\OpenIDClient\Client\ClientInterface;
use Facile\OpenIDClient\Token\IdTokenVerifierBuilderInterface;

/** Verifies both documented Google issuer spellings with complete library verifiers. */
final class GoogleAliasIdTokenVerifierBuilder implements IdTokenVerifierBuilderInterface
{
    public function build(ClientInterface $client): IdTokenVerifierInterface
    {
        $issuer = $client->getIssuer()->getMetadata()->toArray();
        $metadata = $client->getMetadata()->toArray();
        $verifiers = [];
        foreach (['https://accounts.google.com', 'accounts.google.com'] as $expectedIssuer) {
            $issuer['issuer'] = $expectedIssuer;
            $verifiers[] = JoseIdTokenVerifierBuilder::create($issuer, $metadata)
                ->withJwksProvider($client->getIssuer()->getJwksProvider())
                ->withExpectedAzp($metadata['client_id'])
                ->build();
        }

        return new GoogleAliasIdTokenVerifier($verifiers);
    }
}
