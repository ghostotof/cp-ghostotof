<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Portfolio\Watch\Domain\Repository\WatchSnapshotRepositoryInterface;
use App\Portfolio\Watch\Domain\Service\ExternalUrlFilter;
use App\Portfolio\Watch\Domain\Service\SnapshotFreshnessCalculator;
use App\Portfolio\Watch\Domain\ValueObject\SnapshotFreshness;
use App\Portfolio\Watch\Domain\ValueObject\SupportStatus;
use App\Portfolio\Watch\Domain\ValueObject\WatchSnapshotType;
use App\Portfolio\Watch\Presentation\ApiResource\WatchedProductResource;
use App\Portfolio\Watch\Presentation\ApiResource\WatchReleaseCyclesResource;
use App\Portfolio\Watch\Presentation\ApiResource\WatchResource;
use App\Portfolio\Watch\Presentation\ApiResource\WatchVulnerabilitiesResource;

/**
 * Relie WatchResource au snapshot local, et rien d'autre.
 *
 * Ce provider n'a **volontairement aucune dépendance vers une source externe** :
 * il ne peut donc pas, même par accident, remettre un fournisseur tiers dans le
 * chemin de rendu d'une page publique (décision D5). L'absence de snapshot n'est
 * pas traitée comme une erreur mais comme un état — une installation neuve n'est
 * pas un incident.
 *
 * Le payload relu est du JSON : bien qu'écrit par notre propre rafraîchisseur,
 * il est normalisé comme une donnée non fiable. Une entrée amputée de son slug
 * est ignorée plutôt que de faire tomber la page entière.
 *
 * @implements ProviderInterface<WatchResource>
 */
final readonly class WatchProvider implements ProviderInterface
{
    public function __construct(
        private WatchSnapshotRepositoryInterface $snapshotRepository,
        private SnapshotFreshnessCalculator $freshnessCalculator,
        private ExternalUrlFilter $urlFilter,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): WatchResource
    {
        // La fraîcheur se juge à l'instant de la lecture : c'est ce qui fait
        // qu'un snapshot devient périmé tout seul quand le rafraîchissement
        // cesse d'aboutir, sans que personne n'ait à venir le marquer.
        $now = new \DateTimeImmutable();

        return new WatchResource(
            $this->releaseCycles($now),
            $this->vulnerabilities($now),
        );
    }

    private function releaseCycles(\DateTimeImmutable $now): WatchReleaseCyclesResource
    {
        $snapshot = $this->snapshotRepository->findOneByType(WatchSnapshotType::RELEASE_CYCLES);

        if (null === $snapshot) {
            return new WatchReleaseCyclesResource([], null, null, SnapshotFreshness::NEVER_REFRESHED->value);
        }

        return new WatchReleaseCyclesResource(
            $this->productsFrom($snapshot->getPayload()),
            $this->utc($snapshot->getRefreshedAt()),
            $snapshot->getSourceStatus()->value,
            $this->freshnessCalculator->freshnessFor($snapshot->getRefreshedAt(), $now)->value,
        );
    }

    /**
     * **Le cloisonnement de la décision D4 se joue ici**, et nulle part
     * ailleurs : le snapshot contient le détail complet des vulnérabilités —
     * identifiants, paquets touchés, versions correctives — mais cette méthode
     * n'en extrait qu'un décompte. Le détail reste réservé à ROLE_SUPER.
     *
     * Un test fonctionnel dédié asserte l'absence de ces champs dans la réponse
     * anonyme. Il ne doit jamais être assoupli : c'est la seule chose qui
     * empêche une évolution distraite de publier la surface d'attaque du site.
     */
    private function vulnerabilities(\DateTimeImmutable $now): WatchVulnerabilitiesResource
    {
        $snapshot = $this->snapshotRepository->findOneByType(WatchSnapshotType::VULNERABILITIES);

        if (null === $snapshot) {
            // Aucune analyse n'a jamais abouti : on l'annonce, plutôt que de
            // laisser un zéro rassurer à tort.
            return new WatchVulnerabilitiesResource(null, 0, null, SnapshotFreshness::NEVER_REFRESHED->value);
        }

        $payload = $snapshot->getPayload();
        $scanned = $payload['packagesScanned'] ?? null;
        $vulnerabilities = $payload['vulnerabilities'] ?? null;

        return new WatchVulnerabilitiesResource(
            \is_int($scanned) ? $scanned : null,
            \is_array($vulnerabilities) ? \count($vulnerabilities) : 0,
            $this->utc($snapshot->getRefreshedAt()),
            $this->freshnessCalculator->freshnessFor($snapshot->getRefreshedAt(), $now)->value,
        );
    }

    /**
     * Exposée en UTC : le contrat de l'API ne doit pas dépendre du
     * date.timezone du conteneur, qui pourrait changer sans que personne n'y
     * voie un changement d'interface.
     */
    private function utc(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<WatchedProductResource>
     */
    private function productsFrom(array $payload): array
    {
        $entries = $payload['products'] ?? null;

        if (!\is_array($entries)) {
            return [];
        }

        $products = [];

        foreach ($entries as $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            $slug = $this->readString($entry, 'slug');

            if (null === $slug) {
                continue;
            }

            $products[] = new WatchedProductResource(
                $slug,
                $this->readString($entry, 'label') ?? $slug,
                $this->readString($entry, 'version'),
                $this->readString($entry, 'status') ?? SupportStatus::UNKNOWN->value,
                $this->readString($entry, 'cycle'),
                $this->readString($entry, 'endOfActiveSupportFrom'),
                $this->readString($entry, 'eolFrom'),
                $this->readString($entry, 'latestVersion'),
                true === ($entry['hasNewerPatch'] ?? null),
                // Seconde barrière, et non redondance : le filtrage principal
                // est posé à l'écriture, dans le client. Mais un snapshot écrit
                // avant ce correctif contient encore la valeur brute du tiers,
                // et c'est cette lecture-ci qui la republierait — jusqu'au
                // prochain rafraîchissement. Cohérent, du reste, avec la
                // doctrine énoncée plus haut : ce payload est une donnée non
                // fiable.
                $this->urlFilter->keepIfSafe($this->readString($entry, 'documentationUrl')),
            );
        }

        return $products;
    }

    /**
     * @param array<mixed> $data
     */
    private function readString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return \is_string($value) && '' !== $value ? $value : null;
    }
}
