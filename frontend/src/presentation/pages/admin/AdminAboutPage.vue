<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import BaseSelect from '../../ui/BaseSelect.vue'
import AdminAboutSettingsForm from './AdminAboutSettingsForm.vue'
import AdminAboutSiteCardsSection from './AdminAboutSiteCardsSection.vue'
import AdminAboutMeCardsSection from './AdminAboutMeCardsSection.vue'
import { LOCALE_NATIVE_NAMES, SUPPORTED_LOCALES, type Locale } from '../../../domain/portfolio/entities/Locale'

const { t } = useI18n()

const localeOptions = SUPPORTED_LOCALES.map((locale) => ({ value: locale, label: LOCALE_NATIVE_NAMES[locale] }))
const selectedLocale = ref<Locale>('fr')
</script>

<template>
  <div class="d-flex flex-column gap-4">
    <div class="surface-panel p-3 p-sm-4">
      <BaseSelect
        id="admin-about-locale"
        v-model="selectedLocale"
        :label="t('admin.localeLabel')"
        :options="localeOptions"
      />
    </div>

    <AdminAboutSettingsForm :locale="selectedLocale" />
    <AdminAboutSiteCardsSection :locale="selectedLocale" />
    <AdminAboutMeCardsSection :locale="selectedLocale" />
  </div>
</template>
