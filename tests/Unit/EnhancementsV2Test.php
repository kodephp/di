<?php

declare(strict_types=1);

namespace Kode\DI\Tests\Unit;

use Kode\DI\Binding;
use Kode\DI\Container;
use PHPUnit\Framework\TestCase;

/**
 * v2.2.0 体验型 API 增强测试：getBinding / isShared / resolveMany / refreshTag / wrap / call 可调用类
 */
class EnhancementsV2Test extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
        Container::clearCache();
    }

    // ---------------------------------------------------------------
    // getBinding：绑定内省
    // ---------------------------------------------------------------

    public function testGetBindingReturnsBindingWithLifecycleAndTags(): void
    {
        $this->container->singleton(V2Svc::class, V2Svc::class);
        $this->container->tag('group', [V2Svc::class]);

        $binding = $this->container->getBinding(V2Svc::class);

        $this->assertInstanceOf(Binding::class, $binding);
        $this->assertTrue($binding->isSingleton());
        $this->assertTrue($binding->hasTag('group'));
        $this->assertSame(V2Svc::class, $binding->getId());
    }

    public function testGetBindingReturnsNullWhenUnbound(): void
    {
        $this->assertNull($this->container->getBinding('no-such-service'));
    }

    // ---------------------------------------------------------------
    // isShared：共享判定
    // ---------------------------------------------------------------

    public function testIsSharedTrueForSingletonAndFalseForPrototype(): void
    {
        $this->container->singleton(V2Svc::class, V2Svc::class);
        $this->container->prototype(V2Other::class, V2Other::class);

        $this->assertTrue($this->container->isShared(V2Svc::class));
        $this->assertFalse($this->container->isShared(V2Other::class));
    }

    public function testIsSharedTrueForLazyContextualAndInstance(): void
    {
        $this->container->lazy(V2Svc::class, V2Svc::class);
        $this->assertTrue($this->container->isShared(V2Svc::class));

        $this->container->contextual(V2Other::class, V2Other::class);
        $this->assertTrue($this->container->isShared(V2Other::class));

        $this->container->instance(V2State::class, new V2State());
        $this->assertTrue($this->container->isShared(V2State::class));
    }

    public function testIsSharedFalseForUnbound(): void
    {
        $this->assertFalse($this->container->isShared('does-not-exist'));
    }

    // ---------------------------------------------------------------
    // resolveMany：批量解析
    // ---------------------------------------------------------------

    public function testResolveManyReturnsKeyedResults(): void
    {
        $this->container->singleton(V2Svc::class, V2Svc::class);
        $this->container->singleton(V2State::class, V2State::class);

        $result = $this->container->resolveMany([V2Svc::class, V2State::class]);

        $this->assertCount(2, $result);
        $this->assertArrayHasKey(V2Svc::class, $result);
        $this->assertArrayHasKey(V2State::class, $result);
        $this->assertInstanceOf(V2Svc::class, $result[V2Svc::class]);
        $this->assertInstanceOf(V2State::class, $result[V2State::class]);
    }

    // ---------------------------------------------------------------
    // refreshTag：按标签批量重建
    // ---------------------------------------------------------------

    public function testRefreshTagRebuildsTaggedSingletons(): void
    {
        $this->container->singleton(V2State::class, V2State::class);
        $this->container->tag('stateful', [V2State::class]);

        $first = $this->container->get(V2State::class);
        $first->value = 5;

        $rebuilt = $this->container->refreshTag('stateful');

        $this->assertArrayHasKey(V2State::class, $rebuilt);
        $this->assertNotSame($first, $rebuilt[V2State::class]);
        $this->assertSame(0, $rebuilt[V2State::class]->value);
    }

    public function testRefreshTagDoesNotTouchOtherTags(): void
    {
        $this->container->singleton(V2Svc::class, V2Svc::class);
        $this->container->singleton(V2State::class, V2State::class);
        $this->container->tag('only-state', [V2State::class]);

        $svc = $this->container->get(V2Svc::class);
        $this->container->refreshTag('only-state');

        // 未被打标签的服务实例应保持原样（未重新构建）
        $this->assertSame($svc, $this->container->get(V2Svc::class));
    }

    // ---------------------------------------------------------------
    // wrap：带 DI 的延迟可调用
    // ---------------------------------------------------------------

    public function testWrapPrebindsAndDefersInvocation(): void
    {
        $this->container->singleton(V2Dep::class, V2Dep::class);

        $invoked = null;
        $fn = $this->container->wrap(
            function (V2Dep $dep, string $msg) use (&$invoked): void {
                $invoked = $dep->name . ':' . $msg;
            },
            ['msg' => 'hi']
        );

        $this->assertInstanceOf(\Closure::class, $fn);
        $this->assertNull($invoked); // 尚未调用

        $fn();

        $this->assertSame('dep:hi', $invoked);
    }

    public function testWrapAcceptsCallTimeOverride(): void
    {
        $this->container->singleton(V2Dep::class, V2Dep::class);

        $captured = null;
        $fn = $this->container->wrap(
            function (V2Dep $dep, string $msg) use (&$captured): void {
                $captured = $msg;
            },
            ['msg' => 'preset']
        );

        $fn(['msg' => 'override']);

        $this->assertSame('override', $captured);
    }

    // ---------------------------------------------------------------
    // call() 可调用类字符串（'Class' -> __invoke）
    // ---------------------------------------------------------------

    public function testCallInvokableClassString(): void
    {
        // V2Invokable 未手动绑定，call 应解析其实例并调用 __invoke（依赖由容器注入）
        $this->container->singleton(V2Dep::class, V2Dep::class);

        $result = $this->container->call(V2Invokable::class);

        $this->assertSame('invoked:dep', $result);
    }

    // ---------------------------------------------------------------
    // instance() 支持标量 / 数组（如 Application 的 path.base）
    // ---------------------------------------------------------------

    public function testInstanceAcceptsScalar(): void
    {
        $this->container->instance('path.base', '/var/www');

        $this->assertSame('/var/www', $this->container->get('path.base'));
    }

    public function testInstanceAcceptsArray(): void
    {
        $config = ['debug' => true, 'name' => 'app'];
        $this->container->instance('config', $config);

        $this->assertSame($config, $this->container->get('config'));
    }

    public function testInstanceScalarBypassesExtendersAndCallbacks(): void
    {
        // 非对象值不应经过扩展器 / 解析回调（其语义仅对对象有意义）
        $touched = false;
        $this->container->afterResolving('scalar.id', function () use (&$touched): void {
            $touched = true;
        });

        $this->container->instance('scalar.id', 42);

        $this->assertFalse($touched);
        $this->assertSame(42, $this->container->get('scalar.id'));
    }

    public function testInstanceIfAcceptsScalar(): void
    {
        $this->container->instanceIf('path.cache', '/tmp/cache');

        $this->assertSame('/tmp/cache', $this->container->get('path.cache'));
        $this->assertTrue($this->container->bound('path.cache'));
    }

    public function testCallInvokableClassStringWithParameters(): void
    {
        $result = $this->container->call(V2InvokableWithParam::class, ['suffix' => '!']);

        $this->assertSame('ok!', $result);
    }
}

// ---------------------------------------------------------------
// 辅助类
// ---------------------------------------------------------------

class V2Svc
{
}

class V2Other
{
}

class V2State
{
    public int $value = 0;
}

class V2Dep
{
    public string $name = 'dep';
}

class V2Invokable
{
    public function __construct(private V2Dep $dep)
    {
    }

    public function __invoke(): string
    {
        return 'invoked:' . $this->dep->name;
    }
}

class V2InvokableWithParam
{
    public function __invoke(string $suffix = ''): string
    {
        return 'ok' . $suffix;
    }
}
