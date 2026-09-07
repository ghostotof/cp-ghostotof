<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Portfolio\Watch\Domain\Repository\WatchSnapshotRepositoryInterface;
use App\Portfolio\Watch\Domain\ValueObject\SupportStatus;
use App\Portfolio\Watch\Domain\ValueObject\WatchSnapshotType;
use App\Portfolio\Watch\Presentation\ApiResource\WatchedProductResource;
use App\Portfolio\Watch\Presentation\ApiResource\WatchReleaseCyclesResource;
use App\Portfolio\Watch\Presentation\ApiResource\WatchResource;

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
    public function __construct(private WatchSnapshotRepositoryInterface $snapshotRepository)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): WatchResource
    {
        $snapshot = $this->snapshotRepository->findOneByType(WatchSnapshotType::RELEASE_CYCLES);

        if (null === $snapshot) {
            return new WatchResource(new WatchReleaseCyclesResource([], null, null));
        }

        return new WatchResource(new WatchReleaseCyclesResource(
            $this->productsFrom($snapshot->getPayload()),
            // Exposée en UTC : le contrat de l'API ne doit pas dépendre du
            // date.timezone du conteneur, qui pourrait changer sans que
            // personne n'y voie un changement d'interface.
            $snapshot->getRefreshedAt()->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
            $snapshot->getSourceStatus()->value,
        ));
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
                $this->readString($entry, 'documentationUrl'),
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
