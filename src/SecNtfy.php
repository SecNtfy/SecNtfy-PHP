<?php declare(strict_types=1);

namespace SecNtfyPHP;

final class SecNtfy
{
    private static ?SecNtfy $instance = null;
    private string $secNtfyUrl;

    /** Base64 ciphertext of the title from the current send attempt. */
    public string $encTitle = '';

    public function __construct(?string $secntfyUrl = '')
    {
        $secntfyUrl = $secntfyUrl === null || $secntfyUrl === ''
            ? 'https://api.secntfy.app'
            : $secntfyUrl;
        if (!self::isHttpUrl($secntfyUrl)) {
            throw new \InvalidArgumentException('The API URL must be an absolute HTTP(S) URL without credentials, query or fragment.');
        }
        $this->secNtfyUrl = rtrim($secntfyUrl, '/');
        self::$instance = $this;
    }

    public static function getInstance(): SecNtfy
    {
        return self::$instance ?? new self();
    }

    /**
     * Sends an encrypted notification to SecNtfy.
     *
     * @return ResultResponse|null null when the device key lookup fails.
     * @throws \InvalidArgumentException for invalid UTF-8 text.
     * @throws \LengthException when the title or image URL exceeds the RSA limit.
     * @throws \RuntimeException when the device public key or encryption fails.
     */
    public function sendNotification(
        string $deviceToken,
        ?string $title,
        ?string $msg,
        bool $isCritical = false,
        string $imageUrl = '',
        int $priority = 0
    ): ?ResultResponse {
        $this->encTitle = '';
        if (!str_starts_with($deviceToken, 'NTFY-DEVICE-')
            || strlen($deviceToken) === strlen('NTFY-DEVICE-')
            || preg_match('/[\x00-\x20\x7f]/', $deviceToken) === 1
        ) {
            return ResultResponse::error('Invalid SecNtfy device token.', 400);
        }

        $msg = $msg ?? '';
        $title = $title ?? '';
        foreach ([$title, $msg, $imageUrl] as $text) {
            if (!mb_check_encoding($text, 'UTF-8')) {
                throw new \InvalidArgumentException('Notification text must be valid UTF-8.');
            }
        }

        $publicKey = $this->getPubKeyFromDevice($deviceToken);
        if ($publicKey === '') {
            return null;
        }

        $maxBytes = (new MsgCrypto($publicKey, ''))->getMaxPlaintextBytes();
        if (mb_strlen($msg, 'UTF-8') > 150 || strlen($msg) > $maxBytes) {
            $msg = mb_strcut(mb_substr($msg, 0, 150, 'UTF-8'), 0, $maxBytes - 3, 'UTF-8') . '...';
        }

        $title = (new MsgCrypto($publicKey, $title))->encrypt();
        $msgEnc = (new MsgCrypto($publicKey, $msg))->encrypt();
        $imageEnc = (new MsgCrypto($publicKey, $imageUrl))->encrypt();
        $this->encTitle = $title;

        $model = new SecNtfyModel();
        $model->title = $title;
        $model->body = $msgEnc;
        $model->image = $imageEnc;
        $model->notification->mutablecontent = 1;
        $model->notification->critical = $isCritical;
        $model->notification->priority = $priority;
        $model->notification->sound->name = 'default';
        $model->notification->sound->volume = 1.0;

        $json = json_encode($model, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return ResultResponse::error('JSON encode failed');
        }

        $url = $this->secNtfyUrl . '/Message';
        [$code, $body] = $this->httpRequest('POST', $url, [
            'Accept: application/json',
            'Content-Type: application/json',
            "X-NTFYME-DEVICE-KEY: {$deviceToken}",
        ], $json);

        if ($code === 0 || $body === null) {
            return ResultResponse::error('SecNtfy request failed or timed out.');
        }

        $decoded = json_decode($body, true);
        if ($code < 200 || $code >= 300) {
            return ResultResponse::error('SecNtfy returned HTTP ' . $code . '.', $code);
        }
        if (!is_array($decoded)
            || !isset($decoded['Status'])
            || !is_int($decoded['Status'])
            || $decoded['Status'] < 100
            || $decoded['Status'] > 599
            || (isset($decoded['Message']) && !is_string($decoded['Message']))
        ) {
            return ResultResponse::error('Invalid JSON response from SecNtfy.');
        }

        $status = $decoded['Status'];
        $message = str_replace($deviceToken, '[redacted]', $decoded['Message'] ?? '');
        if ($status < 200 || $status >= 300) {
            return ResultResponse::error($message !== '' ? $message : 'SecNtfy rejected the notification.', $status);
        }

        return ResultResponse::ok($message, $status);
    }

    /**
     * Fetches the Base64 DER public key registered for the device.
     */
    private function getPubKeyFromDevice(string $deviceToken): string
    {
        $url = $this->secNtfyUrl . '/Message/Device';
        [$code, $body] = $this->httpRequest('GET', $url, [
            'Accept: application/json',
            "X-NTFYME-DEVICE-KEY: {$deviceToken}",
        ]);

        if ($code !== 200 || $body === null) {
            return '';
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded)
            && ($decoded['Status'] ?? 0) === 200
            && isset($decoded['Token'])
            && is_string($decoded['Token'])
        ) {
            return $decoded['Token'];
        }
        return '';
    }

    /**
     * Checks the exact URL for HTTP 200 without sending a device token.
     */
    public static function checkIfUrlReachable(string $url): bool
    {
        if (!self::isHttpUrl($url)) {
            return false;
        }
        [$code] = self::rawHttp('GET', $url, []);
        return $code === 200;
    }

    private static function isHttpUrl(string $url): bool
    {
        $parts = parse_url($url);
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && is_array($parts)
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['query'])
            && !isset($parts['fragment']);
    }

    /** @param list<string> $headers
     *  @return array{0:int,1:?string}
     */
    private function httpRequest(string $method, string $url, array $headers, ?string $body = null): array
    {
        return self::rawHttp($method, $url, $headers, $body);
    }

    /** @param list<string> $headers
     *  @return array{0:int,1:?string}
     */
    private static function rawHttp(string $method, string $url, array $headers, ?string $body = null): array
    {
        $ch = \curl_init($url);
        if ($ch === false) {
            return [0, null];
        }

        \curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($body !== null) {
            \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $resp = \curl_exec($ch);
        $code = (int) \curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($resp === false) {
            return [0, null];
        }
        return [$code, $resp];
    }
}
