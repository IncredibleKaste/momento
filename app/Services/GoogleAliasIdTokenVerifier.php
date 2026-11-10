<?php

namespace App\Services;

use Facile\JoseVerifier\IdTokenVerifierInterface;
use Facile\JoseVerifier\TokenVerifierInterface;
use RuntimeException;
use Throwable;

/** @internal */
final class GoogleAliasIdTokenVerifier implements IdTokenVerifierInterface
{
    /** @param list<IdTokenVerifierInterface> $delegates */
    public function __construct(private array $delegates) {}

    public function withNonce(?string $nonce): IdTokenVerifierInterface
    {
        return $this->withDelegates(fn (IdTokenVerifierInterface $verifier): TokenVerifierInterface => $verifier->withNonce($nonce));
    }

    public function withMaxAge(?int $maxAge): IdTokenVerifierInterface
    {
        return $this->withDelegates(fn (IdTokenVerifierInterface $verifier): TokenVerifierInterface => $verifier->withMaxAge($maxAge));
    }

    public function withAccessToken(?string $accessToken): IdTokenVerifierInterface
    {
        return $this->withDelegates(fn (IdTokenVerifierInterface $verifier): IdTokenVerifierInterface => $verifier->withAccessToken($accessToken));
    }

    public function withCode(?string $code): IdTokenVerifierInterface
    {
        return $this->withDelegates(fn (IdTokenVerifierInterface $verifier): IdTokenVerifierInterface => $verifier->withCode($code));
    }

    public function withState(?string $state): IdTokenVerifierInterface
    {
        return $this->withDelegates(fn (IdTokenVerifierInterface $verifier): IdTokenVerifierInterface => $verifier->withState($state));
    }

    /** @param callable(IdTokenVerifierInterface): TokenVerifierInterface $change */
    private function withDelegates(callable $change): self
    {
        $delegates = [];
        foreach ($this->delegates as $verifier) {
            $changed = $change($verifier);
            if (! $changed instanceof IdTokenVerifierInterface) {
                throw new RuntimeException('Invalid ID token verifier.');
            }
            $delegates[] = $changed;
        }

        return new self($delegates);
    }

    public function verify(string $jwt): array
    {
        foreach ($this->delegates as $verifier) {
            try {
                return $verifier->verify($jwt);
            } catch (Throwable) {
                // The next complete verifier handles the other documented issuer spelling.
            }
        }

        throw new RuntimeException('Invalid Google ID token.');
    }
}
