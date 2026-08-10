<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { SUPPORTED_LOCALES, LOCALE_NATIVE_NAMES, type Locale } from '../../domain/portfolio/entities/Locale'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const currentLocale = computed(() => route.params.locale as Locale)

/** Change de langue en restant sur la même page (même chemin, locale remplacée). */
function switchTo(target: Locale): void {
  if (target === currentLocale.value) {
    return
  }
  router.push({ path: route.path.replace(`/${currentLocale.value}`, `/${target}`), hash: route.hash })
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
      :class="localeOption === currentLocale ? 'btn-outline-light' : 'btn-link text-secondary text-decoration-none'"
      :aria-current="localeOption === currentLocale ? 'true' : undefined"
      :aria-label="LOCALE_NATIVE_NAMES[localeOption]"
      :lang="localeOption"
      @click="switchTo(localeOption)"
    >
      {{ localeOption.toUpperCase() }}
    </button>
  </div>
</template>
