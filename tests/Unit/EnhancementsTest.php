<?php

declare(strict_types=1);

namespace Kode\DI\Tests\Unit;

use Kode\DI\Attributes\Inject;
use Kode\DI\Container;
use Kode\DI\Exception\ServiceNotFoundException;
use PHPUnit\Framework\TestCase;

/**
 * 体验型 API / 健壮性 / 自动装配增强测试
 */
class EnhancementsTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
        Container::clearCache();
    }

    // ---------------------------------------------------------------
    // \ArrayAccess 原生数组语法
    // ---------------------------------------------------------------

    public function testArrayAccessGetAndExists(): void
    {
        $this->container->singleton(EnhSvc::class, EnhSvc::class);

        $this->assertTrue(isset($this->container[EnhSvc::class]));
        $this->assertInstanceOf(EnhSvc::class, $this->container[EnhSvc::class]);
    }

    public function testArrayAccessSetObjectBindsInstance(): void
    {
        $svc = new EnhSvc();
        $this->container[EnhSvc::class] = $svc;

        $this->assertSame($svc, $this->container[EnhSvc::class]);
    }

    public function testArrayAccessSetStringBindsConcrete(): void
    {
        $this->container[EnhSvc::class] = EnhSvc::class;

        $this->assertInstanceOf(EnhSvc::class, $this->container[EnhSvc::class]);
    }

    public function testArrayAccessSetClosureBindsFactory(): void
    {
        $this->container[EnhSvc::class] = static fn() => new EnhSvc();

        $this->assertInstanceOf(EnhSvc::class, $this->container[EnhSvc::class]);
    }

    public function testArrayAccessUnsetForgetsBinding(): void
    {
        $this->container->singleton(EnhSvc::class, EnhSvc::class);
        unset($this->container[EnhSvc::class]);

        $this->assertFalse($this->container->bound(EnhSvc::class));
    }

    // ---------------------------------------------------------------
    // refresh：强制重建单例
    // ---------------------------------------------------------------

    public function testRefreshRebuildsSingleton(): void
    {
        $this->container->singleton(EnhState::class, EnhState::class);

        $first = $this->container->get(EnhState::class);
        $first->value = 99;
        $this->assertSame($first, $this->container->get(EnhState::class));

        $rebuilt = $this->container->refresh(EnhState::class);
        $this->assertNotSame($first, $rebuilt);
        $this->assertSame(0, $rebuilt->value);
    }

    // ---------------------------------------------------------------
    // getOr：安全获取
    // ---------------------------------------------------------------

    public function testGetOrReturnsResolvedWhenBound(): void
    {
        $this->container->singleton(EnhSvc::class, EnhSvc::class);

        $this->assertInstanceOf(EnhSvc::class, $this->container->getOr(EnhSvc::class));
    }

    public function testGetOrReturnsDefaultWhenUnbound(): void
    {
        $this->assertNull($this->container->getOr('does-not-exist'));
        $this->assertSame('fallback', $this->container->getOr('does-not-exist', 'fallback'));
    }

    // ---------------------------------------------------------------
    // frozen：冻结模式
    // ---------------------------------------------------------------

    public function testFrozenAllowsReads(): void
    {
        $this->container->singleton(EnhSvc::class, EnhSvc::class);
        $this->container->freeze();

        $this->assertTrue($this->container->isFrozen());
        $this->assertTrue($this->container->has(EnhSvc::class));
        $this->assertInstanceOf(EnhSvc::class, $this->container->get(EnhSvc::class));
    }

    public function testFrozenBlocksAllMutations(): void
    {
        $this->container->singleton(EnhSvc::class, EnhSvc::class);
        $this->container->freeze();

        $mutations = [
            static fn(self $t) => $t->container->bind('x', 'y'),
            static fn(self $t) => $t->container->instance('x', new EnhSvc()),
            static fn(self $t) => $t->container->alias('a', 'b'),
            static fn(self $t) => $t->container->extend(EnhSvc::class, static fn($s) => $s),
            static fn(self $t) => $t->container->tag('t', [EnhSvc::class]),
            static fn(self $t) => $t->container->forget(EnhSvc::class),
            static fn(self $t) => $t->container->flush(),
            static fn(self $t) => $t->container->refresh(EnhSvc::class),
        ];

        foreach ($mutations as $i => $mut) {
            try {
                $mut($this);
                $this->fail("mutation #{$i} 应在冻结态下抛 LogicException");
            } catch (\LogicException $e) {
                $this->assertStringContainsString('冻结', $e->getMessage());
            }
        }
    }

    public function testFrozenBlocksRegisterProvider(): void
    {
        $this->container->freeze();

        $this->expectException(\LogicException::class);
        $this->container->registerProvider('EnhFakeProvider');
    }

    // ---------------------------------------------------------------
    // 全局 '*' 通配 resolving 回调
    // ---------------------------------------------------------------

    public function testGlobalWildcardResolvingCallback(): void
    {
        $touched = [];

        $this->container->resolving('*', static function ($instance) use (&$touched): void {
            $touched[] = get_class($instance);
        });

        $this->container->singleton(EnhSvc::class, EnhSvc::class);
        $this->container->singleton(EnhState::class, EnhState::class);
        $this->container->get(EnhSvc::class);
        $this->container->get(EnhState::class);

        $this->assertContains(EnhSvc::class, $touched);
        $this->assertContains(EnhState::class, $touched);
    }

    public function testGlobalWildcardAfterResolvingFiresAfterSpecific(): void
    {
        $order = [];

        $this->container->afterResolving(EnhSvc::class, static function () use (&$order): void {
            $order[] = 'specific';
        });
        $this->container->afterResolving('*', static function () use (&$order): void {
            $order[] = 'wildcard';
        });

        $this->container->singleton(EnhSvc::class, EnhSvc::class);
        $this->container->get(EnhSvc::class);

        $this->assertSame(['specific', 'wildcard'], $order);
    }

    // ---------------------------------------------------------------
    // 接口 / 抽象类自动定位具体实现
    // ---------------------------------------------------------------

    public function testInterfaceAutoResolvesToImplByNamingConvention(): void
    {
        // EnhRepoInterface 未显式绑定，但 EnhRepo 存在（去 Interface 后缀命中）
        $repo = $this->container->get(EnhRepoInterface::class);

        $this->assertInstanceOf(EnhRepo::class, $repo);
    }

    public function testAbstractClassAutoResolvesToImpl(): void
    {
        // AbstractEnhWidget 抽象类，去 Abstract 前缀得 EnhWidget 无同名类，按约定命中 EnhWidgetImpl
        $impl = $this->container->get(AbstractEnhWidget::class);

        $this->assertInstanceOf(EnhWidgetImpl::class, $impl);
    }

    public function testAutoResolveCanBeDisabled(): void
    {
        $this->container->setAutoResolveImplementations(false);

        $this->expectException(ServiceNotFoundException::class);
        $this->container->get(EnhRepoInterface::class);
    }

    // ---------------------------------------------------------------
    // 枚举参数友好解析
    // ---------------------------------------------------------------

    public function testEnumParameterFallsBackWhenNullableAndUnbound(): void
    {
        // EnhColor 枚举未绑定且可空 -> 回退 null（不尝试 new 枚举）
        $consumer = $this->container->get(EnhEnumConsumer::class);

        $this->assertNull($consumer->color);
    }

    public function testBoundEnumInstanceResolves(): void
    {
        $this->container->instance(EnhColor::class, EnhColor::Red);

        $consumer = $this->container->get(EnhEnumConsumer::class);

        $this->assertSame(EnhColor::Red, $consumer->color);
    }

    // ---------------------------------------------------------------
    // call() 参数读取 #[Inject]
    // ---------------------------------------------------------------

    public function testCallReadsInjectAttributeOnParameter(): void
    {
        $this->container->singleton(EnhSvc::class, EnhSvc::class);

        $result = $this->container->call([new EnhCaller(), 'useSvc']);

        $this->assertInstanceOf(EnhSvc::class, $result);
    }

    // ---------------------------------------------------------------
    // 环境 / 条件绑定
    // ---------------------------------------------------------------

    public function testEnvironmentCallbackFiresOnMatch(): void
    {
        $fired = false;

        $this->container->environment('testing', function (Container $c) use (&$fired): void {
            $fired = true;
            $c->singleton(EnhSvc::class, EnhSvc::class);
        }, ['APP_ENV' => 'testing']);

        $this->assertTrue($fired);
        $this->assertTrue($this->container->bound(EnhSvc::class));
    }

    public function testIfClosureCondition(): void
    {
        $fired = false;

        $this->container->if(static fn(Container $c) => $c->bound(EnhSvc::class), static function () use (&$fired): void {
            $fired = true;
        });
        $this->assertFalse($fired);

        $this->container->singleton(EnhSvc::class, EnhSvc::class);
        $this->container->if(static fn(Container $c) => $c->bound(EnhSvc::class), static function () use (&$fired): void {
            $fired = true;
        });

        $this->assertTrue($fired);
    }
}

// ---------------------------------------------------------------
// 辅助类 / 接口 / 枚举
// ---------------------------------------------------------------

class EnhSvc
{
}

class EnhState
{
    public int $value = 0;
}

interface EnhRepoInterface
{
}

class EnhRepo implements EnhRepoInterface
{
}

abstract class AbstractEnhWidget
{
}

class EnhWidgetImpl extends AbstractEnhWidget
{
}

enum EnhColor: string
{
    case Red = 'red';
    case Green = 'green';
}

class EnhEnumConsumer
{
    public function __construct(
        public ?EnhColor $color = null
    ) {
    }
}

class EnhCaller
{
    public function useSvc(#[Inject(EnhSvc::class)] EnhSvc $svc): EnhSvc
    {
        return $svc;
    }
}
