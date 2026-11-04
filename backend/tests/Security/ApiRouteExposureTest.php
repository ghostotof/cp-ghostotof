<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\Authentication\Infrastructure\Http\AuthCookieFactory;
use App\Security\Authentication\Infrastructure\Http\CsrfCookieRequestSubscriber;
use App\Security\Authentication\Infrastructure\Http\CsrfCookieTokenSigner;
use App\Security\Authentication\Infrastructure\Security\PublicRouteIgnoresRefusedSessionListener;
use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Shared\Infrastructure\Http\CanonicalPath;
use App\Tests\Support\ConcreteApiUrl;
use App\Tests\Support\HttpJson;
use App\Tests\Support\RefusedBearerCookie;
use App\Tests\Support\TestCredentials;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserKitCookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

/**
 * Garde-fou transverse : **aucune route /api ne doit renvoyer de donnée à un
 * appelant qui n'y a pas droit**, indépendamment de ce que le frontend choisit
 * d'afficher. Le masquage côté client n'est jamais une protection.
 *
 * Contrairement aux tests par ressource (BackofficeExperienceTechnologyResourceTest, etc.) qui
 * vérifient chacun *leurs* endpoints, ce test énumère le routeur : une route
 * ajoutée demain est automatiquement soumise à la règle. Pour qu'elle soit
 * servie sans authentification, il faut l'inscrire **explicitement** dans
 * PUBLIC_PATHS avec sa justification — un choix conscient, jamais un oubli.
 *
 * Dix invariants :
 *  1. toute route hors PUBLIC_PATHS refuse un appelant anonyme (401 ou 403) ;
 *  2. aucune route /api/backoffice ne peut être inscrite dans PUBLIC_PATHS ;
 *  3. tout le backoffice refuse un compte authentifié mais sans ROLE_SUPER
 *     (authentifié ≠ autorisé) ;
 *  4. /api/cv et /api/me refusent un compte sans ROLE_TRUSTED (ADR 0003) ;
 *  5. idem pour le jeton du palier de base émis sans compte (D6) ;
 *  6. ce jeton n'ouvre **rien** hors PUBLIC_PATHS ∪ BASE_TIER_PATHS — la
 *     liste de ce que le palier de base porte est explicite, comme
 *     PUBLIC_PATHS l'est pour l'anonyme ;
 *  7. aucune réponse ne pose d'autre cookie que `BEARER` et `XSRF-TOKEN`,
 *     avec les attributs exacts d'AuthCookieFactory (issue #714) — vérifié
 *     après chaque requête de ce test, et sur chaque route publique. Le
 *     pendant à l'exécution d'AuthCookiesComeFromTheFactoryTest, qui ne lit
 *     que `src/` : il voit aussi le vendor, la configuration et les appels
 *     dynamiques, sur les seules réponses produites ici ;
 *  8. un cookie `BEARER` refusé (invalide, expiré) ne donne jamais **plus ni autre
 *     chose** que l'anonyme : sur chaque route et chaque méthode, la réponse a le
 *     statut de celle d'un appelant sans cookie (#748, R35 — une route publique
 *     ne répond plus 401 à un cookie mort ; une route protégée garde son refus),
 *     et une route publique ne pose aucun cookie que l'anonyme ne pose pas ;
 *     sur le pare-feu `api`, une route qui exige un rôle (`requiresAccess()`, la source de
 *     vérité du listener) répond 401 à l'anonyme (C7-10) — jamais la réciproque : le 401
 *     métier d'une route publique n'est pas une session refusée (CR6-6) —, et ce 401 expire
 *     `BEARER` et `XSRF-TOKEN` aux attributs exacts d'`AuthCookieFactory::expired()` (#748,
 *     R46, R59 ; `mcp` et `oauth` ne lisent pas le cookie, ils n'ont rien à effacer). Les
 *     écritures portent une paire CSRF valide (cookie et en-tête) : sans elle, le
 *     double-submit répond 403 avant le pare-feu et l'égalité des statuts serait triviale ;
 *  9. sur le pare-feu `api`, une route publique est exactement une route sans règle
 *     d'`access_control` : `requiresAccess()` vaut vrai si et seulement si le chemin n'est ni
 *     dans PUBLIC_PATHS ni dans RULELESS_OUTSIDE_PUBLIC_PATHS (#748, R73), à l'exécution sur
 *     le routeur et l'`AccessMap` : c'est la prémisse « route publique = route sans règle ».
 *     Les témoins de cette prémisse sont listés une fois, dans
 *     `.claude/rules/security-authentication-sessions.md` (puce de
 *     `PublicRouteIgnoresRefusedSessionListener`) ;
 * 10. chaque route de `/api` déclare ses méthodes, et chacune est jugée par le double-submit : `GET`, `HEAD`,
 *     `OPTIONS` ou l'une des `UNSAFE_METHODS` de `CsrfCookieRequestSubscriber` (#748, R89).
 */
final class ApiRouteExposureTest extends WebTestCase
{
    use HttpJson;
    use RefusedBearerCookie;

    private const string PLAIN_USERNAME = 'jane';

