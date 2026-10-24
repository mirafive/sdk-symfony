# Changelog

## Unreleased

- Revenue events may carry `payment_id`, the payment provider's id of the payment (1–255 characters), next to `revenue` and `currency`. Nothing changes in the API: pass it in the properties. The README shows a Stripe example.
- The default ingest host is `https://collect.mirafive.io`.

- **Breaking:** feature flags and A/B experiments are removed: the autowired `MiraFive\Flags\MiraFlags`, the Twig function `mirafive_flags()`, the `flags` option of `mirafive_script()`, the `Cache-Control: private, no-store` response listener, `MiraFake::serveFlags()` and the flag endpoints of the test transport.
- **Breaking:** the config keys `flags.refresh_seconds` and `flags.cache` are removed. The cache service that shares the delivery pause between processes is now the top-level key `cache` (default `cache.app`).

## 1.0.0 — 2026-09-27

First release, on `mirafive/sdk-php` 1.0 and its framework seams (`enabled`, `flushOnShutdown`, `flagsRefreshSeconds`, `handOff`, `deliverPrepared()`).

- `MiraFive\Symfony\MiraFiveBundle` (`AbstractBundle`), config key `mirafive`: `secret_key`, `website_key`, `host`, `mode`, `script_mode`, `enabled`, `messenger`, `flags.refresh_seconds`, `flags.cache`, `test`; keys from `MIRAFIVE_*` environment variables by default.
- Autowired `MiraFive\Mira` and `MiraFive\Flags\MiraFlags` (`$mira->flags()`, one per process). Without a secret key or with `enabled: false` both record nothing.
- Flush once on `kernel.terminate` and `console.terminate`, on `kernel.reset` for worker runtimes, and after each handled or failed Messenger message; the core's shutdown flush is off.
- Optional delivery of buffered batches through Symfony Messenger (`DeliverBatch`), delivered by the worker's own client byte for byte; `send()` stays synchronous.
- Twig `mirafive_script()` (tracker tag) and `mirafive_flags()` (flag bootstrap, with `Cache-Control: private, no-store` on the response).
- `bin/console mirafive:check` sends `$install_check` and prints the receipt.
- Test mode with `MiraFive\Symfony\Test\MiraFake` and the `InteractsWithMira` trait.
