<?php

declare(strict_types=1);

use PhpSaga\SagaContext;

it('initializes with empty data by default', function () {
    $context = new SagaContext();

    expect($context->all())->toBe([])
        ->and($context->count())->toBe(0);
});

it('initializes with provided data', function () {
    $data = ['order_id' => 123, 'user_id' => 456];
    $context = new SagaContext($data);

    expect($context->all())->toBe($data)
        ->and($context->get('order_id'))->toBe(123)
        ->and($context->get('user_id'))->toBe(456);
});

it('retrieves default value when key does not exist', function () {
    $context = new SagaContext();

    expect($context->get('missing'))->toBeNull()
        ->and($context->get('missing', 'fallback'))->toBe('fallback');
});

it('correctly handles null values on get and has', function () {
    $context = new SagaContext(['nullable_key' => null]);

    expect($context->has('nullable_key'))->toBeTrue()
        ->and($context->get('nullable_key', 'default_never_used'))->toBeNull()
        ->and($context->has('not_present'))->toBeFalse();
});

it('sets and chains values mutably', function () {
    $context = new SagaContext();
    $result = $context->set('order_id', 99)->set('status', 'created');

    expect($result)->toBe($context)
        ->and($context->get('order_id'))->toBe(99)
        ->and($context->get('status'))->toBe('created');
});

it('removes keys from context', function () {
    $context = new SagaContext(['a' => 1, 'b' => 2]);
    $context->remove('a');

    expect($context->has('a'))->toBeFalse()
        ->and($context->has('b'))->toBeTrue()
        ->and($context->all())->toBe(['b' => 2]);
});

it('supports immutable copy via with()', function () {
    $original = new SagaContext(['a' => 1]);
    $modified = $original->with('b', 2);

    expect($original->has('b'))->toBeFalse()
        ->and($original->all())->toBe(['a' => 1])
        ->and($modified->all())->toBe(['a' => 1, 'b' => 2]);
});

it('supports immutable copy via without()', function () {
    $original = new SagaContext(['a' => 1, 'b' => 2]);
    $modified = $original->without('b');

    expect($original->has('b'))->toBeTrue()
        ->and($modified->has('b'))->toBeFalse()
        ->and($modified->all())->toBe(['a' => 1]);
});

it('merges array or another SagaContext', function () {
    $context = new SagaContext(['a' => 1]);
    $context->merge(['b' => 2, 'c' => 3]);

    expect($context->all())->toBe(['a' => 1, 'b' => 2, 'c' => 3]);

    $other = new SagaContext(['c' => 4, 'd' => 5]);
    $context->merge($other);

    expect($context->all())->toBe(['a' => 1, 'b' => 2, 'c' => 4, 'd' => 5]);
});

it('serializes to json correctly', function () {
    $context = new SagaContext(['name' => 'OrderSaga', 'total' => 150.75]);

    $serialized = json_encode($context);
    expect($serialized)->toBe('{"name":"OrderSaga","total":150.75}')
        ->and($context->toJson())->toBe('{"name":"OrderSaga","total":150.75}');
});

it('deserializes safely from array and json', function () {
    $fromArray = SagaContext::fromArray(['foo' => 'bar']);
    expect($fromArray->get('foo'))->toBe('bar');

    $fromJson = SagaContext::fromJson('{"foo":"bar","amount":100}');
    expect($fromJson->get('foo'))->toBe('bar')
        ->and($fromJson->get('amount'))->toBe(100);
});

it('throws exception when deserializing invalid JSON', function () {
    expect(fn () => SagaContext::fromJson('{invalid-json}'))
        ->toThrow(InvalidArgumentException::class);
});

it('throws exception when JSON payload is not an object/array', function () {
    expect(fn () => SagaContext::fromJson('"just a string"'))
        ->toThrow(InvalidArgumentException::class, 'SagaContext JSON must decode to an associative array.');
});

