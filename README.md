# MIRA FIVE for Symfony

[MIRA FIVE](https://mirafive.io) for Symfony 7.4 LTS and 8: revenue attribution and analytics by
Cloo GmbH, compliant with GDPR and TDDDG. One bundle for both sides of your app:

- **Server events:** inject `MiraFive\Mira` for events, revenue and touchpoints only your server
  knows. They are sent after the response (`kernel.terminate`), or handed to Messenger.
- **The browser SDK:** `{{ mirafive_script() }}` prints the script tag with your public key and the
  consent mode you choose.
- **Tests:** `mirafive.test: true` records instead of sending, with assertions.

It builds on [`mirafive/sdk-php`](https://github.com/mirafive/sdk-php); everything documented
there works here too. PHP 8.3 or newer.

## Install

```sh
composer require mirafive/sdk-symfony
```

Register the bundle in `config/bundles.php` if Flex did not:

```php
return [
    // …
    MiraFive\Symfony\MiraFiveBundle::class => ['all' => true],
];
```

Add the project's keys (MIRA FIVE dashboard, Settings, Sources) to `.env.local` or your secrets:

```dotenv
MIRAFIVE_SECRET_KEY=mira_sk_…
MIRAFIVE_PUBLIC_KEY=mira_pk_…
```

Then check them: the ingest answers whether the key works, and stores nothing.

```sh
bin/console mirafive:check
```

No configuration file is needed; the bundle reads the variables above.

## Quickstart

**1. The browser SDK.** In `templates/base.html.twig`, inside `<head>`:

```twig
{{ mirafive_script() }}
```

It follows your consent banner (CMP) by default and tracks page views, engagement, outbound
clicks, downloads and form submits on its own.

**2. Who the user is.** For example after login:

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

        $this->mira->identify(
            userId: $user->getUserIdentifier(),
            traits: ['plan' => $user->getPlan()],
            externalIds: ['stripe_customer' => $user->getStripeCustomerId()], // links Stripe's money to this user
        );
    }
}
```

**3. What only your server knows.** Inject `MiraFive\Mira` anywhere:

```php
use MiraFive\Mira;

final class InvoicePaidHandler
{
    public function __construct(private readonly Mira $mira) {}

    public function __invoke(Invoice $invoice): void
    {
        // Money, in integer minor units: 4900 is 49.00 EUR.
        $this->mira->revenue(
            amount: $invoice->totalCents(),
            currency: $invoice->currency(),
            userId: $invoice->userId(),
            entryId: 'stripe:'.$invoice->stripeId(),                     // the Stripe connector's copy counts once
            eventId: Mira::eventIdFor('invoice:'.$invoice->id().':paid'), // a retried message counts once
        );
    }
}
```

Events wait in memory and leave after the response was sent, after a console command, after each
Messenger message, and on `kernel.reset` in worker runtimes (FrankenPHP, RoadRunner).

## Configuration

Every option has a default; create `config/packages/mirafive.yaml` only to change one:

```yaml
mirafive:
  enabled: true # false: nothing leaves the app, mirafive_script() prints nothing
  secret_key: "%env(default::MIRAFIVE_SECRET_KEY)%"
  public_key: "%env(default::MIRAFIVE_PUBLIC_KEY)%"
  host: "%env(default::MIRAFIVE_HOST)%" # empty: https://events.mirafive.io
  messenger: null # true (messenger.default_bus) or a bus id: send batches through Messenger
  cache: cache.app # a PSR-6 or PSR-16 pool that shares the delivery breaker; null for none
  script:
    src: null # default: the MIRA FIVE CDN, or {host}/sdk/mira.js for another host
    consent: auto # auto, granted, denied, analytics
    autocapture: true # false, or parts to turn off: { forms: false, outbound: false }
  timeout_ms: 3000
  flush_deadline_ms: 3000
  max_retries: 2
  test: false # record instead of sending (see Testing)
