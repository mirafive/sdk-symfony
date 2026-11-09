# Changelog

## 2.1.0 — 2026-11-09

### Changed

- Requires [`mirafive/sdk-php`](https://github.com/mirafive/sdk-php) 2.1, which deprecates `fee`
  and `tax` on `revenue()`: MIRA FIVE counts revenue as the customer paid it, VAT included.
  Passing them still works and does nothing; they are removed in 3.0.0.

## 2.0.0 — 2026-11-08

A rewrite for the MIRA FIVE platform, on [`mirafive/sdk-php`](https://github.com/mirafive/sdk-php)
2.0. The 1.x bundle spoke the retired v1 collector protocol; 2.0 speaks the MIRA FIVE ingest and
mirrors the Node SDK method for method. Symfony 7.4 LTS and 8.

### New

- `group()`, `revenue()` (the revenue ledger: integer minor units, `entryId`, `entryKind`, `fee`,
  `tax`, `refundOf`, `product`, subscriptions) and `touchpoint()` (survey answers, offline contacts,
  redeemed codes) on the injected `MiraFive\Mira`.
- `identify()` takes `externalIds`, so connectors link Stripe, RevenueCat, Shopify and Shopware
  money to the user.
- Per-event `consent` and `context`, and `with()` for a client with defaults per request or user.
- `Mira::eventIdFor()`, identical to the Node SDK, for facts that may be reported twice.
- `mirafive_script()` prints the MIRA FIVE browser SDK with `consent` and `autocapture` options
  (also as `script.*` configuration), and never prints a secret key.
- `mirafive:check` checks both keys and the host without storing anything.
- Test mode exposes `MiraFive\Testing\MiraFake` with `assertRevenue()`, `assertTouchpoint()`,
  `assertGrouped()` and `assertNoErrors()`; `InteractsWithMira` returns it.
- `timeout_ms`, `flush_deadline_ms` and `max_retries` options.

### Changed

- `website_key` (`MIRAFIVE_WEBSITE_KEY`) is now `public_key` (`MIRAFIVE_PUBLIC_KEY`, `mira_pk_…`);
  secret keys are `mira_sk_…`.
- The default host is `https://events.mirafive.io`; the browser SDK loads from
  `https://cdn.mirafive.io/sdk/mira.js`.
- All client methods take named arguments and never throw.
- Messenger messages are `DeliverBatch` with a `payload` of `{stream, seq, events}`; the handler
  adds `sent_at` when it sends.
- `MiraFake` and `RecordingTransport` moved to `MiraFive\Testing` in sdk-php, so plain PHP, Laravel
  and Symfony tests share them.
- Tests use Pest.

### Removed

- `mode`, `script_mode` and the consentless and full modes: consent is per event, and the browser
  SDK follows your consent banner (`script.consent`).
- The 1.x tracker options of `mirafive_script()`: `mode`, `hash`, `manual`, `track_localhost`,
  `site_search` and `integrity`.
- `Mira::send()`, idempotency keys and `Receipt`: use `eventId: Mira::eventIdFor(…)` and
  `flush()`.
- `sessionId`, `$install_check`, `payment_id` and `mf_` keys.

### Migrating from 1.x

1. `composer require mirafive/sdk-symfony:^2.0`.
2. Set `MIRAFIVE_SECRET_KEY` to the new `mira_sk_…` key, rename `MIRAFIVE_WEBSITE_KEY` to
   `MIRAFIVE_PUBLIC_KEY` with the `mira_pk_…` key, and remove `MIRAFIVE_HOST` if it pointed to
   `collect.mirafive.io`.
3. In `config/packages/mirafive.yaml`: rename `website_key` to `public_key`, remove `mode` and
   `script_mode`, and move script choices under `script:`.
4. In templates, drop the removed `mirafive_script()` options; use `consent` and `autocapture`.
5. Update calls to named arguments: `$mira->track('signup', userId: 'u1', properties: […])` becomes
   `$mira->track(event: 'signup', userId: 'u1', properties: […])`; `time:` is `timestamp:`.
6. Move money from properties to `$mira->revenue(amount: 4900, currency: 'EUR', entryId: …)`.
7. In tests, `use MiraFive\Testing\MiraFake` instead of `MiraFive\Symfony\Test\MiraFake`; event
   arrays use the wire's keys (`event`, `user_id`, `properties`).
8. Run `bin/console mirafive:check`.
