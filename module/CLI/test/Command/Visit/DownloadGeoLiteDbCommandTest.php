<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\CLI\Command\Visit;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shlinkio\Shlink\CLI\Command\Visit\DownloadGeoLiteDbCommand;
use Shlinkio\Shlink\CLI\Exception\GeolocationDbUpdateFailedException;
use Shlinkio\Shlink\CLI\GeoLite\GeolocationDbUpdaterInterface;
use Shlinkio\Shlink\CLI\GeoLite\GeolocationResult;
use Shlinkio\Shlink\CLI\Util\ExitCodes;
use ShlinkioTest\Shlink\CLI\CliTestUtilsTrait;
use Symfony\Component\Console\Tester\CommandTester;

use function sprintf;

class DownloadGeoLiteDbCommandTest extends TestCase
{
    use CliTestUtilsTrait;

    private CommandTester $commandTester;
    private MockObject & GeolocationDbUpdaterInterface $dbUpdater;

    protected function setUp(): void
    {
        $this->dbUpdater = $this->createMock(GeolocationDbUpdaterInterface::class);
        $this->commandTester = $this->testerForCommand(new DownloadGeoLiteDbCommand($this->dbUpdater));
    }

    /**
     * @test
     * @dataProvider provideFailureParams
     */
    public function showsProperMessageWhenGeoLiteUpdateFails(
        bool $olderDbExists,
        string $expectedMessage,
        int $expectedExitCode,
    ): void {
        $this->dbUpdater->expects($this->once())->method('checkDbUpdate')->withAnyParameters()->willReturnCallback(
            function (callable $beforeDownload, callable $handleProgress) use ($olderDbExists): void {
                $beforeDownload($olderDbExists);
                $handleProgress(100, 50);

                throw $olderDbExists
                    ? GeolocationDbUpdateFailedException::withOlderDb()
                    : GeolocationDbUpdateFailedException::withoutOlderDb();
            },
        );

        $this->commandTester->execute([]);
        $output = $this->commandTester->getDisplay();
        $exitCode = $this->commandTester->getStatusCode();

        self::assertStringContainsString(
            sprintf('%s GeoLite2 db file...', $olderDbExists ? 'Updating' : 'Downloading'),
            $output,
        );
        self::assertStringContainsString($expectedMessage, $output);
        self::assertSame($expectedExitCode, $exitCode);
    }

    public static function provideFailureParams(): iterable
    {
        yield 'existing db' => [
            true,
            '[WARNING] GeoLite2 db file update failed. Visits will continue to be located',
            ExitCodes::EXIT_WARNING,
        ];
        yield 'not existing db' => [
            false,
            '[ERROR] GeoLite2 db file download failed. It will not be possible to locate',
            ExitCodes::EXIT_FAILURE,
        ];
    }

    /**
     * @test
     * @dataProvider provideSuccessParams
     */
    public function printsExpectedMessageWhenNoErrorOccurs(callable $checkUpdateBehavior, string $expectedMessage): void
    {
        $this->dbUpdater->expects($this->once())->method('checkDbUpdate')->withAnyParameters()->willReturnCallback(
            $checkUpdateBehavior,
        );

        $this->commandTester->execute([]);
        $output = $this->commandTester->getDisplay();
        $exitCode = $this->commandTester->getStatusCode();

        self::assertStringContainsString($expectedMessage, $output);
        self::assertSame(ExitCodes::EXIT_SUCCESS, $exitCode);
    }

    public static function provideSuccessParams(): iterable
    {
        yield 'up to date db' => [fn () => GeolocationResult::CHECK_SKIPPED, '[INFO] GeoLite2 db file is up to date.'];
        yield 'outdated db' => [function (callable $beforeDownload): GeolocationResult {
            $beforeDownload(true);
            return GeolocationResult::DB_CREATED;
        }, '[OK] GeoLite2 db file properly downloaded.'];
    }
}
