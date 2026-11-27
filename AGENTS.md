# mgamadeus/ddd-common-money -- Money Module

Currency and MoneyAmount value objects for the `mgamadeus/ddd` framework.

**Package:** `mgamadeus/ddd-common-money` (v1.0.0)
**Namespace:** `DDD\`
**Depends on:** `mgamadeus/ddd` (^2.10)

> **This module follows all DDD Core conventions.** For base patterns, see the skills in `vendor/mgamadeus/ddd`.

## Untrusted Input (mandatory)

No byte or string from outside (uploads, URLs and their responses, third-party API bodies, model output, a client's
claims about a file) is ever executed or interpreted as code. Use the helpers of `DDD\Infrastructure\Untrusted` in
`mgamadeus/ddd` (`RasterImageDecoder`, `GuardedHttpFetchService`, `SafeXml`, `ContainedPath`, `IsolatedProcess`,
`ArchiveInspector`, `UntrustedContentResponseDto`). Any other raw sink call needs an entry with its reason in
`config/untrusted-sinks-allowlist.php`; `tests/UntrustedSinkScanTest.php` enforces it. Rule, helpers and traps: Core
skill `ddd-untrusted-input-specialist`.

## Architecture

```
src/
+-- Domain/Common/Entities/Money/
|   +-- Currency.php            [ValueObject -- ISO 4217 currency with symbol]
|   +-- MoneyAmount.php         [ValueObject -- amount + currency composition]
|   +-- MoneyAmountInMicros.php [ValueObject -- exact int64-string micros + currency code]
+-- Modules/Money/
    +-- MoneyModule.php       [DDDModule entry point]
```

## Entities

### Currency (ValueObject)

48 supported currencies with ISO 4217 codes. Default: EUR.

**Key properties:** `isoCode` (validated via `#[Choice]`), `symbol`, `displaySymbolBeforeAmount`

**Factory:** `Currency::byIsoCode('USD')`

**Supported:** the single source of truth is `Currency::ALLOWED_CURRENCIES` (48 codes) — read it there instead of relying on any enumeration in docs. New codes are donated to this module first; consuming apps cannot widen the set on a subclass (a redeclared `#[Choice]` merges and can only narrow).

### MoneyAmount (ValueObject)

Monetary amount with currency. Supports equality comparison on both amount and currency.

**Constructor:** `new MoneyAmount(amount: 19.99, currency: 'USD')` or `new MoneyAmount(19.99, Currency::byIsoCode('USD'))`

**Method:** `setCurrencyByIsoCode(string)`, `isEqualTo(?DefaultObject)` (compares amount + currency)

### MoneyAmountInMicros (ValueObject)

Exact monetary amount in micros (one millionth of a currency unit) as a canonical signed decimal **int64 STRING**
(`?string $micros`, never int or float) plus a bare ISO 4217 `?string $currencyCode` validated against
`Currency::ALLOWED_CURRENCIES`. **Not a `MoneyAmount` subtype** — extends `ValueObject` directly; no arithmetic, no
float bridge, no conversion to/from `MoneyAmount`, no embedded `Currency` object (its constructor silently replaces
unknown codes; a value bound for an external money API must reject them instead).

Why a string: a JSON number above 2^53 loses integer precision in JavaScript, and the framework hydrator casts JSON
numbers into int/float properties. The string is the only scalar that travels exactly through API, JSON column, and
external SDKs (Google Ads int64 micros).

**Factory:** `MoneyAmountInMicros::fromMicros('1500000', 'EUR')` — validates and throws `BadRequestException`
(with `validationErrors`) on malformed micros, out-of-int64-range values, or unknown currency codes; builds via
`static::newInstance()` so an app-namespace same-named class substitutes it (deliberate override point).

**Guards:** strict string-only hydration of `micros` (non-string input throws, or clears to null when lenient);
`Regex` canonical-grammar check; `#[Callback]` int64 range check via pure string comparison (exact above
PHP_INT_MAX).

**Helpers:** `isZero()`, `isNegative()`, `isPositive()`; `isEqualTo()` compares micros + currencyCode exactly and is
symmetric across base and subclasses. Thin subclasses (e.g. an app's Ads money class) inherit all constraints via
Symfony constraint merging and MUST NOT redeclare `currencyCode` with an own `#[Choice]` (constraints merge and can
only narrow).

## Usage

```php
use DDD\Domain\Common\Entities\Money\Currency;
use DDD\Domain\Common\Entities\Money\MoneyAmount;

// As entity property
public ?MoneyAmount $price = null;

// Create
$price = new MoneyAmount(29.99, 'EUR');
$price = new MoneyAmount(0.0, Currency::byIsoCode('USD'));

// Compare
$price1->isEqualTo($price2);  // true if same amount AND currency
```

## Coding Conventions

All DDD Core conventions apply. See `vendor/mgamadeus/ddd/AGENTS.md`.

## Contributing: versions and commit messages

- **A pull request never sets a version.** Leave `version` in `composer.json` and in `package.json` as it is, and add
  no version heading to `CHANGELOG.md`. Write the changelog entry under `## Unreleased` at the top of the changelog
  (add the heading when it is missing).
- **The maintainer assigns the version at release:** bumps `composer.json` / `package.json`, renames `## Unreleased` to
  the new version and tags it. Versions are regular only (no pre-release suffixes).
- **Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/):** `feat: …`, `fix: …`,
  `docs: …`, `refactor: …`, `test: …`, `chore: …`, optionally with a scope (`fix(chat): …`); a breaking change carries
  `!` after the type or a `BREAKING CHANGE:` footer.
- **Merging a pull request that still carries a version:** keep the version fields of `main`, move its changelog entry
  under `## Unreleased`, then release as usual.
- **Rebase on the current `main` before the merge,** so that only real code conflicts remain.
- **A breaking change is named.** Its changelog entry says what an application must change. The release makes it a
  major version (a package at 1.0 or above) or a minor version (a package still at 0.x).
- **No application terms in a package:** no application's name, and no logic that only one application has (its own
  posts, its own media screens, its own country rules). A package offers hooks; the application individualises
  through them. Review checks this.
- **Tags are never moved or deleted.** A mistake in a release is fixed by a new patch version.
