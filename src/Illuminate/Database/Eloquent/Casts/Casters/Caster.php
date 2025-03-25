<?php

namespace Illuminate\Database\Eloquent\Casts\Casters;

abstract class Caster
{
    abstract public function isEquivalent($key, $current, $previous): bool;

    abstract public function castFrom($key, $value);

    abstract public function castTo($key, $value);
}