```

Without a secret key nothing is sent and nothing is logged, which keeps local development quiet;
`bin/console mirafive:check` tells you why.

## API

Inject `MiraFive\Mira`. All methods take named arguments and never throw: problems are logged as
warnings on the `mirafive` Monolog channel.

| Method                                                                                        | What it sends                                                                                                                                       |
| --------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| `track(event, userId, anonymousId, properties, …)`                                            | A custom event. Names starting with `$` are reserved                                                                                                |
| `identify(userId, traits, externalIds, …)`                                                    | Who the user is. PII traits are encrypted at rest. `externalIds`: `stripe_customer`, `revenuecat_app_user`, `shopify_customer`, `shopware_customer` |
| `group(groupId, userId, traits, …)`                                                           | The company or account a user belongs to                                                                                                            |
| `revenue(amount, currency, entryId, entryKind, fee, tax, refundOf, product, subscription, …)` | One revenue ledger entry, in integer minor units                                                                                                    |
| `touchpoint(kind, userId, channel, source, medium, campaign, answer, code, …)`                | A touchpoint that is not a visit: a survey answer, an offline contact, a code                                                                       |
| `with(userId, anonymousId, consent, context)`                                                 | A client with these defaults; the call's own values win                                                                                             |
| `Mira::eventIdFor('order:123:paid')`                                                          | A stable event id from your own key, identical in every MIRA FIVE SDK                                                                               |
| `flush()`                                                                                     | Sends now                                                                                                                                           |

Every event method also takes `eventId`, `timestamp` (a date, epoch milliseconds or ISO string;
past dates are fine for backfills), `consent` and `context` (`ip`, `userAgent`, `page`, `app`,
`locale`, `timezone`, `location`). The [sdk-php README](https://github.com/mirafive/sdk-php#api-reference)
documents every argument.

To send the request's IP (for geo, never stored) and user agent with every event:

```php
$mira = $this->mira->with(context: [
    'ip' => $request->getClientIp(),
    'userAgent' => $request->headers->get('User-Agent'),
]);
$mira->track(event: 'report_exported', userId: $user->getUserIdentifier());
```

### Revenue

```php
// A subscription payment (the first one and every renewal), with store commission and VAT.
$this->mira->revenue(
    amount: 999,
    currency: 'EUR',
    userId: 'user_42',
    entryId: 'revenuecat:txn_1000000123',
    fee: 150,
    tax: 160,
    product: 'premium_monthly',
    subscription: ['id' => 'sub_42', 'interval' => 'month'], // or intervalCount, periodStart, periodEnd
);

// A refund takes the money back, and the credit of the channels that earned it.
$this->mira->revenue(
    amount: -999,
    currency: 'EUR',
    userId: 'user_42',
    entryKind: 'refund',
    entryId: 'revenuecat:txn_1000000123:refund',
    refundOf: 'revenuecat:txn_1000000123',
);
```

`entryKind` is `charge` (the default), `subscription_payment` (the default with `subscription`),
`refund` (the default for a negative amount), `dispute` or `dispute_won`. Proceeds are the amount
minus `fee` and `tax`. `19.99` is refused: send `1999`.

### Touchpoints

```php
// "How did you hear about us?" on your sign-up form.
$this->mira->touchpoint(
    kind: 'survey',
    userId: $user->getUserIdentifier(),
    source: $form->get('heardFrom')->getData(),     // podcast, instagram, a friend, …
    answer: $form->get('heardFromText')->getData(), // passes MIRA's PII guard
    eventId: Mira::eventIdFor('survey:'.$user->getUserIdentifier()),
);

// A trade fair contact from your CRM.
$this->mira->touchpoint(kind: 'offline', userId: 'user_42', channel: 'referral', source: 'trade_fair_2026', externalRef: 'hubspot:123');
```

A touchpoint needs a user or anonymous id and a `channel` or a `source`. Kinds: `survey`,
`offline`, `code`, `custom`.

## Consent tiers

Server events default to `not_applicable`: a paid invoice is a fact of your business. For events
that describe what a visitor did, pass what your consent banner recorded:

```php
$this->mira->track(event: 'search', userId: $id, consent: ['analytics' => true, 'marketing' => false]);
// or MiraFive\Consent::granted(), Consent::analyticsOnly(), Consent::denied()
```

| `consent` on the event                           | Tier    | What MIRA keeps                                                                               |
| ------------------------------------------------ | ------- | --------------------------------------------------------------------------------------------- |
| `not_applicable` (default), no `userId`          | T1      | The event, `anonymous_id` if given, geo from `context.ip` (the IP is discarded)               |
| `not_applicable` with `userId`                   | T2      | Plus `user_id`, traits (PII traits encrypted at rest), revenue per person                     |
| `{ analytics: 'granted' }`                       | T1 / T2 | As above                                                                                      |
| `{ analytics: 'granted', marketing: 'granted' }` | T3      | Plus click ids, and later forwarding to ad platforms                                          |
| `{ analytics: 'denied' \| 'unknown' }`           | T0      | No `user_id`, `anonymous_id` or `session_id`: the event counts, but is not linked to a person |

The browser SDK printed by `mirafive_script()` follows your consent banner itself (`consent: auto`).

## The script tag

```twig
{{ mirafive_script() }}
{{ mirafive_script({ consent: 'analytics', autocapture: { forms: false }, nonce: csp_nonce('script') }) }}
```

| Option        | Default                    | Meaning                                                                                                 |
| ------------- | -------------------------- | ------------------------------------------------------------------------------------------------------- |
| `consent`     | `script.consent`, `auto`   | `auto` follows the CMP; `granted`, `denied`, or `analytics` (analytics yes, marketing no)               |
| `autocapture` | `script.autocapture`, true | `false` turns off all automatic events; or parts: `spa`, `engagement`, `outbound`, `downloads`, `forms` |
| `src`, `host` | from the config            | Where the SDK loads from and where it sends                                                             |
| `debug`       | `false`                    | Logs what the SDK does to the browser console                                                           |
| `nonce`       | (none)                     | A Content Security Policy nonce for both tags                                                           |

It prints the tag the MIRA FIVE dashboard shows: a stub that queues `mira(…)` calls made before the
SDK has loaded, then the SDK. Nothing is printed with `enabled: false` or without a public key. A
secret key is never printed: the tag becomes an HTML comment and the problem is logged.

## Messenger and worker runtimes

To keep delivery off your web servers, send batches through Messenger:

```yaml
# config/packages/mirafive.yaml
mirafive:
  messenger: true # or the id of another bus

