<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Infrastructure\Security\KeyedMac;
use App\Shared\Infrastructure\Security\MacEncoding;
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
 * Une enveloppe dont la taille encodée, corps et en-têtes, dépasse
 * {@see self::MAX_ENVELOPE_BYTES} est refusée avant même le MAC (issue #469), par
 * {@see EnvelopeTooLargeException}, qui n'en garde rien. Le refus garde un
 * marqueur, corps vide et taille en en-tête, que ce sérialiseur redécode en ce
 * même refus : {@see \Symfony\Component\Messenger\Middleware\DecodeFailedMessageMiddleware}
 * redécode l'enveloppe gardée, et un corps vide sans MAC y serait sinon refusé
 * « sans MAC », la cause réelle perdue. Le marqueur n'authentifie rien et n'en
 * a pas besoin : il ne vaut que sur un corps vide et pour une taille au-delà de
 * la borne, et, forgé, ne mène qu'à ce refus, avec une taille inventée.
 *
 * Limite acceptée : un message légitime grossit d'un `ErrorDetailsStamp` par
 * échec distinct, à chaque passage dans `failed` et à chaque
 * `messenger:failed:retry`. Au-delà de la borne, il serait remplacé par le
 * marqueur, donc perdu : relever la borne plutôt que d'y faire exception.
 *
 * Limites acceptées : un message authentifié peut être rejoué par qui écrit
 * dans la file (aucun nonce) ; une rotation d'`APP_SECRET` invalide les
 * messages déjà en file (procédure dans k8s/README.md).
 */
final readonly class AuthenticatedEnvelopeSerializer implements SerializerInterface
{
    public const string HEADER = 'X-Envelope-Mac';

    /**
     * Taille encodée maximale, corps et en-têtes, vérifiée avant le MAC (issue
     * #469). Mesurée sur les deux messages réels, au pire de leur validation :
     * un message de contact de 5 000 caractères multi-octets à échapper fait
     * 13,5 Ko nu, 28,7 Ko après trois réessais distincts ; une invitation,
     * 0,6 Ko et 15,8 Ko. Le pire cas de la relecture — exceptions emboîtées par
     * `HandlerFailedException`, quatre `ErrorDetailsStamp` distincts — fait
     * 124 Ko. Un `ErrorDetailsStamp` ne garde pas de trace (Symfony la vide) :
     * c'est le nombre d'échecs distincts qui fait grossir un message. La borne
     * reste anodine face aux 120 Mo du worker. Seule source de ces chiffres :
     * la règle backend-architecture y renvoie. Le broker plafonne un corps à
     * quatre fois cette borne (`max_message_size`, k8s/base/rabbitmq.conf,
     * issue #477) : RabbitMqMessageSizeCapTest lie les deux valeurs.
     */
    public const int MAX_ENVELOPE_BYTES = 256 * 1024;

    /**
     * Sépare cette clé des autres usages d'`APP_SECRET`. En `v2` depuis
     * l'issue #450 : la transition de #444 a pu re-signer sous `v1` une
     * enveloppe forgée sans MAC (au réessai, vers `failed`) ; aucune signature
     * de cette ère n'est plus reconnue.
     */
    private const string KEY_CONTEXT = 'cp-ghostotof/messenger-envelope/v2';

    private KeyedMac $mac;

    public function __construct(
        #[Autowire(service: 'messenger.transport.native_php_serializer')]
        private SerializerInterface $inner,
        #[Autowire(param: 'kernel.secret')]
        #[SensitiveParameter]
        string $secret,
    ) {
        // Un secret vide lève EmptyApplicationSecretException, à la première
        // construction : le premier message mis en file ou lu.
        $this->mac = KeyedMac::fromSecret($secret, self::KEY_CONTEXT, MacEncoding::Hex);
    }

    /**
     * @return array{body: string, headers?: array<string, string>}
     */
    public function encode(Envelope $envelope): array
    {
        $encoded = $this->inner->encode($envelope);
        $encoded['headers'][self::HEADER] = $this->mac->sign($encoded['body']);

        return $encoded;
    }

    /**
     * @param array{body: string, headers?: array<string, string>} $encodedEnvelope
     */
    public function decode(array $encodedEnvelope): Envelope
    {
        // Avant le MAC (issue #469) : ni HMAC ni copie d'une enveloppe
        // démesurée, et le refus redécode en lui-même.
        $size = $this->encodedSize($encodedEnvelope);
        if ($size > self::MAX_ENVELOPE_BYTES) {
            return EnvelopeTooLargeException::forSize($size, self::MAX_ENVELOPE_BYTES);
        }
        $markedSize = $this->markedSize($encodedEnvelope);
        if (null !== $markedSize) {
            return EnvelopeTooLargeException::forSize($markedSize, self::MAX_ENVELOPE_BYTES);
        }

        $givenMac = $encodedEnvelope['headers'][self::HEADER] ?? null;

        // Un transport peut rendre un en-tête qui n'est pas une chaîne (AMQP,
        // JSON du transport Doctrine) : refusé comme un MAC faux, plutôt
        // qu'une erreur de type qui ferait tomber le worker.
        if (!\is_string($givenMac) || !$this->mac->verifies($givenMac, $encodedEnvelope['body'])) {
            return null === $givenMac ? EnvelopeMacRefusedException::missing($encodedEnvelope) : EnvelopeMacRefusedException::invalid($encodedEnvelope);
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

    /**
     * Octets du corps et des en-têtes, noms compris. Un en-tête que le
     * transport rend en tableau compte ses éléments ; un scalaire, sa forme
     * texte ; un objet AMQP (horodatage, décimal), rien : il est borné par
     * construction.
     *
     * @param array{body: string, headers?: array<string, mixed>} $encodedEnvelope
     */
    private function encodedSize(array $encodedEnvelope): int
    {
        return \strlen($encodedEnvelope['body']) + $this->headerBytes($encodedEnvelope['headers'] ?? []);
    }

    private function headerBytes(mixed $value): int
    {
        if (\is_array($value)) {
            $bytes = 0;
            foreach ($value as $name => $item) {
                $bytes += \strlen((string) $name) + $this->headerBytes($item);
            }

            return $bytes;
        }

        return \is_scalar($value) ? \strlen((string) $value) : 0;
    }

    /**
     * La taille que porte un marqueur de refus de taille, ou null si
     * l'enveloppe n'en est pas un : un corps non vide, un en-tête qui n'est pas
     * un nombre, ou une taille sous la borne, qu'aucun refus n'aurait produite.
     *
     * @param array{body: string, headers?: array<string, mixed>} $encodedEnvelope
     */
    private function markedSize(array $encodedEnvelope): ?int
    {
        $size = $encodedEnvelope['headers'][EnvelopeTooLargeException::SIZE_HEADER] ?? null;

        if ('' !== $encodedEnvelope['body'] || !\is_string($size) || 1 !== preg_match('/\A\d{1,12}\z/', $size)) {
            return null;
        }

        return (int) $size > self::MAX_ENVELOPE_BYTES ? (int) $size : null;
    }
}
