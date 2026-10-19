# Integrationsanleitung für SecNtfy-PHP

Diese Anleitung beschreibt die öffentliche API des PHP-Pakets für die Integration in eine Anwendung.

## Voraussetzungen und Einrichtung

- PHP 8.2 oder neuer
- PHP-Erweiterungen `ext-curl`, `ext-openssl` und `ext-mbstring`
- Composer 2

Installiere das Paket und lade den Composer-Autoloader:

```bash
composer require de.sr.secntfy/secntfy-php
```

```php
require __DIR__ . '/vendor/autoload.php';

use SecNtfyPHP\SecNtfy;
```

Die Bibliothek lädt keine `.env`-Dateien und liest selbst keine Prozess-Umgebungsvariablen. Eine Anwendung kann Zugangsdaten in ihrem eigenen Secret- oder Konfigurationssystem speichern und die Werte an den Client übergeben. Das Beispielskript verwendet zu Demonstrationszwecken `SECNTFY_DEVICE_TOKEN` und optional `SECNTFY_API_URL`. Echte Geräte-Tokens dürfen nicht eingecheckt oder zusammen mit Request-Headern, Klartext-Nachrichten oder verschlüsselten Payloads protokolliert werden.

## Client und API

Erzeuge einen Client mit der Standard-API-Basisadresse oder gib eine eigene Basisadresse an:

```php
$client = new SecNtfy();
// Identischer Standardwert:
$client = new SecNtfy('https://api.secntfy.app');
// Eine eigene HTTP(S)-API-Basisadresse ist möglich:
$client = new SecNtfy('https://secntfy.example');
```

Der Konstruktor hat die Signatur `__construct(?string $secntfyUrl = '')`. `null` und ein leerer String wählen `https://api.secntfy.app`. Eine eigene URL muss HTTP oder HTTPS verwenden und darf weder Zugangsdaten noch Query-Parameter oder Fragment enthalten. Jeder Client hält seine eigene URL; die Bibliothek hängt die benötigten Endpoints an.

So rufst du die Methode mit den erforderlichen Argumenten auf:

```php
$result = $client->sendNotification($deviceToken, $title, $msg);
```

Der Methodenkopf lautet:

```text
sendNotification(string $deviceToken, ?string $title, ?string $msg, bool $isCritical = false, string $imageUrl = '', int $priority = 0): ?ResultResponse
```

| Argument | Typ und Standard | Bedeutung |
| --- | --- | --- |
| `deviceToken` | `string`, erforderlich | Geräte-Token mit Präfix `NTFY-DEVICE-` und mindestens einem weiteren Zeichen. |
| `title` | `?string`, erforderlich | Titel; `null` wird als leerer String behandelt. |
| `msg` | `?string`, erforderlich | Nachrichtentext; `null` wird als leerer String behandelt und der Text kann begrenzt werden (siehe unten). |
| `isCritical` | `bool`, `false` | Setzt das Critical-Flag. |
| `imageUrl` | `string`, `''` | Optionale Bild-URL; wird auch als leerer String verschlüsselt. |
| `priority` | `int`, `0` | SecNtfy-Prioritätswert; die Bibliothek setzt keinen eigenen Wertebereich durch. |

Der Methodenkopf gibt `?ResultResponse` zurück: entweder ein Ergebnisobjekt oder `null`. Der Ton ist fest auf `default` mit Lautstärke `1.0` gesetzt. SecNtfy verwendet eine eigene API; ob ein kritischer Alarm zugestellt wird, hängt auch von der Konfiguration des Empfangsgeräts ab.

Das Ergebnisobjekt hat die öffentlichen Felder `Status` (Integer), `Message` (nullable String) und `Error` (String). Eine erfolgreiche API-Antwort hat einen HTTP-Status im 2xx-Bereich, einen API-`Status` im 2xx-Bereich und ein leeres `Error`.

Weitere öffentliche Methoden und Eigenschaften:

- `SecNtfy::getInstance(): SecNtfy` gibt den zuletzt erzeugten Client zurück oder erstellt einen Client mit Standardkonfiguration, falls noch keiner existiert. In Anwendungscode sollte der Client explizit weitergereicht werden.
- `SecNtfy::checkIfUrlReachable(string $url): bool` fragt genau die angegebene URL ab und gibt nur bei HTTP 200 `true` zurück. Die Methode ändert die URL nicht und hängt keinen API-Pfad an.
- `$client->encTitle` enthält den Base64-Chiffretext des Titels, sobald Titel, Nachrichtentext und Bild-URL erfolgreich verschlüsselt wurden. Der Wert wird zu Beginn jedes neuen Sendeversuchs zurückgesetzt. Verwende ihn nur zu Diagnosezwecken und protokolliere ihn nicht.

## Verschlüsselung und Grenzen

