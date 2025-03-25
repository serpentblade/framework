<?php

namespace Illuminate\Database\Eloquent\Concerns;

use BackedEnum;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException as BrickMathException;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEncryptedCollection;
use Illuminate\Database\Eloquent\Casts\AsEnumArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Casts\Casters\Caster;
use Illuminate\Database\Eloquent\Casts\ClosureCast;
use Illuminate\Database\Eloquent\Casts\Json;
use Illuminate\Database\Eloquent\InvalidCastException;
use Illuminate\Database\Eloquent\JsonEncodingException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Exceptions\MathException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

trait HasCasts
{
    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [];

    /**
     * The attributes that have been cast using custom classes.
     *
     * @var array
     */
    protected $classCastCache = [];

    /**
     * The attributes that have been cast using "Attribute" return type mutators.
     *
     * @var array
     */
    protected $attributeCastCache = [];

    /**
     * The built-in, primitive cast types supported by Eloquent.
     *
     * @var string[]
     */
    protected static $primitiveCastTypes = [
        'array',
        'bool',
        'boolean',
        'collection',
        'custom_datetime',
        'date',
        'datetime',
        'decimal',
        'double',
        'encrypted',
        'encrypted:array',
        'encrypted:collection',
        'encrypted:json',
        'encrypted:object',
        'float',
        'hashed',
        'immutable_date',
        'immutable_datetime',
        'immutable_custom_datetime',
        'int',
        'integer',
        'json',
        'json:unicode',
        'object',
        'real',
        'string',
        'timestamp',
    ];

    /**
     * The storage format of the model's date columns.
     *
     * @var string|null
     */
    protected $dateFormat;

    /**
     * Indicates whether attributes are snake cased on arrays.
     *
     * @var bool
     */
    public static $snakeAttributes = true;

    /**
     * The cache of the mutated attributes for each class.
     *
     * @var array
     */
    protected static $mutatorCache = [];

    /**
     * The cache of the "Attribute" return type marked mutated attributes for each class.
     *
     * @var array
     */
    protected static $attributeMutatorCache = [];

    /**
     * The cache of the "Attribute" return type marked mutated, gettable attributes for each class.
     *
     * @var array
     */
    protected static $getAttributeMutatorCache = [];

    /**
     * The cache of the "Attribute" return type marked mutated, settable attributes for each class.
     *
     * @var array
     */
    protected static $setAttributeMutatorCache = [];

    /**
     * The cache of the converted cast types.
     *
     * @var array
     */
    protected static $castTypeCache = [];

    /**
     * The cache of date formats by connection.
     *
     * @var array
     */
    protected static $dateFormatByConnectionCache = [];

    /**
     * Cache of casters.
     *
     * @var array
     */
    protected static $castClassCache = [];

    /**
     * The encrypter instance that is used to encrypt attributes.
     *
     * @var \Illuminate\Contracts\Encryption\Encrypter|null
     */
    public static $encrypter;

    /**
     * Initialize the trait.
     *
     * @return void
     */
    protected function initializeHasCasts()
    {
        $this->casts = $this->ensureCastsAreStringValues(
            array_merge($this->casts, $this->casts()),
        );
    }

    /**
     * Add the casted attributes to the attributes array.
     *
     * @return array
     */
    protected function addCastAttributesToArray(array $attributes, array $mutatedAttributes)
    {
        foreach ($this->getCasts() as $key => $value) {
            if (! array_key_exists($key, $attributes) ||
                in_array($key, $mutatedAttributes)) {
                continue;
            }

            // Here we will cast the attribute. Then, if the cast is a date or datetime cast
            // then we will serialize the date for the array. This will convert the dates
            // to strings based on the date format specified for these Eloquent models.
            $attributes[$key] = $this->castAttribute(
                $key, $attributes[$key]
            );

            // If the attribute cast was a date or a datetime, we will serialize the date as
            // a string. This allows the developers to customize how dates are serialized
            // into an array without affecting how they are persisted into the storage.
            if (isset($attributes[$key]) && in_array($value, ['date', 'datetime', 'immutable_date', 'immutable_datetime'])) {
                $attributes[$key] = $this->serializeDate($attributes[$key]);
            }

            if (isset($attributes[$key]) && ($this->isCustomDateTimeCast($value) ||
                    $this->isImmutableCustomDateTimeCast($value))) {
                $attributes[$key] = $attributes[$key]->format(explode(':', $value, 2)[1]);
            }

            if ($attributes[$key] instanceof DateTimeInterface &&
                $this->isClassCastable($key)) {
                $attributes[$key] = $this->serializeDate($attributes[$key]);
            }

            if (isset($attributes[$key]) && $this->isClassSerializable($key)) {
                $attributes[$key] = $this->serializeClassCastableAttribute($key, $attributes[$key]);
            }

            if ($this->isEnumCastable($key) && (! ($attributes[$key] ?? null) instanceof Arrayable)) {
                $attributes[$key] = isset($attributes[$key]) ? $this->getStorableEnumValue($this->getCasts()[$key], $attributes[$key]) : null;
            }

            if ($attributes[$key] instanceof Arrayable) {
                $attributes[$key] = $attributes[$key]->toArray();
            }
        }

        return $attributes;
    }

