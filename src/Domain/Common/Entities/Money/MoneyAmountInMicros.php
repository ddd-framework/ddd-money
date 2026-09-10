<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Money;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Reflection\ReflectionProperty;
use DDD\Infrastructure\Validation\Constraints\Choice;
use DDD\Infrastructure\Validation\ValidationErrors;
use JsonException;
use ReflectionException;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * An exact monetary amount in micros (one millionth of a currency unit) together with its ISO 4217 currency code.
 *
 * `micros` is a canonical signed decimal int64 STRING, never a PHP int or float. The value must survive JSON without
 * loss: a JSON number above 2^53 loses integer precision in JavaScript, and the framework hydrator casts a JSON number
 * into an int or float property. A string is the only scalar that travels exactly through the API, a JSON column and
 * an external SDK that accepts int64 as a decimal string.
 *
 * This is a separate value object from {@see MoneyAmount}, not a subtype of it. `MoneyAmount` holds a float amount and
 * an embedded {@see Currency} object; this class holds an exact integer string and a bare currency code validated
 * against `Currency::ALLOWED_CURRENCIES`. The code is not resolved into a `Currency` object because
 * `Currency::__construct()` replaces an unknown code with the default currency, whereas a value bound for an external
 * money API must reject it. No arithmetic and no conversion to or from `MoneyAmount` is provided.
 *
 * The sign is unconstrained: a general money value may be negative (adjustments, deltas). A consumer that needs a
 * positive amount enforces that on its own entity.
 *
 * Currency catalog: the accepted codes are owned by this module's `Currency::ALLOWED_CURRENCIES`. A consuming
 * application adds codes by donating them upstream; a redeclared `#[Choice]` on a subclass would be merged with the
 * constraint declared here and could only narrow the set, never widen it.
 *
 * Factory substitution: `fromMicros()` builds via `static::newInstance()`, which resolves through
 * `DDDService::getContainerServiceClassNameForClass()`. A consuming application that declares a same-named class in
 * its own root namespace (`App\Domain\Common\Entities\Money\MoneyAmountInMicros`) thereby substitutes the class every
 * base factory call returns. That is a deliberate framework override point, not an incidental extension slot.
 */
class MoneyAmountInMicros extends ValueObject
{
    /**
     * Canonical decimal int64 grammar: optional minus sign, no leading zeros, no plus sign, no negative zero,
     * no decimal point, exponent or whitespace, at most 19 digits. The int64 bound itself is checked separately.
     */
    public const string MICROS_PATTERN = '/^(0|-?[1-9][0-9]{0,18})$/D';

    public const string MICROS_INT64_MAX = '9223372036854775807';

    public const string MICROS_INT64_MIN = '-9223372036854775808';

    /** @var string|null Amount in micros as a canonical signed decimal int64 string, e.g. "1500000" for 1.50 */
    #[NotNull]
    #[NotBlank]
    #[Regex(pattern: self::MICROS_PATTERN)]
    public ?string $micros = null;

    /** @var string|null ISO 4217 currency code, validated against the currency catalog (`Currency::ALLOWED_CURRENCIES`) */
    #[NotNull]
    #[Choice(choices: Currency::ALLOWED_CURRENCIES)]
    public ?string $currencyCode = null;

    /**
     * Builds a validated money value from its canonical wire representation.
     *
     * @throws BadRequestException When micros or currency code are malformed; `validationErrors` carries the details.
     * @throws JsonException
     * @throws ReflectionException
     */
    public static function fromMicros(string $micros, string $currencyCode): static
    {
        $moneyAmountInMicros = static::newInstance();
        $moneyAmountInMicros->micros = $micros;
        $moneyAmountInMicros->currencyCode = $currencyCode;
        $validationResults = $moneyAmountInMicros->validate();
        if ($validationResults !== true) {
            $badRequestException = new BadRequestException('Money amount in micros contains invalid data');
            $badRequestException->validationErrors = $validationResults instanceof ValidationErrors
                ? $validationResults
                : new ValidationErrors();
            throw $badRequestException;
        }
        return $moneyAmountInMicros;
    }

