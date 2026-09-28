# Changelog

All notable changes to this package are documented in this file. Versions
follow [Semantic Versioning](https://semver.org). New entries are generated from
commit messages by [dry-ci](https://github.com/TallieuTallieu/dry-ci); past
entries may be edited by hand.

## 5.0.1 - 2026-09-18

### Other changes

- Support oak 4 and require PHP 8.4 ([sc-11480](https://app.shortcut.com/tallieu--tallieu/story/11480))

## 5.0.0 - 2026-09-18

### Breaking changes

- `MolliePayment::pay()` returns a `PaymentOutcome` (`PaymentRedirect`, `PaymentSettled` or `PaymentRefused`) and no longer writes `payment_id`, dispatches payment events or redirects; dry-ecommerce's payment ledger records the outcome ([sc-11474](https://app.shortcut.com/tallieu--tallieu/story/11474))
- `statusOf()` is replaced by `reportOf()`, which returns a `PaymentReport`: Mollie's status plus every capture, refund and chargeback ([sc-11474](https://app.shortcut.com/tallieu--tallieu/story/11474))
- The `MolliePayment` constructor no longer takes a `DispatcherInterface` or a `RedirectorInterface` ([sc-11474](https://app.shortcut.com/tallieu--tallieu/story/11474))
- A zero-total order no longer redirects to the return page: `Cart::place()` returns the order and the project's controller sends the visitor to its thank-you page ([sc-11474](https://app.shortcut.com/tallieu--tallieu/story/11474))
- The return page must handle `PaymentStatus::PartiallyRefunded` (show the thank-you page) ([sc-11474](https://app.shortcut.com/tallieu--tallieu/story/11474))
- Require dry-ecommerce ^3.13, the release with the payment ledger ([sc-11474](https://app.shortcut.com/tallieu--tallieu/story/11474))

### Features

- Report to the dry-ecommerce payment ledger: every entry is filed under the provider `mollie`, and the ledger derives the order's `payment_status` ([sc-11474](https://app.shortcut.com/tallieu--tallieu/story/11474))

### Other changes

- Rewrite the gateway docs around "report, don't write" ([sc-11474](https://app.shortcut.com/tallieu--tallieu/story/11474))

## Earlier history

- **1.0.0** (2019-10-15): First release as `reinvanoyen/dry-mollie`: a `MolliePayment` gateway, a `WebhookController` and a service provider on mollie-api-php ^2.12.
- **1.0.2** (2019-10-17) and **1.0.4** (2019-12-09): Stop using deprecated Mollie payment methods (`isRefunded()`, then `isCancelled()` in favour of `isCanceled()`).
- **1.0.5** (2020-03-12): Add the order description and error handling on payment.
- **1.0.6** (2020-05-20) and **1.0.7** (2021-01-29): Add a webhook refund event and pass the refunds along with it.
- **2.0.0** (2025-08-18): Renamed to `tallieutallieu/dry-mollie`. Requires mollie-api-php ^3.4, oak ^1.1.15 and dry-ecommerce ^1.2.1. Adds a cancel URL and the order id in the redirect URL, and moves webhook processing into `MolliePayment`.
- **2.0.1** (2025-08-18): Handle the `open` payment status when processing a webhook.
- **2.0.2 / 3.0.0** (2026-09-02, the same commit): Modernised to dry 4+: PHP 8.4, mollie-api-php ^3.13 and dry-ecommerce ^3.10, with Pest, PHPStan level 9 and the house CI and auto-release workflows. The old auto-release tagged it as the patch 2.0.2, and it was also tagged 3.0.0 ([sc-11347](https://app.shortcut.com/tallieu--tallieu/story/11347)).
- **3.1.0** (2026-09-02): `MolliePayment` implements dry-ecommerce's `PaymentGatewayInterface` with `pay()` and `statusOf()`. Breaking despite the minor bump: the 1.x webhook plumbing (`WebhookController`, static `process()`, the router registration) is gone, and the project wires one route to dry-ecommerce's `PaymentWebhook` ([sc-11348](https://app.shortcut.com/tallieu--tallieu/story/11348)).
- **4.0.0** (2026-09-18): `pay()` catches every `MollieException` and builds the client on demand through `MollieClientFactoryInterface`. A payment without a checkout URL counts as failed, every failed attempt clears `payment_id`, and a partial refund no longer reads as `Refunded`. The retry budget is configurable through `mollie.retries` and `mollie.retry_delay_ms` ([sc-11446](https://app.shortcut.com/tallieu--tallieu/story/11446)).

See the git tags before 5.0.0 for the full history.
