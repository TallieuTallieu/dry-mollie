# dry-mollie

Mollie payment gateway for [dry-ecommerce](https://github.com/TallieuTallieu/dry-ecommerce).

> [!warning] Being reimplemented
> The package was just modernized to the dry 4 line (PHP 8.4, oak 3,
> dry-ecommerce 4.x) with the 1.x behaviour ported as-is. The gateway is
> being reimplemented on dry-ecommerce's payment harness — the
> `PaymentGatewayInterface` / `PaymentWebhook` shape described in that
> package's docs/payment.md "Writing a gateway" guide. Until that lands,
> treat the current gateway class as transitional.

## Pages

- [Installation](installation.md) — requirements and the dev-only path-repo
  arrangement

## Configuration (mollie.php)

| Key            | Meaning                                        |
| -------------- | ---------------------------------------------- |
| `api_key`      | Mollie API key (`test_...` / `live_...`)       |
| `redirect_url` | Where Mollie sends the visitor back afterwards |
