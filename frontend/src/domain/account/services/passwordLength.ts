/*
 * Bornes de longueur d'un mot de passe, recopiées du backend
 * (`CpgUser::MIN_PASSWORD_LENGTH` / `MAX_PASSWORD_LENGTH`, appliquées par la
 * contrainte `PlainPasswordLength`, #386). Le backend reste la seule
 * protection : ce contrôle local ne sert qu'à afficher le bon message sans
 * aller-retour, et sans entamer le quota par IP du parcours public.
 *
 * Les deux bornes ne se comptent pas dans la même unité, comme côté backend :
 *  - le minimum en **points de code** (`mb_strlen`, unité par défaut de
 *    `Assert\Length`) — jamais `string.length`, qui compte des unités UTF-16
 *    (un emoji y vaut 2, #410) ;
 *  - le maximum en **octets UTF-8** (`strlen`, la borne du hasher).
 */

/** En points de code : la règle annoncée à la personne qui choisit son mot de passe. */
export const MIN_PASSWORD_LENGTH = 8

/** En octets UTF-8 : `PasswordHasherInterface::MAX_PASSWORD_LENGTH` de Symfony. */
export const MAX_PASSWORD_BYTES = 4096

export type PasswordLengthViolation = 'too-short' | 'too-long'

/** Une seule instance : l'encodeur est sans état. */
const utf8Encoder = new TextEncoder()

/**
 * Borne violée par `password`, ou `null` s'il respecte les deux.
 *
 * Limite connue, sans conséquence pratique : une moitié de paire de
 * substitution isolée compte pour un point de code ici et pour 3 octets
 * (U+FFFD) à l'encodage, alors que le backend refuse un tel JSON avant même
 * de mesurer quoi que ce soit.
 */
export function passwordLengthViolation(password: string): PasswordLengthViolation | null {
  // Le spread itère par points de code, pas par unités UTF-16.
  if ([...password].length < MIN_PASSWORD_LENGTH) {
    return 'too-short'
  }
  if (utf8Encoder.encode(password).length > MAX_PASSWORD_BYTES) {
    return 'too-long'
  }

  return null
}
