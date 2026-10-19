<?php declare(strict_types=1);

namespace SecNtfyPHP;

final class MsgCrypto
{
    private string $publicKeyB64;
    private string $msg;
    private ?\OpenSSLAsymmetricKey $publicKey = null;
    private int $maxPlaintextBytes = 0;

    public function __construct(string $publicKeyB64, string $msg)
    {
        $this->publicKeyB64 = $publicKeyB64;
        $this->msg = $msg;
    }

    /**
     * Verschlüsselt $msg mit RSA/PKCS#1 v1.5.
     * Nimmt Base64-kodierte DER-Bytes entgegen:
     *  - "PUBLIC KEY" (SubjectPublicKeyInfo, X.509)
     *  - oder "RSA PUBLIC KEY" (PKCS#1)
     */
    public function encrypt(): string
    {
        $publicKey = $this->getPublicKey();
        if (strlen($this->msg) > $this->maxPlaintextBytes) {
            throw new \LengthException("RSA plaintext exceeds {$this->maxPlaintextBytes} bytes");
        }

        $ok = \openssl_public_encrypt($this->msg, $cipher, $publicKey, \OPENSSL_PKCS1_PADDING);
        if (!$ok) {
            throw new \RuntimeException('RSA encryption failed');
        }
        return base64_encode($cipher);
    }

    public function getMaxPlaintextBytes(): int
    {
        $this->getPublicKey();
        return $this->maxPlaintextBytes;
    }

    private function getPublicKey(): \OpenSSLAsymmetricKey
    {
        if ($this->publicKey !== null) {
            return $this->publicKey;
        }

        $der = base64_decode($this->publicKeyB64, true);
        if ($der === false || $der === '') {
            throw new \RuntimeException('Invalid base64 public key');
        }

        $this->clearOpenSslErrors();
        $publicKey = false;
        foreach (['PUBLIC KEY', 'RSA PUBLIC KEY'] as $type) {
            $publicKey = @\openssl_pkey_get_public($this->derToPem($der, $type));
            if ($publicKey !== false) {
                break;
            }
        }
        $this->clearOpenSslErrors();
        if ($publicKey === false) {
            throw new \RuntimeException('Unable to parse RSA public key');
        }

        $details = \openssl_pkey_get_details($publicKey);
        if ($details === false || $details['type'] !== \OPENSSL_KEYTYPE_RSA) {
            throw new \RuntimeException('Public key must be an RSA key');
        }
        $maxPlaintextBytes = intdiv($details['bits'] + 7, 8) - 11;
        if ($maxPlaintextBytes < 0) {
            throw new \RuntimeException('RSA public key is too small');
        }

        $this->publicKey = $publicKey;
        $this->maxPlaintextBytes = $maxPlaintextBytes;
        return $this->publicKey;
    }

    private function clearOpenSslErrors(): void
    {
        while (\openssl_error_string() !== false) {
        }
    }

    private function derToPem(string $der, string $type): string
    {
        $b64 = chunk_split(base64_encode($der), 64, "\n");
        return "-----BEGIN {$type}-----\n{$b64}-----END {$type}-----\n";
    }
}
