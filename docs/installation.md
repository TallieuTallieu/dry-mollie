# Installation

## Requirements

|               |          |
| ------------- | -------- |
| PHP           | `>= 8.4` |
| dry-ecommerce | 4.x      |

oak `^3` and dry `^4` arrive transitively through dry-ecommerce.

## Getting the package

```sh
composer require tallieutallieu/dry-mollie
```

The repository is not on Packagist, so the project needs the VCS repository
as well:

```json
"repositories": [
  { "type": "vcs", "url": "git@github.com:reinvanoyen/dry-mollie.git" }
]
```

### While dry-ecommerce 4.x is unreleased

dry-ecommerce's 4.x line is untagged and lives on master, so this package —
like every project on that line — resolves it from a sibling checkout. This
repository's own `composer.json` carries the arrangement:

```json
"repositories": [
  {
    "type": "path",
    "url": "../dry-ecommerce",
    "options": {
      "symlink": true,
      "versions": { "tallieutallieu/dry-ecommerce": "4.0.x-dev" }
    }
  }
],
"require": { "tallieutallieu/dry-ecommerce": "^4.0@dev" }
```

The `versions` option pins what the path repository claims to be: without
it, composer infers `dev-<branch>` from whatever branch the sibling checkout
happens to have out, and the committed `composer.lock` would break the
moment that branch changes.

Two things this breaks that are easy to miss:

- **Docker.** `../dry-ecommerce` is outside the project mount, so the
  container needs it mounted or the symlink in `vendor/` dangles —
  `docker-compose.yml` here mounts it at `/var/www/dry-ecommerce`.
- **Deployment.** A path repository is a development arrangement. Nothing
  built this way is deployable — switch to the tagged release first.

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
