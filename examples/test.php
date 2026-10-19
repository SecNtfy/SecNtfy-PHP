<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SecNtfyPHP\SecNtfy;

$deviceToken = getenv('SECNTFY_DEVICE_TOKEN');
if (!is_string($deviceToken) || $deviceToken === '') {
    fwrite(STDERR, "SECNTFY_DEVICE_TOKEN is required.\n");
    exit(1);
}

$apiUrl = getenv('SECNTFY_API_URL');

try {
    $client = new SecNtfy($apiUrl === false ? null : $apiUrl);
    $result = $client->sendNotification(
        $deviceToken,
        'Test notification',
        'Test message sent by SecNtfy-PHP.'
    );

    if ($result === null) {
        fwrite(STDERR, "Notification was not sent: device key lookup failed.\n");
        exit(1);
    }

    if ($result->Error !== '') {
        fwrite(STDERR, "Notification was not sent. Check the token and API configuration.\n");
        exit(1);
    }

    fwrite(STDOUT, "Notification sent (API status {$result->Status}).\n");
} catch (Throwable) {
    // Keine Exception-Details ausgeben, sie könnten sensible Daten enthalten.
    fwrite(STDERR, "Notification failed. Check the token, API configuration, and server logs.\n");
    exit(1);
}
