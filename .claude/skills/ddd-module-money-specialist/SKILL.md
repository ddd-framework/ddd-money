---
name: ddd-module-money-specialist
description: Work with the Currency, MoneyAmount, and MoneyAmountInMicros value objects from the ddd-common-money module. Currency carries 48 ISO 4217 codes (single source of truth Currency::ALLOWED_CURRENCIES; unknown codes fall back to EUR). MoneyAmount is a float amount + Currency composition with value equality. MoneyAmountInMicros is the EXACT money value — a canonical signed decimal int64 STRING of micros plus a bare validated currency code, wire-safe beyond 2^53 (Google Ads int64 micros, JSON columns, JS clients); factory fromMicros(), strict string-only hydration, int64 range check, sign helpers, symmetric exact equality, thin-subclass friendly (constraints inherit; never redeclare currencyCode Choice). Use when handling monetary values, currency codes, or price properties in entities, when an external API needs exact int64/micros amounts or rejects unknown currencies, when a money value must survive JSON without float precision loss, or when adding a new currency code (donate to the module first).
metadata:
  author: mgamadeus
  version: "1.1.0"
  module: mgamadeus/ddd-common-money
---

# Money Module Specialist

Currency and MoneyAmount value objects within the `mgamadeus/ddd-common-money` module.

> **Base patterns:** See core skills in `vendor/mgamadeus/ddd` for entity/service conventions.

## When to Use

- Adding price/cost/amount properties to entities
- Working with multi-currency monetary values
- Comparing monetary amounts

## Currency (ValueObject)

48 ISO 4217 currencies (single source of truth: `Currency::ALLOWED_CURRENCIES` — don't trust doc enumerations). Default: `EUR`. Invalid codes fall back to EUR (see `MoneyAmountInMicros` below when that fallback is unacceptable).

```php
use DDD\Domain\Common\Entities\Money\Currency;

$eur = Currency::byIsoCode('EUR');
$usd = Currency::byIsoCode('USD');
$invalid = Currency::byIsoCode('XYZ');  // Falls back to EUR

// Properties
$eur->isoCode;                    // 'EUR'
$eur->symbol;                     // null (set by consuming app)
$eur->displaySymbolBeforeAmount;  // false
```

**Supported codes:** ARS, AUD, BRL, CAD, CHF, CLP, COP, CZK, EUR, GBP, HUF, IDR, INR, JPY, MXN, MYR, NOK, PEN, PHP, PLN, RUB, SEK, SGD, USD, VEF, ZAR

**Constants:** `Currency::EUR`, `Currency::USD`, etc. + `Currency::DEFAULT_ISO_CODE` (EUR) + `Currency::ALLOWED_CURRENCIES` (array)

## MoneyAmount (ValueObject)

Amount + currency composition with value equality.

```php
use DDD\Domain\Common\Entities\Money\MoneyAmount;

// Construction
$price = new MoneyAmount(29.99, 'EUR');
$price = new MoneyAmount(0.0, Currency::byIsoCode('USD'));
$price = new MoneyAmount();  // 0.0 EUR

// Properties
$price->amount;     // float
$price->currency;   // Currency object

// Update currency
$price->setCurrencyByIsoCode('USD');

// Equality (checks BOTH amount and currency)
$a = new MoneyAmount(10.0, 'EUR');
$b = new MoneyAmount(10.0, 'USD');
$a->isEqualTo($b);  // false (different currency)
```

## Entity Property Pattern

```php
use DDD\Domain\Common\Entities\Money\MoneyAmount;

class Product extends Entity
{
    public ?MoneyAmount $price = null;
    public ?MoneyAmount $costPerUnit = null;
}
```

MoneyAmount is a ValueObject -- stored as JSON in the database automatically.

## MoneyAmountInMicros (ValueObject) — exact int64 money

For amounts that must be EXACT beyond float/2^53 precision (external money APIs like Google Ads int64 micros). NOT a
`MoneyAmount` subtype — extends `ValueObject` directly; no arithmetic, no float bridge, no `Currency` object (its
constructor silently replaces unknown codes; this class rejects them instead).

```php
use DDD\Domain\Common\Entities\Money\MoneyAmountInMicros;

// Factory — validates, throws BadRequestException (validationErrors populated) on bad input
$amount = MoneyAmountInMicros::fromMicros('1500000', 'EUR');   // 1.50 EUR

// Properties: canonical signed decimal int64 STRING + bare ISO code
$amount->micros;        // '1500000'  (never int/float — wire-safe beyond 2^53)
$amount->currencyCode;  // 'EUR'      (validated against Currency::ALLOWED_CURRENCIES)

// Sign helpers + exact, subclass-symmetric equality
$amount->isZero(); $amount->isNegative(); $amount->isPositive();
$amount->isEqualTo($other);  // micros + currencyCode, exact
```

Guards: strict string-only hydration of `micros` (a JSON number throws, or clears to null in lenient mode — PHP's
float-to-string keeps only 14 significant digits, so casting would silently pass validation with a wrong value);
canonical-grammar `Regex`; int64 range check via pure string comparison.

Thin app subclasses (e.g. an Ads money class keeping its serialized `objectType`) inherit ALL constraints via
Symfony constraint merging — do NOT redeclare `currencyCode` with an own `Choice` (constraints merge and can only
narrow). `fromMicros()` builds via `static::newInstance()`, so an app-namespace same-named class substitutes the
base factory's return (deliberate override point).

## Used By

- `ddd-ai` module: `AILanguageModelSetting` stores token costs as MoneyAmount
- `ddd-common-translations`: `AppTranslationsResult` stores estimated AI costs
