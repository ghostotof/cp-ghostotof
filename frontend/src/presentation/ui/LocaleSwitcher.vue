<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter, type LocationQuery } from 'vue-router'
import { SUPPORTED_LOCALES, LOCALE_NATIVE_NAMES, type Locale } from '../../domain/portfolio/entities/Locale'
import { applyInterfaceLocale } from '../router/interfaceLocale'
import { internalRedirectTarget } from '../router/internalRedirect'
import { swapLocalePrefix } from '../router/localePrefix'
import { applySeoMeta } from '../router/seo'
import { useRouteLocale } from '../router/useRouteLocale'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const pageLocale = useRouteLocale()

/**
 * Change de langue en restant sur la même page (#748, R227, D4 de la dix-huitième relecture).
 *
 * Sous un préfixe de langue, le **premier** segment est remplacé (`swapLocalePrefix` : `/fr/about` → `/en/about`, jamais
 * `/frogs` → `/engs`), la query et le fragment gardés : la demande d'un consentement OAuth (`client_id`, `state`,
 * `code_challenge`) et les drapeaux de la connexion (`reauth`, `expired`) sont dans la query, et la perdre casse le flux
 * (CR18-1). La cible de retour `redirect` suit la langue choisie (CR19-2) quand `internalRedirectTarget()` l'admet et
 * qu'elle commence par la langue de la page ; toute autre valeur (une cible externe, un tableau, `/frogs`) reste telle
 * quelle, et la page de connexion la juge.
 *
 * Un chemin qui ne commence pas par la langue (la page 404 `/frogs`, sans segment `:locale`) n'a aucune adresse à
 * changer : le sélecteur pose la langue d'interface lui-même, sans naviguer, comme le routeur le ferait
 * (`applyInterfaceLocale`), puis récrit le titre et la description de la page dans cette langue (`applySeoMeta`, que le
 * routeur n'appelle qu'après une navigation).
 */
function switchTo(target: Locale): void {
  if (target === pageLocale.value) {
    return
  }
  const from = pageLocale.value
  const path = swapLocalePrefix(route.path, from, target)
  if (null === path) {
    applyInterfaceLocale(target)
    applySeoMeta(route)
    return
  }
  void router.push({ path, query: queryFollowing(from, target), hash: route.hash })
}

/** La query de la page, la cible `redirect` passée de `from` à `to` quand elle est interne et préfixée par `from`. */
function queryFollowing(from: Locale, to: Locale): LocationQuery {
  const redirect = internalRedirectTarget(route.query.redirect)
  const moved = null === redirect ? null : swapLocalePrefix(redirect, from, to)

  return null === moved ? route.query : { ...route.query, redirect: moved }
}
</script>

<template>
  <div
    class="d-flex align-items-center gap-1"
    role="group"
    :aria-label="t('localeSwitcher.label')"
  >
    <button
      v-for="localeOption in SUPPORTED_LOCALES"
      :key="localeOption"
      type="button"
      class="btn btn-sm px-2"
      :class="localeOption === pageLocale ? 'btn-outline-light' : 'btn-link text-body-secondary text-decoration-none'"
      :aria-current="localeOption === pageLocale ? 'true' : undefined"
      :lang="localeOption"
      @click="switchTo(localeOption)"
    >
      {{ localeOption.toUpperCase() }}
      <!--
        Le nom natif, masqué : le nom accessible est « FR (Français) », il commence par le texte visible (WCAG 2.5.3, « Label in
        Name » : « cliquer FR » à la voix trouve le bouton), et `lang` fait prononcer « English » en anglais (#748, R243 de la
        vingtième relecture). Pas d'`aria-label` : il remplacerait le contenu, et le nom ne contiendrait plus « FR ».
      -->
      <span
        class="visually-hidden"
        :lang="localeOption"
      >{{ ' ' }}({{ LOCALE_NATIVE_NAMES[localeOption] }})</span>
    </button>
  </div>
</template>
