<?php declare(strict_types=1);

namespace SecNtfyPHP;

final class SecNtfy
{
    private static ?SecNtfy $instance = null;
    private static ?string $secNtfyUrl = null;

    /** Nur für Debug-Parität mit C# */
    public string $encTitle = '';

    public function __construct(?string $secntfyUrl = '')
    {
        if ($secntfyUrl !== null && strlen($secntfyUrl) === 0) {
            $secntfyUrl = 'https://api.secntfy.app';
        }
        self::$secNtfyUrl = $secntfyUrl;
        self::$instance = $this;
    }

    public static function getInstance(): SecNtfy
    {
        return self::$instance ?? new self();
    }

    /**
     * Sendet eine Notification an SecNtfy.
     *
     * @return ResultResponse|null  null wenn kein Public Key verfügbar.
     */
    public function sendNotification(
        string $deviceToken,
        ?string $title,
        ?string $msg,
        bool $isCritical = false,
        string $imageUrl = '',
        int $priority = 0
    ): ?ResultResponse {
        if (!str_contains($deviceToken, 'NTFY-DEVICE-')) {
            return ResultResponse::error("No valid SecNtfy Token! You're token: {$deviceToken}; Is not a valid SecNtfy Token!");
        }

        $msg = $msg ?? '';
        // APNs Payload-Trim wie im C# (150 Chars + "...")
        if (mb_strlen($msg) > 150) {
            $msg = mb_substr($msg, 0, 150) . '...';
        }

        $publicKey = $this->getPubKeyFromDevice($deviceToken);
        if ($publicKey === '') {
            return null;
        }

        // Verschlüsseln (Titel, Body, Bild-URL)
        $title = (new MsgCrypto($publicKey, $title ?? ''))->encrypt();
        $msgEnc = (new MsgCrypto($publicKey, $msg))->encrypt();
        $imageEnc = (new MsgCrypto($publicKey, $imageUrl ?? ''))->encrypt();
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

        $url = rtrim((string) self::$secNtfyUrl, '/') . '/Message';
        [$code, $body] = $this->httpRequest('POST', $url, [
            'Accept: application/json',
            'Content-Type: application/json',
            "X-NTFYME-DEVICE-KEY: {$deviceToken}",
        ], $json);

        if ($code === 0 || $body === null) {
            return ResultResponse::error('Response is null or empty!');
        }

        /** @var array<string,mixed>|null $decoded */
        $decoded = json_decode($body, true);
        $status = is_array($decoded) && isset($decoded['Status']) ? (int)$decoded['Status'] : 0;
        $message = is_array($decoded) && isset($decoded['Message']) ? (string)$decoded['Message'] : '';

        if ($status === 0) {
            return ResultResponse::error('Response is null or empty!');
        }
        return ResultResponse::ok($message, $status);
    }

    /**
     * Holt den Public Key zu einem Device.
     */
    private function getPubKeyFromDevice(string $deviceToken): string
    {
        $url = rtrim((string) self::$secNtfyUrl, '/') . '/Message/Device';
        [$code, $body] = $this->httpRequest('GET', $url, [
            'Accept: application/json',
            "X-NTFYME-DEVICE-KEY: {$deviceToken}",
        ]);

        if ($code === 0 || $body === null) {
            return '';
        }

        /** @var array<string,mixed>|null $decoded */
        $decoded = json_decode($body, true);
        if (is_array($decoded) && (($decoded['Status'] ?? 0) === 200)) {
            return (string)($decoded['Token'] ?? '');
        }
        return '';
    }

    /**
     * Prüft, ob URL erreichbar ist (wie in C#, api.* und /api entfernen).
     */
    public static function checkIfUrlReachable(string $url): bool
    {
        $url = str_replace(['api.', '/api'], ['', ''], $url);
        [$code, $_] = self::rawHttp('GET', $url, []);
        return $code === 200;
    }

    /** @return array{0:int,1:?string} */
    private function httpRequest(string $method, string $url, array $headers, ?string $body = null): array
    {
        return self::rawHttp($method, $url, $headers, $body);
    }

    /** @return array{0:int,1:?string} */
    private static function rawHttp(string $method, string $url, array $headers, ?string $body = null): array
    {
        $ch = \curl_init($url);
        if ($ch === false) {
            return [0, null];
        }

        \curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
        ]);

        if ($body !== null) {
            \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $resp = \curl_exec($ch);
        $code = (int) \curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($resp === false) {
            \curl_close($ch);
            return [0, null];
        }
        \curl_close($ch);
        return [$code, $resp];
    }
}