Der Client verschlüsselt Titel, Nachrichtentext und Bild-URL automatisch. Alle Werte müssen gültiges UTF-8 sein. Der Nachrichtentext kann auf höchstens 150 Unicode-Zeichen Inhalt gekürzt werden; zusätzlich gilt das RSA-Byte-Limit einschließlich des möglichen Suffixes `...` (bei einem 2048-Bit-Schlüssel höchstens 245 Bytes). Zu lange Titel oder Bild-URLs lösen `LengthException` aus; ungültiges UTF-8 löst `InvalidArgumentException` aus.

Verwende für entfernte Server HTTPS. Der synchrone Sendevorgang kann wegen zweier Requests insgesamt bis zu 40 Sekunden dauern und führt keine automatischen Wiederholungen aus. Nach einem unklaren POST-Timeout kann eine manuelle Wiederholung eine doppelte Benachrichtigung erzeugen.

## Rückgabewerte und Fehler

`sendNotification()` hat drei mögliche Ergebnisse:

| Ergebnis | Bedeutung |
| --- | --- |
| `ResultResponse` mit leerem `Error` und `Status` im 2xx-Bereich | Die API hat die Benachrichtigung angenommen. |
| `ResultResponse` mit nicht leerem `Error` | Ungültiges Token (Status 400), Request-/API-Fehler oder nicht erfolgreiche API-Antwort. Prüfe `Status` und `Error`; ein nicht-null Ergebnis allein bedeutet keinen Erfolg. |
| `null` | Die Abfrage des Geräte-Public-Keys ist fehlgeschlagen, hat keinen nichtleeren Schlüssel-String geliefert oder konnte nicht abgeschlossen werden. |

Ein gültiges Geräte-Token muss mit `NTFY-DEVICE-` beginnen, mindestens ein Zeichen nach dem Präfix enthalten und darf keine Leer- oder Steuerzeichen enthalten. Das Token wird nicht in die zurückgegebene Validierungsfehlermeldung aufgenommen. Eine fehlgeschlagene Schlüsselabfrage, etwa wegen eines Netzwerkfehlers, einer nicht erfolgreichen Antwort oder eines fehlenden Schlüssels, ergibt `null`. Wird bei einer erfolgreichen Abfrage ein Schlüssel geliefert, der kein lesbarer RSA-Public-Key ist, löst die Verschlüsselung dagegen `RuntimeException` aus.

Fehler bei der Verschlüsselung können `RuntimeException` auslösen, etwa bei ungültigen oder nicht lesbaren Public Keys oder OpenSSL-Fehlern. Zu lange Klartexte lösen `LengthException` aus. Ungültiges UTF-8 löst `InvalidArgumentException` aus. Fange diese Exceptions an der Anwendungsgrenze ab und gib nur eine allgemeine Fehlermeldung aus. Vermeide Exception-Details in Logs, die Zugangsdaten enthalten könnten.

## Konfiguration in einer Anwendung

Das Beispiel verwendet Prozess-Umgebungsvariablen; die Bibliothek selbst liest sie nicht:

```bash
export SECNTFY_DEVICE_TOKEN='NTFY-DEVICE-…'
# Optional; nicht gesetzt oder leer verwendet https://api.secntfy.app
export SECNTFY_API_URL='https://api.secntfy.app'
php examples/test.php
```

Lies diese Werte in einer Anwendung über deren Konfigurationssystem ein, prüfe, ob das Geräte-Token vorhanden ist, und übergib die Werte explizit:

```php
$client = new SecNtfy($configuredApiUrl ?? null);
$result = $client->sendNotification($configuredDeviceToken, $title, $body);
```

## Prüfstand und Paketversion

Stand 19.10.2026: Der GET-Lookup und der POST-Versand wurden mit Python gegen die Live-API ausgeführt; beide lieferten HTTP 200 und API-Status 200. PHPUnit wurde lokal nicht ausgeführt, da in der Umgebung PHP und Composer fehlen. Die CI ist für PHP 8.2, 8.3, 8.4 und 8.5 eingerichtet. Diese Angaben beschreiben den Prüfstand der Repository-Änderungen und keine Garantie für jede Installation.

Die Änderungen bereiten Version 1.0.2 vor. Die Paketversion wird durch den Git-Tag festgelegt; `composer require de.sr.secntfy/secntfy-php` installiert jeweils den veröffentlichten Paketstand.

## Lokale Entwicklung

Installiere Entwicklungsabhängigkeiten mit `composer install`. Das Projekt stellt Composer-Skripte `test` und `lint` sowie eine PHPUnit-Konfiguration bereit. Führe nach Änderungen `composer test` und `composer lint` aus. Das Beispiel sendet eine echte Benachrichtigung und benötigt ein gültiges Geräte-Token sowie Netzwerkzugriff. Zum Ausführen wird PHP und Composer benötigt.
