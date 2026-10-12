<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Messenger;

use App\Shared\Infrastructure\Messenger\AuthenticatedEnvelopeSerializer;
use App\Tests\Support\KubernetesManifests;
use PHPUnit\Framework\TestCase;

/**
 * Le broker refuse à la source un message démesuré (issue #477).
 *
 * AuthenticatedEnvelopeSerializer refuse une enveloppe de plus de
 * `MAX_ENVELOPE_BYTES` avant son MAC (issue #469), mais le transport AMQP a
 * déjà chargé le message entier : RabbitMQ en accepte 16 Mio par défaut, de
 * quoi faire boucler le worker (`--memory-limit=120M`, conteneur à 192 Mi)
 * avant tout refus. `max_message_size` plafonne le corps à la publication.
 * Les en-têtes, eux, sont déjà bornés par `frame_max` (128 Kio par défaut) :
 * une trame d'en-tête ne se découpe pas.
 *
 * Le plafond vaut exactement quatre fois la borne du sérialiseur : au-dessus,
 * pour qu'une enveloppe légitime à la borne passe le broker, et lié à elle,
 * pour que relever l'une sans l'autre fasse rougir ce test.
 *
 * Ce test lit la base ; tools/check-rabbitmq-conf.sh (CI `rabbitmq-conf`)
 * démarre l'image épinglée avec le fichier, lit la valeur que le nœud a
 * retenue, et vérifie le montage de docker-compose.yml.
 */
final class RabbitMqMessageSizeCapTest extends TestCase
{
    private const string CONFIG_MAP = 'rabbitmq-conf';

    private const string MOUNT_PATH = '/etc/rabbitmq/conf.d/20-cp-ghostotof.conf';

    public function testTheBrokerCapsTheMessageSizeAtFourTimesTheSerializerBound(): void
    {
        // Une seule ligne : RabbitMQ retiendrait la dernière, un test qui lirait
        // la première pourrait passer à côté.
        self::assertSame(1, preg_match_all('/^\s*max_message_size\s*=\s*(\d+)\s*$/m', KubernetesManifests::base()->source('rabbitmq.conf'), $matches));

        self::assertSame(4 * AuthenticatedEnvelopeSerializer::MAX_ENVELOPE_BYTES, (int) $matches[1][0]);
    }

    /**
     * Par un `configMapGenerator` : le hachage du contenu entre dans le nom,
     * donc dans le gabarit du pod. Un changement du fichier déclenche un
     * rollout, seul moyen pour un fichier monté par `subPath` d'être relu.
     */
    public function testTheConfigurationIsGeneratedWithAContentHash(): void
    {
        $kustomization = KubernetesManifests::base()->resource('kustomization.yaml', 'Kustomization');
        $generators = $kustomization['configMapGenerator'] ?? null;
        self::assertIsArray($generators);

        $found = array_filter($generators, static fn (mixed $generator): bool => \is_array($generator) && self::CONFIG_MAP === ($generator['name'] ?? null));
        self::assertCount(1, $found, 'configMapGenerator '.self::CONFIG_MAP.' absent.');
        $generator = array_first($found);

        self::assertSame(['rabbitmq.conf'], $generator['files'] ?? null);
        self::assertArrayNotHasKey('options', $generator, 'Le hachage du nom doit rester actif.');

        // Un `generatorOptions` global, dans la base ou un overlay, couperait
        // aussi le hachage (incident de backend-nginx-conf, #18).
        foreach (['', '../overlays/preprod/', '../overlays/prod/'] as $directory) {
            $file = KubernetesManifests::base()->resource($directory.'kustomization.yaml', 'Kustomization');
            $options = $file['generatorOptions'] ?? [];
            self::assertIsArray($options);
            self::assertNotTrue($options['disableNameSuffixHash'] ?? false, 'generatorOptions.disableNameSuffixHash dans k8s/base/'.$directory.'kustomization.yaml');
        }
    }

    public function testTheBrokerMountsTheConfigurationBesideTheImageDefaults(): void
    {
        $podSpec = KubernetesManifests::base()->podSpec('rabbitmq.yaml', 'Deployment');
        $container = KubernetesManifests::base()->container('rabbitmq.yaml', 'Deployment', 'rabbitmq');

        // Un fichier dans conf.d, par subPath : le dossier garde le
        // 10-defaults.conf de l'image.
        $mounts = array_filter(
            \is_array($container['volumeMounts'] ?? null) ? $container['volumeMounts'] : [],
            static fn (mixed $m): bool => \is_array($m) && self::MOUNT_PATH === ($m['mountPath'] ?? null),
        );
        self::assertCount(1, $mounts, self::MOUNT_PATH.' non monté.');
        $mount = array_first($mounts);
        self::assertSame('rabbitmq.conf', $mount['subPath'] ?? null);
        self::assertTrue($mount['readOnly'] ?? false);

        $volumes = array_filter(
            \is_array($podSpec['volumes'] ?? null) ? $podSpec['volumes'] : [],
            static fn (mixed $v): bool => \is_array($v) && ($v['name'] ?? null) === $mount['name'],
        );
        $volume = array_first($volumes);
        self::assertIsArray($volume);
        self::assertSame(['name' => self::CONFIG_MAP], $volume['configMap'] ?? null);
    }
}
