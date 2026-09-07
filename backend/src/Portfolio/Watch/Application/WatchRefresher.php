<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Application;

use App\Portfolio\Watch\Domain\Entity\WatchedProduct;
use App\Portfolio\Watch\Domain\Entity\WatchSnapshot;
use App\Portfolio\Watch\Domain\Exception\ReleaseCycleProductNotFoundException;
use App\Portfolio\Watch\Domain\Exception\ReleaseCycleSourceUnavailableException;
use App\Portfolio\Watch\Domain\Exception\VulnerabilitySourceUnavailableException;
use App\Portfolio\Watch\Domain\Repository\WatchedProductRepositoryInterface;
use App\Portfolio\Watch\Domain\Repository\WatchSnapshotRepositoryInterface;
use App\Portfolio\Watch\Domain\Service\InstalledVersionMatcher;
use App\Portfolio\Watch\Domain\Service\InstalledVersionResolverInterface;
use App\Portfolio\Watch\Domain\Service\PackageManifestReaderInterface;
use App\Portfolio\Watch\Domain\Service\ReleaseCycleSourceInterface;
use App\Portfolio\Watch\Domain\Service\SupportStatusCalculator;
use App\Portfolio\Watch\Domain\Service\VulnerabilitySourceInterface;
use App\Portfolio\Watch\Domain\ValueObject\KnownVulnerability;
use App\Portfolio\Watch\Domain\ValueObject\SnapshotSourceStatus;
use App\Portfolio\Watch\Domain\ValueObject\SupportStatus;
use App\Portfolio\Watch\Domain\ValueObject\WatchSnapshotType;
use Psr\Log\LoggerInterface;

/**
 * Rafraîchit les deux volets de la veille : les cycles de vie des versions, et
 * les vulnérabilités connues des paquets déployés.
 *
 * Les deux sont indépendants — instantanés distincts, sources distinctes — et
 * l'échec de l'un ne prive pas l'autre de sa mise à jour. C'est aussi pourquoi
 * le compte-rendu est structuré par volet plutôt qu'à plat.
 *
 * Deux échecs, deux traitements, sur le volet des cycles de vie :
 *
 *  - **produit inconnu de la source (404)** : erreur de contenu, réparable au
 *    backoffice. L'entrée est conservée dans le snapshot avec le statut
 *    « inconnu », les autres produits ne sont pas affectés, et la source n'est
 *    pas déclarée en panne — elle a répondu, correctement.
 *  - **source injoignable ou illisible** : panne. L'entrée est omise et le
 *    snapshot est marqué partiel.
 *
 * Et une règle qui prime sur les deux : **si rien n'a pu être rafraîchi, rien
 * n'est écrit**. Persister un payload « zéro produit » effacerait la page à la
 * première panne du fournisseur, alors que la donnée de la veille est encore
 * parfaitement lisible. C'est aussi pourquoi la vérification est faite ici et
 * pas seulement dans l'entité : `['products' => []]` n'est pas un tableau vide,
 * WatchSnapshot l'accepterait sans broncher.
 */
