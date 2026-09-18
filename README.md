# dry-mollie

Mollie payment gateway for
[dry-ecommerce](https://github.com/TallieuTallieu/dry-ecommerce) — the
first gateway on that package's payment harness
(`PaymentGatewayInterface` + `PaymentWebhook`), and the reference shape
for new provider packages.

## Install

See [docs/installation.md](docs/installation.md). Short version:

```sh
composer require tallieutallieu/dry-mollie
```

with the VCS repositories configured — dry-mollie and dry-ecommerce
(`^3.10`, the release that ships the payment harness) from GitHub, dry
from Bitbucket.

## Wiring

Three things, all project-side and all documented in
[docs/gateway.md](docs/gateway.md):

1. `config/mollie.php` — `api_key` (from the env), `redirect_url`,
   `webhook_url`, optionally `retries`/`retry_delay_ms`; and `'payment' => \Tnt\Mollie\MolliePayment::class` in
   `config/ecommerce.php`.
2. One webhook route handed to dry-ecommerce's `PaymentWebhook`.
3. A return page that reads the order's own payment status.

The gateway dispatches events and never writes `payment_status` — the
dry-ecommerce listeners own that column.
