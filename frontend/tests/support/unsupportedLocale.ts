import type { createAppI18n } from '../../src/presentation/i18n'

/**
 * Pose dans vue-i18n une langue que le site ne porte pas (#748, R213, St17-21), comme un navigateur ou une extension
 * peut le faire : le type de `i18n.global.locale` est une `string`, mais `'de' as never` écrivait la valeur en faisant
 * taire le compilateur à chaque site. `Reflect.set` écrit la même propriété sans conversion de type, **une fois, ici**.
 *
 * Sert aux tests qui prouvent qu'une langue d'interface hors du site n'atteint jamais une adresse : `useCurrentLocale`
 * la ramène à la langue par défaut.
 */
export function setUnsupportedLocale(i18n: ReturnType<typeof createAppI18n>, value: string): void {
  Reflect.set(i18n.global.locale, 'value', value)
}
