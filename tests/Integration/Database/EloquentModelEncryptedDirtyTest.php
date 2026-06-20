<?php

namespace Illuminate\Tests\Integration\Database;

use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Orchestra\Testbench\TestCase;

class EloquentModelEncryptedDirtyTest extends TestCase
{
    public function testEncryptedJsonIsNotDirtyWhenStoredFormattingDiffersFromReEncoded()
    {
        config(['app.key' => str_repeat('a', 32)]);
        Model::$encrypter = null;

        $model = new EncryptedDirtyAttributeCast;

        // Simulate a row whose encrypted JSON was stored with non-canonical
        // whitespace (e.g. migrated from another system). Decrypting yields the
        // same structure Laravel re-encodes, so the attribute is not dirty.
        $model->setRawAttributes([
            'secret_array' => Crypt::encryptString('{"a": 1, "b": 2}'),
        ], true);

        $model->secret_array = ['a' => 1, 'b' => 2];

        $this->assertFalse($model->isDirty('secret_array'));
    }

    public function testDirtyAttributeBehaviorWithNoPreviousKeys()
    {
        config(['app.key' => str_repeat('a', 32)]);
        Model::$encrypter = null;

        $model = new EncryptedDirtyAttributeCast([
            'secret' => 'some-secret',
            'secret_array_object' => [1, 2, 3],
        ]);

        $model->syncOriginal();

        $this->assertFalse($model->isDirty('secret'));
        $this->assertFalse($model->isDirty('secret_array_object'));

        $model->secret = 'some-secret';
        $model->secret_array_object = [1, 2, 3];

        // Encrypted attributes should always be considered dirty if updated in any way because of rotatable encryption keys...
        $this->assertFalse($model->isDirty('secret'));
        $this->assertFalse($model->isDirty('secret_array_object'));

        $model->secret = 'some-other-secret';
        $model->secret_array_object = [4, 5, 6];

        // Encrypted attributes should always be considered dirty if updated in any way because of rotatable encryption keys...
        $this->assertTrue($model->isDirty('secret'));
        $this->assertTrue($model->isDirty('secret_array_object'));
    }

    public function testIsEncryptedCastableOverrideIsNotHonoredOnTheCastPath()
    {
        config(['app.key' => str_repeat('a', 32)]);
        Model::$encrypter = null;

        // The narrowed cast-resolution contract: whether a cast type is
        // encrypted is decided once per cast type from the built-in
        // encrypted:* set, not re-derived per key. A model that declares a
        // plain "array" cast and overrides isEncryptedCastable() to claim a key
        // is encrypted is therefore NOT honored on the cast path — the value is
        // stored as plain JSON. To encrypt a field, give it an encrypted cast
        // type (e.g. 'data' => 'encrypted:array'), which still works (see the
        // EncryptedDirtyAttributeCast cases above).
        $model = new OverriddenEncryptedCastable;

        $model->data = ['a' => 1, 'b' => 2];

        // The plain "array" cast stores plain JSON; the per-key override does
        // not encrypt it.
        $this->assertSame('{"a":1,"b":2}', $model->getAttributes()['data']);

        // ...and it still reads back as the decoded array.
        $this->assertSame(['a' => 1, 'b' => 2], $model->data);

        // Dirty comparison runs through the plain JSON comparator, so a
        // re-assignment of the same structure is not dirty.
        $model->syncOriginal();
        $model->data = ['a' => 1, 'b' => 2];
        $this->assertFalse($model->isDirty('data'));
    }

    public function testDirtyAttributeBehaviorWithPreviousKeys()
    {
        config(['app.key' => str_repeat('a', 32)]);
        config(['app.previous_keys' => [str_repeat('b', 32)]]);
        Model::$encrypter = null;

        $model = new EncryptedDirtyAttributeCast([
            'secret' => 'some-secret',
            'secret_array_object' => [1, 2, 3],
        ]);

        $model->syncOriginal();

        $this->assertFalse($model->isDirty('secret'));
        $this->assertFalse($model->isDirty('secret_array_object'));

        $model->secret = 'some-secret';
        $model->secret_array_object = [1, 2, 3];

        // Encrypted attributes should always be considered dirty if updated in any way because of rotatable encryption keys...
        $this->assertTrue($model->isDirty('secret'));
        $this->assertTrue($model->isDirty('secret_array_object'));

        $model->secret = 'some-other-secret';
        $model->secret_array_object = [4, 5, 6];

        // Encrypted attributes should always be considered dirty if updated in any way because of rotatable encryption keys...
        $this->assertTrue($model->isDirty('secret'));
        $this->assertTrue($model->isDirty('secret_array_object'));
    }
}

/**
 * @property $secret
 * @property $secret_array
 * @property $secret_json
 * @property $secret_object
 * @property $secret_collection
 */
class EncryptedDirtyAttributeCast extends Model
{
    protected $guarded = [];

    public $casts = [
        'secret' => 'encrypted',
        'secret_array' => 'encrypted:array',
        'secret_json' => 'encrypted:json',
        'secret_object' => 'encrypted:object',
        'secret_collection' => 'encrypted:collection',
        'secret_array_object' => AsEncryptedArrayObject::class,
    ];
}

class OverriddenEncryptedCastable extends Model
{
    protected $guarded = [];

    public $casts = [
        'data' => 'array',
    ];

    protected function isEncryptedCastable($key)
    {
        return $key === 'data' || parent::isEncryptedCastable($key);
    }
}
