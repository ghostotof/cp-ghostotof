<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Cache;

use App\Shared\Infrastructure\Security\SecretKeyDerivation;
use SensitiveParameter;
use Symfony\Component\Cache\Marshaller\MarshallerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Marshaller des pools de cache en base : toute valeur porte un MAC, vérifié
 * avant le moindre `unserialize()` (issue #468).
 *
 * `cache.app` est un adaptateur Doctrine DBAL (ADR 0005), comme le signal de
 * redémarrage du worker, le cache de résultats Doctrine et le pool du client
 * HTTP. Tous relisent `cache_items` par
 * {@see \Symfony\Component\Cache\Marshaller\DefaultMarshaller}, soit
 * `unserialize()` avec toutes les classes permises : qui écrit dans la table
 * (Adminer, injection SQL, identifiants de la base, que le même Secret porte
 * qu'`APP_SECRET`) obtenait une injection d'objet dans l'API, par les
 * limiteurs de débit, et dans le worker. Décorer `cache.default_marshaller`
 * couvre exactement ces pools : `cache.system` (PhpFilesAdapter) ne passe pas
 * par lui.
 *
 * Format stocké : le MAC binaire (HMAC-SHA256, 32 octets) suivi de la valeur
 * que rend le marshaller interne. `item_data` est un `BYTEA`, que l'adaptateur
 * lie en binaire. Une valeur au MAC absent ou faux lève
 * {@see CacheValueMacRefusedException}, que l'adaptateur traite comme une
 * absence de cache. Au premier déploiement, chaque valeur déjà en table est
 * donc perdue une fois : les compteurs des limiteurs repartent de zéro.
 *
 * Écarté : {@see \Symfony\Component\Cache\Marshaller\SodiumMarshaller}. Une
 * boîte scellée chiffre pour une clé publique : elle n'authentifie l'auteur
 * que tant que cette clé publique reste secrète, ce qui n'est pas la garantie
 * qu'elle promet. Le besoin est l'intégrité, pas la confidentialité, et un HMAC
 * le couvre sans gestion de paire de clés.
 *
 * Limite acceptée : une valeur authentifiée peut être recopiée d'une clé de
 * cache à une autre, ou rejouée plus tard, par qui écrit dans la table (le MAC
 * ne couvre pas l'identifiant). Au pire, un compteur de limiteur revient à un
 * état antérieur : un déni de quota, jamais une exécution de code.
 */
#[AsDecorator(decorates: 'cache.default_marshaller')]
final readonly class AuthenticatedCacheMarshaller implements MarshallerInterface
{
    /**
     * Sépare cette clé des autres usages d'`APP_SECRET` ; la changer invalide
     * tout le cache en base, ce qui en fait une rotation ciblée.
     */
    private const string KEY_CONTEXT = 'cp-ghostotof/cache-value/v1';

    private const int MAC_LENGTH = 32;

    private string $key;

    public function __construct(
        #[AutowireDecorated]
        private MarshallerInterface $inner,
        #[Autowire(param: 'kernel.secret')]
        #[SensitiveParameter]
        string $secret,
    ) {
        // Un secret vide lève EmptyApplicationSecretException, à la première
        // construction : la première lecture ou écriture d'un pool en base.
        $this->key = SecretKeyDerivation::derive($secret, self::KEY_CONTEXT);
    }

    /**
     * @param array<array-key, mixed>      $values
     * @param array<array-key, mixed>|null $failed
     *
     * @return array<array-key, string>
     */
    public function marshall(array $values, ?array &$failed): array
    {
        $signed = [];

        foreach ($this->inner->marshall($values, $failed) as $id => $value) {
            // L'interface ne type pas ce que rend le marshaller interne. Une
            // valeur qui n'est pas une chaîne ne peut pas être signée : elle est
            // signalée en échec, comme l'interface le demande pour toute valeur
            // non sérialisable, et l'adaptateur ne l'écrit pas.
            if (!\is_string($value)) {
                $failed[] = $id;

                continue;
            }

            $signed[$id] = $this->mac($value).$value;
        }

        return $signed;
    }

    public function unmarshall(string $value): mixed
    {
        $mac = substr($value, 0, self::MAC_LENGTH);
        $payload = substr($value, self::MAC_LENGTH);

        // Une valeur plus courte qu'un MAC laisse un `$payload` vide, dont le
        // MAC ne peut pas valoir les octets lus : elle est refusée ici aussi.
        if (!hash_equals($this->mac($payload), $mac)) {
            throw CacheValueMacRefusedException::invalid();
        }

        return $this->inner->unmarshall($payload);
    }

    private function mac(string $value): string
    {
        return hash_hmac('sha256', $value, $this->key, true);
    }
}
