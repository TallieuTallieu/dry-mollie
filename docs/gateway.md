# The gateway

How dry-mollie sits on dry-ecommerce's payment ledger, and the three
things a project wires. Background: dry-ecommerce's `docs/payment.md`,
whose "Writing a gateway" guide this package is the worked proof of.

## Report, don't write

dry-ecommerce keeps an append-only payment ledger, and derives an order's
money and its `payment_status` from it. The rule for a gateway is **it
reports, the package writes**. `MolliePayment` never writes `payment_id`
or `payment_status`, never dispatches a payment event, never touches a
table and never redirects. It answers two questions, and the package turns
the answers into ledger entries:

- what did `pay()` do?
- what does Mollie say about this payment now?

Every entry is filed under `provider()`, which is `'mollie'`. Never change
it: the entries a shop already has would be orphaned.

## What the package does

`MolliePayment` implements `PaymentGatewayInterface`.

- **`pay($order)`** creates the Mollie payment and answers with a
  `PaymentOutcome`. The payment gets the amount from the order's integer
  cents (`Money::toDecimal()`), the order reference as description, and
  the configured return and webhook URLs.

    | What happened                            | `pay()` answers                             |
    | ---------------------------------------- | ------------------------------------------- |
    | Mollie created a payment with a checkout | `PaymentRedirect($id, $checkoutUrl)`        |
    | The order total is zero                  | `PaymentSettled` (below)                    |
    | Mollie created a payment with no checkout | `PaymentRefused($id)`                      |
    | Anything that threw a `MollieException`  | `PaymentRefused()`                          |

    On a redirect, the package records the attempt, points `payment_id` at
    it and sends the visitor to Mollie. On a refusal, the order reads
    `failed`, stays re-placeable, and the visitor still has their basket.

    A **zero total** never reaches Mollie. It is settled on the spot, like
    `NullPayment`: a `Paid` report with one €0 `captured` movement, under a
    payment id minted per placement (`free_…`). The package counts a €0
    capture as paid. Nothing redirects after a settlement: `Cart::place()`
    returns the order and the project's controller sends the visitor to
    its thank-you page.

    Every failure to create the payment is refused the same way:

    | What went wrong                           | Mollie's exception                                           |
    | ----------------------------------------- | ------------------------------------------------------------ |
    | Mollie refuses the payment (4xx)          | `ValidationException`, `NotFoundException`, … `ApiException` |
    | Mollie is down (503)                      | `ServiceUnavailableException` → `ServerException`            |
    | The request times out (408)               | `RequestTimeoutException` → `NetworkRequestException`        |
    | The connection never lands                | `RetryableNetworkRequestException`                           |
    | The API key is missing or malformed       | `InvalidAuthenticationException`                             |

    The catch is on `MollieException`, the root of every class in that
    column — not on `ApiException`, which in `mollie-api-php` v3 means only
    "the API answered with an error", and so covers the first row alone.
    None of them may escape: a refusal is an outcome, and a throw out of
    `pay()` would leave a placed order pending with nobody on the way to
    pay it.

    That is also why the client is **built inside** `pay()`, through
    `MollieClientFactoryInterface`, rather than injected: `setApiKey()`
    refuses a key that is not `test_`/`live_` by throwing, and an injected
    client would throw that while the container assembles the gateway —
    out of reach of any `catch` the gateway could write.

    One of those failures is slow. A dropped connection is _retryable_, so
    the Mollie client sleeps out its backoff before giving up — and it
    does that inside `pay()`, with a visitor watching a checkout that has
    not answered yet. The budget is configurable for that reason; see
    [Retries](#retries).

- **`reportOf($paymentId)`** asks Mollie's API about the payment — never
  the webhook body, which carries only the id — and answers with a
  `PaymentReport`: a status and every money movement Mollie knows of.

    **The status** is Mollie's view of the payment:

    | Mollie says                     | Status     |
    | ------------------------------- | ---------- |
    | `paid`                          | `Paid`     |
    | `failed`                        | `Failed`   |
    | `canceled`                      | `Canceled` |
    | `expired`                       | `Expired`  |
    | `open`, `pending`, `authorized` | `Pending`  |

    `authorized` maps to pending deliberately: the money is only reserved,
    and a capture can still fail or be voided — Mollie sends another
    webhook when it settles into `paid`.

    The gateway never answers `Refunded` or `PartiallyRefunded`. Mollie
    keeps a refunded or charged-back payment on `paid`, and whether an
    order is refunded is arithmetic the ledger does over the movements.

    **The movements**, all in cents through `Money::fromDecimal()`, each
    under Mollie's own id for it:

    | Mollie has                               | Movement                                   |
    | ---------------------------------------- | ------------------------------------------ |
    | a `paid` payment                         | `captured`, reference `tr_…`               |
    | a refund `processing` or `refunded`      | `refunded`, reference `re_…`               |
    | a refund `failed`                        | `refund_reversed`, reference `re_…`        |
    | a refund `queued`, `pending`, `canceled` | nothing — no money moved                   |
    | a chargeback                             | `chargeback`, reference `chb_…`            |
    | a chargeback with `reversedAt`           | also `chargeback_reversed`, same reference |

    A `Paid` report always carries its capture: the ledger decides "paid"
    from the money, not from the word. A reversed chargeback keeps its
    `chargeback` movement, because the reversal needs its counterpart. A
    failed refund is reported as reversed even if its refund was never
    seen; the ledger drops a reversal whose counterpart it does not hold.

    Refunds and chargebacks come embedded in the one `GET`
    (`embed=refunds,chargebacks`). Pass the embeds to the SDK as a list:
    `mollie-api-php` drops a comma string without a word.

    **It reports everything, every time.** The ledger deduplicates on
    `(provider, kind, reference)` and writes only what it does not hold
    yet. A replayed webhook writes nothing, a late `expired` after the
    money arrived is recorded and outranked by the capture, and a refund
    still lands after `paid`. The gateway never remembers what it reported
    before.

    Unlike `pay()`, `reportOf()` **lets Mollie's failures out**. The
    webhook wants them: the host answers non-2xx, and Mollie retries for
    hours. Catching them here would answer 200 to a question that was
    never asked.

**Re-placement:** `Cart::place()` on a failed, canceled or expired order
calls `pay()` again. That is a fresh Mollie payment, and the new attempt
becomes the order's current one. The old attempt's entries stay, and a
late webhook for it still finds the order through the ledger: if that old
payment gets paid after all, its capture counts.

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

The same budget applies to `reportOf()`, where it matters much less: the
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
Mollie the id means nothing here (the package has recorded it as an
`unknown_payment` entry by then). On an exception from Mollie's own API
(`reportOf()` interrogates it), let the request fail — Mollie retries for
hours, which is exactly the recovery you want.

### 3. The return page

Where Mollie sends the visitor back, `?order=<row id>` appended. The
webhook may or may not have arrived first, so the page reads **the
order's own state** and concludes nothing from the visit itself:

```php
$order = OrderRepository::create()->byId($orderId)->firstOrNull();

match ($order->getPaymentStatus()) {
    PaymentStatus::Paid,
    PaymentStatus::PartiallyRefunded => /* thank-you */,
    PaymentStatus::Pending => /* "confirming your payment…" — the webhook
                                 is still on its way; poll or refresh */,
    default => /* failed/canceled/expired/refunded: offer the basket
                  again — the order is re-placeable and the cart still
                  stands */,
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
  The webhook tests run dry-ecommerce's real `PaymentLedger` in memory
  (`tests/Support/InMemoryPaymentLedger.php`).

## See also

- [Installation](installation.md) — requirements and the VCS repositories
- dry-ecommerce `docs/payment.md` — the ledger, the derived status, the events
- dry-ecommerce `docs/orders.md` — re-placement, the return page's rules
