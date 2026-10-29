<?php

declare(strict_types=1);

use PhpSaga\Contracts\StepInterface;
use PhpSaga\Exceptions\InvalidSagaDefinitionException;
use PhpSaga\Execution\ContainerStepResolver;
use PhpSaga\Execution\SagaBuilder;
use PhpSaga\Execution\SagaCoordinator;
use PhpSaga\SagaContext;
use Psr\Container\ContainerInterface;

class SampleStep implements StepInterface
{
    public function getName(): string
    {
        return 'SampleStep';
    }

    public function execute(SagaContext $context): void
    {
        $context->set('sample_executed', true);
    }

    public function compensate(SagaContext $context): void
    {
        $context->set('sample_executed', false);
    }
}

class InjectedStep implements StepInterface
{
    public function __construct(public string $prefix = 'default_') {}

    public function getName(): string
    {
        return 'InjectedStep';
    }

    public function execute(SagaContext $context): void
    {
        $context->set('injected', $this->prefix . 'success');
    }

    public function compensate(SagaContext $context): void {}
}

it('resolves step instance directly when object is provided', function () {
    $resolver = new ContainerStepResolver();
    $step = new SampleStep();

    expect($resolver->resolve($step))->toBe($step);
});

it('resolves step from class name without container using direct instantiation', function () {
    $resolver = new ContainerStepResolver();
    $step = $resolver->resolve(SampleStep::class);

    expect($step)->toBeInstanceOf(SampleStep::class)
        ->and($step->getName())->toBe('SampleStep');
});

it('resolves step from container when available', function () {
    /** @var ContainerInterface&Mockery\MockInterface $container */
    $container = Mockery::mock(ContainerInterface::class);
    $container->shouldReceive('has')->with(InjectedStep::class)->andReturn(true);
    $container->shouldReceive('get')->with(InjectedStep::class)->andReturn(new InjectedStep(prefix: 'container_'));

    $resolver = new ContainerStepResolver($container);
    $step = $resolver->resolve(InjectedStep::class);

    expect($step)->toBeInstanceOf(InjectedStep::class);
    assert($step instanceof InjectedStep);
    expect($step->prefix)->toBe('container_');
});

it('executes saga configured with step class strings', function () {
    $definition = SagaBuilder::new('ClassStringSaga')
        ->addStep(SampleStep::class)
        ->build();

    $coordinator = new SagaCoordinator();
    $result = $coordinator->run($definition);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->getContext()->get('sample_executed'))->toBeTrue();
});

it('throws exception if resolved step class does not exist', function () {
    $resolver = new ContainerStepResolver();

    expect(fn () => $resolver->resolve('App\\NonExistentStep'))
        ->toThrow(InvalidSagaDefinitionException::class, "Step class 'App\\NonExistentStep' does not exist.");
});
