<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Application;

/**
 * Le préambule de l'assistant de parcours (`config/ai/prompts/career_assistant.txt`)
 * est absent ou blanc (issue #323). Sans lui, le message système se réduirait
 * au corpus, sans aucune consigne : mieux vaut refuser l'appel que l'envoyer.
 *
 * Défaut de déploiement, pas une erreur du client : elle sort en 500 et
 * n'implémente donc pas ProblemExceptionInterface. Elle étend \LogicException
 * parce que c'en est une (l'image livre le fichier, son absence est un bogue),
 * mais une classe dédiée se cible dans `framework.exceptions`, s'attrape
 * précisément et se reconnaît dans les journaux. Message littéral : le chemin
 * est une constante du câblage, il n'apprendrait rien de plus.
 */
final class AssistantPreambleMissingException extends \LogicException
{
    public function __construct()
    {
        parent::__construct("Le préambule de l'assistant de parcours est introuvable ou vide.");
    }
}
