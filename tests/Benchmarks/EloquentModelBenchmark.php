<?php

namespace Illuminate\Tests\Benchmarks;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\ParamProviders;
use PhpBench\Attributes\RetryThreshold;
use PhpBench\Attributes\Revs;

#[
    Revs(5000),
    Iterations(10),
    RetryThreshold(2),
    ParamProviders(['provideCasts', 'provideClass'])
]
class EloquentGetDateFormatBenchmark extends OrchestraBenchmark
{
    protected BenchmarkModel $model;

    public function benchConstructor(array $params)
    {
        $this->createModel($params);
    }

    #[BeforeMethods('createModel')]
    public function benchGetAttribute(array $params)
    {
        $field = $params['cast'] . '_field';

        return [$this->model->$field];
    }

    #[BeforeMethods(['createModel', 'saveModel'])]
    public function benchIsDirty(array $params)
    {
        $field = $params['cast'] . '_field';
        $this->model->$field = $params['sample_data_2'];
        $this->model->isDirty();
    }

    public function afterRefreshingDatabase()
    {
        if (! Schema::hasTable('benchmarks')) {
            Schema::create('benchmarks', function ($table) {
                $table->increments('id');
                $table->timestamp('datetime_field')->nullable();
                $table->integer('integer_field')->nullable();
                $table->float('float_field')->nullable();
                $table->boolean('boolean_field')->nullable();
                $table->json('array_field')->nullable();
                $table->timestamps();
            });
        }
    }

    public function createModel(array $params)
    {
        $className = $params['class'];
        $this->model = new $className([
            $params['cast'] . '_field' => $params['sample_data'],
        ], [
            $params['cast'] . '_field' => $params['cast'],
        ]);
    }

    public function saveModel()
    {
        $this->model->save();
        $this->model->refresh();
    }

    public function provideClass()
    {
        yield 'base' => ['class' => BenchmarkOriginalModel::class];
        yield 'improved' => ['class' => BenchmarkModel::class];
    }
    public function provideCasts()
    {
        yield 'datetime' => ['cast' => 'datetime', 'sample_data' => '2025-03-24 12:00:00', 'column_type' => 'timestamp', 'sample_data_2' => '2025-03-25 12:00:00'];
        yield 'integer' => ['cast' => 'integer', 'sample_data' => 123456789, 'column_type' => 'integer', 'sample_data_2' => 987654321];
        yield 'float' => ['cast' => 'float', 'sample_data' => 1234.56789, 'column_type' => 'float', 'sample_data_2' => 9876.54321];
        yield 'boolean' => ['cast' => 'boolean', 'sample_data' => true, 'column_type' => 'boolean', 'sample_data_2' => false];
        yield 'array' => ['cast' => 'array', 'sample_data' => ['foo' => 'bar'], 'column_type' => 'json', 'sample_data_2' => ['baz' => 'qux']];
    }
}

class BenchmarkModel extends Model
{
    protected $table = 'benchmarks';

    protected $guarded = [];

    public function __construct(
        array $attributes = [],
        array $casts = [],
    )
    {
        $this->casts = $casts;
        parent::__construct($attributes);
    }
}

class BenchmarkOriginalModel extends BenchmarkModel
{
    public function getDateFormat()
    {
        return $this->dateFormat ?: $this->getConnection()->getQueryGrammar()->getDateFormat();
    }
}

