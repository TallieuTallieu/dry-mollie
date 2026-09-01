# The gateway

How dry-mollie sits on dry-ecommerce's payment harness, and the three
things a project wires. Background: dry-ecommerce's `docs/payment.md`,
whose "Writing a gateway" guide this package is the worked proof of.

## What the package does

`MolliePayment` implements `PaymentGatewayInterface`:

- **`pay($order)`** creates the Mollie payment — amount from the order's
  integer cents (`Money::toDecimal()`), the order reference as description,
  the configured return and webhook URLs — writes the Mollie id onto
  `ecommerce_order.payment_id`, saves, and redirects the visitor to
  Mollie's checkout through the harness redirector. A **zero total** is
  paid on the spot, like `NullPayment`: `Paid` is dispatched and the
  visitor goes straight to the return page. If Mollie **refuses** the
  payment, the gateway dispatches `PaymentFailed` and returns without
  redirecting: the order reads `failed`, stays re-placeable, and the
  visitor still has their basket.
- **`statusOf($paymentId)`** asks Mollie's API where the money stands —
  never the webhook body, which carries only the id — and answers in the
  harness vocabulary:

    | Mollie says                     | Answer            | Event dispatched  |
    | ------------------------------- | ----------------- | ----------------- |
    | `paid`                          | `Paid`            | `Paid`            |
    | `paid` + refunds or chargebacks | `Refunded`        | `PaymentRefunded` |
    | `failed`                        | `Failed`          | `PaymentFailed`   |
    | `canceled`                      | `Canceled`        | `PaymentCanceled` |
    | `expired`                       | `Expired`         | `PaymentExpired`  |
    | `open`, `pending`, `authorized` | `Pending`         | — nothing         |

    `authorized` maps to pending deliberately: the money is only reserved,
    and a capture can still fail or be voided — Mollie sends another
    webhook when it settles into `paid`. Reporting it as paid would redeem
    coupons and release the cart for money that never arrived.

The gateway **never writes `payment_status`** — it dispatches, and
dry-ecommerce's listeners write the column through
`PaymentStatus::canTransitionTo()`. That guard is also the idempotency: a
replayed `paid` webhook writes nothing, a late `expired` after the money
arrived writes nothing, and a refund still lands after `paid`.

**Re-placement:** `Cart::place()` on a failed/canceled/expired order calls
`pay()` again. That is a fresh Mollie payment — the old one is dead at
Mollie — so `payment_id` is overwritten and the amount is re-read from the
re-frozen order. A webhook for the dead attempt finds no order and gets a
404, which is the right answer for it.

## What the project wires

### 1. Configuration — `config/mollie.php`

```php
return [
    // test_... or live_... — from the environment, never committed.
    'api_key' => getenv('MOLLIE_API_KEY'),

    // The return page (below). The gateway appends ?order=<id> — or
    // &order=<id> when the URL already carries a query.
    'redirect_url' => \dry\abs_url('checkout/return/'),

    // The one webhook route (below), as Mollie must reach it from
    // outside. On a local environment this needs a tunnel — Mollie
    // cannot post to localhost.
    'webhook_url' => \dry\abs_url('mollie-webhook/'),
];
```

And point dry-ecommerce at the gateway:

```php
// config/ecommerce.php
'payment' => \Tnt\Mollie\MolliePayment::class,
```

The service provider (register `\Tnt\Mollie\MollieServiceProvider` after
`EcommerceServiceProvider`) binds the Mollie client; dry-ecommerce's
provider sees the gateway implements `PaymentGatewayInterface` and binds
the webhook plumbing.

### 2. The webhook route

Mollie POSTs `id=tr_...` when a payment changes. One route, project-side,
handed to the package handler:

```php
'mollie-webhook/' => function ($request) use ($app) {
    try {
        $app->get(\Tnt\Ecommerce\Payment\PaymentWebhook::class)->handle(
            (string) $request->post->string('id')
        );
    } catch (\Tnt\Ecommerce\UnknownPayment $e) {
        throw new \dry\route\NotFound();
    }
},
```

Answer 200 (an empty body is fine) when `handle()` returns; the 404 tells
Mollie the id means nothing here. On an exception from Mollie's own API
(`statusOf()` interrogates it), let the request fail — Mollie retries for
hours, which is exactly the recovery you want.

### 3. The return page

Where Mollie sends the visitor back, `?order=<row id>` appended. The
webhook may or may not have arrived first, so the page reads **the
order's own state** and concludes nothing from the visit itself:

```php
$order = OrderRepository::create()->byId($orderId)->firstOrNull();

match ($order->getPaymentStatus()) {
    PaymentStatus::Paid => /* thank-you */,
    PaymentStatus::Pending => /* "confirming your payment…" — the webhook
                                 is still on its way; poll or refresh */,
    default => /* failed/canceled/expired: offer the basket again —
                  the order is re-placeable and the cart still stands */,
};
```

The `order` parameter identifies, it does not authenticate — decide there
who may see the order, exactly as dry-ecommerce's docs say about
references.

## Test mode

- A `test_...` API key makes every payment a test payment: Mollie's
  checkout shows a status picker instead of moving money. The full
  webhook flow runs — but only if `webhook_url` is publicly reachable,
  so tunnel your local environment or test webhooks on staging.
- The package's own suite runs against Mollie's mock client
  (`Mollie\Api\Fake\MockMollieClient`) — no network, no key. Copy that
  arrangement for project-level tests.

## See also

- [Installation](installation.md) — the package next to dry-ecommerce 4.x
- dry-ecommerce `docs/payment.md` — the harness, the events, the guard
- dry-ecommerce `docs/orders.md` — re-placement, the return page's rules
