<?php

declare(strict_types=1);

namespace Kode\DI\Tests\Unit;

use Kode\DI\Attributes\Autowire;
use Kode\DI\Container;
use Kode\DI\Contract\ContainerInterface;
use PHPUnit\Framework\TestCase;

/**
 * 容器对外契约的加固回归（PSR-11 一致性与清空语义）
 *
 * 覆盖 v2.5.0 修复：
 *  - flush() 把自注册绑定一并清掉，之后 get('container') 抛「服务未找到」，
 *    而 get(self::class) 会经自动解析造出一个全新容器接管后续绑定（幽灵容器）；
 *  - has() 只看 bindings 与 class_exists，对「接口按命名约定定位到实现」这条
 *    get() 支持的路径报 false，导致框架侧「先 has 再 get」的门禁永久哑火；
 *  - 属性上的 #[Autowire(false)] 从不被读取，写 false 与不写等价。
 */
final class ContractHardeningTest extends TestCase
{
    public function testFlushKeepsSelfRegistration(): void
    {
        $container = new Container();
        $container->bind('probe.value', static fn(): string => 'x');

        $container->flush();

        $this->assertFalse($container->has('probe.value'), 'flush 应清掉业务绑定');
        $this->assertSame($container, $container->get('container'), 'flush 后自注册必须还在');
        $this->assertSame($container, $container->get(ContainerInterface::class));
        $this->assertSame($container, $container->get(Container::class), '不得再自动解析出第二个容器');
    }

    public function testBindingsAfterFlushLandOnTheSameContainer(): void
    {
        $container = new Container();
        $container->flush();
        $container->singleton(FlushDependent::class);

        $this->assertSame($container, $container->get(FlushDependent::class)->container);
    }

    public function testHasAgreesWithGetForConventionResolvedInterface(): void
    {
        $container = new Container();

        $this->assertTrue($container->has(ConvInterface::class), 'get() 能解析出的 id，has() 必须报 true');
        $this->assertInstanceOf(ConvImpl::class, $container->get(ConvInterface::class));
    }

    /** 关闭自动定位后，has() 与 get() 要同向收回，否则该开关只对 get 生效。 */
    public function testHasFollowsAutoResolveSwitch(): void
    {
        $container = new Container();
        $container->setAutoResolveImplementations(false);

        $this->assertFalse($container->has(ConvInterface::class));
    }

    public function testHasStillFalseForUnknownId(): void
    {
        $this->assertFalse((new Container())->has('No.Such.Service.Anywhere'));
    }

    public function testAutowireDisabledSkipsPropertyInjection(): void
    {
        $container = new Container();
        $container->singleton(ConvImpl::class);

        $skipped = $container->get(AutowireOffHost::class);
        $this->assertNull($skipped->dep, '#[Autowire(false)] 属性应保持未注入');

        $injected = $container->get(AutowireOnHost::class);
        $this->assertInstanceOf(ConvInterface::class, $injected->dep);
    }
}

final class FlushDependent
{
    #[Autowire]
    public ContainerInterface $container;
}

interface ConvInterface
{
}

final class ConvImpl implements ConvInterface
{
}

final class AutowireOffHost
{
    #[Autowire(false)]
    public ?ConvInterface $dep = null;
}

final class AutowireOnHost
{
    #[Autowire]
    public ?ConvInterface $dep = null;
}