    /**
     * Merge new casts with existing casts on the model.
     *
     * @param  array  $casts
     * @return $this
     */
    public function mergeCasts($casts)
    {
        $casts = $this->ensureCastsAreStringValues($casts);

        $this->casts = array_merge($this->casts, $casts);

        return $this;
    }

    /**
     * Ensure that the given casts are strings.
     *
     * @param  array  $casts
     * @return array
     */
    protected function ensureCastsAreStringValues($casts)
    {
        foreach ($casts as $attribute => $cast) {
            $casts[$attribute] = match (true) {
                is_array($cast) => value(function () use ($cast) {
                    if (count($cast) === 1) {
                        return $cast[0];
                    }

                    [$cast, $arguments] = [array_shift($cast), $cast];

                    return $cast.':'.implode(',', $arguments);
                }),
                default => $cast,
            };
        }

        return $casts;
    }

    /**
     * Cast an attribute to a native PHP type.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    protected function castAttribute($key, $value)
    {
        return $this->getInternalCastClass($key)
            ->get($this, $key, $value, $this->attributes);
    }

    /**
     * Cast the given attribute using a custom cast class.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    protected function getClassCastableAttributeValue($key, $value)
    {
        $caster = $this->resolveCasterClass($key);

        $objectCachingDisabled = $caster->withoutObjectCaching ?? false;

        if (isset($this->classCastCache[$key]) && ! $objectCachingDisabled) {
            return $this->classCastCache[$key];
        } else {
            $value = $caster instanceof CastsInboundAttributes
                ? $value
                : $caster->get($this, $key, $value, $this->attributes);

            if ($caster instanceof CastsInboundAttributes ||
                ! is_object($value) ||
                $objectCachingDisabled) {
                unset($this->classCastCache[$key]);
            } else {
                $this->classCastCache[$key] = $value;
            }

            return $value;
        }
    }

    /**
     * Cast the given attribute to an enum.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    protected function getEnumCastableAttributeValue($key, $value)
    {
        if (is_null($value)) {
            return;
        }

        $castType = $this->getCasts()[$key];

        if ($value instanceof $castType) {
            return $value;
        }

        return $this->getEnumCaseFromValue($castType, $value);
    }

    /**
     * Get the type of cast for a model attribute.
     *
     * @param  string  $key
     * @return string
     */
    protected function getCastType($key)
    {
        $castType = $this->getCasts()[$key] ?? '';

        if (isset(static::$castTypeCache[$castType])) {
            return static::$castTypeCache[$castType];
        }

        if ($this->isCustomDateTimeCast($castType)) {
            $convertedCastType = 'custom_datetime';
        } elseif ($this->isImmutableCustomDateTimeCast($castType)) {
            $convertedCastType = 'immutable_custom_datetime';
        } elseif ($this->isDecimalCast($castType)) {
            $convertedCastType = 'decimal';
        } elseif (class_exists($castType)) {
            $convertedCastType = $castType;
        } else {
            $convertedCastType = trim(strtolower($castType));
        }

        return static::$castTypeCache[$castType] = $convertedCastType;
    }

