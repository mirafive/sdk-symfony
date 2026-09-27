# MIRA FIVE for Symfony

Privacy-first analytics and feature flags for Symfony applications, hosted in the EU. The bundle wires the [PHP SDK](https://packagist.org/packages/mirafive/sdk-php) into your container, sends events after the response has gone out, prints the tracker tag in Twig and hands flag answers to the page.

## Install

```bash
composer require mirafive/sdk-symfony
```

PHP 8.3 or newer, Symfony 6.4, 7.x or 8.x. Twig and Messenger are optional.

With Symfony Flex the bundle is registered for you. Without Flex, add it to `config/bundles.php`:

```php
return [
    // …
    MiraFive\Symfony\MiraFiveBundle::class => ['all' => true],
];
```

Create a **server source** in MIRA FIVE for the backend and, if you also measure the website, a **website source**. Put the keys in `.env.local` (or your secret store), never in the repository:

```bash
MIRAFIVE_SECRET_KEY=mf_ab12cd34_…   # server source, server-side only
MIRAFIVE_WEBSITE_KEY=mf_ef56gh78_…  # website source, public
```

No configuration file is needed; the defaults read these variables. To change something, create `config/packages/mirafive.yaml` (see [Configuration](#configuration)).

Check the setup:

```bash
bin/console mirafive:check
```

It sends an `$install_check` event, which is never stored or billed, and prints the receipt.

## Quickstart

### Track from a controller

`MiraFive\Mira` is autowired. `track()` only buffers; the bundle sends the buffer on `kernel.terminate`, after the response has reached the browser.

```php
use MiraFive\Mira;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SignupController extends AbstractController
{
    #[Route('/signup', methods: ['POST'])]
    public function __invoke(Mira $mira): Response
    {
        $user = /* … create the account … */;

        $mira->track('signup', userId: (string) $user->getId(), properties: ['plan' => 'pro']);

        return $this->redirectToRoute('dashboard');
    }
}
```

### Identify after login

```php
use MiraFive\Mira;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

#[AsEventListener]
final readonly class IdentifyOnLogin
{
    public function __construct(private Mira $mira) {}

    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();

        // Your internal id. getUserIdentifier() is often the e-mail address: never send that.
        $this->mira->identify((string) $user->getId(), ['plan' => $user->getPlan()]);
    }
}
```

### The tracker tag in `base.html.twig`

```twig
<head>
    {# … #}
    {{ mirafive_script() }}
</head>
```

This prints the hosted tracker with the website key:

```html
<script>window.mirafive=window.mirafive||function(){(mirafive.q=mirafive.q||[]).push(arguments)}</script>
<script defer src="https://cdn.mirafive.io/mira.js" data-key="mf_ef56gh78_…"></script>
```

It needs only the website key and prints nothing without one or with `enabled: false`. Options map to the tracker's attributes:

```twig
{{ mirafive_script({mode: 'full', autocapture: true, site_search: ['q', 'term']}) }}
```

| Option | Attribute | |
|---|---|---|
| `mode` | `data-mode` | `'consentless'` or `'full'`; defaults to `script_mode` |
| `hash` | `data-hash` | the site routes by `#` |
| `manual` | `data-manual` | no automatic pageviews |
| `autocapture` | `data-autocapture` | clicks, submits, changes |
| `site_search` | `data-site-search` | `true`, or the query parameters as a string or list |
| `flags` | `data-flags` | load the flags chunk up front |
| `track_localhost` | `data-track-localhost` | measure `localhost` too |
| `host` | `data-host` | defaults to the configured host when it is not the default |
| `src` | `src` | a pinned (`mira.<hash>.js`) or self-hosted copy of the tracker |
| `integrity` | `integrity`, `crossorigin` | Subresource Integrity for a pinned copy, from the tracker's `manifest.json`; the rolling `mira.js` changes under one URL and cannot carry it |
| `nonce` | `nonce` | set on both script tags, for a Content Security Policy |

## Consent & privacy

The server client and the tracker tag each have a collection mode.

- **Server events** default to **full** (`mode: full`), as with every MIRA FIVE server SDK: they may carry `userId`, `anonymousId` and `sessionId`. Use it for people who consented, or where you hold another lawful basis. `mode: consentless` sends no identifiers at all; passing one throws an `InvalidArgumentException`.
- **The tracker tag** has its own mode, `script_mode`, default **consentless**, which needs no banner: no cookies, no storage, no identifiers. With `script_mode: full` (or `mirafive_script({mode: 'full'})`) the tracker stores ids only once the visitor consents; tell it with `mirafive('consent', true)` or `{statistics, experiments, targeting}` from your banner.
- **Flags** take the banner's answer and the visitor's opt-out, see [Flags](#flags).

Always:

- **No personal data in event names or properties.** No e-mail addresses, names or free text a person typed.
- **`userId` is pseudonymous**: your internal id, never `getUserIdentifier()` when that is an e-mail address.
- **The secret key stays on the server.** Only `website_key` ever reaches a page; the bundle never prints the secret key.
- Browsers sending Do Not Track or Global Privacy Control are not measured by the tracker, and `mirafive_flags()` treats `Sec-GPC: 1` or `DNT: 1` as an opt-out.

## Configuration

Every key is optional. The full reference with the defaults:

```yaml
# config/packages/mirafive.yaml
mirafive:
    secret_key: '%env(default::MIRAFIVE_SECRET_KEY)%'    # server source; server-side only
    website_key: '%env(default::MIRAFIVE_WEBSITE_KEY)%'  # website source; printed by mirafive_script()
    host: '%env(default::MIRAFIVE_HOST)%'                # empty: https://events.mirafive.io
    mode: full                                           # full | consentless, for server events
    script_mode: consentless                             # consentless | full, for the tracker tag
    enabled: true                                        # false: record nothing
    messenger: null                                      # true or a bus service id: deliver through Messenger
    flags:
        refresh_seconds: 30                              # at least 10
        cache: cache.app                                 # PSR-16 or PSR-6 service id sharing the flag document; null for none
    test: false                                          # record instead of send, for the test environment
```

- **Disabled.** With `enabled: false`, or without a secret key, `Mira` and `MiraFlags` are still autowired but record nothing: nothing leaves the process, `send()` returns a local receipt, flags answer your fallbacks and `mirafive_flags()` prints nothing. `mirafive_script()` needs only the website key, so it is removed by `enabled: false` alone. Invalid input still throws, so a bug shows up before production. A typical `when@dev: { mirafive: { enabled: false } }`.
- **Autowired services.** `MiraFive\Mira` and `MiraFive\Flags\MiraFlags`. The injected `MiraFlags` is `$mira->flags()`: one flag document per process.
- **Errors.** Delivery never throws into your code. Failures go to the `mirafive` Monolog channel (or the `logger` service), as warnings.
- **Idempotent sends.** `$mira->send([...], idempotencyKey: 'order-981')` sends at once and returns the receipt; the same key is stored once. Use it for webhooks that may arrive twice.

## Messenger & worker runtimes

**Flushing.** The bundle sends the buffer once per request on `kernel.terminate`, and once per command on `console.terminate`. It switches the PHP SDK's own shutdown flush off (`flushOnShutdown: false`), so nothing goes out twice. Under PHP-FPM, `kernel.terminate` runs after `fastcgi_finish_request()`, so delivery does not delay the response.

**Worker runtimes** (FrankenPHP worker mode, RoadRunner, Swoole, `messenger:consume`). The bundle's listener is tagged `kernel.reset`, so every service reset between two requests or messages sends what is left in the buffer, including events tracked after the terminate flush. Request state (whether a page carried a flag bootstrap) lives on the request, not in a service, so nothing leaks into the next request. The flag document is kept on purpose: it is a process-wide cache.

**Messenger.** Sending happens in `kernel.terminate`, which is already after the response for PHP-FPM and FrankenPHP. If you would still rather send from a worker, hand the batches to Messenger:

```yaml
# config/packages/mirafive.yaml
mirafive:
    messenger: true                 # or a bus service id, e.g. messenger.bus.events

# config/packages/messenger.yaml
framework:
    messenger:
        routing:
            MiraFive\Symfony\Messenger\DeliverBatch: async
```

- Every buffered flush is handed to Messenger as a `MiraFive\Symfony\Messenger\DeliverBatch` carrying the body exactly as the PHP SDK encoded it (its `handOff` seam). The message carries no key.
- The worker delivers it with its own `Mira` (`deliverPrepared()`), byte for byte, so every retry reuses the batch id and MIRA FIVE stores a retried batch once. The worker's client retries briefly first; a failure that is still retryable (timeouts, `429`, `5xx`) is then thrown for your retry strategy, and refusals (`400`, `401`, `403`, `413`) are unrecoverable and go to the failure transport.
- Without a routing entry the message is handled synchronously, i.e. sent in `kernel.terminate` as without Messenger.
- `$mira->send()` is never queued: it sends at once and returns the collector's receipt. So does `bin/console mirafive:check`.
- Flag documents and segment lookups are always fetched directly.

## Flags

```php
use MiraFive\Flags\MiraFlags;
use Symfony\Component\HttpFoundation\Request;

public function checkout(MiraFlags $flags, Request $request): Response
{
    $id = $this->getUser()?->getId();
    $user = $flags->for(
        userId: $id === null ? null : (string) $id,
        properties: ['plan' => 'pro'],                            // facts your rules test; never sent
        consent: ['experiments' => true, 'targeting' => false],   // your banner's answer, when you have one
        optedOut: $request->headers->get('Sec-GPC') === '1' || $request->headers->get('DNT') === '1',
    );

    if ($user->enabled('new-checkout')) {
        // …
    }

    $limits = $user->config('limits', ['max' => 3]);
    $variant = $user->variant('pricing-test');
}
```

Reads never throw; without a document or for an unknown key they answer your fallback. The document is fetched from MIRA FIVE on first use and refreshed on read after `flags.refresh_seconds`. With PHP-FPM every request starts empty, so the bundle shares the document through `cache.app` by default; set `flags.cache` to another pool or to `null`. Server-counted experiments send one `$exposure` per person, experiment and variant through the same buffer. Consent, opt-out, reasons and error codes are described in the [PHP SDK README](https://github.com/mirafive/sdk-php#flags).

### Bootstrap in Twig

Hand the server's answers to the browser so the first paint shows the right variant:

```twig
<head>
    {{ mirafive_flags({userId: app.user ? app.user.id : null, properties: {plan: 'pro'}}) }}
    {{ mirafive_script({flags: true}) }}
</head>
```

`mirafive_flags()` takes `userId`, `anonymousId`, `properties`, `consent` and `optedOut` (read from `Sec-GPC`/`DNT` when left out), or a `UserFlags` you already built in the controller. It prints `<script type="application/json" id="mirafive-flags">…</script>` with every `<`, `>` and `&` escaped, and only flags your website reads.

A page with a bootstrap belongs to one person. The bundle therefore sets `Cache-Control: private, no-store` (`MiraFlags::BOOTSTRAP_HEADERS`) on the main response of any request that rendered one. For a `StreamedResponse`, where Twig renders after the headers are sent, set them yourself:

```php
foreach (MiraFlags::BOOTSTRAP_HEADERS as $name => $value) {
    $response->headers->set($name, $value);
}
```

## Testing

Switch the bundle to test mode in the test environment:

```yaml
# config/packages/mirafive.yaml
when@test:
    mirafive:
        test: true
```

In test mode nothing touches the network: batches are recorded, a secret key is not needed, Messenger and the flag cache are bypassed. `MiraFive\Symfony\Test\MiraFake` asserts on what was tracked, including events still in the buffer:

```php
use MiraFive\Symfony\Test\InteractsWithMira;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SignupTest extends WebTestCase
{
    use InteractsWithMira;

    public function test_signup_is_tracked(): void
    {
        $client = static::createClient();
        $client->request('POST', '/signup', ['plan' => 'pro']);

        self::mira()->assertTracked('signup', fn (array $event): bool => $event['properties']['plan'] === 'pro');
        self::mira()->assertIdentified('42');
        self::mira()->assertNotTracked('checkout');
    }
}
```

| `MiraFake` | |
|---|---|
| `assertTracked(name, ?where, ?times)` | `where` receives each wire event: `name`, `time`, `userId`, `anonymousId`, `properties`, `page` |
| `assertNotTracked(name, ?where)` | |
| `assertIdentified(userId, ?traits)` | a `$identify` for this user, with exactly these traits when given |
| `assertNothingTracked()` | |
| `events(?name)`, `batches()` | what was recorded, decoded |
| `serveFlags(document)` | the flag document `MiraFlags` fetches (FLAGS.md §3); call it before the first flag read |
| `clear()` | forget what was recorded |

Without a `WebTestCase`, get it from the container: `static::getContainer()->get(MiraFake::class)`.

## Troubleshooting

- **`bin/console mirafive:check` says disabled.** `MIRAFIVE_SECRET_KEY` is empty in this environment, or `mirafive.enabled` is false. `bin/console debug:container --env-vars` shows what Symfony sees.
- **`unauthorized` / `website_key_as_bearer`.** `MIRAFIVE_SECRET_KEY` holds a wrong key or the public website key. Server code needs the secret key of a *server* source.
- **Nothing arrives, no error.** Look at the `mirafive` log channel. Run `mirafive:check`. With `messenger` set, make sure a worker consumes the transport `DeliverBatch` is routed to.
- **Events from a long-running command arrive only at the end.** They are sent on `console.terminate`. Call `$mira->flush()` at checkpoints of a long import.
- **The Twig tag prints nothing.** `website_key` is empty or `mirafive.enabled` is false in this environment.
- **Flags always answer the fallback.** `$flags->status()` shows whether a document arrived; `$user->evaluate($key)->errorCode` says why: `NOT_READY` (no document yet, see the log), `FLAG_NOT_FOUND` (not served to this server source), `NOT_ALLOWED` (consent, or an experiment counted in the browser).
- **Pages with a bootstrap end up in a shared cache.** A reverse proxy that ignores `Cache-Control: private, no-store`, or a `StreamedResponse` (set the headers yourself).

## For AI agents

(a) A prompt for an agent adding MIRA FIVE to a Symfony application:

```text
Add MIRA FIVE analytics to this Symfony application with the Composer package mirafive/sdk-symfony.

1. Run `composer require mirafive/sdk-symfony` (PHP 8.3+, Symfony 6.4/7/8). With Flex the bundle registers
   itself; otherwise add `MiraFive\Symfony\MiraFiveBundle::class => ['all' => true]` to config/bundles.php.
2. Add `MIRAFIVE_SECRET_KEY=` and `MIRAFIVE_WEBSITE_KEY=` (empty values) to .env. Never commit real keys and never
   render MIRAFIVE_SECRET_KEY into a template or JavaScript. No config file is needed.
3. Add `when@test: { mirafive: { test: true } }` to config/packages/mirafive.yaml so tests never send.
4. Inject `MiraFive\Mira` where the few business events happen (signup, order completed) and call
   `$mira->track('signup', userId: (string) $user->getId(), properties: ['plan' => $plan]);`.
   Use the internal user id, never an e-mail address or getUserIdentifier() if that is an e-mail.
   No personal data in event names or properties.
5. Add a LoginSuccessEvent listener calling `$mira->identify((string) $user->getId(), [...traits]);`.
6. Put `{{ mirafive_script() }}` in the <head> of templates/base.html.twig. Keep the default consentless mode
   unless the project already has a consent banner; then set `script_mode: full` in mirafive.yaml and pass
   the banner's answer with `mirafive('consent', …)`.
7. Do not call flush(): the bundle sends after the response and after console commands.
8. Add a test using MiraFive\Symfony\Test\InteractsWithMira and `self::mira()->assertTracked('signup')`.
9. Verify with `bin/console mirafive:check` once a real key is set, and report what you changed.
Do not add other analytics libraries, cookies or consent banners.
```

(b) Facts for agents:

- Package `mirafive/sdk-symfony`, bundle `MiraFive\Symfony\MiraFiveBundle`, config key `mirafive`. Core: `mirafive/sdk-php`, namespace `MiraFive`.
- Autowired: `MiraFive\Mira` (`track`, `identify`, `send`, `flush`) and `MiraFive\Flags\MiraFlags` (`for(userId:, anonymousId:, properties:, consent:, optedOut:)` → `enabled`, `variant`, `config`, `evaluate`, `bootstrap`).
- Env vars: `MIRAFIVE_SECRET_KEY` (server source, server-side only), `MIRAFIVE_WEBSITE_KEY` (public), `MIRAFIVE_HOST` (optional, default `https://events.mirafive.io`).
- Without a secret key, or with `enabled: false`, server events and flags are a silent no-op; invalid input still throws `InvalidArgumentException`. `mirafive_script()` needs only the website key.
- Sent on `kernel.terminate` and `console.terminate`, and on `kernel.reset` in worker runtimes. Never call `flush()` in a controller.
- Config `mode` (server events, default `full`) and `script_mode` (tracker tag, default `consentless`) are separate.
- Twig: `mirafive_script(options)` (tracker tag, website key, mode from `script_mode`), `mirafive_flags(unit)` (flag bootstrap; sets `Cache-Control: private, no-store`).
- Delivery never throws; failures are logged on the `mirafive` channel. `send()` throws `MiraFive\MiraError`.
- Tests: `when@test: { mirafive: { test: true } }`, then `MiraFive\Symfony\Test\MiraFake` (`assertTracked`, `assertNotTracked`, `assertIdentified`, `assertNothingTracked`, `serveFlags`) via the `InteractsWithMira` trait.
- Verify: `bin/console mirafive:check` exits 0 and prints the reason `install_check`.
- Wire contract: [mirafive/protocol](https://github.com/mirafive/protocol).

## License

MIT, see [LICENSE](LICENSE). Copyright (c) 2026 Cloo GmbH.
