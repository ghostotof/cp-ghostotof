<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Presentation\Command;

use App\Portfolio\Watch\Application\WatchedProductAdministratorInterface;
use App\Portfolio\Watch\Domain\Repository\WatchedProductRepositoryInterface;
use App\Portfolio\Watch\Domain\ValueObject\VersionSource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pose le catalogue des produits dont la page /stack suit le cycle de vie.
 *
 * Attention : purge avant de recréer. Rejouer cette commande écrase les
 * produits ajoutés depuis le backoffice — elle sert à poser l'état de référence
 * sur un environnement neuf, pas à synchroniser une base éditée. Même
 * comportement que `app:incidents:seed` et `app:about:seed`.
 *
 * Les `slug` sont les identifiants du catalogue endoflife.date, et tous ont été
 * vérifiés à l'écriture de cette commande. Un slug fautif ne casserait pas la
 * page — l'entrée s'afficherait « inconnue » — mais il n'y a aucune raison d'en
 * laisser passer un.
 *
 * Les versions reflètent `../.env` / `versions.lock`, à deux exceptions près :
 * PHP et Symfony n'en portent pas, parce qu'elles sont lues dans le processus
 * au moment du rafraîchissement (décision D2). Les renseigner ici
 * réintroduirait exactement la version périmée que cette décision élimine.
 */
#[AsCommand(
    name: 'app:watch:seed',
    description: 'Pose le catalogue des produits suivis par la page de veille technique.',
)]
final class SeedWatchedProductsCommand extends Command
{
    public function __construct(
        private readonly WatchedProductRepositoryInterface $watchedProductRepository,
        private readonly WatchedProductAdministratorInterface $watchedProductAdministrator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        foreach ($this->watchedProductRepository->findAllOrdered() as $existing) {
            $this->watchedProductRepository->remove($existing);
        }

        // Passe par le cas d'usage plutôt que d'écrire en base directement,
        // comme les autres seeds du projet : la vérification d'unicité du slug
        // est inutile juste après la purge, mais elle protégerait le jour où
        // cette commande cesserait de purger.
        foreach ($this->catalog() as $position => [$slug, $label, $versionSource, $version]) {
            $this->watchedProductAdministrator->create($slug, $label, $versionSource, $version, $position);
        }

        $io->success(sprintf('%d produits surveillés posés.', \count($this->catalog())));

        return Command::SUCCESS;
    }

    /**
     * Ordonné comme la page l'affiche : le langage et son framework, puis la
     * base, puis le front, puis l'infrastructure.
     *
     * @return list<array{string, string, VersionSource, string|null}>
     */
    private function catalog(): array
    {
        return [
            ['php', 'PHP', VersionSource::RUNTIME_PHP, null],
            ['symfony', 'Symfony', VersionSource::RUNTIME_SYMFONY, null],
            ['postgresql', 'PostgreSQL', VersionSource::MANUAL, '18.4'],
            ['nodejs', 'Node.js', VersionSource::MANUAL, '26.7.0'],
            ['vue', 'Vue.js', VersionSource::MANUAL, '3.5.42'],
            ['nginx', 'nginx', VersionSource::MANUAL, '1.30.4'],
            ['rabbitmq', 'RabbitMQ', VersionSource::MANUAL, '4.3.4'],
        ];
    }
}
