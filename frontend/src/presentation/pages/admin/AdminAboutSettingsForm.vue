<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAdminAboutSettings } from '../../../application/admin/about/useAdminAboutSettings'
import { useAdminTranslation } from '../../../application/admin/translation/useAdminTranslation'
import { applyTranslationProposal, collectProseFields } from '../../../application/admin/translation/proseFields'
import BaseTextInput from '../../ui/BaseTextInput.vue'
import TranslateEntryButton from '../../ui/admin/TranslateEntryButton.vue'
import type { Locale } from '../../../domain/portfolio/entities/Locale'
import type { TranslationProposal } from '../../../domain/admin/translation/entities/TranslationProposal'

/**
 * Les réglages sont le **seul** formulaire de la page à garder la langue de
 * page (spec 0004, D8 : les cartes ont chacune la leur). Ce n'est pas une
 * exception de commodité — un singleton par locale n'a pas de « langue de
 * l'entrée » : choisir une langue, c'est choisir l'enregistrement à éditer,
 * donc en charger un autre. D'où le prop `locale` piloté par la page.
 *
 * `isLocked` vient du verrou d'ordre global (D6) : un brouillon d'ordre
 * modifié ailleurs sur la page désactive aussi l'enregistrement d'ici, qui
 * rechargerait des listes et perdrait ce brouillon.
 */
const props = defineProps<{ locale: Locale; isLocked: boolean; lockedHintId?: string }>()
const emit = defineEmits<{ switchLocale: [locale: Locale] }>()

const { t } = useI18n()

const { settings, isLoading, hasError, isLoaded, errorMessage, load, save } = useAdminAboutSettings()

const form = reactive({ siteEyebrow: '', meEyebrow: '', technicalSubtitle: '', personalSubtitle: '', hobbiesSubtitle: '' })
const isSubmitting = ref(false)
const errorText = computed(() => {
  const reason = errorMessage.value?.reason

  return reason ? t(`admin.about.errors.${reason}`) : null
})

/**
 * Assistant de traduction (spec 0002). Les réglages sont un singleton par
 * locale : « proposer la version EN » signifie basculer la page en EN, dont
 * les réglages existants se rechargent et remplissent le formulaire — la
 * proposition serait écrasée si elle était appliquée avant. Elle est donc mise en
 * attente et posée sur le formulaire une fois la locale cible chargée ;
 * Enregistrer met alors à jour les réglages de cette locale.
 *
 * « Une fois chargée » est `isLoaded` (R4, #748), pas le retour de `load()` : une
 * lecture en session finie ne lève ni erreur ni valeur, et ne finit qu'au retour
 * de la session, lancée alors par le composable lui-même. La proposition attend
 * donc ce retour, jamais posée sur un formulaire non chargé.
 */
const { isTranslating, errorReason: translationErrorReason, translate } = useAdminTranslation()
const PROSE_FIELDS = ['siteEyebrow', 'meEyebrow', 'technicalSubtitle', 'personalSubtitle', 'hobbiesSubtitle'] as const
const pendingProposal = ref<TranslationProposal | null>(null)
const proposalSourceLocale = ref<Locale | null>(null)
const hasProseToTranslate = computed(() => PROSE_FIELDS.some((field) => '' !== form[field].trim()))
const translationErrorText = computed(() => {
  const reason = translationErrorReason.value

  return reason ? t(`admin.translation.errors.${reason}`) : null
})

// `flush: 'sync'` : le formulaire est rempli au moment même où les réglages
// arrivent, et non au prochain tick — ainsi, quand `isLoaded` se lève (après la
// pose des réglages), la copie a déjà eu lieu et la proposition en attente peut
// se poser par-dessus sans être écrasée ensuite.
watch(
  settings,
  (value) => {
    if (value) {
      form.siteEyebrow = value.siteEyebrow
      form.meEyebrow = value.meEyebrow
      form.technicalSubtitle = value.technicalSubtitle
      form.personalSubtitle = value.personalSubtitle
      form.hobbiesSubtitle = value.hobbiesSubtitle
    }
  },
  { immediate: true, flush: 'sync' },
)

// Changer de locale vide les réglages de l'ancienne (`clear` du composable) avant de lire : le formulaire
// n'est rendu que si `isLoaded`, jamais avec les champs d'une autre langue sous l'en-tête de celle-ci.
watch(
  () => props.locale,
  (locale) => {
    void load(locale)
  },
  { immediate: true },
)

