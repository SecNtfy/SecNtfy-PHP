<?php declare(strict_types=1);

namespace SecNtfyPHP;

final class MsgCrypto
{
    private string $publicKeyB64;
    private string $msg;

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
        $der = base64_decode($this->publicKeyB64, true);
        if ($der === false) {
            throw new \RuntimeException('Invalid base64 public key');
        }

        // Heuristik wie im C#: Länge 294 -> meist SPKI; sonst PKCS#1
        // (am Ende bauen wir in beiden Fällen eine gültige PEM-Hülle)
        $type = (strlen($der) === 294) ? 'PUBLIC KEY' : 'RSA PUBLIC KEY';

        $pem = $this->derToPem($der, $type);

        $res = \openssl_pkey_get_public($pem);
        if ($res === false) {
            // Fallback: wenn SPKI/PKCS1-Erkennung daneben lag, einmal gegentauschen
            $typeAlt = $type === 'PUBLIC KEY' ? 'RSA PUBLIC KEY' : 'PUBLIC KEY';
            $pemAlt = $this->derToPem($der, $typeAlt);
            $res = \openssl_pkey_get_public($pemAlt);
            if ($res === false) {
                throw new \RuntimeException('Unable to parse RSA public key');
            }
        }

        $ok = \openssl_public_encrypt($this->msg, $cipher, $res, \OPENSSL_PKCS1_PADDING);
        if (!$ok) {
            throw new \RuntimeException('RSA encryption failed: '.(\openssl_error_string() ?: 'unknown error'));
        }
        return base64_encode($cipher);
    }

    private function derToPem(string $der, string $type): string
    {
        $b64 = chunk_split(base64_encode($der), 64, "\n");
        return "-----BEGIN {$type}-----\n{$b64}-----END {$type}-----\n";
    }
}