<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Infrastructure\Manifest;

/**
 * Relève, au moment de la construction de l'image, les versions des composants
 * que le projet déploie ou avec lesquels il construit.
 *
 * Ces versions étaient auparavant saisies au backoffice. Le défaut était réel :
 * monter `POSTGRES_TAG` et déployer laissait `/stack` annoncer l'ancienne
 * version jusqu'à ce que quelqu'un pense à éditer l'entrée — la page affirmait
 * alors quelque chose de faux sur ce qui tourne, ce qu'elle existe précisément
 * pour éviter.
 *
 * **La source est le manifeste Kubernetes**, et non `.env`, parce que c'est lui
 * que le cluster applique. `.env` sert à construire les images de
 * développement ; les deux étaient tenus synchronisés par un commentaire, ce
 * qui tient tant que personne ne l'oublie. Lire le manifeste supprime la
 * question : ce qui est affiché est ce qui sera tiré.
 *
 * Node fait exception et c'est assumé : il n'est déployé nulle part. Il
 * construit le bundle puis disparaît, le conteneur frontend ne servant que du
 * nginx. Sa version vient donc de `.env`, qui pilote réellement le build — et
 * la page l'affiche au titre de la chaîne d'outils, pas de ce qui tourne.
 */
final readonly class DeployedVersionsBuilder
{
    /**
     * Où lire chaque version, par slug de produit suivi.
     *
     * @var array<string, array{file: string, pattern: string}>
     */
    private const array SOURCES = [
        'postgresql' => ['file' => 'k8s/base/postgres.yaml', 'pattern' => '/image:\s*postgres:(\S+)/'],
        'rabbitmq' => ['file' => 'k8s/base/rabbitmq.yaml', 'pattern' => '/image:\s*rabbitmq:(\S+)/'],
        'nginx' => ['file' => 'k8s/base/backend-deployment.yaml', 'pattern' => '/image:\s*nginx:(\S+)/'],
        'nodejs' => ['file' => '.env', 'pattern' => '/^NODE_TAG=(\S+)/m'],
    ];

    /**
     * @param array<string, string> $tagOverrides tags bruts fournis par le
     *                                            `docker build`, par slug ; ils
     *                                            priment sur la lecture des
     *                                            fichiers
     */
    public function __construct(
        private string $repositoryRoot,
        private string $npmLockPath,
        private string $outputPath,
        private array $tagOverrides = [],
    ) {
    }

    /**
     * @return array<string, string> version par slug, celles qui ont pu être lues
     */
    public function build(\DateTimeImmutable $generatedAt): array
    {
        $versions = [];

        foreach (self::SOURCES as $slug => $source) {
            // Le tag fourni par le build prime, parce qu'au build les fichiers
            // sources ne sont pas là : `k8s/` est délibérément exclu du contexte
            // — l'image n'a pas à embarquer les manifestes de déploiement — et
            // copier `.env` créerait une couche qui le conserverait même
            // supprimé ensuite. Le Makefile extrait donc les tags et les passe
            // en arguments ; la lecture des fichiers reste la voie du
            // développement, où la racine du dépôt est montée.
            $version = \array_key_exists($slug, $this->tagOverrides) && '' !== $this->tagOverrides[$slug]
                ? $this->semanticPrefixOf($this->tagOverrides[$slug])
                : $this->readFrom($this->repositoryRoot.'/'.$source['file'], $source['pattern']);

            if (null !== $version) {
                $versions[$slug] = $version;
            }
        }

        $vue = $this->readVueVersion();

        if (null !== $vue) {
            $versions['vue'] = $vue;
        }

        $this->write($versions, $generatedAt);

        return $versions;
    }

    /**
     * Extrait le numéro de version d'une valeur qui en contient davantage : un
     * tag d'image porte sa variante (`18.4-alpine`,
     * `4.3.4-management-alpine`), et c'est la version que le lecteur veut voir,
     * pas la saveur de la distribution sous-jacente.
     *
     * Une version absente n'est pas une erreur : le relevé se poursuit et le
     * produit reste simplement sans version, ce que la page sait afficher. Un
     * build ne doit pas échouer parce qu'un manifeste a été renommé.
     */
    private function readFrom(string $path, string $pattern): ?string
    {
        if (!is_readable($path)) {
            return null;
        }

        $content = file_get_contents($path);

        if (false === $content || 1 !== preg_match($pattern, $content, $matches)) {
            return null;
        }

        return $this->semanticPrefixOf($matches[1]);
    }

    private function semanticPrefixOf(string $tag): ?string
    {
        return 1 === preg_match('/^\d+(?:\.\d+)*/', $tag, $matches) ? $matches[0] : null;
    }

    /**
     * Vue vient du fichier de verrouillage npm et non d'un manifeste : c'est une
     * dépendance du bundle, pas une image déployée.
     */
    private function readVueVersion(): ?string
    {
        if (!is_readable($this->npmLockPath)) {
            return null;
        }

        $content = file_get_contents($this->npmLockPath);

        if (false === $content) {
            return null;
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($content, true);

        if (!\is_array($decoded)) {
            return null;
        }

        $packages = $decoded['packages'] ?? null;

        if (!\is_array($packages)) {
            return null;
        }

        $vue = $packages['node_modules/vue'] ?? null;

        if (!\is_array($vue)) {
            return null;
        }

        $version = $vue['version'] ?? null;

        return \is_string($version) && '' !== $version ? $version : null;
    }

    /**
     * @param array<string, string> $versions
     */
    private function write(array $versions, \DateTimeImmutable $generatedAt): void
    {
        $directory = \dirname($this->outputPath);

        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Impossible de créer le répertoire « %s ».', $directory));
        }

        $encoded = json_encode(
            ['generatedAt' => $generatedAt->format(\DATE_ATOM), 'versions' => $versions],
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
        );

        file_put_contents($this->outputPath, $encoded."\n");
    }
}
