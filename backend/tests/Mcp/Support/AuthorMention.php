<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Support;

use Stringable;

/**
 * Une mention de l'auteur trouvée par {@see AuthorMentions} : l'endroit, la
 * nature et le numéro du terme, **jamais le terme ni le texte qui le porte**.
 */
final readonly class AuthorMention implements Stringable
{
    public function __construct(
        public string $path,
        public MentionKind $kind,
        public int $termIndex,
    ) {
    }

    public function __toString(): string
    {
        return \sprintf('%s : %s (terme n°%d)', $this->path, $this->kind->value, $this->termIndex);
    }
}
