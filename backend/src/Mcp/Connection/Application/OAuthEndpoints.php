<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Les URL publiques du serveur d'autorisation et de la ressource protégée
 * (#647, spec 0007 phase 2), écrites une fois : l'émetteur, la ressource
 * (RFC 8707) qu'un jeton d'accès porte en audience, les points
 * d'autorisation, de jeton et de révocation, le document de métadonnées de
 * la ressource (RFC 9728) et la page de consentement du frontend.
 *
 * Deux bases. `app.public_api_base_url` porte tout ce qui est servi par l'API ;
 * vide (`APP_PUBLIC_API_BASE_URL`), elle retombe sur `app.frontend_base_url`,
 * le cas de la préprod et de la prod où l'API est servie sur l'origine du
 * frontend. Seul le dev les sépare (nginx sur un port, Vite sur un autre). La
 * page de consentement est une page du frontend : elle suit toujours la seconde.
 *
 * La barre finale d'une base est retirée : l'émetteur et la ressource sont
 * comparés à l'octet près par les clients (RFC 8414 §3.3, RFC 9728 §3.3), une
 * double barre les rendrait méconnaissables.
 */
final readonly class OAuthEndpoints
{
    /**
     * Le chemin de l'émetteur sous la base publique de l'API : écrit une fois,
     * il entre dans l'émetteur et dans l'URL de son document de métadonnées.
     */
    private const string ISSUER_PATH = '/api/oauth';

    private string $publicApiBaseUrl;
    private string $frontendBaseUrl;

    public function __construct(
        #[Autowire(param: 'app.public_api_base_url')]
        string $publicApiBaseUrl,
        #[Autowire(param: 'app.frontend_base_url')]
        string $frontendBaseUrl,
    ) {
        $this->publicApiBaseUrl = rtrim($publicApiBaseUrl, '/');
        $this->frontendBaseUrl = rtrim($frontendBaseUrl, '/');
    }

    /** L'émetteur (`issuer`, RFC 8414) : le préfixe des points OAuth. */
    public function issuer(): string
    {
        return $this->publicApiBaseUrl.self::ISSUER_PATH;
    }

    /**
     * La ressource protégée (RFC 8707, RFC 9728) : l'URL canonique du serveur
     * MCP, l'audience de chaque jeton d'accès émis.
     */
    public function resource(): string
    {
        return $this->publicApiBaseUrl.'/api/mcp';
    }

    public function authorizationEndpoint(): string
    {
        return $this->issuer().'/authorize';
    }

    public function tokenEndpoint(): string
    {
        return $this->issuer().'/token';
    }

    public function revocationEndpoint(): string
    {
        return $this->issuer().'/revoke';
    }

    /**
     * Le document de métadonnées de la ressource protégée, hors `/api` : le
     * chemin bien connu suivi du chemin de la ressource (RFC 9728 §3.1).
     */
    public function protectedResourceMetadataUrl(): string
    {
        return $this->publicApiBaseUrl.'/.well-known/oauth-protected-resource/api/mcp';
    }

    /**
     * Le document de métadonnées du serveur d'autorisation, hors `/api` : le
     * chemin bien connu inséré avant le chemin de l'émetteur (RFC 8414 §3.1),
     * celui qu'un client calcule depuis {@see self::issuer()}. Écrit ici pour
     * que la route, l'Ingress dédié et leurs tests le lisent d'une source
     * unique au lieu de le recomposer.
     *
     * Comme pour la ressource protégée, la base publique est une origine sans
     * chemin (l'API est servie à la racine de son hôte) : une base servie sous
     * un chemin demanderait d'insérer le segment bien connu avant lui.
     */
    public function authorizationServerMetadataUrl(): string
    {
        return $this->publicApiBaseUrl.'/.well-known/oauth-authorization-server'.self::ISSUER_PATH;
    }

    /**
     * La page de consentement du frontend, dans la langue de la personne.
     * La langue est celle que le point d'autorisation a déjà retenue ; elle
     * n'est pas validée ici.
     */
    public function consentPage(string $locale): string
    {
        return $this->frontendBaseUrl.'/'.$locale.'/oauth/consent';
    }
}
