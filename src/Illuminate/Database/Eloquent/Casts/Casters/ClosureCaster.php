<?php

namespace Illuminate\Database\Eloquent\Casts\Casters;

class ClosureCaster extends Caster
{
    /**
     * @var callable
     */
    private $castFrom;

    /**
     * @var callable
     */
    private $castTo;

    /**
     * @var callable
     */
    private $isEquivalent;

    public function __construct(
        callable $castTo,
        ?callable $castFrom = null,
        ?callable $isEquivalent = null
    )
    {
        $this->castTo = $castTo;
        $this->castFrom = $castFrom
            ?: fn ($key, $value) => $value;
        $this->isEquivalent = $isEquivalent
            ?: fn ($key, $current, $previous) => $this->castTo($key, $current) === $this->castTo($key, $previous);
    }

    public function isEquivalent($key, $current, $previous): bool
    {
        return call_user_func($this->isEquivalent, $key, $current, $previous);
    }

    public function castFrom($key, $value)
    {
        return call_user_func($this->castFrom, $key, $value);
    }

    public function castTo($key, $value)
    {
        return call_user_func($this->castTo, $key, $value);
    }
}
