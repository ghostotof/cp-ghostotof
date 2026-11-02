<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useCurrentLocale } from '../../application/shared/useCurrentLocale'
import { SUPPORTED_LOCALES, LOCALE_NATIVE_NAMES, type Locale } from '../../domain/portfolio/entities/Locale'
import { routeLocale } from '../router/routeLocale'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const interfaceLocale = useCurrentLocale()
const currentLocale = computed(() => routeLocale(route, interfaceLocale.value))

/**
 * Change de langue en restant sur la même page (même chemin, locale remplacée).
 *
 * Le segment remplacé est le **premier** (`/fr/about` → `/en/about`). La langue courante peut venir de l'interface et non
 * de la route (la page 404 n'a pas de segment `:locale`) : un `replace` sur tout le chemin changerait alors `/frogs` en
 * `/engs`. Un chemin qui ne commence pas par la langue reste tel quel.
 */
function switchTo(target: Locale): void {
  if (target === currentLocale.value) {
    return
  }
  const prefix = `/${currentLocale.value}`
  const startsWithLocale = route.path === prefix || route.path.startsWith(`${prefix}/`)
  router.push({ path: startsWithLocale ? `/${target}${route.path.slice(prefix.length)}` : route.path, hash: route.hash })
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
      :class="localeOption === currentLocale ? 'btn-outline-light' : 'btn-link text-body-secondary text-decoration-none'"
      :aria-current="localeOption === currentLocale ? 'true' : undefined"
      :aria-label="LOCALE_NATIVE_NAMES[localeOption]"
      :lang="localeOption"
      @click="switchTo(localeOption)"
    >
      {{ localeOption.toUpperCase() }}
    </button>
  </div>
</template>
