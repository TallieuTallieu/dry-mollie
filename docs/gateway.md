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
  visitor goes straight to the return page.

    A **failed attempt** is anything that did not end at a checkout page,
    and they are all answered the same way: `PaymentFailed` is dispatched,
    `payment_id` is cleared, and nothing is redirected. The order reads
    `failed`, stays re-placeable, and the visitor still has their basket.
    What counts:

    | What went wrong                           | Mollie's exception                                           |
    | ----------------------------------------- | ------------------------------------------------------------ |
    | Mollie refuses the payment (4xx)          | `ValidationException`, `NotFoundException`, … `ApiException` |
    | Mollie is down (503)                      | `ServiceUnavailableException` → `ServerException`            |
    | The request times out (408)               | `RequestTimeoutException` → `NetworkRequestException`        |
    | The connection never lands                | `RetryableNetworkRequestException`                           |
    | The API key is missing or malformed       | `InvalidAuthenticationException`                             |
    | Mollie creates a payment with no checkout | — (nowhere to send the visitor)                              |

    The catch is on `MollieException`, the root of every class in that
    column — not on `ApiException`, which in `mollie-api-php` v3 means only
    "the API answered with an error", and so covers the first row alone.
    The rest must not escape: by the time `pay()` runs, `Cart::place()` has
    already placed the order, and a throw out of here leaves a placed,
    unpaid order on an error page that a dry host answers with HTTP 200.

    That is also why the client is **built inside** `pay()`, through
    `MollieClientFactoryInterface`, rather than injected: `setApiKey()`
    refuses a key that is not `test_`/`live_` by throwing, and an injected
    client would throw that while the container assembles the gateway —
    out of reach of any `catch` the gateway could write.

    Clearing `payment_id` matters on a **re-placed** order, where the
    previous attempt's id is still on the row: a failed attempt leaves no
    live payment, so no webhook may speak for the order either.

    One of those failures is slow. A dropped connection is _retryable_, so
    the Mollie client sleeps out its backoff before giving up — and it
    does that inside `pay()`, with a visitor watching a checkout that has
    not answered yet. The budget is configurable for that reason; see
    [Retries](#retries).

- **`statusOf($paymentId)`** asks Mollie's API where the money stands —
  never the webhook body, which carries only the id — and answers in the
  harness vocabulary:

    | Mollie says                     | Answer     | Event dispatched  |
    | ------------------------------- | ---------- | ----------------- |
    | `paid`                          | `Paid`     | `Paid`            |
    | `paid`, everything returned     | `Refunded` | `PaymentRefunded` |
    | `paid`, part of it returned     | `Paid`     | `Paid`            |
    | `failed`                        | `Failed`   | `PaymentFailed`   |
    | `canceled`                      | `Canceled` | `PaymentCanceled` |
    | `expired`                       | `Expired`  | `PaymentExpired`  |
    | `open`, `pending`, `authorized` | `Pending`  | — nothing         |

    `authorized` maps to pending deliberately: the money is only reserved,
    and a capture can still fail or be voided — Mollie sends another
    webhook when it settles into `paid`. Reporting it as paid would redeem
    coupons and release the cart for money that never arrived.

    **Refunds are weighed, not counted.** Mollie keeps a refunded or
    charged-back payment on `paid`; what went back hangs off it. The
    gateway adds `amountRefunded` and `amountChargedBack` — in cents,
    through `Money::fromDecimal()` — and only calls it `Refunded` when
    they reach the payment's own amount. `hasRefunds()`/`hasChargebacks()`
    are not asked, because they only say a link is there: by them a €1
    refund on a €100 order would read as the whole order coming back. And
    `Refunded` is **terminal** in dry-ecommerce — nothing may follow it,
    and the order is never re-placeable again — so it is not a status to
    reach on a partial return. A refund larger than the payment (Mollie
    allows it, to reimburse return shipping) still counts as everything
    back.

    Unlike `pay()`, `statusOf()` **lets Mollie's failures out**. The
    webhook wants them: the host answers non-2xx, and Mollie retries for
    hours. Catching them here would answer 200 to a question that was
    never asked.

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

    // Optional; see Retries below. These are the defaults.
    'retries' => 5,
    'retry_delay_ms' => 1000,
];
```

And point dry-ecommerce at the gateway:

```php
// config/ecommerce.php
'payment' => \Tnt\Mollie\MolliePayment::class,
```

The service provider (register `\Tnt\Mollie\MollieServiceProvider` after
`EcommerceServiceProvider`) binds `MollieClientFactoryInterface`, and
`MollieApiClient` through it for project code that wants the client
itself; dry-ecommerce's provider sees the gateway implements
`PaymentGatewayInterface` and binds the webhook plumbing.

Note that resolving `MollieApiClient` from the container is what validates
the key, so a bad one throws there — the gateway goes through the factory
precisely to keep that throw inside `pay()`.

#### Retries

`retries` and `retry_delay_ms` set what a **dropped connection** costs
before the client gives up. Only that one failure is retried — a refusal,
a 503 or a timeout is answered at once — and the wait before retry _n_ is
`n * retry_delay_ms`, so the defaults spend up to 15 seconds
(1 + 2 + 3 + 4 + 5) before giving an answer.

They are the Mollie client's own defaults, kept so nothing changes under a
project that has not thought about it. In `pay()` that waiting happens
inside the checkout request, with a person watching it, so most projects
want less:

```php
'retries' => 1,
'retry_delay_ms' => 250,
```

Set `retries` to `0` to never retry. A value that is not a whole number is
ignored and the default used.

The same budget applies to `statusOf()`, where it matters much less: the
failure goes out to the host either way, and Mollie's own retries — hours
of them — are the recovery there.

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
  arrangement for project-level tests: the gateway asks
  `MollieClientFactoryInterface` for its client, so bind a factory that
  hands out the mock (see `tests/Support/FixedMollieClientFactory.php`).

## See also

- [Installation](installation.md) — requirements and the VCS repositories
- dry-ecommerce `docs/payment.md` — the harness, the events, the guard
- dry-ecommerce `docs/orders.md` — re-placement, the return page's rules