// Les réglages de `props.locale` viennent d'être posés et recopiés dans le formulaire : la proposition en
// attente, si c'est leur locale qu'elle visait, se pose par-dessus (spec 0002). Aussi au retour d'une session
// finie : la lecture interrompue est relancée par le composable, `isLoaded` se lève alors.
watch(
  isLoaded,
  (loaded) => {
    if (loaded) {
      applyPendingProposal(props.locale)
    }
  },
  { flush: 'sync' },
)

/** Pose la proposition en attente si la locale qui vient d'être chargée est sa cible. */
function applyPendingProposal(loadedLocale: Locale): void {
  if (!pendingProposal.value || loadedLocale !== pendingProposal.value.targetLocale) {
    return
  }

  applyTranslationProposal(form, PROSE_FIELDS, pendingProposal.value)
  proposalSourceLocale.value = pendingProposal.value.sourceLocale
  pendingProposal.value = null
}

async function handleTranslate(targetLocale: Locale): Promise<void> {
  const proposal = await translate(props.locale, targetLocale, collectProseFields(form, PROSE_FIELDS))
  if (!proposal) {
    return
  }

  pendingProposal.value = proposal
  emit('switchLocale', targetLocale)
}

async function handleSubmit(): Promise<void> {
  isSubmitting.value = true
  proposalSourceLocale.value = null
  await save(props.locale, { ...form })
  isSubmitting.value = false
}
</script>

<template>
  <div class="surface-panel p-3 p-sm-4">
    <h2 class="h6 fw-bold text-white mb-3">
      {{ t('admin.about.settings.title') }}
    </h2>

    <p
      v-if="isLoading"
      class="text-body-secondary mb-0"
    >
      {{ t('admin.about.loading') }}
    </p>
    <p
      v-else-if="hasError"
      class="text-danger-emphasis mb-0"
      role="alert"
    >
      {{ t('admin.about.loadError') }}
    </p>
    <p
      v-else-if="!isLoaded"
      class="text-body-secondary mb-0"
    >
      {{ t('admin.notLoaded') }}
    </p>
    <form
      v-else
      novalidate
      @submit.prevent="handleSubmit"
    >
      <BaseTextInput
        id="admin-about-site-eyebrow"
        v-model="form.siteEyebrow"
        :label="t('admin.about.settings.siteEyebrowLabel')"
        required
      />
      <BaseTextInput
        id="admin-about-me-eyebrow"
        v-model="form.meEyebrow"
        :label="t('admin.about.settings.meEyebrowLabel')"
        required
      />
      <BaseTextInput
        id="admin-about-technical-subtitle"
        v-model="form.technicalSubtitle"
        :label="t('admin.about.settings.technicalSubtitleLabel')"
        required
      />
      <BaseTextInput
        id="admin-about-personal-subtitle"
        v-model="form.personalSubtitle"
        :label="t('admin.about.settings.personalSubtitleLabel')"
        required
      />
      <BaseTextInput
        id="admin-about-hobbies-subtitle"
        v-model="form.hobbiesSubtitle"
        :label="t('admin.about.settings.hobbiesSubtitleLabel')"
        required
      />

      <div class="mb-3">
        <TranslateEntryButton
          :form-locale="locale"
          :is-translating="isTranslating"
          :disabled="!hasProseToTranslate || isSubmitting || isLocked"
          :aria-describedby="lockedHintId"
          @translate="handleTranslate"
        />
      </div>

      <p
        v-if="proposalSourceLocale"
        class="alert alert-info small"
        role="status"
      >
        {{ t('admin.translation.proposalNotice', { locale: proposalSourceLocale.toUpperCase() }) }}
      </p>

      <p
        v-if="translationErrorText"
        class="text-danger-emphasis small"
        role="alert"
      >
        {{ translationErrorText }}
      </p>

      <p
        v-if="errorText"
        class="text-danger-emphasis small"
        role="alert"
      >
        {{ errorText }}
      </p>

      <button
        type="submit"
        class="btn btn-gradient"
        :disabled="isSubmitting || isLocked"
        :aria-describedby="lockedHintId"
      >
        {{ t('admin.about.save') }}
      </button>
    </form>
  </div>
</template>