    /**
     * Increment or decrement the given attribute using the custom cast class.
     *
     * @param  string  $method
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    protected function deviateClassCastableAttribute($method, $key, $value)
    {
        return $this->resolveCasterClass($key)->{$method}(
            $this, $key, $value, $this->attributes
        );
    }

    /**
     * Serialize the given attribute using the custom cast class.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    protected function serializeClassCastableAttribute($key, $value)
    {
        return $this->resolveCasterClass($key)->serialize(
            $this, $key, $value, $this->attributes
        );
    }

    /**
     * Determine if the cast type is a custom date time cast.
     *
     * @param  string  $cast
     * @return bool
     */
    protected function isCustomDateTimeCast($cast)
    {
        return str_starts_with($cast, 'date:') ||
            str_starts_with($cast, 'datetime:');
    }

    /**
     * Determine if the cast type is an immutable custom date time cast.
     *
     * @param  string  $cast
     * @return bool
     */
    protected function isImmutableCustomDateTimeCast($cast)
    {
        return str_starts_with($cast, 'immutable_date:') ||
            str_starts_with($cast, 'immutable_datetime:');
    }

    /**
     * Determine if the cast type is a decimal cast.
     *
     * @param  string  $cast
     * @return bool
     */
    protected function isDecimalCast($cast)
    {
        return str_starts_with($cast, 'decimal:');
    }

    /**
     * Determine if the given attribute is a date or date castable.
     *
     * @param  string  $key
     * @return bool
     */
    protected function isDateAttribute($key)
    {
        return in_array($key, $this->getDates(), true) ||
            $this->isDateCastable($key);
    }

    /**
     * Set a given JSON attribute on the model.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return $this
     */
    public function fillJsonAttribute($key, $value)
    {
        [$key, $path] = explode('->', $key, 2);

        $value = $this->asJson($this->getArrayAttributeWithValue(
            $path, $key, $value
        ), $this->getJsonCastFlags($key));

        $this->attributes[$key] = $this->isEncryptedCastable($key)
            ? $this->castAttributeAsEncryptedString($key, $value)
            : $value;

        if ($this->isClassCastable($key)) {
            unset($this->classCastCache[$key]);
        }

        return $this;
    }

    /**
     * Set the value of a class castable attribute.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return void
     */
    protected function setClassCastableAttribute($key, $value)
    {
        $caster = $this->resolveCasterClass($key);

        $this->attributes = array_replace(
            $this->attributes,
            $this->normalizeCastClassResponse($key, $caster->set(
                $this, $key, $value, $this->attributes
            ))
        );

        if ($caster instanceof CastsInboundAttributes ||
            ! is_object($value) ||
            ($caster->withoutObjectCaching ?? false)) {
            unset($this->classCastCache[$key]);
        } else {
            $this->classCastCache[$key] = $value;
        }
    }

    /**
     * Set the value of an enum castable attribute.
     *
     * @param  string  $key
     * @param  \UnitEnum|string|int|null  $value
     * @return void
     */
    protected function setEnumCastableAttribute($key, $value)
    {
        $enumClass = $this->getCasts()[$key];

        if (! isset($value)) {
            $this->attributes[$key] = null;
        } elseif (is_object($value)) {
            $this->attributes[$key] = $this->getStorableEnumValue($enumClass, $value);
        } else {
            $this->attributes[$key] = $this->getStorableEnumValue(
                $enumClass, $this->getEnumCaseFromValue($enumClass, $value)
            );
        }
    }

    /**
     * Get an enum case instance from a given class and value.
     *
     * @param  string  $enumClass
     * @param  string|int  $value
     * @return \UnitEnum|\BackedEnum
     */
    protected function getEnumCaseFromValue($enumClass, $value)
    {
        return is_subclass_of($enumClass, BackedEnum::class)
            ? $enumClass::from($value)
            : constant($enumClass.'::'.$value);
    }

    /**
     * Cast the given attribute to JSON.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return string
     */
    protected function castAttributeAsJson($key, $value)
    {
        $value = $this->asJson($value, $this->getJsonCastFlags($key));

        if ($value === false) {
            throw JsonEncodingException::forAttribute(
                $this, $key, json_last_error_msg()
            );
        }

        return $value;
    }

    /**
     * Get the JSON casting flags for the given attribute.
     *
     * @param  string  $key
     * @return int
     */
    protected function getJsonCastFlags($key)
    {
        $flags = 0;

        if ($this->hasCast($key, ['json:unicode'])) {
            $flags |= JSON_UNESCAPED_UNICODE;
        }

        return $flags;
    }

