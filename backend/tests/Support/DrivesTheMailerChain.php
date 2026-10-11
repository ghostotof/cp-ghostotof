<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bridge\Twig\Mime\BodyRenderer;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mailer\EventListener\MessageListener;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Messenger\MessageHandler;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * La vraie chaîne du mailer, pour faire échouer le rendu d'un e-mail comme en
 * production, et les réglages de trace sous lesquels vérifier ce que l'échec
 * laisse voir (issues #419, #427).
 */
trait DrivesTheMailerChain
{
    /**
     * La chaîne du mailer câblée comme `mailer.mailer` (transport, bus,
     * dispatcher). Le gabarit y est rendu deux fois : au
     * {@see \Symfony\Component\Mailer\Event\MessageEvent} « queued » que
     * Mailer::send() émet avant le bus (MessageListener rend sans regarder
     * isQueued()), puis dans le transport, derrière le bus (SendEmailMessage
     * traité en synchrone faute de routage, issue #426). Un gabarit cassé
     * échoue donc dès le premier rendu, hors de toute HandlerFailedException.
     *
     * @param array<string, string> $templates nom => source, sous strict_variables
     */
    private function renderingMailer(array $templates): MailerInterface
    {
        $twig = new Environment(new ArrayLoader($templates), ['strict_variables' => true]);
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new MessageListener(null, new BodyRenderer($twig)));
        $transport = new NullTransport($dispatcher);

        return new Mailer($transport, new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            SendEmailMessage::class => [new MessageHandler($transport)],
        ]))]), $dispatcher);
    }

    /**
     * Exécute `$run` avec les réglages du dev et du test (arguments gardés dans
     * les traces), et au pire une longueur de chaîne relevée : ce que le test
     * prouve ne doit pas dépendre d'un php.ini. PHP tronque les arguments
     * d'une trace au moment où il la formate, pas à la capture : les formes
     * texte à vérifier naissent donc dans `$run`.
     *
     * @template T
     *
     * @param callable(): T $run
     *
     * @return T
     */
    private function withVerboseTraces(callable $run): mixed
    {
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        $maxLength = ini_set('zend.exception_string_param_max_len', '1000000');

        try {
            return $run();
        } finally {
            if (false !== $ignoreArgs) {
                ini_set('zend.exception_ignore_args', $ignoreArgs);
            }
            if (false !== $maxLength) {
                ini_set('zend.exception_string_param_max_len', $maxLength);
            }
        }
    }
}