    /**
     * Raw micros input must already be a string. The inherited hydration casts any scalar to string, and PHP's
     * float-to-string conversion keeps only 14 significant digits, so a JSON number like 12345678901234.1 would
     * silently become the canonical, validation-passing "12345678901234". Integers and booleans are refused for the
     * same contract reason: the wire representation is a decimal string, and a JSON integer above 2^53 has already
     * lost precision in JavaScript. Null keeps the inherited handling and is reported by validation afterwards.
     * Without $throwErrors the refused value clears micros, so a previously populated object cannot keep a stale,
     * still-valid amount next to the newly hydrated currency; validation then reports micros.
     * Everything else, including the currency code, is delegated to the framework unchanged.
     *
     * @throws BadRequestException When micros arrives as a non-string and $throwErrors is set.
     * @throws InternalErrorException
     * @throws ReflectionException
     */
    public function setPropertyFromObject(
        \ReflectionProperty|ReflectionProperty &$property,
        mixed &$value,
        $throwErrors = true,
        ?ReflectionClass $reflectionClass = null,
        bool $sanitizeInput = false
    ): void {
        if ($property->getName() === 'micros' && $value !== null && !is_string($value)) {
            if ($throwErrors) {
                throw new BadRequestException(
                    'Property ' . static::class . '->micros must be a decimal string, ' . get_debug_type($value)
                    . ' provided'
                );
            }
            $this->micros = null;
            return;
        }
        parent::setPropertyFromObject($property, $value, $throwErrors, $reflectionClass, $sanitizeInput);
    }

    /**
     * Rejects canonical micros strings outside the signed 64-bit integer range. Malformed strings are left to the
     * `Regex` constraint so each defect is reported once.
     */
    #[Callback]
    public function validateMicrosWithinInt64Range(ExecutionContextInterface $executionContext): void
    {
        if (!$this->hasCanonicalMicros()) {
            return;
        }
        if (!self::isCanonicalMicrosWithinInt64Range($this->micros)) {
            $executionContext->buildViolation('This value is outside the signed 64-bit integer range.')
                ->atPath('micros')
                ->setInvalidValue($this->micros)
                ->addViolation();
        }
    }

    public function isZero(): bool
    {
        return $this->micros === '0';
    }

    public function isNegative(): bool
    {
        return $this->hasCanonicalMicros() && str_starts_with($this->micros, '-');
    }

    public function isPositive(): bool
    {
        return $this->hasCanonicalMicros() && !$this->isZero() && !$this->isNegative();
    }

    /**
     * Exact comparison of micros and currency code. Any instance of this class or a subclass compares, so a base
     * instance and a subclass instance holding the same fields are equal in both directions.
     *
     * @param MoneyAmountInMicros|null $other
     */
    public function isEqualTo(?DefaultObject $other = null): bool
    {
        if (!($other instanceof MoneyAmountInMicros)) {
            return false;
        }
        return $this->micros === $other->micros && $this->currencyCode === $other->currencyCode;
    }

    protected function hasCanonicalMicros(): bool
    {
        return $this->micros !== null && preg_match(self::MICROS_PATTERN, $this->micros) === 1;
    }

    /**
     * Digit-wise comparison against the int64 bounds; the input must already match `MICROS_PATTERN`.
     * Pure string arithmetic, no int or float conversion, so values above PHP_INT_MAX compare exactly.
     */
    protected static function isCanonicalMicrosWithinInt64Range(string $canonicalMicros): bool
    {
        $isNegative = str_starts_with($canonicalMicros, '-');
        $digits = $isNegative ? substr($canonicalMicros, 1) : $canonicalMicros;
        $boundDigits = $isNegative ? substr(self::MICROS_INT64_MIN, 1) : self::MICROS_INT64_MAX;
        if (strlen($digits) !== strlen($boundDigits)) {
            return strlen($digits) < strlen($boundDigits);
        }
        return strcmp($digits, $boundDigits) <= 0;
    }
}
