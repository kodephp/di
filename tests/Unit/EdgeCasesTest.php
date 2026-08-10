<?php

declare(strict_types=1);

namespace Kode\DI\Tests\Unit;

use Kode\DI\Container;
use Kode\DI\ContainerHelper;
use Kode\DI\Exception\ContainerException;
use Kode\DI\Exception\ServiceNotFoundException;
use Kode\DI\ServiceProvider;
use PHPUnit\Framework\TestCase;

/**
 * 边界与健壮性测试
 */
class EdgeCasesTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
        Container::clearCache();
    }

    // ---------------------------------------------------------------
    // 解析钩子：resolving / afterResolving / rebinding 为观察者语义
    // ---------------------------------------------------------------

    public function testResolvingCallbackDoesNotSwallowInstance(): void
    {
        $touched = [];

        // 回调无返回值，实例不应被吞成 null
        $this->container->resolving(EcCounter::class, function ($instance) use (&$touched): void {
            $touched[] = 'resolving';
            $instance->value = 10;
        });

        $instance = $this->container->get(EcCounter::class);

        $this->assertInstanceOf(EcCounter::class, $instance);
        $this->assertSame(10, $instance->value);
        $this->assertSame(['resolving'], $touched);
    }

    public function testAfterResolvingRunsAfterResolving(): void
    {
        $order = [];

        $this->container->afterResolving(EcCounter::class, function () use (&$order): void {
            $order[] = 'after';
        });
        $this->container->resolving(EcCounter::class, function () use (&$order): void {
            $order[] = 'resolving';
        });

        $this->container->get(EcCounter::class);

        $this->assertSame(['resolving', 'after'], $order);
    }

    public function testExtenderCanReplaceInstanceWhileResolvingCannot(): void
    {
        $this->container->bind(EcContract::class, EcPrimary::class);

        $this->container->extend(EcContract::class, fn() => new EcSecondary());
        $this->container->resolving(EcContract::class, fn() => new EcPrimary());

        $this->assertInstanceOf(EcSecondary::class, $this->container->get(EcContract::class));
    }

    public function testRebindingIsObserverOnly(): void
    {
        $seen = null;

        $this->container->rebinding(EcCounter::class, function ($instance) use (&$seen): void {
            $seen = $instance;
        });

        $resolved = $this->container->get(EcCounter::class);

        $this->assertSame($resolved, $seen);
    }

    // ---------------------------------------------------------------
    // forget / flush 清理完整性
    // ---------------------------------------------------------------

    public function testForgetClearsHooksAndContextual(): void
    {
        $calls = 0;

        $this->container->singleton(EcContract::class, EcPrimary::class);
        $this->container->resolving(EcContract::class, function () use (&$calls): void {
            $calls++;
        });

        $this->container->get(EcContract::class);
        $this->assertSame(1, $calls);

        $this->container->forget(EcContract::class);
        $this->container->singleton(EcContract::class, EcPrimary::class);
        $this->container->get(EcContract::class);

        $this->assertSame(1, $calls, 'forget 后旧的解析回调不应再触发');
    }

    public function testFlushResetsEverything(): void
    {
        $this->container->singleton(EcContract::class, EcPrimary::class);
        $this->container->alias('primary', EcContract::class);
        $this->container->get(EcContract::class);

        $this->container->flush();

        $this->assertSame([], $this->container->getBindings());
        $this->assertSame([], $this->container->getAliases());
        $this->assertFalse($this->container->resolved(EcContract::class));
    }

    // ---------------------------------------------------------------
    // 参数解析边界
    // ---------------------------------------------------------------

    public function testVariadicConstructorParameterDefaultsToEmptyArray(): void
    {
        $instance = $this->container->get(EcVariadic::class);

        $this->assertSame([], $instance->items);
    }

    public function testBuiltinParameterWithDefaultIsUsed(): void
    {
        $instance = $this->container->get(EcWithDefaults::class);

        $this->assertSame('fallback', $instance->name);
        $this->assertSame(42, $instance->size);
    }

    public function testPassedParametersOverrideAutowiring(): void
    {
        $instance = $this->container->make(EcWithDefaults::class, ['name' => 'custom']);

        $this->assertSame('custom', $instance->name);
        $this->assertSame(42, $instance->size);
    }

    public function testUnresolvableRequiredBuiltinThrows(): void
    {
        $this->expectException(ContainerException::class);

        $this->container->get(EcRequiresScalar::class);
    }

    public function testNonInstantiableClassThrows(): void
    {
        $this->expectException(ContainerException::class);

        $this->container->get(EcAbstract::class);
    }

    public function testUnknownClassThrowsServiceNotFound(): void
    {
        $this->expectException(ServiceNotFoundException::class);

        $this->container->get('Kode\\DI\\Tests\\Unit\\ThisClassDoesNotExist');
    }

    // ---------------------------------------------------------------
    // 闭包 / 可调用对象注入
    // ---------------------------------------------------------------

    public function testCallInjectsClosureDependencies(): void
    {
        $this->container->bind(EcContract::class, EcPrimary::class);

        $result = $this->container->call(function (EcContract $dep, string $suffix = '!') {
            return $dep->name() . $suffix;
        });

        $this->assertSame('primary!', $result);
    }

    public function testCallWithObjectInstanceMethod(): void
    {
        $this->container->bind(EcContract::class, EcPrimary::class);

        $service = new EcConsumer();
        $result = $this->container->call([$service, 'describe']);

        $this->assertSame('consumer:primary', $result);
    }

    public function testCallWithInvalidArrayCallableThrows(): void
    {
        $this->expectException(ContainerException::class);

        /** @phpstan-ignore-next-line 故意传入非法结构 */
        $this->container->call([123, 'describe']);
    }

    // ---------------------------------------------------------------
    // 标签 / 工厂 / 条件绑定
    // ---------------------------------------------------------------

    public function testTaggedResolvesAllTaggedServices(): void
    {
        $this->container->bind(EcPrimary::class);
        $this->container->bind(EcSecondary::class);
        $this->container->tag('impl', [EcPrimary::class, EcSecondary::class]);

        $tagged = $this->container->tagged('impl');

        $this->assertCount(2, $tagged);
        $this->assertInstanceOf(EcPrimary::class, $tagged[EcPrimary::class]);
        $this->assertInstanceOf(EcSecondary::class, $tagged[EcSecondary::class]);
    }

    public function testFactoryProducesFreshCallable(): void
    {
        $this->container->prototype(EcCounter::class);

        $factory = $this->container->factory(EcCounter::class);

        $this->assertNotSame($factory(), $factory());
    }

    public function testIfHelperRunsMatchingBranch(): void
    {
        $branch = null;

        $this->container->if(
            true,
            function () use (&$branch): void {
                $branch = 'true';
            },
            function () use (&$branch): void {
                $branch = 'false';
            }
        );

        $this->assertSame('true', $branch);

        $this->container->if(
            false,
            function () use (&$branch): void {
                $branch = 'true';
            },
            function () use (&$branch): void {
                $branch = 'false';
            }
        );

        $this->assertSame('false', $branch);
    }

    // ---------------------------------------------------------------
    // 别名链 / 循环依赖信息
    // ---------------------------------------------------------------

    public function testNestedAliasChainResolves(): void
    {
        $this->container->singleton(EcContract::class, EcPrimary::class);
        $this->container->alias('a', EcContract::class);
        $this->container->alias('b', 'a');

        $this->assertSame(
            $this->container->get(EcContract::class),
            $this->container->get('b')
        );
    }

    public function testCircularDependencyMessageContainsChain(): void
    {
        try {
            $this->container->get(EcCircularA::class);
            $this->fail('应抛出循环依赖异常');
        } catch (ContainerException $e) {
            $this->assertStringContainsString('EcCircularA', $e->getMessage());
            $this->assertStringContainsString('EcCircularB', $e->getMessage());
        }
    }

    // ---------------------------------------------------------------
    // 服务提供者
    // ---------------------------------------------------------------

    public function testProviderBootRunsOnce(): void
    {
        EcBootProvider::$bootCount = 0;

        $this->container->registerProvider(EcBootProvider::class);
        $this->container->bootProviders();
        $this->container->bootProviders();

        $this->assertSame(1, EcBootProvider::$bootCount);
    }

    public function testDuplicateProviderRegistrationIsIgnored(): void
    {
        EcBootProvider::$registerCount = 0;

        $this->container->registerProviders([EcBootProvider::class, EcBootProvider::class]);

        $this->assertSame(1, EcBootProvider::$registerCount);
    }

    // ---------------------------------------------------------------
    // 静态门面
    // ---------------------------------------------------------------

    public function testContainerHelperFacade(): void
    {
        ContainerHelper::setInstance($this->container);

        ContainerHelper::singleton(EcContract::class, EcPrimary::class);

        $this->assertInstanceOf(EcPrimary::class, ContainerHelper::get(EcContract::class));
        $this->assertInstanceOf(EcPrimary::class, ContainerHelper::make(EcContract::class));
        $this->assertTrue(ContainerHelper::has(EcContract::class));
    }

    // ---------------------------------------------------------------
    // 交叉类型
    // ---------------------------------------------------------------

    public function testIntersectionTypeResolvesInstanceSatisfyingAllConstraints(): void
    {
        // EcAlpha 绑定到只实现 Alpha 的类（不满足交叉约束）
        $this->container->singleton(EcAlpha::class, EcOnlyAlpha::class);
        // EcBeta 绑定到同时实现 Alpha+Beta 的类（满足交叉约束）
        $this->container->singleton(EcBeta::class, EcBoth::class);

        $consumer = $this->container->get(EcIntersectionConsumer::class);

        $this->assertInstanceOf(EcBoth::class, $consumer->value);
        $this->assertInstanceOf(EcAlpha::class, $consumer->value);
        $this->assertInstanceOf(EcBeta::class, $consumer->value);
    }

    public function testIntersectionTypeFallsBackWhenNoCandidateSatisfies(): void
    {
        $this->container->singleton(EcAlpha::class, EcOnlyAlpha::class);
        $this->container->singleton(EcBeta::class, EcOnlyBeta::class);

        // 没有候选同时满足 Alpha&Beta，应回退到默认值 null
        $consumer = $this->container->get(EcIntersectionConsumer::class);

        $this->assertNull($consumer->value);
    }

    // ---------------------------------------------------------------
    // 条件注册
    // ---------------------------------------------------------------

    public function testConditionalBindingAcceptsBooleanAndClosure(): void
    {
        $this->container->if(true, fn($c) => $c->instance('a', new \stdClass()));
        $this->container->if(false, fn($c) => $c->instance('b', new \stdClass()));

        $this->assertTrue($this->container->bound('a'));
        $this->assertFalse($this->container->bound('b'));

        // 闭包条件延迟求值，且能拿到容器
        $this->container->if(
            fn($c) => $c->bound('a'),
            fn($c) => $c->instance('c', new \stdClass()),
            fn($c) => $c->instance('d', new \stdClass())
        );

        $this->assertTrue($this->container->bound('c'));
        $this->assertFalse($this->container->bound('d'));
    }

    public function testEnvironmentAcceptsInjectedEnvSource(): void
    {
        $this->container->environment(
            ['dev', 'testing'],
            fn($c) => $c->instance('env.hit', new \stdClass()),
            ['APP_ENV' => 'testing']
        );

        $this->container->environment(
            'production',
            fn($c) => $c->instance('env.miss', new \stdClass()),
            ['APP_ENV' => 'testing']
        );

        $this->assertTrue($this->container->bound('env.hit'));
        $this->assertFalse($this->container->bound('env.miss'));
    }
}

