<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * La nature d'un site d'autorisation que `AuthorizationSites` recense (#748, St7-8).
 *
 * Une énumération plutôt que des chaînes libres : le détecteur, les exemptions de
 * `AuthorizationLivesInAccessControlTest` et ses fournisseurs de cas nomment les mêmes
 * natures, et une faute de frappe dans l'une d'elles ne rougirait rien (une exemption
 * `'isgranted'` ne couvrirait aucun site, sans qu'aucun test le dise). Les valeurs sont
 * les libellés lus dans les messages d'échec et dans les exemptions.
 */
enum AuthorizationSiteKind: string
{
    /** L'attribut `#[IsGranted]` : son nom court, son import ou son nom complet. */
    case IsGrantedAttribute = 'IsGranted';

    /** L'appel `denyAccessUnlessGranted` du contrôleur de base. */
    case DenyAccessUnlessGranted = 'denyAccessUnlessGranted';

    /** Un argument nommé par lequel API Platform pose une autorisation sur une opération. */
    case SecurityArgument = 'security:';

    /** Un appel `->isGranted(`, `?->isGranted(` ou `::isGranted(`. */
    case IsGrantedCall = 'isGranted';

    /** Un refus levé à la main : `new AccessDeniedException` et ses deux cousines HTTP. */
    case Refusal = 'refusal';

    /** Le nom `AccessDecisionManagerInterface`. */
    case DecisionManager = 'decisionManager';

    /** Une chaîne littérale qui contient `is_granted(`. */
    case IsGrantedExpression = 'is_granted';
}
