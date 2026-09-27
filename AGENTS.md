# AGENTS.md

`mirafive/sdk-symfony`: the Symfony bundle of MIRA FIVE. It wires `mirafive/sdk-php` into the container; it has no
transport and no flag evaluator of its own. The public surface is fixed by the `mirafive/sdk-symfony` section of
`API.md` in the protocol repository; the wire by `PROTOCOL.md`, flag semantics by `FLAGS.md`. Change those first.

## Commands

```bash
COMPOSER=composer.local.json composer install   # local development against ../sdk-php (see below)
composer check      # pint --test, phpstan (level max), phpunit
composer lint       # pint, fixes formatting
composer analyse    # phpstan
composer test       # phpunit
```

## Developing against the unpublished core

`composer.json` requires `mirafive/sdk-php: ^1.0` from Packagist. Until that is published, install from the sibling
checkout with the gitignored `composer.local.json`, a copy of `composer.json` plus a path repository:

```bash
jq '. + {repositories: [{type: "path", url: "../sdk-php", options: {symlink: true, versions: {"mirafive/sdk-php": "1.0.0"}}}]}' \
  composer.json > composer.local.json
COMPOSER=composer.local.json composer install
```

Regenerate it whenever `composer.json` changes. CI uses `composer.json` and goes green once sdk-php is on Packagist.

## Rules

- PHP 8.3 syntax and functions only (CI runs 8.3–8.5): no property hooks, asymmetric visibility, pipe operator,
  `new` chained without parentheses, `array_find`/`array_any`/`array_all`.
- Symfony `^6.4|^7.0|^8.0`. Use only APIs present in 6.4; CI covers 6.4, 7.4 and 8.x.
- Never reimplement what sdk-php does (batching, retries, evaluation, bootstrap encoding). When the bundle needs a
  hook, change sdk-php first.
- `context.sdk` stays the core's `mirafive-php/<version>` (API.md: framework packages report the SDK they run on).
- Delivery never throws into application code; the Messenger handler throws only for Messenger's retry strategy.
- The client is built with `flushOnShutdown: false`: `Lifecycle` owns every flush. Disabled means `enabled: false` on
  the core, never a fake transport.
- No request state in services: per-request marks go on the Request. Anything held by a service must be flushed or
  cleared in `Lifecycle::reset()`.
- Twig output is built from escaped attributes only; the secret key is never rendered.
- Tests never touch the network: `mirafive.test` (RecordingTransport) or `tests/Support/SpyTransport`.
- Comments only for non-obvious constraints, one or two lines.
- Do not run git write commands unless asked; the maintainer commits.