# config/packages/messenger.yaml
framework:
  messenger:
    transports:
      mirafive: "%env(MESSENGER_TRANSPORT_DSN)%"
    routing:
      MiraFive\Symfony\Messenger\DeliverBatch: mirafive
```

Each batch becomes a `DeliverBatch` message. It carries no key: the worker sends with its own
`MIRAFIVE_SECRET_KEY`, so set it on workers too. A failed delivery is thrown for Messenger's retry
strategy with the same events and sequence number, so a message handled twice is counted once. A
batch the ingest refuses for good, or a worker without a key, is unrecoverable and goes to the
failure transport.

In worker runtimes (FrankenPHP, RoadRunner, Swoole), the client flushes on `kernel.terminate` and
on `kernel.reset` between requests.

## Testing

```yaml
# config/packages/mirafive.yaml
when@test:
  mirafive:
    test: true
```

```php
use MiraFive\Symfony\Test\InteractsWithMira;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CheckoutTest extends WebTestCase
{
    use InteractsWithMira;

    public function testCheckoutRecordsRevenue(): void
    {
        $client = static::createClient();
        $client->request('POST', '/checkout', ['plan' => 'pro']);

        static::mira()->assertTracked('checkout_completed');
        static::mira()->assertRevenue(fn (array $event) => $event['revenue']['amount'] === 4900);
        static::mira()->assertNoErrors();
    }
}
```

With `test: true` the client records instead of sending (no key needed). Input is checked exactly as
in production, so an event the ingest would refuse fails the test. `static::mira()` is a
`MiraFive\Testing\MiraFake`: `assertTracked(name, callback|times)`, `assertNotTracked`,
`assertIdentified`, `assertGrouped`, `assertRevenue`, `assertTouchpoint`, `assertNothingTracked`,
`assertNoErrors`; plus `events(?name)`, `errors()` and `clear()`. Callbacks get the wire event with
snake_case keys (`user_id`, `properties`, `revenue`, `traits`, `external_ids`).

## Troubleshooting

Start with `bin/console mirafive:check`: it shows the host, the keys and the delivery mode, and
asks the ingest whether the key works; nothing is stored or counted.

- **Nothing arrives, nothing is logged.** `MIRAFIVE_SECRET_KEY` is not set for this environment
  (check `bin/console debug:container --env-vars`).
- **`batch dropped: HTTP 401 unknown_key`.** The key is wrong, revoked or from another host.
- **`MIRAFIVE_SECRET_KEY holds a public key`.** Server code needs `mira_sk_…`; `mira_pk_…` is for
  the script tag.
- **`mirafive_script()` prints an HTML comment.** `MIRAFIVE_PUBLIC_KEY` holds a secret key; the log
  says so.
- **Messages pile up.** Run `bin/console messenger:consume mirafive` with `MIRAFIVE_SECRET_KEY` set.
- **`revenue: amount must be an integer in minor units`.** Send cents: `1999`, not `19.99`.

## Development

```sh
composer install
composer check   # pint --test, phpstan (max, strict, Symfony extension), pest
```

## License

MIT, Cloo GmbH. See [LICENSE](LICENSE).
