<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Infrastructure\Corpus;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\State\ProviderInterface;
use App\Ai\Assistant\Application\Corpus\CorpusRendererInterface;
use App\Ai\Assistant\Domain\Exception\CorpusRenderingException;
use App\Portfolio\AnonymousCv\Infrastructure\ApiPlatform\AnonymousCvProvider;
use App\Portfolio\AnonymousCv\Presentation\ApiResource\AnonymousCvSectionResource;
use App\Portfolio\CaseStudy\Infrastructure\ApiPlatform\CaseStudyProvider;
use App\Portfolio\CaseStudy\Presentation\ApiResource\CaseStudyResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Corpus de l'assistant, en Markdown déterministe (spec 0005 D5).
 *
 * Les sources sont lues par les providers publics, jamais par un repository :
 * l'assistant ne peut ainsi rien savoir que la personne ne lise déjà sur le
 * site, et une injection de prompt réussie ne révèle rien (ADR 0004 D7).
 * CorpusSourcesTest pince la liste.
 *
 * Le document est toujours délimité, même vide, et chaque section vide le dit
 * en toutes lettres : sans bloc explicite, mistral-small-3.2 invente un
 * parcours complet (mesure du 2026-09-26, journal de la spec). L'ordre des
 * entrées est celui des providers (position croissante), le même que sur les
 * pages. Aucun champ technique (id, groupe, locale) n'y figure, et le rendu est
 * byte-identique d'un appel à l'autre : c'est la condition du cache de prompt
 * du fournisseur (D8).
 *
 * @phpstan-type Labels array{anonymousCv: string, caseStudies: string, yearsOfExperience: string, skills: string, achievements: string, problem: string, solution: string, tradeoffs: string, measuredResult: string, empty: string}
 */