final readonly class WatchRefresher implements WatchRefresherInterface
{
    public function __construct(
        private WatchedProductRepositoryInterface $productRepository,
        private WatchSnapshotRepositoryInterface $snapshotRepository,
        private ReleaseCycleSourceInterface $releaseCycleSource,
        private InstalledVersionResolverInterface $versionResolver,
        private InstalledVersionMatcher $matcher,
        private SupportStatusCalculator $statusCalculator,
        private PackageManifestReaderInterface $packageManifestReader,
        private VulnerabilitySourceInterface $vulnerabilitySource,
        private LoggerInterface $logger,
    ) {
    }

    public function refresh(\DateTimeImmutable $now, bool $dryRun = false): WatchRefreshReport
    {
        return new WatchRefreshReport(
            $this->refreshReleaseCycles($now, $dryRun),
            $this->refreshVulnerabilities($now, $dryRun),
        );
    }

    private function refreshReleaseCycles(\DateTimeImmutable $now, bool $dryRun): ReleaseCyclesRefreshReport
    {
        $entries = [];
        $unknownSlugs = [];
        $failedSlugs = [];

        foreach ($this->productRepository->findAllOrdered() as $product) {
            $slug = $product->getSlug();
            $version = $this->installedVersionOf($product);

            if (null === $version) {
                $this->logger->warning('Produit surveillé sans version exploitable.', ['slug' => $slug]);
                $unknownSlugs[] = $slug;
                $entries[] = $this->unresolvedEntry($product, null);

                continue;
            }

            try {
                $cycles = $this->releaseCycleSource->fetchProduct($slug);
            } catch (ReleaseCycleProductNotFoundException $exception) {
                $this->logger->warning('Produit inconnu de la source de cycles de vie.', [
                    'slug' => $slug,
                    'exception' => $exception,
                ]);
                $unknownSlugs[] = $slug;
                $entries[] = $this->unresolvedEntry($product, $version);

                continue;
            } catch (ReleaseCycleSourceUnavailableException $exception) {
                $this->logger->error('Source de cycles de vie indisponible.', [
                    'slug' => $slug,
                    'exception' => $exception,
                ]);
                $failedSlugs[] = $slug;

                continue;
            }

            $cycle = $this->matcher->matchInstalledVersion($cycles, $version);

            $entries[] = [
                'slug' => $slug,
                'label' => $product->getLabel(),
                'version' => $version,
                'status' => $this->statusCalculator->statusFor($cycle, $now)->value,
                'cycle' => $cycle?->name,
                'endOfActiveSupportFrom' => $cycle?->endOfActiveSupportFrom?->format('Y-m-d'),
                'eolFrom' => $cycle?->eolFrom?->format('Y-m-d'),
                'latestVersion' => $cycle?->latestVersion,
                'hasNewerPatch' => $this->matcher->hasNewerPatch($cycle, $version),
                'documentationUrl' => $cycles->documentationUrl,
            ];
        }

        if ([] === $entries) {
            return new ReleaseCyclesRefreshReport(0, $unknownSlugs, $failedSlugs, false);
        }

        if ($dryRun) {
            return new ReleaseCyclesRefreshReport(\count($entries), $unknownSlugs, $failedSlugs, false);
        }

        $this->persist(
            WatchSnapshotType::RELEASE_CYCLES,
            ['products' => $entries],
            $now,
            [] === $failedSlugs ? SnapshotSourceStatus::OK : SnapshotSourceStatus::PARTIAL,
        );

        return new ReleaseCyclesRefreshReport(\count($entries), $unknownSlugs, $failedSlugs, true);
    }

    /**
     * Le snapshot conserve le **détail** des vulnérabilités, que seul le
     * backoffice affichera (décision D4) ; l'API publique n'en tire qu'un
     * décompte. Ce cloisonnement se joue à la lecture, dans le provider, et non
     * ici — écrire un snapshot amputé priverait l'administrateur de ce qu'il
     * est précisément le seul à avoir le droit de voir.
     */
    private function refreshVulnerabilities(\DateTimeImmutable $now, bool $dryRun): VulnerabilityRefreshReport
    {
        $manifest = $this->packageManifestReader->read();

        if (null === $manifest) {
            // Aucun manifeste : l'analyse n'a pas lieu d'être tentée. Ne rien
            // écrire est ce qui permettra à la page de dire « analyse non
            // effectuée » plutôt qu'un « 0 vulnérabilité » mensonger.
            $this->logger->info('Aucun manifeste de paquets : analyse de vulnérabilités ignorée.');

            return new VulnerabilityRefreshReport(null, 0, false, false);
        }

        $packagesScanned = \count($manifest->packages);

        try {
            $vulnerabilities = $this->vulnerabilitySource->findVulnerabilities($manifest->packages);
        } catch (VulnerabilitySourceUnavailableException $exception) {
            $this->logger->error('Base de vulnérabilités indisponible.', ['exception' => $exception]);

            return new VulnerabilityRefreshReport($packagesScanned, 0, false, true);
        }

        if ($dryRun) {
            return new VulnerabilityRefreshReport($packagesScanned, \count($vulnerabilities), false, false);
        }

        $this->persist(
            WatchSnapshotType::VULNERABILITIES,
            [
                'packagesScanned' => $packagesScanned,
                'vulnerabilities' => array_map($this->vulnerabilityToArray(...), $vulnerabilities),
            ],
            $now,
            SnapshotSourceStatus::OK,
        );

        return new VulnerabilityRefreshReport($packagesScanned, \count($vulnerabilities), true, false);
    }

    /**
     * @return array<string, mixed>
     */
    private function vulnerabilityToArray(KnownVulnerability $vulnerability): array
    {
        return [
            'id' => $vulnerability->id,
            'aliases' => $vulnerability->aliases,
            'summary' => $vulnerability->summary,
            'severity' => $vulnerability->severity,
            'package' => [
                'ecosystem' => $vulnerability->package->ecosystem,
                'name' => $vulnerability->package->name,
                'version' => $vulnerability->package->version,
            ],
            'fixedIn' => $vulnerability->fixedIn,
        ];
    }

    /**
     * Décision D2 : la version vient du processus pour PHP et Symfony, de la
     * saisie pour tout le reste.
     */
    private function installedVersionOf(WatchedProduct $product): ?string
    {
        return $product->getVersionSource()->isResolvedAtRuntime()
            ? $this->versionResolver->resolve($product->getVersionSource())
            : $product->getVersion();
    }

    /**
     * Entrée d'un produit dont on n'a pas pu établir le cycle : elle reste
     * affichée, avec un statut qui l'annonce, plutôt que de disparaître
     * silencieusement de la page.
     *
     * @return array<string, mixed>
     */
    private function unresolvedEntry(WatchedProduct $product, ?string $version): array
    {
        return [
            'slug' => $product->getSlug(),
            'label' => $product->getLabel(),
            'version' => $version,
            'status' => SupportStatus::UNKNOWN->value,
            'cycle' => null,
            'endOfActiveSupportFrom' => null,
            'eolFrom' => null,
            'latestVersion' => null,
            'hasNewerPatch' => false,
            'documentationUrl' => null,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function persist(
        WatchSnapshotType $type,
        array $payload,
        \DateTimeImmutable $now,
        SnapshotSourceStatus $status,
    ): void {
        $snapshot = $this->snapshotRepository->findOneByType($type);

        if (null === $snapshot) {
            $snapshot = new WatchSnapshot($type, $payload, $now, $status);
        } else {
            $snapshot->refresh($payload, $now, $status);
        }

        $this->snapshotRepository->save($snapshot);
    }
}
