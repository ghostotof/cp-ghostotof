<?php

declare(strict_types=1);

namespace App\Portfolio\About\Presentation\Command;

use App\Portfolio\About\Application\AboutMeCardAdministratorInterface;
use App\Portfolio\About\Application\AboutSettingsAdministratorInterface;
use App\Portfolio\About\Application\AboutSiteCardAdministratorInterface;
use App\Portfolio\About\Domain\Entity\AboutSettings;
use App\Portfolio\About\Domain\Repository\AboutMeCardRepositoryInterface;
use App\Portfolio\About\Domain\Repository\AboutSettingsRepositoryInterface;
use App\Portfolio\About\Domain\Repository\AboutSiteCardRepositoryInterface;
use App\Portfolio\About\Domain\ValueObject\AboutMeCardCategory;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Peuple/réinitialise le contenu de la page À propos (fr/en) avec son contenu
 * de référence (repris de l'ancien contenu statique frontend
 * infrastructure/portfolio/content/{fr,en}.ts, aujourd'hui servi depuis la
 * base via GET /api/about/{locale}). Idempotente : chaque exécution purge et
 * recrée les cartes, et upsert les réglages (AboutSettings est un singleton
 * par locale, jamais supprimé).
 */
#[AsCommand(
    name: 'app:about:seed',
    description: 'Peuple/réinitialise le contenu de la page À propos (fr/en) avec son contenu de référence.',
)]
final class SeedAboutContentCommand extends Command
{
    public function __construct(
        private readonly AboutSettingsRepositoryInterface $aboutSettingsRepository,
        private readonly AboutSiteCardRepositoryInterface $aboutSiteCardRepository,
        private readonly AboutMeCardRepositoryInterface $aboutMeCardRepository,
        private readonly AboutSettingsAdministratorInterface $aboutSettingsAdministrator,
        private readonly AboutSiteCardAdministratorInterface $aboutSiteCardAdministrator,
        private readonly AboutMeCardAdministratorInterface $aboutMeCardAdministrator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        foreach (self::content() as $localeValue => $content) {
            $locale = Locale::from($localeValue);

            $this->upsertSettings($locale, $content);
            $this->reseedSiteCards($locale, $content['site']['cards']);
            $this->reseedMeCards($locale, AboutMeCardCategory::TECHNICAL, $content['me']['technicalCards']);
            $this->reseedMeCards($locale, AboutMeCardCategory::PERSONAL, $content['me']['personalCards']);
            $this->reseedMeCards($locale, AboutMeCardCategory::HOBBY, $content['me']['hobbiesCards']);

            $io->success(sprintf(
                '[%s] Réglages à jour, %d carte(s) site, %d carte(s) technique(s), %d carte(s) personnelle(s), %d carte(s) loisir(s).',
                $localeValue,
                \count($content['site']['cards']),
                \count($content['me']['technicalCards']),
                \count($content['me']['personalCards']),
                \count($content['me']['hobbiesCards']),
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * @param array{
     *     site: array{eyebrow: string, cards: list<array{title: string, description: string, iconKey: ?string}>},
     *     me: array{
     *         eyebrow: string,
     *         technicalSubtitle: string,
     *         technicalCards: list<array{title: string, description: string, iconKey: ?string}>,
     *         personalSubtitle: string,
     *         personalCards: list<array{title: string, description: string, iconKey: ?string}>,
     *         hobbiesSubtitle: string,
     *         hobbiesCards: list<array{title: string, description: string, iconKey: ?string}>,
     *     },
     * } $content
     */
    private function upsertSettings(Locale $locale, array $content): void
    {
        $existing = $this->aboutSettingsRepository->findByLocale($locale);

        if (null !== $existing) {
            $this->aboutSettingsAdministrator->update(
                $locale,
                $content['site']['eyebrow'],
                $content['me']['eyebrow'],
                $content['me']['technicalSubtitle'],
                $content['me']['personalSubtitle'],
                $content['me']['hobbiesSubtitle'],
            );

            return;
        }

        $this->aboutSettingsRepository->save(new AboutSettings(
            $locale,
            $content['site']['eyebrow'],
            $content['me']['eyebrow'],
            $content['me']['technicalSubtitle'],
            $content['me']['personalSubtitle'],
            $content['me']['hobbiesSubtitle'],
        ));
    }

    /**
     * @param list<array{title: string, description: string, iconKey: ?string}> $cards
     */
    private function reseedSiteCards(Locale $locale, array $cards): void
    {
        foreach ($this->aboutSiteCardRepository->findByLocale($locale) as $existing) {
            $this->aboutSiteCardRepository->remove($existing);
        }

        foreach ($cards as $position => $card) {
            $this->aboutSiteCardAdministrator->create($locale, $card['title'], $card['description'], $card['iconKey'], $position);
        }
    }

    /**
     * @param list<array{title: string, description: string, iconKey: ?string}> $cards
     */
    private function reseedMeCards(Locale $locale, AboutMeCardCategory $category, array $cards): void
    {
        foreach ($this->aboutMeCardRepository->findByLocaleAndCategory($locale, $category) as $existing) {
            $this->aboutMeCardRepository->remove($existing);
        }

        foreach ($cards as $position => $card) {
            $this->aboutMeCardAdministrator->create($locale, $category, $card['title'], $card['description'], $card['iconKey'], $position);
        }
    }

    /**
     * @return array<string, array{
     *     site: array{eyebrow: string, cards: list<array{title: string, description: string, iconKey: ?string}>},
     *     me: array{
     *         eyebrow: string,
     *         technicalSubtitle: string,
     *         technicalCards: list<array{title: string, description: string, iconKey: ?string}>,
     *         personalSubtitle: string,
     *         personalCards: list<array{title: string, description: string, iconKey: ?string}>,
     *         hobbiesSubtitle: string,
     *         hobbiesCards: list<array{title: string, description: string, iconKey: ?string}>,
     *     },
     * }>
     */
    private static function content(): array
    {
        return [
            'fr' => [
                'site' => [
                    'eyebrow' => 'À propos de ce site',
                    'cards' => [
                        ['title' => 'Architecture', 'description' => "Application découplée en deux parties indépendantes : une API Symfony côté backend et une interface Vue.js/TypeScript côté frontend, conçues selon les principes du DDD et de l'architecture propre.", 'iconKey' => 'layers'],
                        ['title' => 'Stack technique', 'description' => 'Symfony, Doctrine/PostgreSQL, messagerie asynchrone, Vue 3 et TypeScript, le tout orchestré avec Docker Compose pour un environnement reproductible.', 'iconKey' => 'server'],
                        ['title' => 'Confidentialité', 'description' => "Aucune information personnelle identifiable n'est visible sans authentification : un compte invité générique permet de découvrir le site en toute confidentialité.", 'iconKey' => 'shield'],
                        ['title' => 'Conception', 'description' => "Site conçu et développé par moi-même, avec l'aide d'outils d'intelligence artificielle pour accélérer certaines étapes tout en gardant la main sur les choix techniques.", 'iconKey' => 'sparkles'],
                    ],
                ],
                'me' => [
                    'eyebrow' => 'À propos de moi',
                    'technicalSubtitle' => 'Techniquement',
                    'technicalCards' => [
                        ['title' => 'Développeur PHP/Symfony senior', 'description' => "Plusieurs années d'expérience en développement web, avec une appétence pour les architectures propres (DDD, SOLID, Clean Architecture) et un code maintenable dans la durée.", 'iconKey' => 'code'],
                        ['title' => 'Approche full-stack', 'description' => "À l'aise sur l'ensemble de la chaîne : backend Symfony/Doctrine, frontend Vue.js/TypeScript, conteneurisation Docker.", 'iconKey' => 'boxes'],
                        ['title' => 'Exigence qualité & sécurité', 'description' => 'Tests automatisés, analyse statique, revues de code : la qualité, la sécurité et la maintenabilité sont une priorité, pas une option.', 'iconKey' => 'shield'],
                    ],
                    'personalSubtitle' => 'Humainement',
                    'personalCards' => [
                        ['title' => 'Curieux et autodidacte', 'description' => 'Toujours en veille technique, avec un vrai plaisir à explorer de nouvelles pratiques et technologies en dehors du cadre professionnel.', 'iconKey' => 'lightbulb'],
                        ['title' => 'Rigoureux et pédagogue', 'description' => "J'aime comprendre le pourquoi avant le comment, et documenter mes choix techniques pour qu'ils restent compréhensibles dans le temps.", 'iconKey' => 'target'],
                        ['title' => 'Collaboratif', 'description' => "J'apprécie les échanges techniques, la remise en question constructive et le partage de connaissances au sein d'une équipe.", 'iconKey' => 'users'],
                    ],
                    'hobbiesSubtitle' => 'En dehors du travail',
                    'hobbiesCards' => [
                        ['title' => 'Musique & guitare', 'description' => "Je joue de la guitare et j'aime explorer différents styles musicaux ; je m'essaye aussi, doucement, à la MAO.", 'iconKey' => 'guitar'],
                        ['title' => 'Moto', 'description' => "Amateur de moto, j'apprécie autant la mécanique que les sorties sur route.", 'iconKey' => 'motorbike'],
                        ['title' => 'Littérature', 'description' => 'Grand lecteur, toujours un roman en cours à côté de mes lectures techniques.', 'iconKey' => 'book-open'],
                        ['title' => 'Cinéma & séries', 'description' => 'Cinéphile et amateur de séries, entre grands classiques et découvertes récentes.', 'iconKey' => 'clapperboard'],
                        ['title' => 'Plongée sous-marine', 'description' => "La plongée sous-marine est une vraie passion, entre exploration et calme sous l'eau.", 'iconKey' => 'waves'],
                    ],
                ],
            ],
            'en' => [
                'site' => [
                    'eyebrow' => 'About this site',
                    'cards' => [
                        ['title' => 'Architecture', 'description' => 'Decoupled into two independent parts: a Symfony API on the backend and a Vue.js/TypeScript interface on the frontend, designed around DDD and clean architecture principles.', 'iconKey' => 'layers'],
                        ['title' => 'Tech stack', 'description' => 'Symfony, Doctrine/PostgreSQL, asynchronous messaging, Vue 3 and TypeScript, all orchestrated with Docker Compose for a reproducible environment.', 'iconKey' => 'server'],
                        ['title' => 'Privacy', 'description' => 'No personally identifiable information is visible without authentication: a generic guest account lets visitors explore the site with full confidentiality.', 'iconKey' => 'shield'],
                        ['title' => 'Design', 'description' => 'Designed and built by me, with the help of AI tools to speed up some steps while staying in control of the technical decisions.', 'iconKey' => 'sparkles'],
                    ],
                ],
                'me' => [
                    'eyebrow' => 'About me',
                    'technicalSubtitle' => 'Technically',
                    'technicalCards' => [
                        ['title' => 'Senior PHP/Symfony developer', 'description' => 'Several years of experience in web development, with a strong interest in clean architectures (DDD, SOLID, Clean Architecture) and code that stays maintainable over time.', 'iconKey' => 'code'],
                        ['title' => 'Full-stack approach', 'description' => 'Comfortable across the whole chain: Symfony/Doctrine backend, Vue.js/TypeScript frontend, Docker containerization.', 'iconKey' => 'boxes'],
                        ['title' => 'Quality & security mindset', 'description' => 'Automated tests, static analysis, code reviews: quality, security, and maintainability are a priority, not an afterthought.', 'iconKey' => 'shield'],
                    ],
                    'personalSubtitle' => 'Personally',
                    'personalCards' => [
                        ['title' => 'Curious and self-taught', 'description' => 'Always keeping an eye on new practices and technologies, and genuinely enjoying exploring them outside of work.', 'iconKey' => 'lightbulb'],
                        ['title' => 'Thorough and a good communicator', 'description' => 'I like understanding the why before the how, and documenting technical decisions so they stay understandable over time.', 'iconKey' => 'target'],
                        ['title' => 'Collaborative', 'description' => 'I enjoy technical discussions, constructive challenge, and sharing knowledge within a team.', 'iconKey' => 'users'],
                    ],
                    'hobbiesSubtitle' => 'Outside of work',
                    'hobbiesCards' => [
                        ['title' => 'Music & guitar', 'description' => "I play the guitar and enjoy exploring different musical styles; I'm also slowly dabbling in music production.", 'iconKey' => 'guitar'],
                        ['title' => 'Motorcycling', 'description' => 'A motorcycle enthusiast, I enjoy both the mechanics and the rides.', 'iconKey' => 'motorbike'],
                        ['title' => 'Literature', 'description' => 'An avid reader, always with a novel on the go alongside technical reading.', 'iconKey' => 'book-open'],
                        ['title' => 'Movies & TV shows', 'description' => 'A film and TV buff, enjoying both classics and recent discoveries.', 'iconKey' => 'clapperboard'],
                        ['title' => 'Scuba diving', 'description' => 'Scuba diving is a real passion, between exploration and calm underwater.', 'iconKey' => 'waves'],
                    ],
                ],
            ],
        ];
    }
}