    /**
     * Routes délibérément servies sans authentification, chacune justifiée.
     *
     * @var array<string, string> chemin de route (tel que déclaré au routeur) => raison
     */
    private const array PUBLIC_PATHS = [
        // --- Contenu de vitrine, non identifiant, éditable au backoffice :
        // c'est à la saisie qu'on décide de ce qui est publié.
        '/api/about/{locale}' => 'Page « À propos » — publique dans son intégralité (audit C3).',
        '/api/quality/{locale}' => 'Principes/traits qualité — contenu de démonstration.',
        '/api/experience/technologies' => 'Liste de technologies — contenu de démonstration.',
        '/api/contributions/{locale}' => 'Contributions techniques publiques : précisément le contenu destiné au visiteur non authentifié arrivé depuis LinkedIn. Aucune donnée personnelle identifiante.',
        '/api/incidents/{locale}' => 'Journal des pannes de production et des invariants qui en sont sortis : contenu de démonstration, sans donnée personnelle identifiante.',
        // Formulation revue le 2026-09-08 : la version initiale affirmait que la
        // page ne révélait « rien qu'un visiteur ne puisse déjà déduire du dépôt
        // public ». C'est vrai des numéros de version (.env et versions.lock sont
        // suivis en git), mais la page en dit un peu plus, et l'écrire vaut mieux
        // que de le sous-entendre : elle confirme que ces versions tournent
        // effectivement, et signale celles dont un correctif est disponible mais
        // non appliqué. Le dépôt dit ce qui est épinglé ; la page dit ce qui est
        // en retard. Divulgation assumée : c'est le sujet même de la page, et la
        // réponse honnête au risque est d'appliquer les correctifs.
        '/api/watch' => 'Veille technique : versions de la stack et échéances de support, lues dans le snapshot local. Aucune donnée personnelle identifiante. Les versions elles-mêmes figurent déjà dans .env et versions.lock, suivis dans ce dépôt public ; la page y ajoute qu\'elles sont en service et qu\'un correctif reste parfois à appliquer — divulgation délibérée, c\'est l\'objet de la page. Le volet vulnérabilités n\'est exposé ici qu\'agrégé : le détail des CVE reste ROLE_SUPER (décision D4).',

        // --- Parcours publics par conception.
        '/api/contact' => 'Formulaire de contact anonyme (honeypot + rate limit IP).',
        '/api/account/password-setup' => 'Définition du mot de passe via lien e-mail (POST {token, password}) : l\'appelant n\'a pas encore de compte utilisable, il ne peut donc pas être authentifié. Le jeton du corps (64 hex, à usage unique, 48 h) est la seule preuve exigée ; la réponse est un 204 sans corps, aucune donnée servie. Rate limit IP avant désérialisation (PasswordSetupRateLimitRequestListener) + zone nginx pwsetup.',
        '/api/account/password-setup/validate' => 'Vérifie qu\'un jeton d\'invitation est encore exploitable avant d\'afficher le formulaire (POST {token}, audit A7/D6 : le jeton voyage dans le corps, plus jamais dans un chemin). Même appelant sans compte que ci-dessus ; ne répond que par un statut (204 sans corps, ou le même 404 problem+json pour tout lien inutilisable, issue #362), sans nom de compte ni e-mail. Partage le quota IP de la route précédente.',
        '/api/account/base-access' => 'ADR 0003 D6 : émet un jeton ROLE_USER (palier de base, publiable/non-identifiant) sans authentification — c\'est exactement l\'objet de l\'endpoint. Rate limité par IP (BaseAccessRateLimitRequestListener). N\'ouvre jamais /api/cv ni /api/me (ROLE_TRUSTED requis), voir BaseAccessControllerTest.',
        '/api/login_check' => 'Point d\'entrée du login : par définition atteint sans être authentifié.',
        '/api/mcp' => 'Serveur MCP (ADR 0006) : canal de lecture qui ne sert rien qu\'une route du même palier ne sert déjà (test d\'exposition symétrique) ; le palier de confiance y exigera une connexion MCP (phase 2), jamais un cookie — son firewall n\'en lit aucun.',
        '/api/oauth/authorize' => 'Point d\'autorisation OAuth de la connexion MCP (#647, RFC 6749 §3.1) : atteint par le navigateur de la personne avant toute connexion, donc par définition sans authentification. Ne sert aucune donnée : il valide la demande du client et renvoie vers la page de consentement (où la personne est lue), vers le client avec une erreur, ou répond le JSON d\'erreur de league. Son firewall (`oauth`) ne lit aucun cookie.',
        '/api/oauth/token' => 'Point de jeton OAuth de la connexion MCP (#647, RFC 6749 §3.2) : appelé par le client MCP, qui n\'a pas d\'autre preuve que le code et son vérificateur PKCE (clients publics, sans secret) — par définition sans authentification. N\'émet de jeton que contre un code accordé par un compte de confiance au consentement, ou contre un jeton de rafraîchissement ; ne sert aucune donnée de contenu. Son firewall (`oauth`) ne lit aucun cookie.',
        '/api/oauth/revoke' => 'Point de révocation OAuth de la connexion MCP (#647, RFC 7009) : appelé par le client MCP pour rendre un jeton, sans autre preuve que le jeton lui-même. Ne sert aucune donnée : 200 au corps vide, que le jeton existe ou non, ou une erreur OAuth. Son firewall (`oauth`) ne lit aucun cookie.',

        // --- Infrastructure API Platform. enable_docs est à false sous
        // when@prod (cf. api_platform.yaml) : le schéma n'existe ni en préprod
        // ni en prod, seulement en dev/test. Le point d'entrée Hydra
        // (/api/{index}.{_format}) est coupé partout depuis l'issue #360.
        '/api/docs.{_format}' => 'Schéma OpenAPI — désactivé en préprod/prod (when@prod).',
        '/api/errors/{status}.{_format}' => 'Ressource d\'erreur interne d\'API Platform, aucune donnée métier.',
        '/api/validation_errors/{id}' => 'Ressource d\'erreur de validation interne d\'API Platform.',
        '/api/.well-known/genid/{id}' => 'Identifiants anonymes générés par API Platform, aucune donnée métier.',
        '/api/.well-known/genid/{id}.{_format}' => 'Idem, variante formatée.',
    ];

