<?php

declare(strict_types=1);

namespace Kode\DI\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Kode\DI\Container;
use Kode\DI\Binding;
use Kode\DI\Attributes\Inject;
use Kode\DI\Attributes\Singleton;
use Kode\DI\Attributes\Prototype;
use Kode\DI\Exception\ContainerException;
use Kode\DI\Exception\ServiceNotFoundException;

final class ContainerTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    protected function tearDown(): void
    {
        $this->container->flush();
    }

    public function testBasicResolution(): void
    {
        $this->container->singleton('service', fn() => new \stdClass());

        $service = $this->container->get('service');

        $this->assertInstanceOf(\stdClass::class, $service);
    }

    public function testSingletonReturnsSameInstance(): void
    {
        $this->container->singleton('service', fn() => new \stdClass());

        $service1 = $this->container->get('service');
        $service2 = $this->container->get('service');

        $this->assertSame($service1, $service2);
    }

    public function testPrototypeReturnsDifferentInstances(): void
    {
        $this->container->prototype('service', fn() => new \stdClass());

        $service1 = $this->container->get('service');
        $service2 = $this->container->get('service');

        $this->assertNotSame($service1, $service2);
    }

    public function testAliasResolution(): void
    {
        $this->container->singleton('service', fn() => new \stdClass());
        $this->container->alias('alias', 'service');

        $service = $this->container->get('alias');

        $this->assertInstanceOf(\stdClass::class, $service);
    }

    public function testInstanceBinding(): void
    {
        $instance = new \stdClass();
        $instance->value = 'test';

        $this->container->instance('service', $instance);

        $resolved = $this->container->get('service');

        $this->assertSame($instance, $resolved);
        $this->assertEquals('test', $resolved->value);
    }

    public function testAutoResolution(): void
    {
        $service = $this->container->get(\stdClass::class);

        $this->assertInstanceOf(\stdClass::class, $service);
    }

    public function testDependencyInjection(): void
    {
        $dependency = new \stdClass();
        $dependency->value = 'injected';

        $this->container->instance(\stdClass::class, $dependency);

        $service = $this->container->get(TestServiceWithDependency::class);

        $this->assertInstanceOf(TestServiceWithDependency::class, $service);
        $this->assertSame($dependency, $service->dependency);
    }

    public function testServiceNotFoundException(): void
    {
        $this->expectException(ServiceNotFoundException::class);

        $this->container->get('NonExistentService');
    }

    public function testCircularDependencyDetection(): void
    {
        $this->container->singleton(CircularA::class, fn($c) => new CircularA($c->get(CircularB::class)));
        $this->container->singleton(CircularB::class, fn($c) => new CircularB($c->get(CircularA::class)));

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('循环依赖');

        $this->container->get(CircularA::class);
    }

    public function testExtender(): void
    {
        $this->container->singleton('service', fn() => new \stdClass());
        $this->container->extend('service', function ($instance, $container) {
            $instance->extended = true;
            return $instance;
        });

        $service = $this->container->get('service');

        $this->assertTrue($service->extended);
    }

    public function testHasMethod(): void
    {
        $this->container->singleton('service', fn() => new \stdClass());

        $this->assertTrue($this->container->has('service'));
        $this->assertFalse($this->container->has('NonExistent'));
    }

    public function testResolvedMethod(): void
    {
        $this->container->singleton('service', fn() => new \stdClass());

        $this->assertFalse($this->container->resolved('service'));

        $this->container->get('service');

        $this->assertTrue($this->container->resolved('service'));
    }

    public function testForgetMethod(): void
    {
        $this->container->singleton('service', fn() => new \stdClass());
        $this->container->get('service');

        $this->assertTrue($this->container->resolved('service'));

        $this->container->forget('service');

        $this->assertFalse($this->container->has('service'));
    }

    public function testTagging(): void
    {
        $this->container->singleton('service1', fn() => new \stdClass());
        $this->container->singleton('service2', fn() => new \stdClass());
        $this->container->tag('services', ['service1', 'service2']);

        $tagged = $this->container->tagged('services');

        $this->assertCount(2, $tagged);
        $this->assertArrayHasKey('service1', $tagged);
        $this->assertArrayHasKey('service2', $tagged);
    }

    public function testCallMethod(): void
    {
        $dependency = new \stdClass();
        $dependency->value = 'injected';

        $this->container->instance(\stdClass::class, $dependency);

        $result = $this->container->call(function (\stdClass $dep) {
            return $dep->value;
        });

        $this->assertEquals('injected', $result);
    }

    public function testMakeWithParameters(): void
    {
        $service = $this->container->make(TestServiceWithParameters::class, [
            'value' => 'custom_value'
        ]);

        $this->assertEquals('custom_value', $service->value);
    }
}

class TestServiceWithDependency
{
    public function __construct(
        public \stdClass $dependency
    ) {}
}

class TestServiceWithParameters
{
    public function __construct(
        public string $value
    ) {}
}

class CircularA
{
    public function __construct(CircularB $b) {}
}

class CircularB
{
    public function __construct(CircularA $a) {}
}
