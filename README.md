# dry-mollie

Mollie payment gateway for
[dry-ecommerce](https://github.com/TallieuTallieu/dry-ecommerce) — the
first gateway on that package's payment ledger
(`PaymentGatewayInterface` + `PaymentWebhook`), and the reference shape
for new provider packages.

## Install

See [docs/installation.md](docs/installation.md). Short version:

```sh
composer require tallieutallieu/dry-mollie
```

with the VCS repositories configured — dry-mollie and dry-ecommerce
(`^3.13`, with the payment ledger and its admin screen) from GitHub, dry
from Bitbucket.

## Wiring

Three things, all project-side and all documented in
[docs/gateway.md](docs/gateway.md):

1. `config/mollie.php` — `api_key` (from the env), `redirect_url`,
   `webhook_url`, optionally `retries`/`retry_delay_ms`; and `'payment' => \Tnt\Mollie\MolliePayment::class` in
   `config/ecommerce.php`.
2. One webhook route handed to dry-ecommerce's `PaymentWebhook`.
3. A return page that reads the order's own payment status.

The gateway reports and never writes: dry-ecommerce's payment ledger
records what `pay()` and `reportOf()` answer and derives `payment_status`.
