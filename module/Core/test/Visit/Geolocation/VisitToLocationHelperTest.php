<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\Core\Visit\Geolocation;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shlinkio\Shlink\Common\Util\IpAddress;
use Shlinkio\Shlink\Core\Exception\IpCannotBeLocatedException;
use Shlinkio\Shlink\Core\Visit\Entity\Visit;
use Shlinkio\Shlink\Core\Visit\Geolocation\VisitToLocationHelper;
use Shlinkio\Shlink\Core\Visit\Model\Visitor;
use Shlinkio\Shlink\IpGeolocation\Exception\WrongIpException;
use Shlinkio\Shlink\IpGeolocation\Resolver\IpLocationResolverInterface;

class VisitToLocationHelperTest extends TestCase
{
    private VisitToLocationHelper $helper;
    private MockObject & IpLocationResolverInterface $ipLocationResolver;

    protected function setUp(): void
    {
        $this->ipLocationResolver = $this->createMock(IpLocationResolverInterface::class);
        $this->helper = new VisitToLocationHelper($this->ipLocationResolver);
    }

    /**
     * @test
     * @dataProvider provideNonLocatableVisits
     */
    public function throwsExpectedErrorForNonLocatableVisit(
        Visit $visit,
        IpCannotBeLocatedException $expectedException,
    ): void {
        $this->expectExceptionObject($expectedException);
        $this->ipLocationResolver->expects($this->never())->method('resolveIpLocation');

        $this->helper->resolveVisitLocation($visit);
    }

    public static function provideNonLocatableVisits(): iterable
    {
        yield [Visit::forBasePath(Visitor::emptyInstance()), IpCannotBeLocatedException::forEmptyAddress()];
        yield [
            Visit::forBasePath(new Visitor('foo', 'bar', IpAddress::LOCALHOST, '')),
            IpCannotBeLocatedException::forLocalhost(),
        ];
    }

    /** @test */
    public function throwsGenericErrorWhenResolvingIpFails(): void
    {
        $e = new WrongIpException('');

        $this->expectExceptionObject(IpCannotBeLocatedException::forError($e));
        $this->ipLocationResolver->expects($this->once())->method('resolveIpLocation')->willThrowException($e);

        $this->helper->resolveVisitLocation(Visit::forBasePath(new Visitor('foo', 'bar', '1.2.3.4', '')));
    }
}
