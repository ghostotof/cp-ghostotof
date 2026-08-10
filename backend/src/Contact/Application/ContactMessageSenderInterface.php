<?php

declare(strict_types=1);

namespace App\Contact\Application;

use App\Contact\Domain\ValueObject\ContactMessage;

interface ContactMessageSenderInterface
{
    /**
     * Met le message en file d'attente pour envoi asynchrone (cf.
     * ContactMessageSender) : ne garantit pas que l'email a été délivré au
     * moment où la méthode retourne, seulement qu'il a été accepté pour
     * traitement.
     */
    public function send(ContactMessage $message): void;
}
