import { scriptOf, templateExpressionsOf } from './sourceText'

/**
 * Les sources de `src/` lues pour les gardes de `tests/` (#748, R97 ; St8-6).
 *
 * `noOmittedCredentials.spec.ts` et `sessionStateEncapsulation.spec.ts` lisent le même ensemble de fichiers, de la
 * même façon : la glob (`.ts` et `.vue`, `?raw`, chargée à l'import) et la lecture par `sourceText.ts` sont écrites
 * ici, une fois, pour qu'une garde ne lise jamais un ensemble différent de l'autre. (Vitest 5 vide les imports CSS, pas
 * les `?raw` d'un `.ts` ou d'un `.vue`.)
 *
 * Les clés sont les chemins **relatifs à `src/`** (`application/auth/useAuth.ts`), pas ceux de la glob.
 */
const raw = import.meta.glob(['../../src/**/*.ts', '../../src/**/*.vue'], {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>

/** Le chemin d'un fichier relatif à `src/`, depuis la clé de la glob. */
function relativeToSrc(path: string): string {
  return path.replace('../../src/', '')
}

/**
 * Le code lu de chaque fichier : un `.vue` n'y a que ses blocs `<script>` (son `<template>` n'est pas du JavaScript).
 * Un `.vue` illisible fait échouer l'import du module, donc toute garde qui en dépend.
 */
export const SRC_SCRIPTS: Readonly<Record<string, string>> = Object.fromEntries(
  Object.entries(raw).map(([path, source]) => [relativeToSrc(path), scriptOf(path, source)]),
)

/** Les expressions du `<template>` de chaque fichier (`[]` hors d'un `.vue` ou sans gabarit), lues par `templateExpressionsOf`. */
export const SRC_TEMPLATE_EXPRESSIONS: Readonly<Record<string, readonly string[]>> = Object.fromEntries(
  Object.entries(raw).map(([path, source]) => [relativeToSrc(path), templateExpressionsOf(path, source)]),
)
