# dry-mollie

Mollie payment gateway for [dry-ecommerce](https://github.com/TallieuTallieu/dry-ecommerce)
— the first gateway on that package's payment harness, and the shape a new
provider package copies.

## Pages

- [Installation](installation.md) — requirements and the VCS repositories
- [The gateway](gateway.md) — what the package does, the three things a
  project wires (config, the webhook route, the return page), test-mode
  notes

## At a glance

| Key (`mollie.php`) | Meaning                                                 |
| ------------------ | ------------------------------------------------------- |
| `api_key`          | Mollie API key (`test_...` / `live_...`), from the env  |
| `redirect_url`     | The return page; the gateway appends `order=<id>`       |
| `webhook_url`      | The project's one webhook route, as Mollie reaches it   |
| `retries`          | Optional; retries on a dropped connection (default `5`) |
| `retry_delay_ms`   | Optional; the linear backoff step (default `1000`)      |

The gateway dispatches events and never writes `payment_status` — the
dry-ecommerce listeners own that column, and their transition guard is what
makes replayed and late webhooks harmless.
