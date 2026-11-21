# AGENTS.md

Guidance for every contributor, human or agent, working in this repository.

Zeny for WooCommerce is a payment gateway plugin. At checkout it creates one
Zeny payment link per order and sends the shopper to the link's pay page, where
they choose QRIS, a virtual account or an e-wallet. The order is marked paid
only from a verified `payment.paid` webhook (or a payment id an admin confirms
against the API). The plugin never handles a payment method itself.

## Stack

- PHP 7.4 to 8.3, WordPress 6.3+, WooCommerce 8.0+, HPOS and block checkout.
- **Zero runtime Composer dependencies.** HTTP through `wp_remote_request`,
  HMAC through `hash_hmac` + `hash_equals`, classes through `src/Autoloader.php`.
  Composer is for dev tools only.
- No local PHP is needed: every tool runs in Docker through `bin/`.

## Commands

| Command                    | What it does                                                     |
| -------------------------- | ---------------------------------------------------------------- |
| `bin/composer install`     | Install dev tools into `vendor/`                                 |
| `bin/lint`                 | PHPCS (WordPress Coding Standards) and PHPStan level 6           |
| `bin/lint fix`             | Run phpcbf first, then the checks                                |
| `bin/test unit [7.4\|8.3]` | Unit tests (no WordPress) on PHP 7.4 and 8.3, or one of them     |
| `bin/test integration`     | WordPress + WooCommerce + a stub Zeny API, on the posts and HPOS order stores |
| `bin/test`                 | Every suite                                                      |
| `bin/build`                | `build/zeny-woocommerce-<version>.zip`, checked for its contents |
| `bin/i18n`                 | Rebuild `languages/zeny.pot`, merge it into the `.po`, compile `.mo` |

Before every push: `bin/lint && bin/test && bin/build`.

## Architecture

Light Clean Architecture. Dependencies point inward only.

```
zeny-woocommerce.php   header, autoloader, HPOS + block checkout declarations
src/Domain/            pure rules: keys, money, link body, webhook verification, payment checks
src/Infrastructure/    WordPress HTTP transport, Zeny API client with retries, logger
src/WooCommerce/       gateway, settings, checkout, webhook route, order completion, admin
assets/js/             block checkout registration, thank-you status check, admin button
languages/             zeny.pot and the Indonesian translation
```

`src/Domain` references nothing from WordPress or WooCommerce;
`tests/Unit/ArchitectureTest.php` fails the build when it does.

## Rules

- **Never mark an order paid without a verified signal**: a webhook whose
  signature checks out, or a payment read from the API. The payment must name
  one of the order's links, carry the order total in IDR, be `paid` and match
  the key's mode. Anything else puts the order on hold with a note.
- **No money moves twice, and no link is lost.** Every POST carries an
  `Idempotency-Key` that is reused on every retry. The checkout key is
  `wc-<site hash>-<order id>-<attempt>`. The attempt moves on only when a
  create was definitely refused, or the current link expired, belongs to the
  other environment, or asks for an old total (it is canceled first). A failed
  read never drops a link, and an order keeps every link id it ever had
  (`_zeny_payment_link` rows) so a late payment through any of them finds it.
  A stored create is replayed only for the same amount and within 23 hours.
- Retry only network errors, timeouts, 429, 5xx and `409 idempotency_key_in_progress`,
  at most twice, with jittered backoff and `Retry-After` capped at 60 s.
  Checkout is stricter (`ZenyApi::withCheckoutLimits()`): one retry,
  `Retry-After` capped at 5 s, about 20 s in all, because it holds the order lock.
- The order lock (`OrderLock`) stores an owner token and is stale after 60 s,
  above the longest checkout; only its owner releases it.
- The webhook answers `200` only after the order is saved, `200` with
  `ignored: unknown_link` for a link no order holds (the shopper only gets a
  URL after the order stores the link), and `500` when it cannot act yet (so
  Zeny retries).
- An unpaid Zeny order is kept from the hold-stock cancellation while its
  link is open (`UnpaidOrderGuard`).
- **Secrets are never logged or echoed.** The API key and webhook secret are
  masked in `__toString`/`__debugInfo` and never rendered back into settings HTML.
- Orders through `wc_get_order` and the order meta API only, never post meta.
- Never invent an API field: the contract is the Merchant API v1 OpenAPI
  document published with the Node SDK (`@usezeny/node`, `openapi/merchant.v1.json`).
- Admin copy is plain and translatable (text domain `zeny`). After changing
  strings run `bin/i18n`, translate the new entries in `zeny-id_ID.po`, and
  run `bin/i18n` again to compile the `.mo`.

## Releases

Bump the version in the plugin header, `ZENY_WC_VERSION`, `readme.txt`
(`Stable tag`) and `CHANGELOG.md` (`bin/build` fails when they differ), merge
to `main`, then push a tag `vX.Y.Z` on that commit. The Release workflow
builds the zip and attaches it to a GitHub release.

## Code style

WordPress Coding Standards (tabs, Yoda conditions, escaped output) with PSR-4
class files and camelCase own methods. Small final classes, PHPDoc on every
public symbol, comments that explain why. New behaviour ships with a test.

## Commits

Conventional Commits (`feat:`, `fix:`, `test:`, `docs:`, `ci:`, `chore:`),
no trailers.
