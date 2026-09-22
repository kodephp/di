<?php

declare(strict_types=1);

namespace Kode\DI\Tests\Unit;

use Kode\DI\Container;
use Kode\DI\Exception\ContainerException;
use PHPUnit\Framework\TestCase;

/**
 * 常驻进程（长生命周期 worker）下的容器行为回归
 *
 * 覆盖 v2.3.0 修复：单例已解析后注册的扩展器被实例缓存吞掉；别名成环导致
 * resolveAlias 的 while 永不收敛（worker 静默空转到死）。
 * 覆盖 v2.4.0 修复：装饰结果未进单例缓存（第二次 get 拿到未装饰对象）、懒加载代理被
 * 装饰闭包直接接收抛 TypeError、接口 id 每次取用重跑扩展器、别名键上的显式绑定不可达。
 */
final class ResidentHardeningTest extends TestCase
{
    public function testExtendAppliesImmediatelyToAlreadyResolvedSingleton(): void
    {
        $container = new Container();
        $container->singleton(Decorated::class, static fn(): Decorated => new Decorated('plain'));

        $first = $container->get(Decorated::class);
        $container->extend(Decorated::class, static fn(Decorated $d): Decorated => new Decorated($d->tag . '+ext'));

        $second = $container->get(Decorated::class);

        $this->assertSame('plain+ext', $second->tag);
        $this->assertNotSame($first, $second, '扩展器返回新对象时按替换语义处理');
        $this->assertSame($second, $container->get(Decorated::class), '替换后的实例须成为新的共享单例');
    }

    public function testInPlaceExtendKeepsSharedIdentity(): void
    {
        $container = new Container();
        $container->singleton(Decorated::class, static fn(): Decorated => new Decorated('plain'));

        $first = $container->get(Decorated::class);
        $container->extend(Decorated::class, static function (Decorated $d): void {
            $d->tag = 'touched';
        });

        $this->assertSame('touched', $first->tag);
        $this->assertSame($first, $container->get(Decorated::class));
    }

    public function testExtendOnResolvedScalarKeepsInstanceUntouched(): void
    {
        $container = new Container();
        $container->instance('config.value', 42);

        $seen = null;
        $container->extend('config.value', static function (mixed $value) use (&$seen) {
            $seen = $value;

            return null;
        });

        $this->assertSame(42, $container->get('config.value'));
        $this->assertNull($seen, '非对象实例不经过扩展器，与解析路径语义一致');
    }

    public function testExtendBeforeResolutionStillRunsOncePerResolve(): void
    {
        $container = new Container();
        $calls = 0;
        $container->singleton(Decorated::class, static fn(): Decorated => new Decorated('plain'));
        $container->extend(Decorated::class, static function (Decorated $d) use (&$calls): Decorated {
            $calls++;

            return $d;
        });

        $container->get(Decorated::class);
        $container->get(Decorated::class);

        $this->assertSame(1, $calls);
    }

    public function testCachedSingletonIsTheDecoratedInstance(): void
    {
        $container = new Container();
        $container->singleton(Decorated::class, static fn(): Decorated => new Decorated('plain'));
        $container->extend(Decorated::class, static fn(Decorated $d): Decorated => new Decorated($d->tag . '+ext'));

        $first = $container->get(Decorated::class);
        $second = $container->get(Decorated::class);

        $this->assertSame('plain+ext', $second->tag, '缓存必须写入装饰后的实例，否则第二次取用绕过扩展器');
        $this->assertSame($first, $second);
    }

    public function testFailingExtenderLeavesNoPartiallyDecoratedCache(): void
    {
        $container = new Container();
        $container->singleton(Decorated::class, static fn(): Decorated => new Decorated('plain'));
        $container->extend(Decorated::class, static function (Decorated $d): Decorated {
            throw new \RuntimeException('扩展失败');
        });

        try {
            $container->get(Decorated::class);
            $this->fail('扩展器异常应向外抛出');
        } catch (\RuntimeException $e) {
            $this->assertSame('扩展失败', $e->getMessage());
        }

        $this->assertFalse($container->resolved(Decorated::class), '扩展器失败后不得留下缓存');
        $this->assertFalse($container->getBinding(Decorated::class)->isResolved());
    }

