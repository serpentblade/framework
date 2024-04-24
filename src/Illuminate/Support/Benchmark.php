<?php

namespace Illuminate\Support;

use Closure;
use InvalidArgumentException;

class Benchmark
{
    /**
     * Measure a callable or array of callables over the given number of iterations.
     *
     * @param  \Closure|array  $benchmarkables
     * @param  int  $iterations
     * @return array|float
     */
    public static function measure(Closure|array $benchmarkables, int $iterations = 1, string|array $aggregateFunctions = 'average'): array|float
    {
        return collect(Arr::wrap($benchmarkables))->map(function ($callback) use ($aggregateFunctions, $iterations) {
            $timings =  collect(range(1, $iterations))->map(function () use ($callback) {
                gc_collect_cycles();

                $start = hrtime(true);

                $callback();

                return (hrtime(true) - $start) / 1000000;
            });

            return self::aggregateMeasurements($timings, $aggregateFunctions);
        })->when(
            $benchmarkables instanceof Closure,
            fn ($c) => $c->first(),
            fn ($c) => $c->all(),
        );
    }

    /**
     * Measure a callable once and return the duration and result.
     *
     * @template TReturn of mixed
     *
     * @param  (callable(): TReturn)  $callback
     * @return array{0: TReturn, 1: float}
     */
    public static function value(callable $callback): array
    {
        gc_collect_cycles();

        $start = hrtime(true);

        $result = $callback();

        return [$result, (hrtime(true) - $start) / 1000000];
    }

    /**
     * Measure a callable or array of callables over the given number of iterations, then dump and die.
     *
     * @param  \Closure|array  $benchmarkables
     * @param  int  $iterations
     * @return never
     */
    public static function dd(Closure|array $benchmarkables, int $iterations = 1, string|array $aggregateFunction = 'average'): void
    {
        $result = collect(static::measure(Arr::wrap($benchmarkables), $iterations, $aggregateFunction))
            ->map(fn ($average) => number_format($average, 3).'ms')
            ->when($benchmarkables instanceof Closure, fn ($c) => $c->first(), fn ($c) => $c->all());

        dd($result);
    }

    protected static function aggregateMeasurements(Collection $timings, string|array $aggregateFunctions): mixed
    {
        $aggregateFunctions = Arr::wrap($aggregateFunctions);

        $aggregateResult = [];

        foreach($aggregateFunctions as $aggregateFunction) {

            if (preg_match('/^p(\d+)$/', $aggregateFunction, $matches)) {
                $aggregateResult[$aggregateFunction] = self::percentile($timings, $matches[1]);
            } else {
                $aggregateResult[$aggregateFunction] = match($aggregateFunction) {
                    'average' => $timings->average(),
                    'sum', 'total' => $timings->sum(),
                    'min' => $timings->min(),
                    'max' => $timings->max(),
                    'median' => self::percentile($timings, 50),
                    default => throw new InvalidArgumentException("Unsupported benchmark aggregate function: $aggregateFunction"),
                };
            }
        }

        return count($aggregateFunctions) > 1 ? $aggregateResult : head($aggregateResult);
    }

    protected static function percentile(Collection $timings, int $percentile): float
    {
        $sortedTimings = $timings->sort();

        return $sortedTimings->get((int) ($sortedTimings->count() * $percentile / 100));
    }

}
