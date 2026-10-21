<?php

declare(strict_types=1);

namespace App\Mcp\Presentation\Prompt;

use Random\RandomException;

/**
 * La balise du bloc qui porte le rôle dans le message de `present_for_role` :
 * le mot `role` suivi d'un nonce tiré à chaque prompt, `<role-3f9c…>` (#662).
 *
 * C'est la défense qui ferme toute la famille des contournements : une
 * annonce piégée est écrite avant que le nonce existe, son auteur ne peut donc
 * pas écrire la vraie fin du bloc, quels que soient les sosies de `<`, de `/`
 * ou de `role` qu'il emploie. Une fausse fin (`</role>`, `</ʀᴏʟᴇ>`) n'a pas le
 * nonce, et la consigne dit que le bloc ne se termine qu'à la sienne. Le
 * nettoyage de {@see PromptRole} reste en défense en profondeur.
 *
 * Aléatoire plutôt que dérivé du texte (un HMAC sous `APP_SECRET`) : rien n'a
 * à le recalculer, et un nonce qui change à chaque appel ne s'apprend pas en
 * appelant le prompt sur le texte qu'on piège. Hexadécimal : ni le garde D8
 * (aucune lettre au-delà de `f`) ni le nettoyage du rôle n'y lisent un mot.
 */
final readonly class RoleBlockTag
{
    /** Le mot de la balise, que {@see PromptRole} empêche aussi d'introduire par un signe ouvrant. */
    public const string WORD = 'role';

    /** 64 bits : un tiers ne le devine pas, et la balise reste courte à lire. */
    private const int NONCE_BYTES = 8;

    private function __construct(
        public string $name,
    ) {
    }

    /**
     * @throws RandomException aucune source d'aléa sûre : un défaut du serveur
     */
    public static function random(): self
    {
        return new self(self::WORD.'-'.bin2hex(random_bytes(self::NONCE_BYTES)));
    }

    public function opening(): string
    {
        return '<'.$this->name.'>';
    }

    public function closing(): string
    {
        return '</'.$this->name.'>';
    }
}
