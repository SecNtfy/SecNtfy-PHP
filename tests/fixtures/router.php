<?php declare(strict_types=1);

$fixtureDir = getenv('SECNTFY_TEST_DIR');
if ($fixtureDir === false || $fixtureDir === '') {
    http_response_code(500);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$segments = explode('/', trim($path, '/'));
$scenario = $segments[0] ?? '';
$tail = '/' . implode('/', array_slice($segments, 1));
$headers = function_exists('getallheaders') ? getallheaders() : [];
$headers = array_change_key_case(is_array($headers) ? $headers : [], CASE_LOWER);

$record = [
    'method' => $method,
    'path' => $path,
    'headers' => [
        'deviceToken' => $headers['x-ntfyme-device-key'] ?? null,
        'contentType' => $headers['content-type'] ?? null,
        'accept' => $headers['accept'] ?? null,
    ],
];
if ($method === 'POST' && str_ends_with($tail, '/Message')) {
    $wire = json_decode((string) file_get_contents('php://input'), true);
    $record['wire'] = $wire;
    $record['payload'] = [];
    $record['decryptOk'] = [];
    $privatePem = file_get_contents($fixtureDir . '/private.pem');
    foreach (['title', 'body', 'image'] as $field) {
        $encrypted = is_array($wire) ? ($wire[$field] ?? null) : null;
        $cipher = is_string($encrypted) ? base64_decode($encrypted, true) : false;
        $plain = '';
        $decrypted = $cipher !== false
            && is_string($privatePem)
            && openssl_private_decrypt($cipher, $plain, $privatePem, OPENSSL_PKCS1_PADDING);
        $record['decryptOk'][$field] = $decrypted;
        $record['payload'][$field] = $plain;
    }
}
file_put_contents(
    $fixtureDir . '/requests.jsonl',
    json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
    FILE_APPEND | LOCK_EX
);

if ($path === '/api/marker') {
    http_response_code(200);
    echo 'reachable';
    return;
}
if ($path === '/marker') {
    http_response_code(404);
    echo 'missing';
    return;
}
if ($method === 'GET' && $tail === '/Message/Device') {
    if ($scenario === 'redirect-get') {
        http_response_code(302);
        header('Location: /redirect-target/Message/Device');
        return;
    }
    if ($scenario === 'get-http-error') {
        http_response_code(503);
        echo '{"Status":200,"Token":"unused"}';
        return;
    }
    if ($scenario === 'get-api-error') {
        echo '{"Status":503,"Token":"unused"}';
        return;
    }
    if ($scenario === 'get-bad-token-type') {
        echo '{"Status":200,"Token":123}';
        return;
    }
    if ($scenario === 'get-string-status') {
        echo '{"Status":"200","Token":"unused"}';
        return;
    }
    $publicKey = file_get_contents($fixtureDir . '/public.b64');
    echo json_encode(['Status' => 200, 'Token' => $publicKey], JSON_THROW_ON_ERROR);
    return;
}

if ($method === 'POST' && $tail === '/Message') {
    if ($scenario === 'redirect-post') {
        http_response_code(302);
        header('Location: /redirect-target/Message');
        return;
    }
    if ($scenario === 'http-error') {
        http_response_code(503);
        echo '{"Status":200,"Message":"must not be accepted"}';
        return;
    }
    if ($scenario === 'api-error') {
        echo '{"Status":422,"Message":"api failure"}';
        return;
    }
    if ($scenario === 'malformed-json') {
        echo '{not-json';
        return;
    }
    if ($scenario === 'wrong-response-types') {
        echo '{"Status":"200","Message":{"value":"wrong"}}';
        return;
    }
    echo '{"Status":200,"Message":"accepted"}';
    return;
}

if (str_starts_with($path, '/redirect-target')) {
    http_response_code(200);
    echo '{"Status":200,"Token":"should not be used"}';
    return;
}

http_response_code(404);
echo 'not found';
