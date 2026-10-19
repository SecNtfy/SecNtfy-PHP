<?php declare(strict_types=1);

namespace SecNtfyPHP\Tests;

use InvalidArgumentException;
use LengthException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SecNtfyPHP\ResultResponse;
use SecNtfyPHP\SecNtfy;

final class SecNtfyTest extends TestCase
{
    private static string $fixtureDir;
    private static string $baseUrl;
    /** @var resource|null */
    private static $serverProcess = null;
    /** @var array<int,resource> */
    private static array $serverPipes = [];

    public static function setUpBeforeClass(): void
    {
        self::$fixtureDir = sys_get_temp_dir() . '/secntfy-tests-' . bin2hex(random_bytes(8));
        if (!mkdir(self::$fixtureDir, 0700, true)) {
            throw new RuntimeException('Unable to create fixture directory');
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false || !openssl_pkey_export($key, $privateKey)) {
            throw new RuntimeException('Unable to generate fixture RSA key');
        }
        $details = openssl_pkey_get_details($key);
        if ($details === false || !isset($details['key'])) {
            throw new RuntimeException('Unable to export fixture RSA public key');
        }
        $publicDer = base64_decode((string) preg_replace(
            '/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+/',
            '',
            $details['key']
        ), true);
        if ($publicDer === false) {
            throw new RuntimeException('Unable to decode fixture RSA public key');
        }
        file_put_contents(self::$fixtureDir . '/private.pem', $privateKey);
        file_put_contents(self::$fixtureDir . '/public.b64', base64_encode($publicDer));
        file_put_contents(self::$fixtureDir . '/requests.jsonl', '');

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            throw new RuntimeException("Unable to allocate fixture port: {$error}");
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        if ($address === false || !preg_match('/:(\d+)$/', $address, $matches)) {
            throw new RuntimeException('Unable to determine fixture port');
        }
        $port = (int) $matches[1];
        self::$baseUrl = "http://127.0.0.1:{$port}";

        $fixture = __DIR__ . '/fixtures/router.php';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', self::$fixtureDir . '/server.out', 'a'],
            2 => ['file', self::$fixtureDir . '/server.err', 'a'],
        ];
        $previousFixtureDir = getenv('SECNTFY_TEST_DIR');
        putenv('SECNTFY_TEST_DIR=' . self::$fixtureDir);
        self::$serverProcess = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", $fixture],
            $descriptors,
            self::$serverPipes,
            dirname(__DIR__)
        );
        if ($previousFixtureDir === false) {
            putenv('SECNTFY_TEST_DIR');
        } else {
            putenv('SECNTFY_TEST_DIR=' . $previousFixtureDir);
        }
        if (!is_resource(self::$serverProcess)) {
            throw new RuntimeException('Unable to start local PHP fixture server');
        }

        $ready = false;
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $connection = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 0.1);
            if ($connection !== false) {
                fclose($connection);
                $ready = true;
                break;
            }
            usleep(100_000);
        }
        if (!$ready) {
            self::tearDownAfterClass();
            throw new RuntimeException('Local PHP fixture server did not become ready');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
            self::$serverProcess = null;
        }
        foreach (self::$serverPipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        self::$serverPipes = [];
        if (isset(self::$fixtureDir) && is_dir(self::$fixtureDir)) {
            foreach (glob(self::$fixtureDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir(self::$fixtureDir);
        }
    }

    protected function setUp(): void
    {
        file_put_contents(self::$fixtureDir . '/requests.jsonl', '');
    }

    public function testComposerAutoloadsResultResponseAndInvalidTokenIsRedacted(): void
    {
        self::assertTrue(class_exists(ResultResponse::class));
        foreach ([
            'not-a-real-token-value',
            'NTFY-DEVICE-',
            "NTFY-DEVICE-bad\nvalue",
        ] as $secret) {
            $result = (new SecNtfy(self::$baseUrl . '/unused'))
                ->sendNotification($secret, 'title', 'body');

            self::assertInstanceOf(ResultResponse::class, $result);
            self::assertNotSame('', $result->Error);
            self::assertStringNotContainsString($secret, $result->Error);
        }
        self::assertSame([], $this->requests());
    }

    public function testEachClientKeepsItsOwnBaseUrlAndGetInstanceTracksMostRecentClient(): void
    {
        $first = new SecNtfy(self::$baseUrl . '/alpha');
        $second = new SecNtfy(self::$baseUrl . '/beta');

        self::assertSame($second, SecNtfy::getInstance());
        self::assertSame(200, $first->sendNotification($this->token(), 'one', 'first')?->Status);
        self::assertSame(200, $second->sendNotification($this->token(), 'two', 'second')?->Status);

        $posts = array_values(array_filter($this->requests(), static fn (array $r): bool => $r['method'] === 'POST'));
        self::assertSame(['/alpha/Message', '/beta/Message'], array_column($posts, 'path'));
        self::assertSame(['first', 'second'], array_column(array_column($posts, 'payload'), 'body'));
    }

    public function testSendUsesExpectedHeadersAndCompleteEncryptedNotificationWireContract(): void
    {
        $client = new SecNtfy(self::$baseUrl . '/ok');
        $result = $client->sendNotification(
            $this->token(),
            'encrypted title',
            'encrypted body',
            true,
            'https://images.example.test/notice.png',
            7
        );

        self::assertSame(200, $result?->Status);
        $requests = $this->requests();
        self::assertCount(2, $requests);
        self::assertSame($this->token(), $requests[0]['headers']['deviceToken']);
        self::assertSame('application/json', $requests[0]['headers']['accept']);

        $post = $requests[1];
        self::assertSame('POST', $post['method']);
        self::assertSame($this->token(), $post['headers']['deviceToken']);
        self::assertSame('application/json', $post['headers']['accept']);
        self::assertSame('application/json', $post['headers']['contentType']);
        self::assertSame(['title' => true, 'body' => true, 'image' => true], $post['decryptOk']);
        self::assertSame('encrypted title', $post['payload']['title']);
        self::assertSame('encrypted body', $post['payload']['body']);
        self::assertSame('https://images.example.test/notice.png', $post['payload']['image']);
        self::assertSame($post['wire']['title'], $client->encTitle);
        self::assertSame(true, $post['wire']['notification']['critical']);
        self::assertSame(7, $post['wire']['notification']['priority']);
        self::assertSame(1, $post['wire']['notification']['mutablecontent']);
        self::assertSame('default', $post['wire']['notification']['sound']['name']);
        self::assertSame(1.0, (float) $post['wire']['notification']['sound']['volume']);
    }

    public function testNullTitleAndBodyAndEmptyImageAreEncryptedAsNonemptyCiphertexts(): void
    {
        $client = new SecNtfy(self::$baseUrl . '/ok');
        self::assertSame(200, $client->sendNotification($this->token(), null, null, false, '')?->Status);
        $post = $this->lastPost();

        self::assertSame(['title' => true, 'body' => true, 'image' => true], $post['decryptOk']);
        self::assertSame(['title' => '', 'body' => '', 'image' => ''], $post['payload']);
        foreach (['title', 'body', 'image'] as $field) {
            self::assertIsString($post['wire'][$field]);
            self::assertNotSame('', $post['wire'][$field]);
        }
        self::assertSame($post['wire']['title'], $client->encTitle);
        self::assertFalse($post['wire']['notification']['critical']);
        self::assertSame(0, $post['wire']['notification']['priority']);
        self::assertSame(1, $post['wire']['notification']['mutablecontent']);
        self::assertSame('default', $post['wire']['notification']['sound']['name']);
        self::assertSame(1.0, (float) $post['wire']['notification']['sound']['volume']);
    }

    public function testDeviceLookupRequiresHttpAndIntegerApiSuccessAndStringToken(): void
    {
        foreach (['get-http-error', 'get-api-error', 'get-bad-token-type', 'get-string-status'] as $scenario) {
            file_put_contents(self::$fixtureDir . '/requests.jsonl', '');
            self::assertNull((new SecNtfy(self::$baseUrl . '/' . $scenario))
                ->sendNotification($this->token(), 'title', 'body'), $scenario);
            $requests = $this->requests();
            self::assertNotContains('POST', array_column($requests, 'method'), $scenario . ' must not POST');
        }
    }

    #[DataProvider('failedSendResponses')]
    public function testHttpApiAndMalformedResponsesReturnErrors(string $scenario, int $expectedStatus): void
    {
        $result = (new SecNtfy(self::$baseUrl . '/' . $scenario))
            ->sendNotification($this->token(), 'title', 'body');

        self::assertInstanceOf(ResultResponse::class, $result);
        self::assertNotSame('', $result->Error);
        self::assertSame($expectedStatus, $result->Status);
    }

    public static function failedSendResponses(): array
    {
        return [
            'HTTP failure keeps HTTP status' => ['http-error', 503],
            'API failure' => ['api-error', 422],
            'malformed JSON' => ['malformed-json', 500],
            'wrong response field types' => ['wrong-response-types', 500],
        ];
    }

    public function testLongUnicodeBodyFitsRsaLimitWithSuffixAndRemainsValidUtf8(): void
    {
        $body = str_repeat('😀', 100);
        $client = new SecNtfy(self::$baseUrl . '/ok');
        $result = $client->sendNotification($this->token(), 'title', $body);
        self::assertSame(200, $result?->Status);

        $post = $this->lastPost();
        $trimmed = $post['payload']['body'];
        self::assertSame(str_repeat('😀', 60) . '...', $trimmed);
        self::assertLessThanOrEqual(245, strlen($trimmed));
        self::assertSame(1, preg_match('//u', $trimmed));

        $client->sendNotification($this->token(), 'title', str_repeat('a', 160));
        self::assertSame(str_repeat('a', 150) . '...', $this->lastPost()['payload']['body']);
    }

    public function testOversizedTitleAndImageAndInvalidUtf8AreRejected(): void
    {
        $client = new SecNtfy(self::$baseUrl . '/ok');
        try {
            $client->sendNotification($this->token(), str_repeat('t', 246), 'body');
            self::fail('Oversized title should throw LengthException');
        } catch (LengthException) {
            self::assertTrue(true);
        }
        try {
            $client->sendNotification($this->token(), 'title', 'body', false, str_repeat('i', 246));
            self::fail('Oversized image URL should throw LengthException');
        } catch (LengthException) {
            self::assertTrue(true);
        }
        try {
            $client->sendNotification($this->token(), 'title', "bad\xFFutf8");
            self::fail('Invalid UTF-8 should throw InvalidArgumentException');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
    }

    public function testEncryptedTitleStateIsReplacedForEachSend(): void
    {
        $client = new SecNtfy(self::$baseUrl . '/ok');
        $client->sendNotification($this->token(), 'first title', 'body');
        self::assertSame($this->lastPost()['wire']['title'], $client->encTitle);
        $client->sendNotification($this->token(), 'second title', 'body');

        $post = $this->lastPost();
        self::assertSame('second title', $post['payload']['title']);
        self::assertSame($post['wire']['title'], $client->encTitle);

        $invalid = $client->sendNotification('invalid-token', 'ignored', 'ignored');
        self::assertInstanceOf(ResultResponse::class, $invalid);
        self::assertSame('', $client->encTitle);
    }

    public function testRedirectsAreNotFollowedForDeviceLookupOrPost(): void
    {
        self::assertNull((new SecNtfy(self::$baseUrl . '/redirect-get'))
            ->sendNotification($this->token(), 'title', 'body'));
        self::assertSame([], array_values(array_filter(
            $this->requests(),
            static fn (array $r): bool => str_contains($r['path'], 'redirect-target')
        )));

        file_put_contents(self::$fixtureDir . '/requests.jsonl', '');
        $result = (new SecNtfy(self::$baseUrl . '/redirect-post'))
            ->sendNotification($this->token(), 'title', 'body');
        self::assertNotSame('', $result?->Error);
        self::assertSame(302, $result?->Status);
        self::assertSame([], array_values(array_filter(
            $this->requests(),
            static fn (array $r): bool => str_contains($r['path'], 'redirect-target')
        )));
    }

    public function testReachabilityChecksTheExactUrlAndReturnsFalseForInvalidUrl(): void
    {
        self::assertTrue(SecNtfy::checkIfUrlReachable(self::$baseUrl . '/api/marker'));
        self::assertFalse(SecNtfy::checkIfUrlReachable(self::$baseUrl . '/marker'));
        self::assertFalse(SecNtfy::checkIfUrlReachable('file:///etc/passwd'));
    }

    #[DataProvider('invalidBaseUrls')]
    public function testInvalidBaseUrlsAreRejected(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SecNtfy($url);
    }

    public static function invalidBaseUrls(): array
    {
        return [
            'unsupported scheme' => ['ftp://example.test'],
            'credentials' => ['https://user:password@example.test'],
            'query' => ['https://example.test?x=1'],
            'fragment' => ['https://example.test#section'],
        ];
    }

    private function token(): string
    {
        return 'NTFY-DEVICE-fixture-only';
    }

    /** @return list<array<string,mixed>> */
    private function requests(): array
    {
        $lines = file(self::$fixtureDir . '/requests.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        return array_values(array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines));
    }

    /** @return array<string,mixed> */
    private function lastPost(): array
    {
        $posts = array_values(array_filter($this->requests(), static fn (array $r): bool => $r['method'] === 'POST'));
        self::assertNotSame([], $posts, 'Expected the fixture to receive a POST');
        return $posts[array_key_last($posts)];
    }
}
