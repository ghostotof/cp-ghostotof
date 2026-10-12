<?php

declare(strict_types=1);

/*
 * Processus fils de ReinviteConcurrencyTest (issue #435) : relance
 * l'invitation d'un compte pendant que le test en tient une copie chargée
 * avant cette relance — deux workers PHP-FPM sur un double clic.
 *
 * Usage : php reinvite-account.php <identifiant du compte>
 *
 * Écrit « generation <n> », la génération que porte le message publié, et
 * sort en 0 ; en ChildPhpProcess::SETUP_FAILED si le fils n'a pas pu se mettre en place.
 */

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\User\Application\CpgUserInviterInterface;
use App\Security\User\Application\Message\SendAccountInvitationMessage;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Tests\Support\ChildPhpProcess;
use App\Tests\Support\TestServiceLocator;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

require \dirname(__DIR__, 4).'/tests/bootstrap.php';

$arguments = \is_array($_SERVER['argv'] ?? null) ? array_values($_SERVER['argv']) : [];
$userId = $arguments[1] ?? null;
if (!\is_string($userId) || !Uuid::isValid($userId)) {
    fwrite(\STDERR, "Usage : php reinvite-account.php <identifiant du compte>\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

$repository = TestServiceLocator::locate(CpgUserRepositoryInterface::class);
$inviter = TestServiceLocator::locate(CpgUserInviterInterface::class);
$transport = TestServiceLocator::locate('messenger.transport.async');
if (!$repository instanceof CpgUserRepositoryInterface || !$inviter instanceof CpgUserInviterInterface || !$transport instanceof InMemoryTransport) {
    fwrite(\STDERR, "Services introuvables dans le conteneur de test.\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

$user = $repository->findOneById(Uuid::fromString($userId));
if (null === $user) {
    fwrite(\STDERR, "Compte introuvable.\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

$inviter->reinvite($user, Locale::FR);

$sent = $transport->getSent();
$last = end($sent);
$message = false === $last ? null : $last->getMessage();
if (!$message instanceof SendAccountInvitationMessage) {
    fwrite(\STDERR, "Aucun message d'invitation publié.\n");
    exit(ChildPhpProcess::SETUP_FAILED);
}

echo 'generation '.$message->invitationGeneration."\n";

exit(0);
