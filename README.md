# SecNtfy PHP

PHP-Client zum Versand Ende-zu-Ende-verschlüsselter Benachrichtigungen über die SecNtfy-API.

[![Packagist](https://img.shields.io/packagist/v/de.sr.secntfy/secntfy-php)](https://packagist.org/packages/de.sr.secntfy/secntfy-php) [![PHP](https://img.shields.io/badge/PHP-%3E%3D%208.2-blue.svg)](https://www.php.net/supported-versions.php) [![Lizenz](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

Quellcode: [SecNtfy-PHP auf GitHub](https://github.com/SecNtfy/SecNtfy-PHP). Benötigt werden PHP 8.2 oder neuer sowie die PHP-Erweiterungen `curl`, `openssl` und `mbstring`.

## Installation

```bash
composer require de.sr.secntfy/secntfy-php
```

Lade den Composer-Autoloader in deiner Anwendung:

```php
require __DIR__ . '/vendor/autoload.php';
```

## Schnellstart

Nach `composer require` enthält `vendor/` den veröffentlichten Packagist-Stand; Beispieldateien sind nicht Teil der Installation. Der folgende eigenständige `send.php`-Ausschnitt funktioniert in einem Projektverzeichnis mit installiertem Paket. Die Bibliothek selbst liest keine Umgebungsvariablen; die Anwendung liest das Token ein und übergibt es.

```php
<?php declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use SecNtfyPHP\SecNtfy;

$deviceToken = getenv('SECNTFY_DEVICE_TOKEN');
if (!is_string($deviceToken) || $deviceToken === '') {
    fwrite(STDERR, "SECNTFY_DEVICE_TOKEN is required.\n");
    exit(1);
}

$apiUrl = getenv('SECNTFY_API_URL');

try {
    $client = new SecNtfy($apiUrl === false ? null : $apiUrl);
    $result = $client->sendNotification($deviceToken, 'Test notification', 'Hello from SecNtfy-PHP.');

    if ($result === null || $result->Error !== '') {
        fwrite(STDERR, "Notification failed. Check the token and API configuration.\n");
        exit(1);
    }

    fwrite(STDOUT, "Notification sent (API status {$result->Status}).\n");
} catch (Throwable) {
    fwrite(STDERR, "Notification failed. Check the token and API configuration.\n");
    exit(1);
}
```

Speichere den Code als `send.php`, setze das Token sicher in der Prozessumgebung und starte ihn:

```bash
export SECNTFY_DEVICE_TOKEN='NTFY-DEVICE-…'
php send.php
```

Die aufrufbare API, Größenlimits und das Fehlerverhalten stehen in der [Integrationsanleitung](docs/INTEGRATION.md). Die Änderungen für Version 1.0.2 werden über den Git-Tag `1.0.2` veröffentlicht; Composer übernimmt die Paketversion aus dem Tag.

## Entwicklung

Installiere die Entwicklungsabhängigkeiten mit `composer install`. Composer wählt eine zur PHP-Version passende PHPUnit-Version (11.5, 12 oder 13); die [PHPUnit-Versionsübersicht](https://phpunit.de/supported-versions.html) nennt die Anforderungen. Die automatisierten Tests verwenden ausschließlich einen lokalen Testserver und erzeugte RSA-Schlüssel. Die CI führt die Prüfungen für PHP 8.2, 8.3, 8.4 und 8.5 aus.

Für eine echte Testnachricht setze `SECNTFY_DEVICE_TOKEN` und starte das Repository-Beispiel mit `php examples/test.php`. Die automatisierten Prüfungen startest du mit:

```bash
composer test
composer lint
```

Dafür werden PHP und Composer benötigt. Laut [PHP Supported Versions](https://www.php.net/supported-versions.php) endet die Sicherheitsunterstützung für PHP 8.2 am 31.12.2026. Prüfe diesen Hinweis, wenn du die minimale PHP-Version aktualisierst.

## Lizenz

Veröffentlicht unter der [MIT-Lizenz](LICENSE).