    /**
     * Routes que le palier de base (ROLE_USER, ADR 0003) ouvre, et lui seul
     * en plus de PUBLIC_PATHS. La liste est fermée : une route qu'un jeton
     * D6 atteint sans figurer ici fait rougir l'invariant n°6. Y inscrire
     * une route revient à affirmer qu'elle ne porte aucune donnée
     * identifiante (D2 : le palier de base est publiable par construction).
     *
     * `/api/logout` figure ici et dans RULELESS_OUTSIDE_PUBLIC_PATHS : chaque liste pose sa question (ce que le
     * palier de base ouvre ; ce qui n'a pas de règle), pour la même substance — aucune donnée servie, les seuls
     * cookies de l'appelant touchés (St8-13).
     *
     * @var array<string, string> chemin de route (tel que déclaré au routeur) => raison
     */
    private const array BASE_TIER_PATHS = [
        '/api/case-studies/{locale}' => 'ADR 0003 D5 : études de cas techniques — ni nom, ni employeur, ni client.',
        '/api/anonymous-cv/{locale}' => 'ADR 0003 D5 : CV sans identité — compétences, ancienneté et réalisations par domaine, sans nom, employeur, client ni chronologie (D5 amendée le 2026-09-13).',
        '/api/logout' => '« Terminer cet accès » (issue #65) : expire les cookies, quel que soit le porteur. Ne sert aucune donnée.',
    ];

    /**
     * Routes du pare-feu `api` hors de PUBLIC_PATHS qui n'ont pourtant aucune règle d'`access_control`, chacune
     * justifiée (invariant n° 9). La liste est fermée : une route sans règle qui n'est ni publique ni ici fait
     * rougir l'invariant, et une entrée devenue inutile aussi. `/api/logout` y figure et dans BASE_TIER_PATHS, pour
     * la même substance (aucune donnée, les seuls cookies de l'appelant) : voir cette liste.
     *
     * @var array<string, string> chemin de route (tel que déclaré au routeur) => raison
     */
    private const array RULELESS_OUTSIDE_PUBLIC_PATHS = [
        '/api/logout' => '« Terminer cet accès » (#65, R35) : sans règle par construction, il expire les deux '
            .'cookies de l\'appelant, y compris d\'un porteur dont le cookie est refusé (2xx) ; une règle '
            .'lui rendrait un 401. Pas public pour autant : il ne sert aucune donnée, et le double-submit le garde de '
            .'la déconnexion forcée — un formulaire tiers part sans `X-XSRF-TOKEN`, et son 403 (priorité 20, avant le '
            .'`LogoutListener`) est la seule barrière, `SameSite=Lax` n\'empêchant pas le navigateur d\'accepter les '
            .'`Set-Cookie` expirés de la réponse. Jamais dans `EXCLUDED_PATHS` (témoin : '
            .'`AuthenticationFlowTest::testAForcedLogoutWithoutTheCsrfHeaderExpiresNoCookie`).',
    ];

    /**
     * Les méthodes qu'une route de `/api` peut déclarer en dehors de `CsrfCookieRequestSubscriber::UNSAFE_METHODS` :
     * celles que le double-submit laisse passer et qu'une route peut servir sans écrire (invariant n° 10). Une autre
     * — `QUERY`, une méthode propre — sortirait du double-submit sans qu'aucune garde le voie (C8-6).
     */
    private const array SAFE_METHODS_OUTSIDE_THE_DOUBLE_SUBMIT = ['GET', 'HEAD', 'OPTIONS'];

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    /**
     * Invariant n° 10 (#748, R89, C8-6, C8-4) : chaque route de `/api` déclare ses méthodes, et chacune est jugée par
     * le double-submit — `GET`, `HEAD`, `OPTIONS` (SAFE_METHODS_OUTSIDE_THE_DOUBLE_SUBMIT) ou l'une des
     * `CsrfCookieRequestSubscriber::UNSAFE_METHODS`. Une route sans méthode déclarée les accepte toutes et les autres
     * invariants ne la sondent qu'en GET ; une route `QUERY` ou d'une méthode propre sortirait du double-submit sans
     * qu'aucune garde le voie.
     * « Sûre » est une convention : l'invariant juge les méthodes déclarées, pas qu'une route `GET` n'écrit rien ;
     * une écriture servie en `GET` échapperait au double-submit, la relecture tient ce point (C9-7).
     */
    public function testEveryApiRouteDeclaresMethodsTheDoubleSubmitJudges(): void
    {
        $judged = [...self::SAFE_METHODS_OUTSIDE_THE_DOUBLE_SUBMIT, ...CsrfCookieRequestSubscriber::UNSAFE_METHODS];
        $checked = 0;

        foreach ($this->router()->getRouteCollection() as $route) {
            $path = $route->getPath();
            if (!CanonicalPath::isPathUnder($path, '/api')) {
                continue;
            }

            self::assertNotSame(
                [],
                $route->getMethods(),
                \sprintf('%s ne déclare aucune méthode : elle les accepte toutes, et l\'invariant n° 1 ne la sonde qu\'en GET.', $path),
            );
            foreach ($route->getMethods() as $method) {
                self::assertContains(
                    $method,
                    $judged,
                    \sprintf(
                        '%s déclare %s, que le double-submit ne juge pas : ni sûre (GET, HEAD, OPTIONS) ni dans '
                        .'CsrfCookieRequestSubscriber::UNSAFE_METHODS.',
                        $path,
                        $method,
                    ),
                );
            }
            ++$checked;
        }

        self::assertGreaterThan(0, $checked, 'Aucune route de /api sondée : le test ne vérifie plus rien.');
    }

