<?php

declare(strict_types=1);

namespace Koh\WebMcp\Application\Contract;

/**
 * The port for identifying the client behind the current request.
 *
 * The Presentation layer binds every nonce it issues to the caller's identity,
 * and the API middleware later recomputes that identity to validate the nonce.
 * How the identity is derived — reverse-proxy trust, address narrowing — is
 * Infrastructure knowledge that must not leak into the layers issuing nonces.
 *
 * The method deliberately takes no request argument: a PSR-7 parameter would
 * pull the framework into this layer, and the only request anyone may
 * fingerprint through this port is the one currently being served — the same
 * one the middleware will see again. The adapter resolves it itself, so both
 * sides are guaranteed to hash the same request.
 */
interface ClientFingerprintInterface
{
    /**
     * @throws \RuntimeException when no request is being served — inventing a
     *                           fingerprint would bind every nonce to the same
     *                           shared identity
     */
    public function ofCurrentRequest(): string;
}
