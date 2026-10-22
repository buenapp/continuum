<?php

namespace Continuum\Identity;

/**
 * Continuum's attestation signer (docs/IDENTITY.md 6.3).
 *
 * The Ed25519 key material named by [identity] sign_key_file is loaded
 * once per process and signs, on every message_send, the canonical
 * covered set plus the authenticated workstream. Unset setting = no
 * attestation and a byte-identical send path.
 *
 * Two raw key layouts are accepted (docs/IDENTITY.md 10):
 *   64 bytes - the sodium secret key (seed || public key), the
 *              contract's "raw 64-byte keypair"
 *   96 bytes - the full sodium_crypto_sign_keypair() keypair
 * Both are sodium's raw formats; the public key is derivable from
 * either and is published as registry workstream 'continuum'.
 */
class Attestor {

    private string $secretKey;
    private string $publicKey;

    /** @param string $keyMaterial raw 64-byte secret key or 96-byte sodium keypair */
    public function __construct(string $keyMaterial) {
        $length = strlen($keyMaterial);
        if ($length === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            $this->secretKey = $keyMaterial;
            $this->publicKey = substr($keyMaterial, SODIUM_CRYPTO_SIGN_SEEDBYTES);
        } elseif ($length === SODIUM_CRYPTO_SIGN_KEYPAIRBYTES) {
            $this->secretKey = sodium_crypto_sign_secretkey($keyMaterial);
            $this->publicKey = sodium_crypto_sign_publickey($keyMaterial);
        } else {
            throw new \RuntimeException('sign key must be a raw ' . SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
                . '-byte sodium secret key or a ' . SODIUM_CRYPTO_SIGN_KEYPAIRBYTES
                . '-byte sodium keypair (sodium_crypto_sign_keypair format)');
        }
    }

    public static function fromFile(string $path): self {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("cannot read sign key file '{$path}'");
        }
        return new self($raw);
    }

    /** base64 Ed25519 signature over the canonical attest covered set. */
    public function attest(array $envelope, string $workstream): string {
        $message = Envelope::canonical(Envelope::attestCovered($envelope, $workstream));
        return base64_encode(sodium_crypto_sign_detached($message, $this->secretKey));
    }

    /** base64 public key, published as registry workstream 'continuum'. */
    public function publicKey(): string {
        return base64_encode($this->publicKey);
    }
}
