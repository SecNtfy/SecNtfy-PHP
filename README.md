# 🔔 SecNtfy-PHP

[![PHP](https://img.shields.io/badge/PHP-%3E%3D%208.2-blue.svg)](https://www.php.net/releases/8.2/en.php)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

**SecNtfy-PHP** is the official PHP port of the original [[SecNtfy C# library](https://github.com/DEINUSER/SecNtfy-NuGet)](https://github.com/SecNtfy/SecNtfy-Nuget) (currently Private).  
It provides secure, end-to-end encrypted notifications via the SecNtfy API,  
including RSA encryption, critical alerts, sound options, and message priority support.

---

## ✨ Features

- 🔒 **RSA encryption** (OpenSSL, fully compatible with the C# version)
- 🚀 **Simple JSON API** powered by cURL
- ⚡ **Pure PHP 8.2+**, no external dependencies
- 🧩 **Composer-ready** (PSR-4 autoloading)
- ✅ **Includes example and optional PHPUnit tests**

---

## 📦 Installation

### Requirements
- PHP ≥ **8.2**
- PHP extensions: `openssl`, `curl`
- Composer installed

### Install via Composer

```bash
composer require de.sr.secntfy/secntfy-php
```

### Autoloading

Include Composer’s autoloader in your project entrypoint:

```php
require __DIR__ . '/vendor/autoload.php';
```

---

## 🚀 Quick Example

```php
<?php declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use SecNtfyPHP\\SecNtfy;

$secNtfy = new SecNtfy(); // or new SecNtfy('https://api.secntfy.app')

try {
    $res = $secNtfy->sendNotification(
        'NTFY-DEVICE-ABCDEFG123456...',
        'Test message',
        'This is a test body from SecNtfy-PHP',
        false,
        '',    // optional image URL
        0      // priority
    );

    echo "Encrypted title: {$secNtfy->encTitle}\\n";

    if ($res === null) {
        echo "No public key received or device lookup failed.\\n";
    } else {
        echo "Status: {$res->Status}\\n";
        echo "Message: {$res->Message}\\n";
        echo "Error: {$res->Error}\\n";
    }
} catch (Throwable $e) {
    echo "Error: {$e->getMessage()}\\n";
    echo $e->getTraceAsString() . "\\n";
}
```

---

## 🧪 Running Locally

### Example script

```bash
composer install
php examples/test.php
```

### Optional unit tests

If you have PHPUnit installed or added as a dev dependency:

```bash
composer test
# or
vendor/bin/phpunit
```

---

## 🧱 Project Structure

```
SecNtfy-PHP/
├── src/
│   ├── SecNtfy.php           # Main client logic (HTTP + encryption)
│   ├── MsgCrypto.php         # RSA encryption handling
│   └── SecNtfyModel.php      # Data models and response classes
├── examples/
│   └── test.php              # Example usage
├── tests/                    # Optional PHPUnit tests
├── composer.json
├── phpunit.xml
└── LICENSE
```

---

## 🧠 Troubleshooting

| Issue | Possible Cause |
|-------|----------------|
| `Response is null or empty!` | Invalid device token or missing public key |
| `RSA encryption failed` | The public key is invalid or not Base64-encoded |
| `curl_exec()` returns `false` | Network issue or incorrect API URL |

---

## 🤝 Contributing

Pull requests and issues are welcome!  
Please follow the [PSR-12 coding style](https://www.php-fig.org/psr/psr-12/)  
and include unit tests when adding new features.

---

## 📄 License

Released under the [MIT License](LICENSE).  
© 2025 androidseb25 — SecNtfy-PHP