    /**
     * Invariant n°1 — la règle de fond. Une route absente de PUBLIC_PATHS doit
     * être refusée à un anonyme : 401 (firewall JWT) pour les lectures, 403
     * (CsrfCookieRequestSubscriber, qui s'exécute au-dessus du firewall) pour
     * les méthodes d'écriture. Dans les deux cas : aucune donnée servie.
     *
     * Limite (C8-4) : un paramètre de chemin n'y prend qu'une valeur (`ConcreteApiUrl`). Exact tant qu'aucune règle
     * d'`access_control` ne nomme un segment qu'une route paramètre — vérifié le 2026-10-30. Une route sans méthode
     * déclarée (sondée en GET seul) est refusée par l'invariant n° 10.
     */
    public function testEveryRouteOutsideThePublicAllowListRefusesAnonymousCallers(): void
    {
        $client = self::createClient();
        $routes = $this->protectedApiRoutes();

        self::assertNotEmpty($routes, 'Aucune route protégée détectée : le test ne vérifie plus rien.');

        foreach ($routes as [$path, $method]) {
            $client->request($method, ConcreteApiUrl::of($path));
            $this->assertOnlyFactoryCookies($client, $method.' '.$path);

            self::assertContains(
                $client->getResponse()->getStatusCode(),
                [401, 403],
                \sprintf(
                    '%s %s a répondu %d à un appelant anonyme. Soit la route doit être protégée, '
                    .'soit elle est publique et doit être inscrite dans PUBLIC_PATHS avec sa justification.',
                    $method,
                    $path,
                    $client->getResponse()->getStatusCode(),
                ),
            );
        }
    }

    /**
     * Invariant n°2 — garde structurel : on ne peut pas « faire taire » ce test
     * en inscrivant une route d'administration dans la liste blanche.
     */
    public function testNoBackofficeRouteCanBeAllowListedAsPublic(): void
    {
        foreach (array_keys(self::PUBLIC_PATHS) as $path) {
            self::assertStringStartsNotWith(
                '/api/backoffice',
                $path,
                'Une route de backoffice ne peut jamais être publique.',
            );
        }
    }

    /**
     * Garde d'hygiène : une entrée de PUBLIC_PATHS qui ne correspond plus à
     * aucune route est un reste de refactor — elle donnerait une fausse
     * impression de couverture.
     */
    public function testThePublicAllowListHasNoStaleEntry(): void
    {
        $declaredPaths = $this->allApiRoutePaths();

        foreach (array_keys(self::PUBLIC_PATHS) as $path) {
            self::assertContains(
                $path,
                $declaredPaths,
                \sprintf('PUBLIC_PATHS déclare "%s", qui n\'existe plus dans le routeur.', $path),
            );
        }
    }

    /**
     * Invariant n°7 (issue #714), sur les routes que les autres invariants
     * n'appellent jamais : chacune de PUBLIC_PATHS, en anonyme, ne pose que
     * des cookies de la fabrique. C'est là que le vendor sert seul — le SDK
     * MCP derrière /api/mcp, dont le pont PSR-7 convertit tout `Set-Cookie`.
     * Corps `{}` et en-têtes des appels du frontend, pour aller au-delà des
     * gardes de requête ; les quotas par IP sont remis à zéro d'abord.
     */
    public function testEveryPublicRouteSetsOnlyTheFactoryCookies(): void
    {
        $client = self::createClient();
        self::getContainer()->get('cache.rate_limiter')->clear();

        foreach (array_keys(self::PUBLIC_PATHS) as $path) {
            foreach ($this->requestableMethods($this->routeMethods($path)) as $method) {
                $client->request($method, ConcreteApiUrl::of($path), server: [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_ACCEPT' => 'application/json',
                    'HTTP_X_REQUESTED_WITH' => 'fetch',
                ], content: self::jsonBody([]));
                $this->assertOnlyFactoryCookies($client, $method.' '.$path);
            }
        }
    }

