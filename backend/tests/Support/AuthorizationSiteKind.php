<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * La nature d'un site d'autorisation que `AuthorizationSites` recense (#748, St7-8).
 *
 * Une énumération plutôt que des chaînes libres : le détecteur, les exemptions de
 * `AuthorizationLivesInAccessControlTest` et ses fournisseurs de cas nomment les mêmes
 * natures, et une faute de frappe dans l'une d'elles ne rougirait rien (une exemption
 * `'isgranted'` ne couvrirait aucun site, sans qu'aucun test le dise). Une énumération
 * pure : les messages d'échec et le décompte des exemptions nomment le cas (`->name`),
 * un seul vocabulaire.
 */
enum AuthorizationSiteKind
{
    /** L'attribut `#[IsGranted]` : son nom court, son import ou son nom complet. */
    case IsGrantedAttribute;

    /** L'appel `denyAccessUnlessGranted` du contrôleur de base. */
    case DenyAccessUnlessGranted;

    /** Un argument nommé par lequel API Platform pose une autorisation sur une opération. */
    case SecurityArgument;

    /** Un appel `->isGranted(`, `?->isGranted(` ou `::isGranted(`. */
    case IsGrantedCall;

    /**
     * Un refus levé à la main : `new AccessDeniedException`, `new AccessDeniedHttpException` ou
     * `new UnauthorizedHttpException`, de nom court ou complet : il décide à la place d'`access_control`.
     * Attraper, tester ou importer l'exception n'en est pas un.
     */
    case Refusal;

    /**
     * Le nom `AccessDecisionManagerInterface`, partout où il est écrit (import, type, propriété) : le nom
     * et non l'appel `->decide(`, que le consentement OAuth porte en homonyme.
     */
    case DecisionManager;

    /** Une chaîne littérale qui contient `is_granted(`. */
    case IsGrantedExpression;
}
