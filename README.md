# dry-mollie

Mollie payment gateway for
[dry-ecommerce](https://github.com/TallieuTallieu/dry-ecommerce).

> **Status:** modernized to the dry 4 line (PHP 8.4, dry-ecommerce 4.x via
> the dev-only path-repo arrangement) with the 1.x behaviour ported as-is.
> The gateway is being **reimplemented on the dry-ecommerce payment
> harness** — see the `dry-mollie` epic. Until that lands, treat the
> gateway class as transitional.

## Install

See [docs/installation.md](docs/installation.md). Short version:

```sh
composer require tallieutallieu/dry-mollie
```

with the GitHub VCS repository configured, and — while dry-ecommerce 4.x is
untagged — a path repository to a sibling `../dry-ecommerce` checkout.

## Configuration (`mollie.php`)

| Key            | Meaning                                        |
| -------------- | ---------------------------------------------- |
| `api_key`      | Mollie API key (`test_...` / `live_...`)       |
| `redirect_url` | Where Mollie sends the visitor back afterwards |

More on payments: dry-ecommerce's `docs/payment.md`.
