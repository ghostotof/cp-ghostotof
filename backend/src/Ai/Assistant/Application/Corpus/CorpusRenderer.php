<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Application\Corpus;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\State\ProviderInterface;
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
            '## '.self::text($section->title),
            \sprintf($labels['yearsOfExperience'], $section->yearsOfExperience),
            '### '.$labels['skills'],
            self::text($section->skills),
            '### '.$labels['achievements'],
            self::text($section->achievements),
        ]);
    }

    /**
     * @param Labels $labels
     */
    private static function caseStudyEntry(CaseStudyResource $caseStudy, array $labels): string
    {
        return implode("\n\n", [
            '## '.self::text($caseStudy->title),
            '### '.$labels['problem'],
            self::text($caseStudy->problem),
            '### '.$labels['solution'],
            self::text($caseStudy->solution),
            '### '.$labels['tradeoffs'],
            self::text($caseStudy->tradeoffs),
            '### '.$labels['measuredResult'],
            self::text($caseStudy->measuredResult),
        ]);
    }

    /**
     * Fins de ligne unifiées (préfixe byte-identique, D8) et balises du bloc
     * retirées, casse comprise : une donnée ne referme pas le bloc de documents.
     */
    private static function text(string $value): string
    {
        return trim(str_ireplace([self::CLOSING_TAG, self::OPENING_TAG], '', str_replace(["\r\n", "\r"], "\n", $value)));
    }
}