    public function testExtendOnLazyBindingAppliesAtRealizationWithoutTypeError(): void
    {
        $container = new Container();
        $container->lazy(Decorated::class, static fn(): Decorated => new Decorated('lazy'));

        $proxy = $container->get(Decorated::class);
        $container->extend(Decorated::class, static fn(Decorated $d): Decorated => new Decorated($d->tag . '+ext'));

        $this->assertSame('lazy+ext', $proxy->tag, '扩展器应在懒加载代理实现时生效');
    }

    public function testExtendOnLazyBindingBeforeFirstResolveApplies(): void
    {
        $container = new Container();
        $container->lazy(Decorated::class, static fn(): Decorated => new Decorated('lazy'));
        $container->extend(Decorated::class, static fn(Decorated $d): Decorated => new Decorated($d->tag . '+ext'));

        $this->assertSame('lazy+ext', $container->get(Decorated::class)->tag);
    }

    public function testInterfaceIdSharesCachedInstanceWithSingletonImplementation(): void
    {
        $container = new Container();
        $container->singleton(ResidentRepoImpl::class);
        $calls = 0;
        $container->extend(ResidentRepo::class, static function (ResidentRepo $c) use (&$calls): ResidentRepo {
            $calls++;

            return new ResidentRepoDecorator($c);
        });

        $first = $container->get(ResidentRepo::class);
        $second = $container->get(ResidentRepo::class);

        $this->assertSame(1, $calls, '实现为单例时接口 id 上的扩展器只能跑一次');
        $this->assertSame($first, $second, '同一单例经接口取用须保持身份');
        $this->assertTrue($container->resolved(ResidentRepo::class));
        $this->assertSame($container->get(ResidentRepoImpl::class), $first->inner);
    }

    public function testInterfaceWithUnboundImplementationStaysPrototype(): void
    {
        $container = new Container();
        $container->extend(ResidentRepo::class, static fn(ResidentRepo $c): ResidentRepo => new ResidentRepoDecorator($c));

        $this->assertNotSame($container->get(ResidentRepo::class), $container->get(ResidentRepo::class));
    }

    public function testExplicitBindingUnderAliasKeyIsReachable(): void
    {
        $container = new Container();
        $container->alias('named', Decorated::class);
        $container->singleton('named', static fn(): Decorated => new Decorated('via-alias'));

        $this->assertTrue($container->bound('named'));
        $this->assertSame('via-alias', $container->get('named')->tag, '显式绑定优先于别名，条目不得静默失效');
        $this->assertFalse($container->bound(Decorated::class), '绑定留在别名键上');

        // 移除显式绑定后别名恢复生效
        $container->forget('named');
        $this->assertFalse($container->bound('named'));
    }

    public function testInstanceUnderAliasKeyWinsOverAlias(): void
    {
        $container = new Container();
        $instance = new Decorated('direct');
        $container->alias('inst', Decorated::class);
        $container->instance('inst', $instance);

        $this->assertSame($instance, $container->get('inst'));
        $this->assertFalse($container->bound(Decorated::class), '实例注册在别名键上而非目标 id');
    }

    public function testExtendViaAliasTargetsResolvedInstance(): void
    {
        $container = new Container();
        $container->singleton(Decorated::class, static fn(): Decorated => new Decorated('plain'));
        $container->alias('deco', Decorated::class);
        $container->get(Decorated::class);

        $container->extend('deco', static fn(Decorated $d): Decorated => new Decorated($d->tag . '+alias'));

        $this->assertSame('plain+alias', $container->get('deco')->tag);
    }

    public function testSelfAliasIsRejected(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('别名成环');

        $container->alias('loop', 'loop');
    }

    public function testAliasCycleIsRejectedAtDeclaration(): void
    {
        $container = new Container();
        $container->alias('a', 'b');

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('别名成环');

        $container->alias('b', 'a');
    }

