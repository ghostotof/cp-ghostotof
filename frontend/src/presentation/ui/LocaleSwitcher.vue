<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { SUPPORTED_LOCALES, LOCALE_NATIVE_NAMES, type Locale } from '../../domain/portfolio/entities/Locale'
import { applyInterfaceLocale } from '../router/interfaceLocale'
import { applySeoMeta } from '../router/seo'
import { useRouteLocale } from '../router/useRouteLocale'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const pageLocale = useRouteLocale()

/**
 * Change de langue en restant sur la même page (#748, R227, D4).
 *
 * Sous un préfixe de langue, le **premier** segment est remplacé (`/fr/about` → `/en/about`), la query et le fragment
 * gardés : la demande d'un consentement OAuth (`client_id`, `state`, `code_challenge`) et les drapeaux de la connexion
 * (`redirect`, `reauth`, `expired`) sont dans la query, et la perdre cassait le flux (CR18-1). Un `replace` sur tout le
 * chemin changerait `/frogs` en `/engs`.
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
  const prefix = `/${pageLocale.value}`
  const startsWithLocale = route.path === prefix || route.path.startsWith(`${prefix}/`)
  if (!startsWithLocale) {
    applyInterfaceLocale(target)
    applySeoMeta(route)
    return
  }
  void router.push({ path: `/${target}${route.path.slice(prefix.length)}`, query: route.query, hash: route.hash })
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
      :aria-label="LOCALE_NATIVE_NAMES[localeOption]"
      :lang="localeOption"
      @click="switchTo(localeOption)"
    >
      {{ localeOption.toUpperCase() }}
    </button>
  </div>
</template>
