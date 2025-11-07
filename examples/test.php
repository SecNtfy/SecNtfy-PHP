<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SecNtfyPHP\SecNtfy;

$secNtfy = new SecNtfy(''); // leerer String => Default https://api.secntfy.app

try {
    $res = $secNtfy->sendNotification(
        'NTFY-DEVICE-ABDCKJNLKSLFKJFKE5663GAVJDVES',
        'test',
        'test body',
        false,
        '',
        0
    );

    // entspricht Console.WriteLine(secNtfy.encTitle);
    // (Verschlüsselter Titel als Base64)
    echo $secNtfy->encTitle . PHP_EOL;

    // Ergebnis wie in C#: null (kein Key) oder ResultResponse-Objekt
    if ($res === null) {
        echo "[NULL] Kein Public Key erhalten oder Fehler bei Lookup." . PHP_EOL;
    } else {
        echo "[Result] Status={$res->Status}; Message={$res->Message}; Error={$res->Error}" . PHP_EOL;
    }
} catch (Throwable $e) {
    // entspricht C# catch (Exception e) { Console.WriteLine(e.StackTrace); }
    echo $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
}

echo "Hello, World!" . PHP_EOL;
