<?php

namespace Illuminate\Database\Eloquent\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\ComparesAttributes;

class ClosureCast implements CastsAttributes, ComparesAttributes
{
    /**
     * @var callable
     */
    private $get;

    /**
     * @var callable
     */
    private $set;

    /**
     * @var callable
     */
    private $comparator;

    public function __construct(
        callable $get,
        ?callable $set = null,
        ?callable $comparator = null,
        public bool $setsOwnAttribute = false,
        protected bool $nullable = true,
    ) {
        $this->get = $get;
        $this->set = $set ?: fn ($model, $key, $value) => $value;
        $this->comparator = $comparator ?: fn () => false;
    }

    public function get($model, string $key, mixed $value, array $attributes)
    {
        if (is_null($value) && $this->nullable) {
            return null;
        }

        return call_user_func($this->get, $model, $key, $value, $attributes);
    }

    public function set($model, string $key, mixed $value, array $attributes)
    {
        return call_user_func($this->set, $model, $key, $value, $attributes);
    }

    public function compare($model, string $key, mixed $original, mixed $current): bool
    {
        return call_user_func($this->comparator, $model, $key, $original, $current);
    }
}
