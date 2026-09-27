# Changelog

## 0.5.0 — 2026-09-27

First release, on `mirafive/sdk-php` 0.5 and its framework seams (`enabled`, `flushOnShutdown`, `flagsRefreshSeconds`, `handOff`, `deliverPrepared()`).

- `MiraFive\Symfony\MiraFiveBundle` (`AbstractBundle`), config key `mirafive`: `secret_key`, `website_key`, `host`, `mode`, `script_mode`, `enabled`, `messenger`, `flags.refresh_seconds`, `flags.cache`, `test`; keys from `MIRAFIVE_*` environment variables by default.
- Autowired `MiraFive\Mira` and `MiraFive\Flags\MiraFlags` (`$mira->flags()`, one per process). Without a secret key or with `enabled: false` both record nothing.
- Flush once on `kernel.terminate` and `console.terminate`, and on `kernel.reset` for worker runtimes; the core's shutdown flush is off.
- Optional delivery of buffered batches through Symfony Messenger (`DeliverBatch`), delivered by the worker's own client byte for byte; `send()` stays synchronous.
- Twig `mirafive_script()` (tracker tag) and `mirafive_flags()` (flag bootstrap, with `Cache-Control: private, no-store` on the response).
- `bin/console mirafive:check` sends `$install_check` and prints the receipt.
- Test mode with `MiraFive\Symfony\Test\MiraFake` and the `InteractsWithMira` trait.