    /**
     * Invariant n°8 (#748, R35) : un cookie `BEARER` **refusé** ne change pas la réponse
     * de l'anonyme. Sur chaque chemin de `/api` et chaque méthode, trois appels sur un
     * pot de cookies vidé et des quotas remis à zéro : sans cookie, avec une valeur
     * invalide, avec un JWT expiré signé par l'encodeur du conteneur. Les statuts des
     * deux derniers **égalent** celui du premier — une route publique répond à un
     * cookie mort comme à l'anonyme, une route protégée garde son refus, jamais plus
     * que l'anonyme n'obtiendrait —, et pour une route de PUBLIC_PATHS la réponse
     * n'ajoute aucun cookie à ceux de l'anonyme (le cookie refusé y est laissé, la
     * première route protégée l'efface). Sur le pare-feu `api`, une route qui exige un rôle
     * (`requiresAccess()`) répond 401 à l'anonyme (C7-10, jamais la réciproque : le 401 métier
     * d'une route publique reste toléré, CR6-6), et les deux cookies sont expirés dans la
     * réponse à un cookie refusé (`StaleSessionCookiesClearingListener`), aux attributs exacts
     * de la fabrique : sans quoi une session morte resterait dans le navigateur jusqu'à sa
     * fermeture. Une route sans règle n'y est pas jugée : l'invariant n° 9 voit qu'une route
     * hors de PUBLIC_PATHS en a une : c'est la prémisse « route publique = route sans règle ».
     * Les témoins de cette prémisse sont listés une fois, dans
     * `.claude/rules/security-authentication-sessions.md` (puce de
     * `PublicRouteIgnoresRefusedSessionListener`).
     * Mêmes corps et en-têtes que l'invariant n°7 ; les méthodes non sûres portent en plus
     * une paire `XSRF-TOKEN` valide, sans quoi le 403 du double-submit répondrait avant le
     * pare-feu dans les trois variantes (R59, C6-1).
     */
    public function testARefusedSessionCookieNeverChangesTheAnonymousAnswer(): void
    {
        $client = self::createClient();
        $cookies = ['anonyme' => null, 'invalide' => bin2hex(random_bytes(16)), 'expiré' => self::expiredJwt()];

        $checked = 0;
        foreach ($this->allApiRoutePaths() as $path) {
            foreach ($this->requestableMethods($this->routeMethods($path)) as $method) {
                $answers = [];
                foreach ($cookies as $label => $bearer) {
                    $answers[$label] = $this->answerWithBearer($client, $method, $path, $bearer);
                }

                $statuses = \sprintf(
                    '%s %s : statuts anonyme/invalide/expiré = %d/%d/%d.',
                    $method,
                    $path,
                    $answers['anonyme']['status'],
                    $answers['invalide']['status'],
                    $answers['expiré']['status'],
                );
                self::assertSame($answers['anonyme']['status'], $answers['invalide']['status'], $statuses);
                self::assertSame($answers['anonyme']['status'], $answers['expiré']['status'], $statuses);

                if (isset(self::PUBLIC_PATHS[$path])) {
                    foreach (['invalide', 'expiré'] as $label) {
                        self::assertSame(
                            $answers['anonyme']['cookies'],
                            $answers[$label]['cookies'],
                            \sprintf('%s %s pose avec un cookie %s d\'autres cookies que pour l\'anonyme.', $method, $path, $label),
                        );
                    }
                }

                // Une route du pare-feu api qui exige un rôle répond 401 à l'anonyme (C7-10), jamais la
                // réciproque : le 401 métier d'une route publique n'est pas une session refusée (CR6-6) et
                // reste toléré. Ce 401, celui du pare-feu, efface les deux cookies.
                if ('api' === $this->firewallOf($path, $method) && $this->requiresAccess($path, $method)) {
                    self::assertSame(
                        401,
                        $answers['anonyme']['status'],
                        \sprintf(
                            '%s %s exige un rôle (requiresAccess) : un appelant anonyme doit y recevoir 401, pas %d.',
                            $method,
                            $path,
                            $answers['anonyme']['status'],
                        ),
                    );

                    $factory = self::getContainer()->get(AuthCookieFactory::class);
                    foreach (['invalide', 'expiré'] as $label) {
                        foreach ([AuthCookieFactory::BEARER, AuthCookieFactory::XSRF_TOKEN] as $name) {
                            self::assertSame(
                                $this->attributes($factory->expired($name)),
                                $answers[$label]['cleared'][$name] ?? null,
                                \sprintf(
                                    '%s %s : le 401 du pare-feu api doit expirer %s aux attributs de la fabrique '
                                    .'avec un cookie %s (expirés : %s).',
                                    $method,
                                    $path,
                                    $name,
                                    $label,
                                    [] === $answers[$label]['cleared'] ? 'aucun' : implode(', ', array_keys($answers[$label]['cleared'])),
                                ),
                            );
                        }
                    }
                }
                ++$checked;
            }
        }

        self::assertGreaterThan(0, $checked, 'Aucune route sondée : le test ne vérifie plus rien.');
    }

    /**
     * Un appel isolé : pot de cookies vidé, quotas remis à zéro, `BEARER` éventuel posé
     * comme un navigateur le garderait. Une méthode non sûre porte une paire `XSRF-TOKEN`
     * valide (cookie et en-tête, signée par le signataire du conteneur), dans les trois
     * variantes : sans elle, le double-submit répond 403 avant le pare-feu. Rend le statut,
     * les noms des cookies posés et, par nom, les attributs de ceux que la réponse efface
     * (`Cookie::isCleared()` : échéance strictement positive et passée ; un cookie de session
     * a une échéance nulle).
     *
     * @return array{status: int, cookies: list<string>, cleared: array<string, array<string, mixed>>}
     */
    private function answerWithBearer(KernelBrowser $client, string $method, string $path, ?string $bearer): array
    {
        $client->getCookieJar()->clear();
        self::getContainer()->get('cache.rate_limiter')->clear();
        if (null !== $bearer) {
            self::putTheBearer($client, $bearer);
        }

        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'fetch',
        ];
        // La même liste que la garde du double-submit (une seule source) : une méthode qu'elle
        // contrôle porte la paire valide, sans quoi la requête répond 403 avant le pare-feu.
        if (\in_array($method, CsrfCookieRequestSubscriber::UNSAFE_METHODS, true)) {
            $csrfToken = self::getContainer()->get(CsrfCookieTokenSigner::class)->issue();
            $client->getCookieJar()->set(
                new BrowserKitCookie(AuthCookieFactory::XSRF_TOKEN, $csrfToken, null, '/', 'localhost', false, false, false, 'lax'),
            );
            $server['HTTP_X_XSRF_TOKEN'] = $csrfToken;
        }

