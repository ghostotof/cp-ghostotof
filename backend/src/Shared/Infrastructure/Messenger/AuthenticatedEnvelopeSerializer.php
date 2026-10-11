<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Infrastructure\Security\SecretKeyDerivation;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Sérialiseur par défaut des transports Messenger (issue #444) : toute
 * enveloppe porte un MAC de son corps, vérifié avant le moindre
 * `unserialize()`.
 *
 * Les deux files — `async` sur RabbitMQ, `failed` en table — sont relues par
 * {@see \Symfony\Component\Messenger\Transport\Serialization\PhpSerializer},
 * soit `unserialize()` sans liste de classes : écrire dans l'une d'elles
 * vaudrait injection d'objet dans le worker (ou chez l'opérateur qui lance
 * `messenger:failed:show`). La signature par type de Messenger
 * (`#[AsMessageHandler(sign: true)]`) ne la ferme pas : une enveloppe non
 * signée d'un type non listé — `stdClass` portant un gadget — est toujours
 * désérialisée. Ici, rien n'atteint `unserialize()` sans MAC valable, quel que
 * soit le type, y compris les enveloppes d'échec de décodage que le réessai
 * remet en file.
 *
 * Le MAC couvre le corps seul : c'est tout ce que PhpSerializer lit, les
 * en-têtes que les transports ajoutent n'y entrent pas. Clé dérivée
 * d'`APP_SECRET` par HKDF, avec son contexte : aucun HMAC du site sous le
 * secret brut ne la donne.
 *
 * La classe ne doit jamais implémenter
 * {@see \Symfony\Component\Messenger\Transport\Serialization\MessageTypeAwareSerializerInterface} :
 * le SigningSerializer de Messenger, qui la décore, lirait alors le type de
 * l'enveloppe — une désérialisation, même restreinte — avant le MAC.
 * QueuedEnvelopesAreAuthenticatedTest l'épingle.
 *
 * Deux temps, pour ne perdre aucun message au déploiement : tant que
 * `app.messenger.require_envelope_mac` est faux, une enveloppe **sans** MAC
 * (mise en file par l'ancien code, avant ou pendant le rollout, ou après un
 * retour arrière) reste décodée, avec un `warning` ; un MAC présent mais faux
 * est refusé dans les deux modes. Une release suivante exige le MAC.
 *
 * Un refus est un échec de décodage ({@see MessageDecodingFailedException},
 * qui ne garde que des chaînes) : il suit les réessais du transport, puis
 * `failed`. Son message ne cite que sa cause. Tout échec rendu, refus du MAC
 * ou échec du sérialiseur interne, garde l'enveloppe encodée d'origine, MAC
 * compris (issue #465) : DecodeFailedMessageMiddleware la redécode.
 *
 * Limites acceptées : un message authentifié peut être rejoué par qui écrit
 * dans la file (aucun nonce) ; une rotation d'`APP_SECRET` invalide les
 * messages déjà en file (procédure dans k8s/README.md).
 */
final readonly class AuthenticatedEnvelopeSerializer implements SerializerInterface
{
    public const string HEADER = 'X-Envelope-Mac';

    /** Sépare cette clé des autres usages d'`APP_SECRET`. */
    private const string KEY_CONTEXT = 'cp-ghostotof/messenger-envelope/v1';

    private string $key;

    public function __construct(
        #[Autowire(service: 'messenger.transport.native_php_serializer')]
        private SerializerInterface $inner,
        #[Autowire(param: 'kernel.secret')]
        #[SensitiveParameter]
        string $secret,
        #[Autowire(param: 'app.messenger.require_envelope_mac')]
        private bool $requireMac,
        private LoggerInterface $logger,
    ) {
        // Un secret vide lève EmptyApplicationSecretException, à la première
        // construction : le premier message mis en file ou lu.
        $this->key = SecretKeyDerivation::derive($secret, self::KEY_CONTEXT);
    }

    /**
     * @return array{body: string, headers?: array<string, string>}
     */
    public function encode(Envelope $envelope): array
    {
        $encoded = $this->inner->encode($envelope);
        $encoded['headers'][self::HEADER] = $this->mac($encoded['body']);

        return $encoded;
    }

    /**
     * @param array{body: string, headers?: array<string, string>} $encodedEnvelope
     */
    public function decode(array $encodedEnvelope): Envelope
    {
        $mac = $encodedEnvelope['headers'][self::HEADER] ?? null;

        if (null === $mac && !$this->requireMac) {
            $this->logger->warning('Enveloppe Messenger sans MAC acceptée : transition de l\'issue #444, à exiger à la release suivante.');

            return $this->inner->decode($encodedEnvelope);
        }

        // Un transport peut rendre un en-tête qui n'est pas une chaîne (AMQP,
        // JSON du transport Doctrine) : refusé comme un MAC faux, plutôt
        // qu'une erreur de type qui ferait tomber le worker.
        if (!\is_string($mac) || !hash_equals($this->mac($encodedEnvelope['body']), $mac)) {
            return MessageDecodingFailedException::wrap(
                $encodedEnvelope,
                null === $mac ? 'Enveloppe Messenger sans MAC refusée (issue #444).' : 'Enveloppe Messenger au MAC invalide refusée (issue #444).',
            );
        }

        $authenticated = $encodedEnvelope;
        unset($authenticated['headers'][self::HEADER]);
        $decoded = $this->inner->decode($authenticated);

        // L'échec garde l'enveloppe encodée, que DecodeFailedMessageMiddleware
        // redécode par ce sérialiseur, à la réception puis après chaque réessai
        // (issue #465) : celle d'origine, MAC compris, sinon ce redécodage la
        // voit « sans MAC » — faux warning en transition, cause réelle
        // remplacée par un refus une fois le MAC exigé. PhpSerializer y écrit
        // aussi le corps décodé du base64, que le MAC ne couvre pas.
        $failure = $decoded->getMessage();
        if ($failure instanceof MessageDecodingFailedException) {
            return MessageDecodingFailedException::wrap($encodedEnvelope, $failure->getMessage(), $failure->getCode(), $failure->getPrevious());
        }

        return $decoded;
    }

    private function mac(string $body): string
    {
        return hash_hmac('sha256', $body, $this->key);
    }
}
