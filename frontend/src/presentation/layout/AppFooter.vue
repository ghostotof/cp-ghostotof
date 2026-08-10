<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import type { SiteIdentity } from '../../domain/portfolio/entities/SiteIdentity'
import { isSupportedLocale, type Locale } from '../../domain/portfolio/entities/Locale'
import IconDisc3 from '~icons/lucide/disc-3'

defineProps<{
  siteIdentity: SiteIdentity
}>()

const { t, locale } = useI18n()
const route = useRoute()
const year = new Date().getFullYear()

/** Même repli route → i18n que AppHeader.homeLink, pour rester correct sur la page 404. */
const currentLocale = computed<Locale>(() => {
  const routeLocale = route.params.locale
  return typeof routeLocale === 'string' && isSupportedLocale(routeLocale) ? routeLocale : (locale.value as Locale)
})
</script>

<template>
  <footer class="border-top mt-5 py-3">
    <div class="container-xl d-flex flex-column flex-sm-row align-items-center justify-content-between gap-2 gap-sm-3 small text-body-secondary">
      <span>{{ t('common.footerCopyright', { year, brand: siteIdentity.brandName }) }}</span>

      <nav :aria-label="t('common.legalNavigation')">
        <ul class="list-unstyled d-flex align-items-center gap-3 mb-0">
          <li>
            <RouterLink
              :to="`/${currentLocale}/legal-notice`"
              class="link-secondary link-underline-opacity-0 link-underline-opacity-100-hover"
            >
              {{ t('common.legalNoticeLink') }}
            </RouterLink>
          </li>
          <li>
            <RouterLink
              :to="`/${currentLocale}/privacy-policy`"
              class="link-secondary link-underline-opacity-0 link-underline-opacity-100-hover"
            >
              {{ t('common.privacyPolicyLink') }}
            </RouterLink>
          </li>
        </ul>
      </nav>

      <!-- Clin d'œil discret : pas de logo, juste une icône générique de vinyle
           avec une infobulle native, visible seulement au survol. -->
      <span
        class="d-inline-flex align-items-center opacity-50"
        aria-hidden="true"
        title="Led Zeppelin · Queen · AC/DC · ZZ Top · Muse"
      >
        <IconDisc3
          width="14"
          height="14"
        />
      </span>
    </div>
  </footer>
</template>
