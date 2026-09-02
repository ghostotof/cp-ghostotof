<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Experience\Presentation\Command;

use App\Portfolio\Experience\Domain\Repository\ExperienceTechnologyRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

final class AddExperienceTechnologyCommandTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $this->getEntityManager()->getConnection()->executeStatement('DELETE FROM experience_technology');
    }

    protected function tearDown(): void
    {
        $this->getEntityManager()->getConnection()->executeStatement('DELETE FROM experience_technology');
        parent::tearDown();
    }

    public function testAddsTechnologyWithGivenOptions(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute([
            '--name' => 'PHP',
            '--years' => '13.5',
            '--icon' => 'php',
            '--related-technology' => 'HTML / CSS / JavaScript',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('PHP', $tester->getDisplay());

        $technology = self::getContainer()->get(ExperienceTechnologyRepositoryInterface::class)->findOneByName('PHP');
        self::assertNotNull($technology);
        self::assertSame(13.5, $technology->getYears());
        self::assertSame('php', $technology->getIconKey());
        self::assertSame('HTML / CSS / JavaScript', $technology->getRelatedTechnologyName());
    }

    public function testAddsTechnologyWithoutOptionalFields(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute(['--name' => 'MySQL', '--years' => '13.5']);

        self::assertSame(0, $exitCode);

        $technology = self::getContainer()->get(ExperienceTechnologyRepositoryInterface::class)->findOneByName('MySQL');
        self::assertNotNull($technology);
        self::assertNull($technology->getIconKey());
        self::assertNull($technology->getRelatedTechnologyName());
    }

    public function testFailsWhenNameAlreadyUsed(): void
    {
        $tester = $this->commandTester();
        $tester->execute(['--name' => 'PHP', '--years' => '13.5']);

        $exitCode = $tester->execute(['--name' => 'PHP', '--years' => '1']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('existe déjà', $tester->getDisplay());
    }

    public function testFailsOnNonNumericYears(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute(['--name' => 'PHP', '--years' => 'not-a-number']);

        self::assertSame(1, $exitCode);
    }

    private function commandTester(): CommandTester
    {
        \assert(self::$kernel instanceof KernelInterface);
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:experience:add-technology'));
    }

    private function getEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
