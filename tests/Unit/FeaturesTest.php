<?php

declare(strict_types=1);

namespace Kode\DI\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Kode\DI\Container;
use Kode\DI\ServiceProvider;
use Kode\DI\Attributes\Singleton;
use Kode\DI\Attributes\Autowire;
use Kode\DI\Exception\ServiceNotFoundException;

final class FeaturesTest extends TestCase
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

    // ---- 上下文绑定 when()->needs()->give() ----

    public function testContextualBindingFluent(): void
    {
        $this->container->singleton(Logger::class, FileLogger::class);
        $this->container->when(CtrlA::class)->needs(Logger::class)->give(DbLogger::class);

        $ctrl = $this->container->get(CtrlA::class);

        $this->assertInstanceOf(DbLogger::class, $ctrl->logger);
    }

    public function testContextualBindingClosure(): void
    {
        $this->container->when(CtrlA::class)->needs(Logger::class)
            ->give(fn() => new DbLogger());

        $ctrl = $this->container->get(CtrlA::class);

        $this->assertInstanceOf(DbLogger::class, $ctrl->logger);
    }

    public function testContextualBindingViaContainerLevelCalls(): void
    {
        $this->container->when(CtrlA::class);
        $this->container->needs(Logger::class);
        $this->container->give(DbLogger::class);

        $ctrl = $this->container->get(CtrlA::class);

        $this->assertInstanceOf(DbLogger::class, $ctrl->logger);
    }

    public function testContextualBindingThrowsWithoutWhen(): void
    {
        $this->expectException(\LogicException::class);

        $this->container->needs(Logger::class);
    }

    // ---- 属性驱动的生命周期 ----

    public function testAttributeSingletonCachesInstance(): void
    {
        $a = $this->container->get(SingletonService::class);
        $b = $this->container->get(SingletonService::class);

        $this->assertSame($a, $b);
    }

    public function testAttributePrototypeCreatesNewInstances(): void
    {
        $this->container->singleton(Logger::class, FileLogger::class);

        $a = $this->container->get(PrototypeController::class);
        $b = $this->container->get(PrototypeController::class);

        $this->assertNotSame($a, $b);
        $this->assertInstanceOf(FileLogger::class, $a->logger);
    }

    public function testContextualLifecycleUsesKodeContext(): void
    {
        // kode/context 作为 require-dev 引入后，contextual 生命周期会走真实的上下文隔离。
        // 同进程内对同一 contextual 服务的多次解析应命中上下文缓存，返回同一实例
        // （若 kode/context 不可用则回退为每次重建，断言会失败，从而反向验证集成生效）。
        $this->container->contextual(ContextualService::class);

        $instanceA = $this->container->get(ContextualService::class);
        $instanceB = $this->container->get(ContextualService::class);

        $this->assertSame($instanceA, $instanceB);
    }

    // ---- 联合 / 交叉类型 ----

    public function testUnionTypeResolvesFirstResolvable(): void
    {
        $this->container->singleton(UnionFoo::class, UnionFooImpl::class);

        $consumer = $this->container->get(UnionConsumer::class);

        $this->assertInstanceOf(UnionFooImpl::class, $consumer->foo);
    }

    public function testNullableUnionResolvesToNullWhenUnbound(): void
    {
        $consumer = $this->container->get(NullableConsumer::class);

        $this->assertNull($consumer->foo);
    }

    // ---- bindMethod 集成 ----

    public function testBindMethodOverridesCall(): void
    {
        $this->container->instance(MethodTarget::class, new MethodTarget());

        $this->container->bindMethod(MethodTarget::class . '::greet', function ($instance, $c) {
            return 'overridden';
        });

        $result = $this->container->call([MethodTarget::class, 'greet'], ['name' => 'x']);

        $this->assertSame('overridden', $result);
    }

    public function testBindMethodWithoutBindingCallsNormally(): void
    {
        $this->container->instance(MethodTarget::class, new MethodTarget());

        $result = $this->container->call([MethodTarget::class, 'greet'], ['name' => 'World']);

        $this->assertSame('hi World', $result);
    }

    // ---- 服务提供者（含延迟加载） ----

    public function testImmediateServiceProvider(): void
    {
        $this->container->registerProvider(ImmediateProvider::class);
        $this->container->bootProviders();

        $this->assertInstanceOf(ArrayCacheStore::class, $this->container->get(CacheContract::class));
    }

    public function testDeferredServiceProviderAutoLoadsOnMiss(): void
    {
        $this->container->registerProvider(DeferredProvider::class);

        // 延迟提供者未注册时 has() 仍应识别其可提供的服务
        $this->assertTrue($this->container->has(CacheContract::class));

        $instance = $this->container->get(CacheContract::class);

        $this->assertInstanceOf(ArrayCacheStore::class, $instance);
    }

    // ---- 懒加载代理 ----

    public function testLazyProxyIsCachedAndInstantiatedOnce(): void
    {
        Heavy::reset();

        $this->container->lazy('heavy', fn() => new Heavy());

        $p1 = $this->container->get('heavy');
        $p2 = $this->container->get('heavy');

        $this->assertSame($p1, $p2, '懒加载代理应只创建一次');

        // 首次访问才真正实例化
        $this->assertSame('heavy', $p1->name());
        $this->assertSame('heavy', $p2->name());

        $this->assertSame(1, Heavy::$count, '底层实例应只实例化一次');
    }

    public function testLazyProxySupportsArrayAccess(): void
    {
        $this->container->lazy('bag', fn() => new Bag());

        $bag = $this->container->get('bag');
        $bag['key'] = 'value';

        $this->assertSame('value', $bag['key']);
        $this->assertTrue(isset($bag['key']));
    }

    // ---- 幂等绑定 ----

    public function testBindIfDoesNotOverrideExisting(): void
    {
        $this->container->singleton('svc', fn() => new \stdClass());
        $first = $this->container->get('svc');

        $this->container->singletonIf('svc', fn() => new \stdClass());
        $again = $this->container->get('svc');

        $this->assertSame($first, $again);
        $this->assertTrue($this->container->bound('svc'));
    }

    public function testInstanceIfDoesNotOverrideExisting(): void
    {
        $a = new \stdClass();
        $this->container->instance('obj', $a);

        $b = new \stdClass();
        $this->container->instanceIf('obj', $b);

        $this->assertSame($a, $this->container->get('obj'));
    }

    // ---- 可注入环境 ----

    public function testEnvironmentWithInjectedEnv(): void
    {
        $this->container->environment('testing', function (Container $c) {
            $c->singleton('envMode', fn() => 'testing');
        }, ['APP_ENV' => 'testing']);

        $this->assertSame('testing', $this->container->get('envMode'));
    }

    public function testEnvironmentSkipsWhenEnvMismatch(): void
    {
        $this->container->environment('testing', function (Container $c) {
            $c->singleton('envMode', fn() => 'testing');
        }, ['APP_ENV' => 'production']);

        $this->assertFalse($this->container->bound('envMode'));
    }

    // ---- #[Autowire] 属性注入 ----

    public function testAutowirePropertyInjection(): void
    {
        $this->container->singleton(DepContract::class, DepImpl::class);

        $service = $this->container->get(AutowireService::class);

        $this->assertInstanceOf(DepImpl::class, $service->getDep());
    }

    // ---- 相似建议 ----

    public function testNotFoundIncludesSuggestions(): void
    {
        $this->container->singleton('database', fn() => new \stdClass());

        $this->expectException(ServiceNotFoundException::class);
        $this->expectExceptionMessage('database');

        $this->container->get('databas');
    }
}

