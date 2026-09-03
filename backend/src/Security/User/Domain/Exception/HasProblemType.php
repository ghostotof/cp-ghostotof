<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Exception;

/**
 * Rend une exception métier auto-descriptive pour API Platform (RFC 7807) : le
 * champ `type` du problem+json devient un slug stable (`/errors/<slug>`),
 * exploitable côté client pour choisir le message affiché sans avoir à analyser
 * le `detail` (localisé, susceptible de changer) — cf. frontend
 * HttpAdminUserRepository. Complète `exception_to_status` (qui, lui, ne porte
 * que le code HTTP).
 */
trait HasProblemType
{
    abstract protected function problemType(): string;

    abstract protected function problemStatus(): int;

    public function getType(): string
    {
        return '/errors/'.$this->problemType();
    }

    public function getTitle(): ?string
    {
        return $this->problemType();
    }

    public function getStatus(): ?int
    {
        return $this->problemStatus();
    }

    public function getDetail(): ?string
    {
        return $this->getMessage();
    }

    public function getInstance(): ?string
    {
        return null;
    }
}
