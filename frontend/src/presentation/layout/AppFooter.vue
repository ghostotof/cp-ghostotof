<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { SiteIdentity } from '../../domain/portfolio/entities/SiteIdentity'
import IconDisc3 from '~icons/lucide/disc-3'

defineProps<{
  siteIdentity: SiteIdentity
}>()

const { t } = useI18n()
const year = new Date().getFullYear()
</script>

<template>
  <footer class="border-top mt-5 py-3">
    <div class="container-xl d-flex align-items-center justify-content-between gap-3 small text-body-secondary">
      <span>{{ t('common.footerCopyright', { year, brand: siteIdentity.brandName }) }}</span>

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
