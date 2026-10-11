<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Infrastructure\Security\SecretKeyDerivation;
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
 * Une enveloppe **sans** MAC est refusée comme un MAC faux (issue #450). La
 * release de #444 l'acceptait encore, avec un `warning`, le temps que les
 * files se vident des messages de l'ancien code ; ce mode transition est
 * supprimé, pas désactivé : aucun paramètre ne rouvre l'injection.
 *
 * Un refus est un échec de décodage définitif ({@see EnvelopeMacRefusedException},
 * qui garde l'enveloppe reçue, jamais un objet de son corps) : droit dans
 * `failed`, sans réessai (issue #450). Son message ne cite que sa cause.
 * Tout échec de ce décodage, refus du MAC ou échec du sérialiseur interne,
 * garde l'enveloppe encodée reçue, MAC compris (issue #465) :
 * {@see \Symfony\Component\Messenger\Middleware\DecodeFailedMessageMiddleware}
 * la redécode. L'échec qu'un réessai transporte comme message revient tel
 * quel.
 *
 * Un corps au-delà de {@see self::MAX_BODY_BYTES} est refusé avant même le MAC
 * (issue #469), par {@see EnvelopeTooLargeException}, qui ne garde pas le corps.
 *
 * Limites acceptées : un message authentifié peut être rejoué par qui écrit
 * dans la file (aucun nonce) ; une rotation d'`APP_SECRET` invalide les
 * messages déjà en file (procédure dans k8s/README.md).
 */
final readonly class AuthenticatedEnvelopeSerializer implements SerializerInterface
{
    public const string HEADER = 'X-Envelope-Mac';

    /**
     * Taille maximale d'un corps, vérifiée avant le MAC (issue #469). Mesurée
     * sur les deux messages réels, au pire de leur validation : un message de
     * contact de 5 000 caractères multi-octets à échapper fait 13,5 Ko nu,
     * 28,7 Ko après trois réessais (un `ErrorDetailsStamp` par échec distinct,
     * trace comprise) ; une invitation, 0,6 Ko et 15,8 Ko. La borne laisse plus
     * de huit fois cette marge à une trace plus profonde, et reste anodine face
     * aux 120 Mo du worker. Un message légitime qui la dépasserait serait perdu,
     * refusé comme les autres : la relever plutôt que d'y faire exception.
     */
    public const int MAX_BODY_BYTES = 256 * 1024;

    /**
     * Sépare cette clé des autres usages d'`APP_SECRET`. En `v2` depuis
     * l'issue #450 : la transition de #444 a pu re-signer sous `v1` une
     * enveloppe forgée sans MAC (au réessai, vers `failed`) ; aucune signature
     * de cette ère n'est plus reconnue.
     */
    private const string KEY_CONTEXT = 'cp-ghostotof/messenger-envelope/v2';

    private string $key;

    public function __construct(
        #[Autowire(service: 'messenger.transport.native_php_serializer')]
        private SerializerInterface $inner,
        #[Autowire(param: 'kernel.secret')]
        #[SensitiveParameter]
        string $secret,
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
        // Avant le MAC (issue #469) : ni HMAC ni copie d'un corps démesuré. Le
        // refus redécode en lui-même, cf. EnvelopeTooLargeException.
        $size = \strlen($encodedEnvelope['body']);
        if ($size > self::MAX_BODY_BYTES) {
            return EnvelopeTooLargeException::forSize($size);
        }
        $markedSize = EnvelopeTooLargeException::markedSize($encodedEnvelope);
        if (null !== $markedSize) {
            return EnvelopeTooLargeException::forSize($markedSize);
        }

        $mac = $encodedEnvelope['headers'][self::HEADER] ?? null;

        // Un transport peut rendre un en-tête qui n'est pas une chaîne (AMQP,
        // JSON du transport Doctrine) : refusé comme un MAC faux, plutôt
        // qu'une erreur de type qui ferait tomber le worker.
        if (!\is_string($mac) || !hash_equals($this->mac($encodedEnvelope['body']), $mac)) {
            return null === $mac ? EnvelopeMacRefusedException::missing($encodedEnvelope) : EnvelopeMacRefusedException::invalid($encodedEnvelope);
        }

        $authenticated = $encodedEnvelope;
        unset($authenticated['headers'][self::HEADER]);
        $decoded = $this->inner->decode($authenticated);

        // L'échec garde l'enveloppe encodée, que
        // Symfony\Component\Messenger\Middleware\DecodeFailedMessageMiddleware
        // redécode par ce sérialiseur, à la réception puis après chaque réessai
        // (issue #465) : celle d'origine, MAC compris, sinon ce redécodage la
        // voit « sans MAC » et la refuse : la cause réelle serait remplacée
        // par ce refus. Le PhpSerializer interne y écrit aussi le corps décodé
        // du base64, que le MAC ne couvre pas.
        $failure = $decoded->getMessage();
        if ($failure instanceof MessageDecodingFailedException && $this->isFailureToDecode($failure, $authenticated)) {
            return MessageDecodingFailedException::wrap($encodedEnvelope, $failure->getMessage(), $failure->getCode(), $failure->getPrevious());
        }

        return $decoded;
    }

    /**
     * L'échec que le sérialiseur interne vient de rendre pour `$handed`, et non
     * celui qu'un réessai transporte comme message : ce dernier revient tel
     * quel, avec son enveloppe d'origine et ses tampons —
     * {@see \Symfony\Component\Messenger\Stamp\RedeliveryStamp}, qui compte les
     * réessais, et
     * {@see \Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp}, que
     * lit `messenger:failed:*`. Ré-enveloppé, il les perdait — réessais sans
     * fin — et chaque réessai s'emboîtait dans le suivant.
     *
     * {@see \Symfony\Component\Messenger\Transport\Serialization\PhpSerializer}
     * garde l'enveloppe qu'on lui passe, son corps décodé du base64 le cas
     * échéant. L'échec transporté garde le corps du message d'origine, que le
     * corps courant contient : jamais le même.
     *
     * @param array{body: string, headers?: array<string, string>} $handed
     */
    private function isFailureToDecode(MessageDecodingFailedException $failure, array $handed): bool
    {
        $body = $failure->encodedEnvelope['body'] ?? null;

        return $body === $handed['body'] || $body === base64_decode($handed['body'], true);
    }

    private function mac(string $body): string
    {
        return hash_hmac('sha256', $body, $this->key);
    }
}
