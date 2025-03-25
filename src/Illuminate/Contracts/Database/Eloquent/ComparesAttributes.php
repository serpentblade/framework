<?php

namespace Illuminate\Contracts\Database\Eloquent;

use Illuminate\Database\Eloquent\Model;

interface ComparesAttributes
{
    /**
     * Compares the given value with the original.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $key
     * @param  string  $original
     * @param  string  $current
     * @return bool
     */
    public function compare(Model $model, string $key, mixed $original, mixed $current): bool;
}