    public function testLongAliasChainResolvesAndStaysBounded(): void
    {
        $container = new Container();
        $container->instance('leaf', 'value');

        // a0 -> a1 -> ... -> a9 -> leaf
        $previous = 'leaf';
        for ($i = 9; $i >= 0; $i--) {
            $container->alias("a{$i}", $previous);
            $previous = "a{$i}";
        }

        $this->assertSame('value', $container->get('a0'));
    }

    public function testFrozenContainerStillRejectsLateExtend(): void
    {
        $container = new Container();
        $container->singleton(Decorated::class, static fn(): Decorated => new Decorated('plain'));
        $container->get(Decorated::class);
        $container->freeze();

        $this->expectException(\LogicException::class);
        $container->extend(Decorated::class, static fn(Decorated $d): Decorated => $d);
    }

    /**
     * 上下文隔离绑定不进 instances 缓存，此前也不置循环守卫标记，
     * 于是互相依赖的两个上下文服务会把 worker 递归到内存耗尽（普通绑定只抛异常）。
     */
    public function testContextualCycleIsDetectedLikePlainBinding(): void
    {
        $container = new Container();
        $container->contextual(CycleA::class, CycleA::class);
        $container->contextual(CycleB::class, CycleB::class);

        try {
            $container->get(CycleA::class);
            $this->fail('上下文绑定的循环依赖必须抛 ContainerException，而不是无限递归');
        } catch (ContainerException $e) {
            $this->assertStringContainsString('循环依赖', $e->getMessage());
        }
    }

    /** 守卫按调用栈粒度生效：菱形依赖（两条分支共用同一上下文服务）不得被误判成环。 */
    public function testContextualDiamondStillResolves(): void
    {
        $container = new Container();
        $container->contextual(SharedLeaf::class, SharedLeaf::class);

        $left = $container->get(LeftNode::class);
        $right = $container->get(RightNode::class);

        $this->assertInstanceOf(SharedLeaf::class, $left->leaf);
        $this->assertInstanceOf(SharedLeaf::class, $right->leaf);
    }

    /** required:false 配显式 id：服务未注册时跳过注入（README 的 cache.ttl 示例语义）。 */
    public function testOptionalInjectSkipsMissingService(): void
    {
        $container = new Container();
        $host = $container->get(InjectHost::class);
        $this->assertSame('unset', $host->ttl, '未注册的可选依赖应保持属性原值');

        $container->singleton('cache.ttl', static fn(): int => 300);
        $this->assertSame(300, $container->get(InjectHost::class)->ttl, '注册后即应注入真值');
    }

    /** 可选性只吞「服务未找到」，默认 required:true 必须照抛。 */
    public function testRequiredInjectStillThrowsOnMissingService(): void
    {
        $container = new Container();

        $this->expectException(\Kode\DI\Exception\ServiceNotFoundException::class);
        $container->get(RequiredInjectHost::class);
    }
}

interface ResidentRepo
{
}

final class ResidentRepoImpl implements ResidentRepo
{
}

final class ResidentRepoDecorator implements ResidentRepo
{
    public function __construct(public ResidentRepo $inner)
    {
    }
}

final class Decorated
{
    public function __construct(public string $tag)
    {
    }
}

final class CycleA
{
    public function __construct(public CycleB $b)
    {
    }
}

final class CycleB
{
    public function __construct(public CycleA $a)
    {
    }
}

final class SharedLeaf
{
}

final class LeftNode
{
    public function __construct(public SharedLeaf $leaf)
    {
    }
}

final class RightNode
{
    public function __construct(public SharedLeaf $leaf)
    {
    }
}

final class InjectHost
{
    #[\Kode\DI\Attributes\Inject(id: 'cache.ttl', required: false)]
    public mixed $ttl = 'unset';
}

final class RequiredInjectHost
{
    #[\Kode\DI\Attributes\Inject(id: 'cache.ttl')]
    public mixed $ttl = 'unset';
}