// -------------------------------------------------------------------
// 测试夹具
// -------------------------------------------------------------------

interface EcContract
{
    public function name(): string;
}

class EcPrimary implements EcContract
{
    public function name(): string
    {
        return 'primary';
    }
}

class EcSecondary implements EcContract
{
    public function name(): string
    {
        return 'secondary';
    }
}

class EcCounter
{
    public int $value = 0;
}

class EcConsumer
{
    public function describe(EcContract $dep): string
    {
        return 'consumer:' . $dep->name();
    }
}

class EcVariadic
{
    /** @var array<int, mixed> */
    public array $items;

    public function __construct(mixed ...$items)
    {
        $this->items = $items;
    }
}

class EcWithDefaults
{
    public function __construct(
        public string $name = 'fallback',
        public int $size = 42
    ) {
    }
}

class EcRequiresScalar
{
    public function __construct(public string $required)
    {
    }
}

abstract class EcAbstract
{
}

class EcCircularA
{
    public function __construct(public EcCircularB $b)
    {
    }
}

class EcCircularB
{
    public function __construct(public EcCircularA $a)
    {
    }
}

class EcBootProvider extends ServiceProvider
{
    public static int $bootCount = 0;

    public static int $registerCount = 0;

    public function register(): void
    {
        self::$registerCount++;
    }

    public function boot(): void
    {
        self::$bootCount++;
    }
}

interface EcAlpha
{
}

interface EcBeta
{
}

class EcOnlyAlpha implements EcAlpha
{
}

class EcOnlyBeta implements EcBeta
{
}

class EcBoth implements EcAlpha, EcBeta
{
}

class EcIntersectionConsumer
{
    public function __construct(public (EcAlpha&EcBeta)|null $value = null)
    {
    }
}
