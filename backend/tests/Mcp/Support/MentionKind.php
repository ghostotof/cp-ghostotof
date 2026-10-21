<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Support;

/**
 * Ce qu'{@see AuthorMentions} a trouvé de l'auteur à un endroit — jamais le
 * terme lui-même.
 */
enum MentionKind: string
{
    /** Le nom complet, ou l'un de ses mots, comme mot entier. */
    case Name = 'nom de l\'auteur';

    /** Le nom, ou un mot assez long, accolé à d'autres lettres (un identifiant, une adresse). */
    case JoinedName = 'nom accolé';

    /** Les initiales, en capitales ou en tête d'un identifiant (`xy-…`). */
    case Initials = 'initiales';

    /** Une suite de mots du CV nominatif. */
    case CvFragment = 'fragment du CV nominatif';
}
