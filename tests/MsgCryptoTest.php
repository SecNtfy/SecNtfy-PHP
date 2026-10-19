<?php declare(strict_types=1);

namespace SecNtfyPHP\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SecNtfyPHP\MsgCrypto;

final class MsgCryptoTest extends TestCase
{
    private static \OpenSSLAsymmetricKey $privateKey;
    private static string $spki;
    private static string $pkcs1;

    public static function setUpBeforeClass(): void
    {
        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $privateKey);
        self::$privateKey = $privateKey;
        $details = openssl_pkey_get_details($privateKey);
        self::assertIsArray($details);
        self::$spki = self::publicPemToBase64($details['key']);
        $integers = self::derInteger($details['rsa']['n']) . self::derInteger($details['rsa']['e']);
        self::$pkcs1 = base64_encode("\x30" . self::derLength(strlen($integers)) . $integers);
    }

    #[DataProvider('plaintexts')]
    public function testSpkiEncryptionRoundTrips(string $plaintext): void
    {
        $this->assertRoundTrip(self::$spki, $plaintext);
    }

    #[DataProvider('plaintexts')]
    public function testPkcs1EncryptionRoundTrips(string $plaintext): void
    {
        $this->assertRoundTrip(self::$pkcs1, $plaintext);
    }

    public static function plaintexts(): array
    {
        return [
            'empty' => [''],
            'ASCII' => ['SecNtfy notification'],
            'UTF-8' => ['Grüße aus Köln 👋'],
            'maximum bytes' => [str_repeat('a', 245)],
        ];
    }

    public function testReportsTheRsaPlaintextByteLimit(): void
    {
        $crypto = new MsgCrypto(self::$spki, '');
        self::assertSame(245, $crypto->getMaxPlaintextBytes());
        self::assertSame(245, $crypto->getMaxPlaintextBytes());
    }

    public function testRejectsPlaintextAboveTheByteLimit(): void
    {
        $crypto = new MsgCrypto(self::$spki, str_repeat('a', 246));
        $this->expectException(\LengthException::class);
        $crypto->encrypt();
    }

    public function testCountsUtf8BytesRatherThanCharacters(): void
    {
        $crypto = new MsgCrypto(self::$spki, str_repeat('ä', 150));
        $this->expectException(\LengthException::class);
        $crypto->encrypt();
    }

    #[DataProvider('invalidKeys')]
    public function testRejectsInvalidKeysWhenEncryptionIsRequested(string $key): void
    {
        $crypto = new MsgCrypto($key, 'message');
        $this->expectException(\RuntimeException::class);
        $crypto->encrypt();
    }

    #[DataProvider('invalidKeys')]
    public function testRejectsInvalidKeysWhenTheirLimitIsRequested(string $key): void
    {
        $crypto = new MsgCrypto($key, 'message');
        $this->expectException(\RuntimeException::class);
        $crypto->getMaxPlaintextBytes();
    }

    public static function invalidKeys(): array
    {
        return [
            'empty' => [''],
            'whitespace' => [" \n\t"],
            'invalid Base64' => ['not Base64!'],
            'invalid DER' => [base64_encode('not a DER key')],
            'truncated DER' => [base64_encode("\x30\x82\x01")],
        ];
    }

    public function testRejectsNonRsaPublicKeys(): void
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $crypto = new MsgCrypto(self::publicPemToBase64($details['key']), 'message');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Public key must be an RSA key');
        $crypto->encrypt();
    }

    private function assertRoundTrip(string $key, string $plaintext): void
    {
        $crypto = new MsgCrypto($key, $plaintext);
        // PKCS#1 v1.5 uses random padding: verify recovered bytes instead of ciphertext equality.
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $ciphertext = base64_decode($crypto->encrypt(), true);
            self::assertNotFalse($ciphertext);
            self::assertSame(256, strlen($ciphertext));
            self::assertTrue(openssl_private_decrypt(
                $ciphertext,
                $decrypted,
                self::$privateKey,
                OPENSSL_PKCS1_PADDING,
            ));
            self::assertSame($plaintext, $decrypted);
        }
    }

    private static function publicPemToBase64(string $pem): string
    {
        return str_replace(["-----BEGIN PUBLIC KEY-----", "-----END PUBLIC KEY-----", "\r", "\n"], '', $pem);
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\0");
        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\0" . $bytes;
        }
        return "\x02" . self::derLength(strlen($bytes)) . $bytes;
    }

    private static function derLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }
        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xff) . $bytes;
            $length >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }
}