final readonly class CorpusRenderer implements CorpusRendererInterface
{
    private const string OPENING_TAG = '<documents>';
    private const string CLOSING_TAG = '</documents>';
    // Quantificateurs possessifs : sans eux, les deux `\s*` se partagent la même
    // suite d'espaces et le retour arrière devient quadratique (contre-audit).
    private const string TAG_CHEVRON = '#[<＜](?=\s*+[/／]?+\s*+documents)#iu';

    /**
     * @param ProviderInterface<AnonymousCvSectionResource> $anonymousCvProvider
     * @param ProviderInterface<CaseStudyResource>          $caseStudyProvider
     */
    public function __construct(
        #[Autowire(service: AnonymousCvProvider::class)]
        private ProviderInterface $anonymousCvProvider,
        #[Autowire(service: CaseStudyProvider::class)]
        private ProviderInterface $caseStudyProvider,
    ) {
    }

    public function render(Locale $locale): string
    {
        $labels = $this->labels($locale);

        $blocks = [
            self::OPENING_TAG,
            ...$this->section($labels['anonymousCv'], array_map(
                static fn (AnonymousCvSectionResource $section): string => self::anonymousCvEntry($section, $labels),
                $this->read($this->anonymousCvProvider, AnonymousCvSectionResource::class, $locale),
            ), $labels),
            ...$this->section($labels['caseStudies'], array_map(
                static fn (CaseStudyResource $caseStudy): string => self::caseStudyEntry($caseStudy, $labels),
                $this->read($this->caseStudyProvider, CaseStudyResource::class, $locale),
            ), $labels),
            self::CLOSING_TAG,
        ];

        return implode("\n\n", $blocks)."\n";
    }

    /**
     * Intertitres dans la langue du corpus : des libellés de rendu pour le
     * modèle, pas des chaînes d'interface (spec §7), repris des libellés du
     * frontend pour que l'assistant nomme les sections comme la personne les
     * lit. Le `match` sur l'enum est exhaustif : une troisième locale échoue à
     * l'analyse statique tant qu'elle n'a pas ses libellés.
     *
     * @return Labels
     */
    private function labels(Locale $locale): array
    {
        return match ($locale) {
            Locale::FR => [
                'anonymousCv' => 'CV sans identité',
                'caseStudies' => 'Études de cas',
                'yearsOfExperience' => "Années d'expérience : %d",
                'skills' => 'Compétences',
                'achievements' => 'Réalisations',
                'problem' => 'Problème',
                'solution' => 'Solution',
                'tradeoffs' => 'Compromis',
                'measuredResult' => 'Résultat mesuré',
                'empty' => 'Aucun document disponible pour cette section.',
            ],
            Locale::EN => [
                'anonymousCv' => 'CV without identity',
                'caseStudies' => 'Case studies',
                'yearsOfExperience' => 'Years of experience: %d',
                'skills' => 'Skills',
                'achievements' => 'Achievements',
                'problem' => 'Problem',
                'solution' => 'Solution',
                'tradeoffs' => 'Trade-offs',
                'measuredResult' => 'Measured result',
                'empty' => 'No document available for this section.',
            ],
        };
    }

    /**
     * @template T of object
     *
     * @param ProviderInterface<T> $provider
     * @param class-string<T>      $class
     *
     * @return list<T>
     */
    private function read(ProviderInterface $provider, string $class, Locale $locale): array
    {
        $result = $provider->provide(new GetCollection(), ['locale' => $locale->value]);
        if (!is_iterable($result)) {
            return [];
        }

        $entries = [];
        foreach ($result as $entry) {
            if ($entry instanceof $class) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @param list<string> $entries
     * @param Labels       $labels
     *
     * @return list<string>
     */
    private function section(string $heading, array $entries, array $labels): array
    {
        return ['# '.$heading, ...([] === $entries ? [$labels['empty']] : $entries)];
    }

    /**
     * @param Labels $labels
     */
    private static function anonymousCvEntry(AnonymousCvSectionResource $section, array $labels): string
    {
        return implode("\n\n", [
            '## '.self::title($section->title),
            \sprintf($labels['yearsOfExperience'], $section->yearsOfExperience),
            '### '.$labels['skills'],
            self::prose($section->skills),
            '### '.$labels['achievements'],
            self::prose($section->achievements),
        ]);
    }

    /**
     * @param Labels $labels
     */
    private static function caseStudyEntry(CaseStudyResource $caseStudy, array $labels): string
    {
        return implode("\n\n", [
            '## '.self::title($caseStudy->title),
            '### '.$labels['problem'],
            self::prose($caseStudy->problem),
            '### '.$labels['solution'],
            self::prose($caseStudy->solution),
            '### '.$labels['tradeoffs'],
            self::prose($caseStudy->tradeoffs),
            '### '.$labels['measuredResult'],
            self::prose($caseStudy->measuredResult),
        ]);
    }

    /** Un titre reste sur sa ligne d'intertitre : il n'ouvre pas de rubrique de son cru. */
    private static function title(string $value): string
    {
        return self::replace('/\s*\n\s*/', ' ', self::text($value));
    }

    /**
     * Un champ de prose ne fabrique pas de structure : un `#` en début de ligne
     * est échappé, seul le rendu pose les intertitres que le modèle cite
     * (règle 4 du préambule).
     */
    private static function prose(string $value): string
    {
        return self::replace('/^([ \t]*)#/m', '$1\#', self::text($value));
    }

    /**
     * Fins de ligne unifiées (préfixe byte-identique, D8) et balises du bloc
     * neutralisées : une donnée ne referme pas le bloc de documents.
     *
     * On retire le chevron qui introduit `documents` (espaces, barre, casse et
     * pleine chasse comprises) plutôt que la balise entière : une balise non
     * fermée n'avale ainsi aucun texte, et sans chevron ce n'est plus qu'un mot.
     * En boucle, parce qu'un retrait peut en rapprocher un autre
     * (`<<documents` redevient `<documents`).
     *
     * Cette neutralisation n'est **pas exhaustive** et ne prétend pas l'être :
     * entités HTML, caractères de largeur nulle, homoglyphes (`‹`, `〈`…) ou
     * lettres d'autres alphabets passent. Une liste d'exclusion ne sera jamais
     * complète. La parade réelle est ailleurs : le préambule traite tout le
     * corpus comme de la donnée (règle 6), et seul ROLE_SUPER écrit ce contenu.
     */
    private static function text(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        do {
            $previous = $value;
            $value = self::replace(self::TAG_CHEVRON, '', $value);
        } while ($value !== $previous);

        return trim($value);
    }

    /**
     * preg_replace rend `null` en cas d'échec : casté en chaîne, le champ
     * disparaîtrait du corpus sans que rien ne le signale.
     */
    private static function replace(string $pattern, string $replacement, string $value): string
    {
        return preg_replace($pattern, $replacement, $value)
            ?? throw new CorpusRenderingException(\sprintf('Rendu du corpus impossible : %s.', preg_last_error_msg()));
    }
}
