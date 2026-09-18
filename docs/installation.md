# Installation

## Requirements

|               |                                                 |
| ------------- | ----------------------------------------------- |
| PHP           | `>= 8.4`                                        |
| dry-ecommerce | `^3.10` — the payment harness arrived in 3.10.0 |

oak `^3` and dry `^4` arrive transitively through dry-ecommerce. Note the
version numbering: dry-ecommerce's modern line (php 8.4, dry 4) is tagged
`3.x` by its auto-release — `3.10` is a floor, not the old 3.x codebase.

## Getting the package

```sh
composer require tallieutallieu/dry-mollie
```

Neither package is on Packagist, so the project needs both VCS
repositories — dry-ecommerce over plain git (`no-api` avoids GitHub's
authenticated API), dry from Bitbucket over SSH:

```json
"repositories": [
  { "type": "vcs", "url": "git@github.com:reinvanoyen/dry-mollie.git" },
  {
    "type": "vcs",
    "url": "https://github.com/TallieuTallieu/dry-ecommerce",
    "no-api": true
  },
  { "type": "vcs", "url": "git@bitbucket.org:tallieu/dry3.git" }
]
```

## Registering the service provider

```php
$app->register([
    // ...
    \Tnt\Ecommerce\EcommerceServiceProvider::class,
    \Tnt\Mollie\MollieServiceProvider::class,
]);
```

And point dry-ecommerce at the gateway:

```php
// config/ecommerce.php
'payment' => \Tnt\Mollie\MolliePayment::class,
```

## Development

```sh
make docker    # dry-mollie-dev container (php8.4 dry-docker image)
make test      # Pest
make phpstan   # PHPStan level 9, no baseline
make yarn-format
```