    /**
     * Encode the given value as JSON.
     *
     * @param  mixed  $value
     * @param  int  $flags
     * @return string
     */
    protected function asJson($value, $flags = 0)
    {
        return Json::encode($value, $flags);
    }

    /**
     * Decode the given JSON back into an array or object.
     *
     * @param  string|null  $value
     * @param  bool  $asObject
     * @return mixed
     */
    public function fromJson($value, $asObject = false)
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Json::decode($value, ! $asObject);
    }

    /**
     * Decrypt the given encrypted string.
     *
     * @param  string  $value
     * @return mixed
     */
    public function fromEncryptedString($value)
    {
        return static::currentEncrypter()->decrypt($value, false);
    }

    /**
     * Cast the given attribute to an encrypted string.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return string
     */
    protected function castAttributeAsEncryptedString($key, #[\SensitiveParameter] $value)
    {
        return static::currentEncrypter()->encrypt($value, false);
    }

    /**
     * Set the encrypter instance that will be used to encrypt attributes.
     *
     * @param  \Illuminate\Contracts\Encryption\Encrypter|null  $encrypter
     * @return void
     */
    public static function encryptUsing($encrypter)
    {
        static::$encrypter = $encrypter;
    }

    /**
     * Get the current encrypter being used by the model.
     *
     * @return \Illuminate\Contracts\Encryption\Encrypter
     */
    protected static function currentEncrypter()
    {
        return static::$encrypter ?? Crypt::getFacadeRoot();
    }

    /**
     * Cast the given attribute to a hashed string.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return string
     */
    protected function castAttributeAsHashedString($key, #[\SensitiveParameter] $value)
    {
        if ($value === null) {
            return null;
        }

        if (! Hash::isHashed($value)) {
            return Hash::make($value);
        }

        /** @phpstan-ignore staticMethod.notFound */
        if (! Hash::verifyConfiguration($value)) {
            throw new RuntimeException("Could not verify the hashed value's configuration.");
        }

        return $value;
    }

    /**
     * Decode the given float.
     *
     * @param  mixed  $value
     * @return mixed
     */
    public function fromFloat($value)
    {
        return match ((string) $value) {
            'Infinity' => INF,
            '-Infinity' => -INF,
            'NaN' => NAN,
            default => (float) $value,
        };
    }

    /**
     * Return a decimal as string.
     *
     * @param  float|string  $value
     * @param  int  $decimals
     * @return string
     */
    protected function asDecimal($value, $decimals)
    {
        try {
            return (string) BigDecimal::of($value)->toScale($decimals, RoundingMode::HALF_UP);
        } catch (BrickMathException $e) {
            throw new MathException('Unable to cast value to a decimal.', previous: $e);
        }
    }

    /**
     * Return a timestamp as DateTime object with time set to 00:00:00.
     *
     * @param  mixed  $value
     * @return \Illuminate\Support\Carbon
     */
    protected function asDate($value)
    {
        return $this->asDateTime($value)->startOfDay();
    }

    /**
     * Return a timestamp as DateTime object.
     *
     * @param  mixed  $value
     * @return \Illuminate\Support\Carbon
     */
    protected function asDateTime($value)
    {
        // If this value is already a Carbon instance, we shall just return it as is.
        // This prevents us having to re-instantiate a Carbon instance when we know
        // it already is one, which wouldn't be fulfilled by the DateTime check.
        if ($value instanceof CarbonInterface) {
            return Date::instance($value);
        }

        // If the value is already a DateTime instance, we will just skip the rest of
        // these checks since they will be a waste of time, and hinder performance
        // when checking the field. We will just return the DateTime right away.
        if ($value instanceof DateTimeInterface) {
            return Date::parse(
                $value->format('Y-m-d H:i:s.u'), $value->getTimezone()
            );
        }

        // If this value is an integer, we will assume it is a UNIX timestamp's value
        // and format a Carbon object from this timestamp. This allows flexibility
        // when defining your date fields as they might be UNIX timestamps here.
        if (is_numeric($value)) {
            return Date::createFromTimestamp($value, date_default_timezone_get());
        }

        // If the value is in simply year, month, day format, we will instantiate the
        // Carbon instances from that format. Again, this provides for simple date
        // fields on the database, while still supporting Carbonized conversion.
        if ($this->isStandardDateFormat($value)) {
            return Date::instance(Carbon::createFromFormat('Y-m-d', $value)->startOfDay());
        }

        $format = $this->getDateFormat();

        // Finally, we will just assume this date is in the format used by default on
        // the database connection and use that format to create the Carbon object
        // that is returned back out to the developers after we convert it here.
        try {
            $date = Date::createFromFormat($format, $value);
        } catch (InvalidArgumentException) {
            $date = false;
        }

        return $date ?: Date::parse($value);
    }

    /**
     * Determine if the given value is a standard date format.
     *
     * @param  string  $value
     * @return bool
     */
    protected function isStandardDateFormat($value)
    {
        return preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value);
    }

    /**
     * Get the format for database stored dates.
     *
     * @return string
     */
    public function getDateFormat()
    {
        if ($this->dateFormat) {
            return $this->dateFormat;
        }

        $connectionName = $this->getConnectionName();

        if (! isset(self::$dateFormatByConnectionCache[$connectionName])) {
            self::$dateFormatByConnectionCache[$connectionName] = $this->getConnection()->getQueryGrammar()->getDateFormat();
        }

        return self::$dateFormatByConnectionCache[$connectionName];
    }

    /**
     * Set the date format used by the model.
     *
     * @param  string  $format
     * @return $this
     */
    public function setDateFormat($format)
    {
        $this->dateFormat = $format;

        return $this;
    }

    /**
     * Determine whether an attribute should be cast to a native type.
     *
     * @param  string  $key
     * @param  array|string|null  $types
     * @return bool
     */
    public function hasCast($key, $types = null)
    {
        if (array_key_exists($key, $this->getCasts())) {
            return $types ? in_array($this->getCastType($key), (array) $types, true) : true;
        }

        return false;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array
     */
    public function getCasts()
    {
        if ($this->getIncrementing()) {
            return array_merge([$this->getKeyName() => $this->getKeyType()], $this->casts);
        }

        return $this->casts;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts()
    {
        return [];
    }

    /**
     * Determine whether a value is Date / DateTime castable for inbound manipulation.
     *
     * @param  string  $key
     * @return bool
     */
    protected function isDateCastable($key)
    {
        return $this->hasCast($key, ['date', 'datetime', 'immutable_date', 'immutable_datetime']);
    }

    /**
     * Determine whether a value is Date / DateTime custom-castable for inbound manipulation.
     *
     * @param  string  $key
     * @return bool
     */
    protected function isDateCastableWithCustomFormat($key)
    {
        return $this->hasCast($key, ['custom_datetime', 'immutable_custom_datetime']);
    }

    /**
     * Determine whether a value is JSON castable for inbound manipulation.
     *
     * @param  string  $key
     * @return bool
     */
    protected function isJsonCastable($key)
    {
        return $this->hasCast($key, ['array', 'json', 'json:unicode', 'object', 'collection', 'encrypted:array', 'encrypted:collection', 'encrypted:json', 'encrypted:object']);
    }

    /**
     * Determine whether a value is an encrypted castable for inbound manipulation.
     *
     * @param  string  $key
     * @return bool
     */
    protected function isEncryptedCastable($key)
    {
        return $this->hasCast($key, ['encrypted', 'encrypted:array', 'encrypted:collection', 'encrypted:json', 'encrypted:object']);
    }

    /**
     * Determine if the given key is cast using a custom class.
     *
     * @param  string  $key
     * @return bool
     *
     * @throws \Illuminate\Database\Eloquent\InvalidCastException
     */
    protected function isClassCastable($key)
    {
        $casts = $this->getCasts();

        if (! array_key_exists($key, $casts)) {
            return false;
        }

        $castType = $this->parseCasterClass($casts[$key]);

        if (in_array($castType, static::$primitiveCastTypes)) {
            return false;
        }

        if (class_exists($castType)) {
            return true;
        }

        throw new InvalidCastException($this->getModel(), $key, $castType);
    }

    /**
     * Determine if the given key is cast using an enum.
     *
     * @param  string  $key
     * @return bool
     */
    protected function isEnumCastable($key)
    {
        $casts = $this->getCasts();

        if (! array_key_exists($key, $casts)) {
            return false;
        }

        $castType = $casts[$key];

        if (in_array($castType, static::$primitiveCastTypes)) {
            return false;
        }

        return enum_exists($castType);
    }

    /**
     * Determine if the key is deviable using a custom class.
     *
     * @param  string  $key
     * @return bool
     *
     * @throws \Illuminate\Database\Eloquent\InvalidCastException
     */
    protected function isClassDeviable($key)
    {
        if (! $this->isClassCastable($key)) {
            return false;
        }

        $castType = $this->resolveCasterClass($key);

        return method_exists($castType::class, 'increment') && method_exists($castType::class, 'decrement');
    }

    /**
     * Determine if the key is serializable using a custom class.
     *
     * @param  string  $key
     * @return bool
     *
     * @throws \Illuminate\Database\Eloquent\InvalidCastException
     */
    protected function isClassSerializable($key)
    {
        return ! $this->isEnumCastable($key) &&
            $this->isClassCastable($key) &&
            method_exists($this->resolveCasterClass($key), 'serialize');
    }

    /**
     * Resolve the custom caster class for a given key.
     *
     * @param  string  $key
     * @return mixed
     */
    protected function resolveCasterClass($key)
    {
        $castType = $this->getCasts()[$key];

        $arguments = [];

        if (is_string($castType) && str_contains($castType, ':')) {
            $segments = explode(':', $castType, 2);

            $castType = $segments[0];
            $arguments = explode(',', $segments[1]);
        }

        if (is_subclass_of($castType, Castable::class)) {
            $castType = $castType::castUsing($arguments);
        }

        if (is_object($castType)) {
            return $castType;
        }

        return new $castType(...$arguments);
    }

    /**
     * Parse the given caster class, removing any arguments.
     *
     * @param  string  $class
     * @return string
     */
    protected function parseCasterClass($class)
    {
        return ! str_contains($class, ':')
            ? $class
            : explode(':', $class, 2)[0];
    }

    /**
     * Merge the cast class and attribute cast attributes back into the model.
     *
     * @return void
     */
    protected function mergeAttributesFromCachedCasts()
    {
        $this->mergeAttributesFromClassCasts();
        $this->mergeAttributesFromAttributeCasts();
    }

    /**
     * Merge the cast class attributes back into the model.
     *
     * @return void
     */
    protected function mergeAttributesFromClassCasts()
    {
        foreach ($this->classCastCache as $key => $value) {
            $caster = $this->resolveCasterClass($key);

            $this->attributes = array_merge(
                $this->attributes,
                $caster instanceof CastsInboundAttributes
                    ? [$key => $value]
                    : $this->normalizeCastClassResponse($key, $caster->set($this, $key, $value, $this->attributes))
            );
        }
    }

    /**
     * Merge the cast class attributes back into the model.
     *
     * @return void
     */
    protected function mergeAttributesFromAttributeCasts()
    {
        foreach ($this->attributeCastCache as $key => $value) {
            $attribute = $this->{Str::camel($key)}();

            if ($attribute->get && ! $attribute->set) {
                continue;
            }

            $callback = $attribute->set ?: function ($value) use ($key) {
                $this->attributes[$key] = $value;
            };

            $this->attributes = array_merge(
                $this->attributes,
                $this->normalizeCastClassResponse(
                    $key, $callback($value, $this->attributes)
                )
            );
        }
    }

    /**
     * Normalize the response from a custom class caster.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return array
     */
    protected function normalizeCastClassResponse($key, $value)
    {
        return is_array($value) ? $value : [$key => $value];
    }

    /**
     * Get the mutated attributes for a given instance.
     *
     * @return array
     */
    public function getMutatedAttributes()
    {
        if (! isset(static::$mutatorCache[static::class])) {
            static::cacheMutatedAttributes($this);
        }

        return static::$mutatorCache[static::class];
    }

    /**
     * Extract and cache all the mutated attributes of a class.
     *
     * @param  object|string  $classOrInstance
     * @return void
     */
    public static function cacheMutatedAttributes($classOrInstance)
    {
        $reflection = new ReflectionClass($classOrInstance);

        $class = $reflection->getName();

        static::$getAttributeMutatorCache[$class] = (new Collection($attributeMutatorMethods = static::getAttributeMarkedMutatorMethods($classOrInstance)))
            ->mapWithKeys(fn ($match) => [lcfirst(static::$snakeAttributes ? Str::snake($match) : $match) => true])
            ->all();

        static::$mutatorCache[$class] = (new Collection(static::getMutatorMethods($class)))
            ->merge($attributeMutatorMethods)
            ->map(fn ($match) => lcfirst(static::$snakeAttributes ? Str::snake($match) : $match))
            ->all();
    }

    /**
     * Get all of the attribute mutator methods.
     *
     * @param  mixed  $class
     * @return array
     */
    protected static function getMutatorMethods($class)
    {
        preg_match_all('/(?<=^|;)get([^;]+?)Attribute(;|$)/', implode(';', get_class_methods($class)), $matches);

        return $matches[1];
    }

    /**
     * Get all of the "Attribute" return typed attribute mutator methods.
     *
     * @param  mixed  $class
     * @return array
     */
    protected static function getAttributeMarkedMutatorMethods($class)
    {
        $instance = is_object($class) ? $class : new $class;

        return (new Collection((new ReflectionClass($instance))->getMethods()))->filter(function ($method) use ($instance) {
            $returnType = $method->getReturnType();

            if ($returnType instanceof ReflectionNamedType &&
                $returnType->getName() === Attribute::class) {
                if (is_callable($method->invoke($instance)->get)) {
                    return true;
                }
            }

            return false;
        })->map->name->values()->all();
    }

    /**
     * Gets cast class for the given key.
     *
     * @return CastsAttributes
     */
    protected function getInternalCastClass(string $key)
    {
        $castType = $this->getCastType($key);

        if (! $castType) {
            return null;
        }

        if (! isset(static::$castClassCache[static::class][$castType])) {
            static::$castClassCache[static::class][$castType] = $this->resolveInternalCastClass($key);
        }

        return static::$castClassCache[static::class][$castType];
    }

    protected function resolveInternalCastClass($key)
    {
        $castType = $this->getCastType($key);

        $caster = null;

        $primitiveComparator = fn ($model, $key, $current, $original) => $model->castAttribute($key, $current) === $model->castAttribute($key, $original);

        if (Str::startsWith($castType, 'encrypted:')) {
            $castType = Str::after($castType, 'encrypted:');
        }

        switch ($castType) {
            case 'int':
            case 'integer':
                $caster = new ClosureCast(
                    fn ($model, $key, $value) => (int) $value,
                    comparator: $primitiveComparator,
                );
                break;
            case 'real':
            case 'float':
            case 'double':
                $caster = new ClosureCast(
                    fn ($model, $key, $value) => $model->fromFloat($value),
                    comparator: function ($model, $key, $current, $original) {
                        if ($original === null) {
                            return false;
                        }

                        return abs($model->castAttribute($key, $current) - $model->castAttribute($key, $original)) < PHP_FLOAT_EPSILON * 4;
                    },
                );
                break;
            case 'decimal':
                $caster = new ClosureCast(
                    fn ($model, $key, $value) => $model->asDecimal($value, explode(':', $model->getCasts()[$key], 2)[1]),
                    comparator: $primitiveComparator,
                );
                break;
            case 'string':
                $caster = new ClosureCast(
                    fn ($model, $key, $value) => (string) $value,
                    comparator: $primitiveComparator,
                );
                break;
            case 'bool':
            case 'boolean':
                $caster = new ClosureCast(
                    fn ($model, $key, $value) => (bool) $value,
                    comparator: $primitiveComparator,
                );
                break;
            case 'object':
                $caster = new ClosureCast(
                    fn ($model, $key, $value) => $model->fromJson($value, true),
                    fn ($model, $key, $value) => is_null($value) ? null : $model->castAttributeAsJson($key, $value),
                    comparator: fn ($model, $key, $current, $original) => $model->fromJson($current) === $model->fromJson($original),
                );
                break;
            case 'array':
            case 'json':
            case 'json:unicode':
                $caster = new ClosureCast(
                    fn ($model, $key, $value) => $model->fromJson($value),
                    fn ($model, $key, $value) => is_null($value) ? null : $model->castAttributeAsJson($key, $value),
                    comparator: $primitiveComparator,
                );
                break;
            case 'collection':
                $caster = new ClosureCast(
                    fn ($model, $key, $value) => new BaseCollection($model->fromJson($value)),
                    fn ($model, $key, $value) => is_null($value) ? null : $model->castAttributeAsJson($key, $value),
                    comparator: fn ($model, $key, $current, $original) => $model->fromJson($current) === $model->fromJson($original),
                );
                break;
            case 'date':
                $caster = new ClosureCast(
                    fn ($model, $key, $value) => $model->asDate($value),
                    comparator: fn ($model, $key, $current, $original) => $model->fromDateTime($current) === $model->fromDateTime($original),
                );
                break;
            case 'datetime':
            case 'custom_datetime':
                $caster = new ClosureCast(
                    fn ($model, $key, $value) => $model->asDateTime($value),
                    comparator: fn ($model, $key, $current, $original) => $model->fromDateTime($current) === $model->fromDateTime($original),
                );
                break;
            case 'immutable_date':
                $caster = new ClosureCast(
                    fn ($model, $key, $value) => $model->asDate($value)->toImmutable(),
                    comparator: fn ($model, $key, $current, $original) => $model->fromDateTime($current) === $model->fromDateTime($original),
                );
                break;
            case 'immutable_custom_datetime':
            case 'immutable_datetime':
                $caster = new ClosureCast(
                    fn ($model, $key, $value) => $model->asDateTime($value)->toImmutable(),
                    comparator: fn ($model, $key, $current, $original) => $model->fromDateTime($current) === $model->fromDateTime($original),
                );
                break;
            case 'timestamp':
                $caster = new ClosureCast(
                    fn ($model, $key, $value) => $model->asTimestamp($value),
                );
                break;

            case 'hashed':
                $caster = new ClosureCast(
                    fn ($model, $key, $value) => $value,
                    fn ($model, $key, $value) => $model->castAttributeAsHashedString($key, $value),
                    comparator: $primitiveComparator,
                );
                break;
        }

        if ($this->isEnumCastable($key)) {
            $caster = new ClosureCast(
                fn ($model, $key, $value) => $model->getEnumCastableAttributeValue($key, $value),
                function ($model, $key, $value) {
                    $model->setEnumCastableAttribute($key, $value);
                },
                setsOwnAttribute: true,
            );
        } elseif ($this->isClassCastable($key)) {
            $caster = new ClosureCast(
                fn ($model, $key, $value) => $model->getClassCastableAttributeValue($key, $value),
                function ($model, $key, $value) {
                    $model->setClassCastableAttribute($key, $value);
                },
                comparator: function ($model, $key, $current, $original) {
                    $castType = $model->getCasts()[$key];

                    if (Str::startsWith($castType, [AsArrayObject::class, AsCollection::class])) {
                        return $model->fromJson($current) === $model->fromJson($original);
                    } elseif (Str::startsWith($castType, [AsEnumArrayObject::class, AsEnumCollection::class])) {
                        return $model->fromJson($current) === $model->fromJson($original);
                    } elseif ($original !== null && Str::startsWith($castType, [AsEncryptedArrayObject::class, AsEncryptedCollection::class])) {
                        if (empty(static::currentEncrypter()->getPreviousKeys())) {
                            return $model->fromEncryptedString($current) === $model->fromEncryptedString($original);
                        }

                        return false;
                    }

                    return false;
                },
                setsOwnAttribute: true,
                nullable: false,
            );
        }

        // If the key is one of the encrypted castable types, we'll first decrypt
        // the value and update the cast type so we may leverage the following
        // logic for casting this value to any additionally specified types.
        if ($this->isEncryptedCastable($key)) {
            $originalCaster = $caster;
            $caster = new ClosureCast(
                function ($model, $key, $value, $attributes) use ($originalCaster) {
                    $result = $model->fromEncryptedString($value);

                    if ($originalCaster) {
                        $result = $originalCaster->get($model, $key, $result, $attributes);
                    }

                    return $result;
                },
                function ($model, $key, $value, $attributes) use ($originalCaster) {
                    if ($originalCaster) {
                        $value = $originalCaster->set($model, $key, $value, $attributes);
                    }

                    return $model->castAttributeAsEncryptedString($key, $value);
                },
                comparator: function ($model, $key, $current, $original) {
                    if (! empty(static::currentEncrypter()->getPreviousKeys())) {
                        return false;
                    }

                    return $model->fromEncryptedString($current) === $model->fromEncryptedString($original);
                },
            );
        }

        return $caster;
    }
}
