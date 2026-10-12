<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Cache;

use App\Shared\Infrastructure\Security\KeyedMac;
use App\Shared\Infrastructure\Security\MacEncoding;
use SensitiveParameter;
use SensitiveParameterValue;
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
 * HTTP. Tous relisaient `cache_items` par
 * {@see \Symfony\Component\Cache\Marshaller\DefaultMarshaller}, soit
 * `unserialize()` avec toutes les classes permises : qui écrit dans la table
 * (Adminer, injection SQL, identifiants de la base, que le même Secret porte
 * qu'`APP_SECRET`) obtenait une injection d'objet dans l'API, par les
 * limiteurs de débit, et dans le worker. La décoration de
 * `cache.default_marshaller` vaut pour tout adaptateur qui le reçoit
 * (FrameworkBundle le passe aussi aux adaptateurs fichiers, APCu, Redis,
 * Memcached et PDO) : aujourd'hui, les seuls pools en base.
 * `cache.system` ({@see \Symfony\Component\Cache\Adapter\PhpFilesAdapter}) ne
 * passe pas par lui.
 *
 * Format stocké : le MAC binaire (HMAC-SHA256, 32 octets) suivi de la valeur
 * que rend le marshaller interne. `item_data` est un `BYTEA`, que l'adaptateur
 * lie en binaire. Une valeur au MAC absent ou faux lève
 * {@see CacheValueMacRefusedException}, que l'adaptateur traite comme une
 * absence de cache. Dans une lecture par lot (`getItems()`), l'exception
 * interrompt le lot : les clés suivantes reviennent absentes elles aussi, comme
 * avec tout échec de DefaultMarshaller. Les lecteurs actuels lisent clé par clé.
 *
 * Une valeur signée sous une autre clé est refusée : au premier déploiement, à
 * une rotation d'`APP_SECRET` ou à un changement de `KEY_CONTEXT`, le cache en
 * base est perdu, et le reste tant que le rollout fait servir anciens et
 * nouveaux pods, chacun refusant et réécrasant les valeurs de l'autre : les
 * compteurs des limiteurs repartent de zéro à chaque alternance.
 *
 * Écarté : {@see \Symfony\Component\Cache\Marshaller\SodiumMarshaller}. Une
 * boîte scellée chiffre pour une clé publique : elle n'authentifie l'auteur
 * que tant que cette clé publique reste secrète, ce qui n'est pas la garantie
 * qu'elle promet. Le besoin est l'intégrité, pas la confidentialité, et un HMAC
 * le couvre sans gestion de paire de clés.
 *
 * Limite acceptée : le MAC ne couvre ni l'identifiant de la ligne ni le pool
 * (l'interface ne passe pas l'identifiant à la lecture). Qui écrit dans la
 * table peut recopier une valeur authentifiée d'une clé ou d'un pool à l'autre,
 * ou la rejouer plus tard : ramener un compteur de limiteur en arrière, ou
 * poser un tableau sur la ligne du signal de redémarrage, que le worker compare
 * à un horodatage et prend pour une demande d'arrêt permanente. C'est un déni
 * de service, jamais une exécution de code, à la portée de qui peut déjà lire et
 * réécrire toutes les données de la base. Une clé par pool le fermerait, au
 * prix d'une instance par pool au lieu d'une décoration.
 */
#[AsDecorator(decorates: 'cache.default_marshaller')]
final class AuthenticatedCacheMarshaller implements MarshallerInterface
{
    /**
     * Sépare cette clé des autres usages d'`APP_SECRET` ; la changer invalide
     * tout le cache en base, ce qui en fait une rotation ciblée.
     */
    private const string KEY_CONTEXT = 'cp-ghostotof/cache-value/v1';

    private ?KeyedMac $mac = null;

    /**
     * Gardé pour dériver la clé plus tard ({@see self::mac()}), dans un
     * {@see SensitiveParameterValue} : aucun dump du service ne l'affiche
     * (issue #493).
     *
     * @var SensitiveParameterValue<string>
     */
    private readonly SensitiveParameterValue $secret;

    public function __construct(
        #[AutowireDecorated]
        private readonly MarshallerInterface $inner,
        #[Autowire(param: 'kernel.secret')]
        #[SensitiveParameter]
        string $secret,
    ) {
        $this->secret = new SensitiveParameterValue($secret);
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

            $signed[$id] = $this->mac()->sign($value).$value;
        }

        return $signed;
    }

    public function unmarshall(string $value): mixed
    {
        $storedMac = substr($value, 0, KeyedMac::BINARY_LENGTH);
        $payload = substr($value, KeyedMac::BINARY_LENGTH);

        // Une valeur plus courte qu'un MAC laisse un `$payload` vide, dont le
        // MAC ne peut pas valoir les octets lus : elle est refusée ici aussi.
        if (!$this->mac()->verifies($storedMac, $payload)) {
            throw CacheValueMacRefusedException::invalid();
        }

        return $this->inner->unmarshall($payload);
    }

    /**
     * La clé est dérivée à la première lecture ou écriture, jamais au
     * constructeur : le préchauffage du cache au `docker build`
     * (`cache:clear`, `APP_SECRET` vide) construit ce service par l'EntityManager et le cache de résultats
     * Doctrine sans jamais s'en servir. Un secret vide lève alors
     * {@see \App\Shared\Infrastructure\Security\EmptyApplicationSecretException}
     * à la première utilisation ; l'adaptateur
     * l'attrape comme toute `Exception` et la journalise en `warning`, mais les
     * autres usages du secret (CSRF, enveloppes Messenger) échouent en 500.
     */
    private function mac(): KeyedMac
    {
        return $this->mac ??= KeyedMac::fromSecret($this->secret->getValue(), self::KEY_CONTEXT, MacEncoding::Binary);
    }
}
