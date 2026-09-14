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

    <!--
      Chaque section peut demander la bascule de la locale de page : c'est ce
      que fait l'assistant de traduction, dont le brouillon s'enregistre dans
      la locale cible (même mécanique que la page Qualité, ici déléguée aux
      enfants puisque les formulaires y vivent).
    -->
    <AdminAboutSettingsForm
      :locale="selectedLocale"
      @switch-locale="selectedLocale = $event"
    />
    <AdminAboutSiteCardsSection
      :locale="selectedLocale"
      @switch-locale="selectedLocale = $event"
    />
    <AdminAboutMeCardsSection
      :locale="selectedLocale"
      @switch-locale="selectedLocale = $event"
    />
  </div>
</template>
