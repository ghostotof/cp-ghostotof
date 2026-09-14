<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { SUPPORTED_LOCALES, type Locale } from '../../../domain/portfolio/entities/Locale'

/**
 * Bouton « Proposer la version EN/FR » de l'assistant de traduction du
 * backoffice (spec 0002), écrit une fois pour toutes les pages admin
 * localisées. Il ne sait rien du formulaire : il calcule la locale cible
 * (l'autre des deux locales supportées), l'émet, et reflète l'état d'attente.
 * `type="button"` est essentiel : placé dans un <form>, un bouton sans type
 * soumettrait le formulaire — c'est-à-dire enregistrerait — au lieu de
 * traduire. Rien ne doit être persisté par ce chemin (ADR 0004, D4).
 */
const props = withDefaults(
  defineProps<{
    formLocale: Locale
    isTranslating: boolean
    disabled?: boolean
  }>(),
  {
    disabled: false,
  },
)

const emit = defineEmits<{ translate: [targetLocale: Locale] }>()

const { t } = useI18n()

const targetLocale = computed<Locale>(() => SUPPORTED_LOCALES.find((locale) => locale !== props.formLocale) ?? SUPPORTED_LOCALES[0])
</script>

<template>
  <button
    type="button"
    class="btn btn-outline-light d-inline-flex align-items-center gap-2"
    :disabled="disabled || isTranslating"
    :aria-busy="isTranslating ? 'true' : 'false'"
    @click="emit('translate', targetLocale)"
  >
    <span
      v-if="isTranslating"
      class="spinner-border spinner-border-sm"
      aria-hidden="true"
    />
    <span v-if="isTranslating">{{ t('admin.translation.inProgress') }}</span>
    <span v-else>{{ t('admin.translation.proposeVersion', { locale: targetLocale.toUpperCase() }) }}</span>
  </button>
</template>