// ---- 辅助类 / 接口 ----

interface Logger
{
}
class FileLogger implements Logger
{
    public string $tag = 'file';
}
class DbLogger implements Logger
{
    public string $tag = 'db';
}
class CtrlA
{
    public function __construct(public Logger $logger)
    {
    }
}

#[Singleton]
class SingletonService
{
    public int $value = 0;
}

class ContextualService
{
}

class PrototypeController
{
    public function __construct(public Logger $logger)
    {
    }
}

interface UnionFoo
{
}
class UnionFooImpl implements UnionFoo
{
    public string $v = 'foo';
}
class UnionConsumer
{
    public function __construct(public UnionFoo $foo, public ?string $name = null)
    {
    }
}
class NullableConsumer
{
    public function __construct(public ?UnionFoo $foo = null)
    {
    }
}

class MethodTarget
{
    public function greet(string $name): string
    {
        return "hi $name";
    }
}

interface CacheContract
{
}
class ArrayCacheStore implements CacheContract
{
}
class ImmediateProvider extends ServiceProvider
{
    protected array $provides = [CacheContract::class];

    public function register(): void
    {
        $this->singleton(CacheContract::class, ArrayCacheStore::class);
    }
}
class DeferredProvider extends ServiceProvider
{
    protected bool $deferred = true;

    protected array $provides = [CacheContract::class];

    public function register(): void
    {
        $this->singleton(CacheContract::class, ArrayCacheStore::class);
    }
}

class Heavy
{
    public static int $count = 0;

    public function __construct()
    {
        self::$count++;
    }

    public function name(): string
    {
        return 'heavy';
    }

    public static function reset(): void
    {
        self::$count = 0;
    }
}

class Bag implements \ArrayAccess
{
    private array $items = [];

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->items[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }
}

interface DepContract
{
}
class DepImpl implements DepContract
{
}
class AutowireService
{
    #[Autowire]
    private DepContract $dep;

    public function __construct()
    {
    }

    public function getDep(): DepContract
    {
        return $this->dep;
    }
}