        $client->request($method, ConcreteApiUrl::of($path), server: $server, content: self::jsonBody([]));

        $names = [];
        $cleared = [];
        foreach ($client->getResponse()->headers->getCookies() as $cookie) {
            $names[] = $cookie->getName();
            if ($cookie->isCleared()) {
                $cleared[$cookie->getName()] = $this->attributes($cookie);
            }
        }
        sort($names);
        ksort($cleared);

        return ['status' => $client->getResponse()->getStatusCode(), 'cookies' => $names, 'cleared' => $cleared];
    }

    /**
     * La route exige-t-elle un rôle ? Le prédicat que le listener des routes publiques lit, sur la
     * requête que le pare-feu verrait : la même source de vérité, aucune seconde liste.
     */
    private function requiresAccess(string $path, string $method): bool
    {
        return self::getContainer()->get(PublicRouteIgnoresRefusedSessionListener::class)
            ->requiresAccess(Request::create(ConcreteApiUrl::of($path), $method));
    }

    /**
     * Le pare-feu que Symfony retient pour cette requête, comme le fait le `FirewallMap` ; `null` hors de tous.
     */
    private function firewallOf(string $path, string $method): ?string
    {
        return self::getContainer()->get('security.firewall.map')
            ->getFirewallConfig(Request::create(ConcreteApiUrl::of($path), $method))
            ?->getName();
    }

    /**
     * Invariant n°9 (#748, R73, C7-1) : sur le pare-feu `api`, une route publique est **exactement** une route
     * sans règle d'`access_control`. Sur chaque chemin et chaque méthode du routeur que ce pare-feu retient,
     * `requiresAccess()` — le prédicat que `PublicRouteIgnoresRefusedSessionListener` lit pour servir un cookie
     * refusé comme l'anonyme — vaut vrai si et seulement si le chemin n'est ni dans PUBLIC_PATHS ni dans
     * RULELESS_OUTSIDE_PUBLIC_PATHS (`/api/logout`, avec sa raison). Une route hors de PUBLIC_PATHS gardée
     * autrement que par une règle (un `#[CurrentUser]` non nullable, un refus levé à la main) y est vue à
     * l'exécution, ce que le détecteur par jetons ne peut pas toujours faire ; l'invariant n° 1 s'en contente,
     * un 401 lui suffit. Lecture du routeur et de l'`AccessMap` seulement : aucune requête HTTP.
     *
     * Les pare-feux `login`, `mcp` et `oauth` n'ont pas de règle d'`access_control` et ne lisent pas le cookie :
     * hors du contrôle.
     */
    public function testOnTheApiFirewallAPublicRouteIsExactlyARouteWithoutRule(): void
    {
        $withoutRule = [];
        $withRule = [];
        $checked = 0;

        foreach ($this->allApiRoutePaths() as $path) {
            foreach ($this->requestableMethods($this->routeMethods($path)) as $method) {
                if ('api' !== $this->firewallOf($path, $method)) {
                    continue;
                }

                ++$checked;
                $expectedRule = !isset(self::PUBLIC_PATHS[$path]) && !isset(self::RULELESS_OUTSIDE_PUBLIC_PATHS[$path]);
                if ($expectedRule === $this->requiresAccess($path, $method)) {
                    continue;
                }

                if ($expectedRule) {
                    $withoutRule[] = $method.' '.$path;
                } else {
                    $withRule[] = $method.' '.$path;
                }
            }
        }

        self::assertGreaterThan(0, $checked, 'Aucune route du pare-feu api sondée : le test ne vérifie plus rien.');
        self::assertSame(
            [],
            $withoutRule,
            'Routes hors de PUBLIC_PATHS, sans règle d\'access_control : ajouter la règle dans security.yaml '
            .'(la source de l\'autorisation de /api), ou, si la route est publique, une entrée justifiée de PUBLIC_PATHS.',
        );
        self::assertSame(
            [],
            $withRule,
            'Routes de PUBLIC_PATHS ou de RULELESS_OUTSIDE_PUBLIC_PATHS qui ont une règle d\'access_control : '
            .'retirer la règle, ou sortir la route de la liste (une route à règle n\'est pas publique).',
        );
    }

    /**
     * Garde d'hygiène de RULELESS_OUTSIDE_PUBLIC_PATHS : chaque entrée existe au routeur, relève du pare-feu
     * `api`, n'est pas dans PUBLIC_PATHS et n'a pas de règle, pour chacune de ses méthodes. Une entrée qui
     * ne remplit plus ces conditions est un reste de refactor ou une route devenue protégée : la liste
     * donnerait une fausse impression de couverture.
     */
    public function testEveryRulelessExceptionIsStillNeeded(): void
    {
        $declaredPaths = $this->allApiRoutePaths();

        foreach (array_keys(self::RULELESS_OUTSIDE_PUBLIC_PATHS) as $path) {
            self::assertContains(
                $path,
                $declaredPaths,
                \sprintf('RULELESS_OUTSIDE_PUBLIC_PATHS déclare "%s", qui n\'existe plus dans le routeur.', $path),
            );
            self::assertArrayNotHasKey(
                $path,
                self::PUBLIC_PATHS,
                \sprintf('"%s" est déjà dans PUBLIC_PATHS : inutile dans RULELESS_OUTSIDE_PUBLIC_PATHS.', $path),
            );

            foreach ($this->requestableMethods($this->routeMethods($path)) as $method) {
                self::assertSame(
                    'api',
                    $this->firewallOf($path, $method),
                    \sprintf('%s %s ne relève plus du pare-feu api : retirer l\'entrée.', $method, $path),
                );
                self::assertFalse(
                    $this->requiresAccess($path, $method),
                    \sprintf('%s %s a désormais une règle d\'access_control : retirer l\'entrée.', $method, $path),
                );
            }
        }
    }

    /**
     * Invariant n°3 — authentifié ne veut pas dire autorisé. Un compte valide
     * mais sans ROLE_SUPER doit se voir refuser l'intégralité du backoffice.
     */
    public function testEveryBackofficeRouteRefusesAnAuthenticatedUserWithoutRoleSuper(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, TestCredentials::plainPassword());
        $csrfToken = $this->loginAs($client, self::PLAIN_USERNAME, TestCredentials::plainPassword());

        $routes = array_filter(
            $this->protectedApiRoutes(),
            static fn (array $route): bool => str_starts_with($route[0], '/api/backoffice'),
        );

        self::assertNotEmpty($routes, 'Aucune route de backoffice détectée.');

        foreach ($routes as [$path, $method]) {
            $client->request($method, ConcreteApiUrl::of($path), server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_XSRF_TOKEN' => $csrfToken,
            ], content: self::jsonBody([]));
            $this->assertOnlyFactoryCookies($client, $method.' '.$path);

            self::assertSame(
                403,
                $client->getResponse()->getStatusCode(),
                \sprintf('%s %s doit répondre 403 à un compte sans ROLE_SUPER.', $method, $path),
            );
        }
    }

    /**
     * Invariant n°4 — le palier de base (ADR 0003) ne suffit pas pour les
     * données identifiantes. Un compte authentifié sans ROLE_TRUSTED doit se
     * voir refuser /api/cv et /api/me, même si ROLE_USER (le palier de base)
     * est accordé.
     */
    public function testCvAndMeRefuseAnAuthenticatedUserWithoutRoleTrusted(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, TestCredentials::plainPassword());
        $this->loginAs($client, self::PLAIN_USERNAME, TestCredentials::plainPassword());

        foreach (['/api/cv', '/api/me'] as $path) {
            $client->request('GET', $path);
            $this->assertOnlyFactoryCookies($client, 'GET '.$path);

            self::assertSame(
                403,
                $client->getResponse()->getStatusCode(),
                \sprintf('GET %s doit répondre 403 à un compte sans ROLE_TRUSTED.', $path),
            );
        }
    }

    /**
     * Invariant n°5 — ADR 0003 D6. Même garde que l'invariant n°4, mais côté
     * jeton du palier de base émis par POST /api/account/base-access (aucun
     * compte, cf. BaseAccessUser/BaseAccessUserProvider) plutôt qu'un compte enregistré :
     * les deux chemins vers ROLE_USER doivent être également insuffisants pour
     * le CV/api-me.
     */
    public function testCvAndMeRefuseABaseAccessToken(): void
    {
        $client = self::createClient();
        $this->obtainBaseAccess($client);

        foreach (['/api/cv', '/api/me'] as $path) {
            $client->request('GET', $path);
            $this->assertOnlyFactoryCookies($client, 'GET '.$path);

            self::assertSame(
                403,
                $client->getResponse()->getStatusCode(),
                \sprintf('GET %s doit répondre 403 à un jeton du palier de base (sans ROLE_TRUSTED).', $path),
            );
        }
    }

    /**
     * Invariant n°6 — la contraposée des invariants n°4/5, et leur
     * généralisation : ils prouvent que le jeton du palier de base n'ouvre
     * pas /api/cv ni /api/me, celui-ci prouve qu'il n'ouvre **que**
     * BASE_TIER_PATHS. Sans lui, une future règle `ROLE_USER` sur une route
     * identifiante passerait sans qu'aucun test ne rougisse (issue #78, pt 1).
     *
     * Le jeton XSRF émis avec l'accès de base est joint aux écritures, pour
     * que le 403 attendu vienne bien de l'autorisation et non du garde CSRF —
     * sinon le test prouverait moins que ce qu'il annonce.
     */
    public function testABaseAccessTokenOpensNothingBeyondTheBaseTierAllowList(): void
    {
        $client = self::createClient();
        $csrfToken = $this->obtainBaseAccess($client);

        $routes = array_filter(
            $this->protectedApiRoutes(),
            static fn (array $route): bool => !isset(self::BASE_TIER_PATHS[$route[0]]),
        );

        self::assertNotEmpty($routes, 'Aucune route hors palier de base détectée : le test ne vérifie plus rien.');

        foreach ($routes as [$path, $method]) {
            $client->request($method, ConcreteApiUrl::of($path), server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_XSRF_TOKEN' => $csrfToken,
            ], content: self::jsonBody([]));
            $this->assertOnlyFactoryCookies($client, $method.' '.$path);

            self::assertSame(
                403,
                $client->getResponse()->getStatusCode(),
                \sprintf(
                    '%s %s a répondu %d à un jeton du palier de base. Soit la route doit exiger '
                    .'ROLE_TRUSTED ou ROLE_SUPER, soit elle appartient au palier de base et doit être '
                    .'inscrite dans BASE_TIER_PATHS avec sa justification.',
                    $method,
                    $path,
                    $client->getResponse()->getStatusCode(),
                ),
            );
        }
    }

    /**
     * Pendant de l'invariant n°6 : BASE_TIER_PATHS ne doit pas mentir dans
     * l'autre sens. Une entrée que le jeton n'atteint pas (401/403) est un
     * reste de refactor ou une route mal protégée — dans les deux cas la
     * liste donnerait une fausse impression de couverture. Un client neuf
     * par entrée : /api/logout expire précisément le cookie qu'on teste.
     */
    public function testEveryBaseTierPathIsActuallyReachableWithABaseAccessToken(): void
    {
        $declaredPaths = $this->allApiRoutePaths();

        foreach (self::BASE_TIER_PATHS as $path => $reason) {
            self::assertContains($path, $declaredPaths, \sprintf('BASE_TIER_PATHS déclare "%s", qui n\'existe plus dans le routeur.', $path));

            self::ensureKernelShutdown();
            $client = self::createClient();
            $csrfToken = $this->obtainBaseAccess($client);

            foreach ($this->requestableMethods($this->routeMethods($path)) as $method) {
                $client->request($method, ConcreteApiUrl::of($path), server: [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_X_XSRF_TOKEN' => $csrfToken,
                ], content: self::jsonBody([]));
                $this->assertOnlyFactoryCookies($client, $method.' '.$path);

                self::assertNotContains(
                    $client->getResponse()->getStatusCode(),
                    [401, 403],
                    \sprintf('%s %s est inscrit dans BASE_TIER_PATHS mais refuse le jeton du palier de base.', $method, $path),
                );
            }
        }
    }

    /**
     * Garde structurel, jumeau de l'invariant n°2 : le palier de base ne peut
     * jamais couvrir le backoffice ni les deux routes identifiantes de
     * l'ADR 0003 — ni, par redondance, une route déjà publique.
     */
    public function testTheBaseTierAllowListNeverCoversIdentifyingOrPublicRoutes(): void
    {
        foreach (array_keys(self::BASE_TIER_PATHS) as $path) {
            foreach (['/api/backoffice', '/api/cv', '/api/me'] as $forbiddenPrefix) {
                self::assertStringStartsNotWith($forbiddenPrefix, $path, \sprintf('"%s" ne peut pas appartenir au palier de base.', $path));
            }

            self::assertArrayNotHasKey($path, self::PUBLIC_PATHS, \sprintf('"%s" est déjà public : inutile (et trompeur) dans BASE_TIER_PATHS.', $path));
        }
    }

    /**
     * Toutes les routes /api hors liste blanche, dédupliquées par (chemin, méthode).
     *
     * @return list<array{0: string, 1: string}>
     */
    private function protectedApiRoutes(): array
    {
        $routes = [];

        foreach ($this->router()->getRouteCollection() as $route) {
            $path = $route->getPath();

            if (!CanonicalPath::isPathUnder($path, '/api') || isset(self::PUBLIC_PATHS[$path])) {
                continue;
            }

            foreach ($this->requestableMethods($route->getMethods()) as $method) {
                $routes[$path.' '.$method] = [$path, $method];
            }
        }

        return array_values($routes);
    }

    /**
     * @return list<string>
     */
    private function allApiRoutePaths(): array
    {
        $paths = [];

        foreach ($this->router()->getRouteCollection() as $route) {
            if (CanonicalPath::isPathUnder($route->getPath(), '/api')) {
                $paths[$route->getPath()] = true;
            }
        }

        return array_keys($paths);
    }

    /**
     * HEAD est écarté (doublon de GET) ; une route sans méthode déclarée les
     * accepte toutes, on la sonde en GET.
     *
     * @param array<string> $methods
     *
     * @return list<string>
     */
    private function requestableMethods(array $methods): array
    {
        $requestable = array_values(array_filter($methods, static fn (string $method): bool => 'HEAD' !== $method));

        return [] === $requestable ? ['GET'] : $requestable;
    }

    /**
     * Méthodes déclarées pour un chemin (toutes routes confondues).
     *
     * @return array<string>
     */
    private function routeMethods(string $path): array
    {
        $methods = [];

        foreach ($this->router()->getRouteCollection() as $route) {
            if ($route->getPath() === $path) {
                $methods = [...$methods, ...$route->getMethods()];
            }
        }

        return array_values(array_unique($methods));
    }

    private function router(): RouterInterface
    {
        return self::getContainer()->get('router');
    }

    /**
     * Obtient un jeton du palier de base (ADR 0003 D6) et renvoie le jeton
     * XSRF qui l'accompagne. Le rate limiter "base_access" est backé par le
     * filesystem (cache.rate_limiter), donc partagé entre tests exécutés sous
     * la même IP client (127.0.0.1) — même rationale que
     * ContactMessageResourceTest::createClientWithFreshRateLimiter().
     */
    private function obtainBaseAccess(KernelBrowser $client): string
    {
        self::getContainer()->get('cache.rate_limiter')->clear();
        $client->request('POST', '/api/account/base-access', server: ['HTTP_X_REQUESTED_WITH' => 'fetch']);
        self::assertResponseIsSuccessful();
        $this->assertOnlyFactoryCookies($client, 'POST /api/account/base-access');

        $csrfCookie = $client->getCookieJar()->get('XSRF-TOKEN');
        self::assertNotNull($csrfCookie);

        return $csrfCookie->getValue();
    }

    private function loginAs(KernelBrowser $client, string $username, string $password): string
    {
        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'fetch'], content: self::jsonBody([
            'username' => $username,
            'password' => $password,
        ]));
        self::assertResponseIsSuccessful();
        $this->assertOnlyFactoryCookies($client, 'POST /api/login_check');

        $csrfCookie = $client->getCookieJar()->get('XSRF-TOKEN');
        self::assertNotNull($csrfCookie);

        return $csrfCookie->getValue();
    }
}
