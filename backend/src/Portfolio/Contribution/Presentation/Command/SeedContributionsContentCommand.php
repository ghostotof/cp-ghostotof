<?php

declare(strict_types=1);

namespace App\Portfolio\Contribution\Presentation\Command;

use App\Portfolio\Contribution\Application\ContributionAdministratorInterface;
use App\Portfolio\Contribution\Domain\Repository\ContributionRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Peuple/réinitialise les contributions affichées sur /contributions (fr/en),
 * servies depuis la base via GET /api/contributions/{locale}. Idempotente :
 * chaque exécution purge et recrée l'intégralité du contenu par locale.
 *
 * Attention : rejouer cette commande écrase les contributions ajoutées depuis
 * le backoffice. Elle sert à poser le contenu de référence sur un
 * environnement neuf, pas à synchroniser une base déjà éditée.
 */
#[AsCommand(
    name: 'app:contributions:seed',
    description: 'Peuple/réinitialise les contributions (fr/en) avec leur contenu de référence.',
)]
final class SeedContributionsContentCommand extends Command
{
    public function __construct(
        private readonly ContributionRepositoryInterface $contributionRepository,
        private readonly ContributionAdministratorInterface $contributionAdministrator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        foreach ($this->content() as $localeValue => $contributions) {
            $locale = Locale::from($localeValue);

            foreach ($this->contributionRepository->findByLocale($locale) as $existing) {
                $this->contributionRepository->remove($existing);
            }

            foreach ($contributions as $position => $contribution) {
                $this->contributionAdministrator->create(
                    $locale,
                    $contribution['title'],
                    $contribution['project'],
                    $contribution['reference'],
                    $contribution['url'],
                    $contribution['summary'],
                    $contribution['body'],
                    $position,
                );
            }

            $io->success(sprintf('[%s] %d contribution(s).', $localeValue, \count($contributions)));
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<string, list<array{title: string, project: string, reference: string, url: string, summary: string, body: string}>>
     */
    private function content(): array
    {
        return [
            'fr' => [
                [
                    'title' => 'Retry de transport, re-prompt de validation : deux mécanismes, un seul mot',
                    'project' => 'symfony/ai',
                    'reference' => 'Issue #1688',
                    'url' => 'https://github.com/symfony/ai/issues/1688',
                    'summary' => "Une proposition d'API inspirée de PydanticAI regroupait sous le mot « retry » deux opérations différentes. Rejouer une requête rejetée par le transport et re-prompter un modèle avec une erreur de validation ne partagent ni le coût, ni le budget, ni ce qu'il faut rendre à l'appelant quand la boucle s'épuise.",
                    'body' => <<<'TXT'
                        Depuis #2206, un échec de transport a sa réponse : une 5xx ou une surcharge est une panne d'infrastructure, et rejouer la requête identique est correct. Re-prompter un modèle avec une erreur de validation n'est pas cela — c'est une nouvelle requête, avec un historique de messages muté. Même mot, opération différente.

                        `maxRetries` reste ambigu tant que les deux mécanismes portent le nom. Si un utilisateur écrit `maxRetries: 3`, est-ce trois appels au modèle, ou trois tentatives de validation pouvant chacune rejouer le transport N fois — donc jusqu'à neuf ? Les retries de transport ne devraient pas consommer le budget de validation, et les deux limites devraient se configurer séparément. Un nom distinct — `maxCorrections`, `maxRepairAttempts` — rendrait cela évident sans documentation.

                        Le profil de coût est inversé. Un retry de transport coûte de la latence : la requête a été rejetée, rien n'a été généré. Une tentative de correction coûte une inférence complète et fait grossir l'historique qu'elle envoie — la tentative 3 est strictement plus chère que la tentative 1, et entame la fenêtre de contexte. Cela vaut contre une API hébergée comme contre un modèle local : la monnaie change, pas le sens. Ce qui range le budget de correction dans la même famille que la limite d'appels d'outils du point 1 — deux plafonds de consommation sur une seule exécution, là où un rate limiter borne l'usage entre exécutions successives.

                        Ce que reçoit l'appelant à l'épuisement compte davantage ici. Quand un retry de transport s'épuise, la dernière exception raconte toute l'histoire. Quand une boucle de correction s'épuise, l'artefact utile est la trace : ce que le modèle a renvoyé à chaque tentative, et quel message de validation lui a été rendu en retour. C'est ce dont on a besoin pour corriger le prompt ou le schéma — et c'est perdu si la boucle ne lève que la dernière erreur. Une exception portant les sorties et les messages de validation par tentative rendrait la fonctionnalité débogable, et pas seulement sûre.

                        En termes Symfony, c'est le même réflexe que ne pas router un échec métier dans la stratégie de retry de Messenger : cette stratégie est juste pour le transport, et fausse dès l'instant où c'est la charge utile qui pose problème.
                        TXT,
                ],
            ],
            'en' => [
                [
                    'title' => 'Transport retry, validation re-prompt: two mechanisms, one word',
                    'project' => 'symfony/ai',
                    'reference' => 'Issue #1688',
                    'url' => 'https://github.com/symfony/ai/issues/1688',
                    'summary' => 'An API proposal inspired by PydanticAI filed two different operations under the word "retry". Replaying a request the transport rejected and re-prompting a model with a validation error share neither their cost, nor their budget, nor what the caller needs back when the loop runs out.',
                    'body' => <<<'TXT'
                        Since #2206, transport failures have a home: a 5xx or an overloaded error is infrastructure, and retrying the identical request is the correct response. Re-prompting a model with a validation error is not that — it is a new request with a mutated message history. Same word, different operation.

                        `maxRetries` stays ambiguous while both mechanisms wear the name. If a user sets `maxRetries: 3`, is that three model calls, or three validation attempts each of which may itself retry the transport N times — up to nine? Transport retries should not consume the validation budget, and the two limits should be configured separately. A distinct name — `maxCorrections`, `maxRepairAttempts` — would make that self-documenting.

                        The cost profile is the opposite one. A transport retry costs latency: the request was rejected, nothing was generated. A correction attempt costs a full inference round and grows the history it sends, so attempt 3 is strictly more expensive than attempt 1 and eats context window. That holds against a hosted API and against a local model: the currency differs, the direction does not. Which puts the correction budget in the same family as point 1's tool call limit — both cap consumption within a single run, where a rate limiter bounds usage across runs over time.

                        What the caller gets on exhaustion matters more here. When a transport retry runs out, the last exception is the whole story. When a correction loop runs out, the useful artefact is the trail: what the model returned at each attempt, and which validation message it was handed back. That is what you need to fix the prompt or the schema — and it is lost if the loop only throws the last error. An exception carrying the per-attempt outputs and validation messages would make the feature debuggable rather than merely safe.

                        In Symfony terms this is the same reflex as not routing a domain failure through the Messenger retry strategy: that strategy is right for transport, and wrong the moment the payload itself is the problem.
                        TXT,
                ],
            ],
        ];
    }
}
