<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Application;

use App\Ai\Assistant\Application\Corpus\CorpusRendererInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Message système de l'assistant : le préambule fixe, puis le corpus rendu
 * (spec 0005 D8), donc un préfixe byte-identique d'un appel à l'autre pour une
 * locale donnée, ce qui ouvre le cache de prompt du fournisseur.
 *
 * Composé ici plutôt que par le bundle : SystemPromptInputProcessor n'injecte
 * pas le `prompt.file` d'ai.yaml quand la conversation porte déjà un message
 * système. Le fichier lu est le même ; ai.yaml le garde pour `ai:agent:call`.
 */
final readonly class CareerAssistantSystemPrompt
{
    public function __construct(
        private CorpusRendererInterface $corpusRenderer,
        #[Autowire('%kernel.project_dir%/config/ai/prompts/career_assistant.txt')]
        private string $preambleFile,
    ) {
    }

    public function compose(Locale $locale): string
    {
        $preamble = is_file($this->preambleFile) ? file_get_contents($this->preambleFile) : false;
        if (false === $preamble || '' === trim($preamble)) {
            throw new \LogicException(\sprintf("Préambule de l'assistant introuvable ou vide : %s.", $this->preambleFile));
        }

        return rtrim($preamble)."\n\n".$this->corpusRenderer->render($locale);
    }
}
