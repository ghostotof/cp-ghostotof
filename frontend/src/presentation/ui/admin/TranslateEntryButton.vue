<script setup lang="ts">
import { computed, useAttrs } from 'vue'
import { useI18n } from 'vue-i18n'
import { otherLocale, type Locale } from '../../../domain/portfolio/entities/Locale'
import { describedBy } from './describedBy'

// Deux racines (le bouton, puis le texte de l'attente) : les attributs de la page vont au bouton, pas au fragment.
defineOptions({ inheritAttrs: false })

/**
 * Bouton « Proposer la version EN/FR » de l'assistant de traduction du
 * backoffice (spec 0002), écrit une fois pour toutes les pages admin
 * localisées. Il ne sait rien du formulaire : il calcule la locale cible
 * (l'autre des deux locales supportées), l'émet, et reflète l'état d'attente.
 * `type="button"` est essentiel : placé dans un <form>, un bouton sans type
 * soumettrait le formulaire — c'est-à-dire enregistrerait — au lieu de
 * traduire. Rien ne doit être persisté par ce chemin (ADR 0004, D4).
 *
 * Après « Annuler » (ou tout geste qui périme la traduction), l'appel déjà parti continue côté serveur et le composable
 * refuse le suivant : `isDiscardPending` désactive alors le bouton et rend, sous l'identifiant `hintId`, le texte qui dit
 * pourquoi il attend ; le bouton le désigne par `aria-describedby`, à la suite de la référence que la page lui passe, et
 * seulement pendant l'attente (aucune référence pendante).
 */
const props = withDefaults(
  defineProps<{
    formLocale: Locale
    isTranslating: boolean
    /** Un appel périmé n'a pas encore répondu : le bouton attend et le dit. */
    isDiscardPending?: boolean
    /** Identifiant du texte de l'attente, unique dans la page (une page porte plusieurs formulaires). */
    hintId: string
    disabled?: boolean
    /**
     * Référence(s) que la page donne au bouton (le verrou, la proposition) : une prop, car Vue y range
     * `aria-describedby` ; l'attente de la réponse périmée s'y ajoute (`buttonDescribedBy`).
     */
    ariaDescribedby?: string
  }>(),
  {
    isDiscardPending: false,
    disabled: false,
    ariaDescribedby: undefined,
  },
)

const emit = defineEmits<{ translate: [targetLocale: Locale] }>()

const { t } = useI18n()

const attrs = useAttrs()

const targetLocale = computed<Locale>(() => otherLocale(props.formLocale))
const buttonDescribedBy = computed(() =>
  describedBy(props.ariaDescribedby, props.isDiscardPending ? props.hintId : undefined),
)
</script>

<template>
  <button
    type="button"
    class="btn btn-outline-light d-inline-flex align-items-center gap-2"
    v-bind="attrs"
    :disabled="disabled || isTranslating || isDiscardPending"
    :aria-busy="isTranslating ? 'true' : 'false'"
    :aria-describedby="buttonDescribedBy"
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
  <p
    v-if="isDiscardPending"
    :id="hintId"
    class="form-text mb-0"
  >
    {{ t('admin.translation.discardPending') }}
  </p>
</template>
