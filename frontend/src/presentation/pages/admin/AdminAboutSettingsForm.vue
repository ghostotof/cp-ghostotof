<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAdminAboutSettings } from '../../../application/admin/about/useAdminAboutSettings'
import { useAdminTranslation } from '../../../application/admin/translation/useAdminTranslation'
import { applyTranslationDraft, collectProseFields } from '../../../application/admin/translation/proseFields'
import BaseTextInput from '../../ui/BaseTextInput.vue'
import TranslateEntryButton from '../../ui/admin/TranslateEntryButton.vue'
import type { Locale } from '../../../domain/portfolio/entities/Locale'
import type { TranslationDraft } from '../../../domain/admin/translation/entities/TranslationDraft'

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

const { settings, isLoading, hasError, errorMessage, load, save } = useAdminAboutSettings()


const form = reactive({ siteEyebrow: '', meEyebrow: '', technicalSubtitle: '', personalSubtitle: '', hobbiesSubtitle: '' })
const isSubmitting = ref(false)
const errorText = computed(() => (errorMessage.value ? t(`admin.about.errors.${errorMessage.value.reason}`) : null))

/**
 * Assistant de traduction (spec 0002). Les réglages sont un singleton par
 * locale : « proposer la version EN » signifie basculer la page en EN, dont
 * les réglages existants se rechargent et remplissent le formulaire — le
 * brouillon serait écrasé s'il était appliqué avant. Il est donc mis en
 * attente et posé sur le formulaire une fois la locale cible chargée ;
 * Enregistrer met alors à jour les réglages de cette locale.
 */
const { isTranslating, errorReason: translationErrorReason, translate } = useAdminTranslation()
const PROSE_FIELDS = ['siteEyebrow', 'meEyebrow', 'technicalSubtitle', 'personalSubtitle', 'hobbiesSubtitle'] as const
const pendingDraft = ref<TranslationDraft | null>(null)
const draftSourceLocale = ref<Locale | null>(null)
const hasProseToTranslate = computed(() => PROSE_FIELDS.some((field) => '' !== form[field].trim()))
const translationErrorText = computed(() =>
  translationErrorReason.value ? t(`admin.translation.errors.${translationErrorReason.value}`) : null,
)

// `flush: 'sync'` : le formulaire est rempli au moment même où les réglages
// arrivent, et non au prochain tick — ainsi, au retour de load(), la copie a
// déjà eu lieu et le brouillon en attente peut se poser par-dessus sans être
// écrasé ensuite.
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

watch(
  () => props.locale,
  async (locale) => {
    await load(locale)
    applyPendingDraft(locale)
  },
  { immediate: true },
)

/** Pose le brouillon en attente si la locale qui vient d'être chargée est sa cible. */
function applyPendingDraft(loadedLocale: Locale): void {
  if (!pendingDraft.value || loadedLocale !== pendingDraft.value.targetLocale) {
    return
  }

  applyTranslationDraft(form, PROSE_FIELDS, pendingDraft.value)
  draftSourceLocale.value = pendingDraft.value.sourceLocale
  pendingDraft.value = null
}

async function handleTranslate(targetLocale: Locale): Promise<void> {
  const draft = await translate(props.locale, targetLocale, collectProseFields(form, PROSE_FIELDS))
  if (!draft) {
    return
  }

  pendingDraft.value = draft
  emit('switchLocale', targetLocale)
}

async function handleSubmit(): Promise<void> {
  isSubmitting.value = true
  draftSourceLocale.value = null
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
      class="text-danger mb-0"
      role="alert"
    >
      {{ t('admin.about.loadError') }}
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
          @translate="handleTranslate"
        />
      </div>

      <p
        v-if="draftSourceLocale"
        class="alert alert-info small"
        role="status"
      >
        {{ t('admin.translation.draftNotice', { locale: draftSourceLocale.toUpperCase() }) }}
      </p>

      <p
        v-if="translationErrorText"
        class="text-danger small"
        role="alert"
      >
        {{ translationErrorText }}
      </p>

      <p
        v-if="errorText"
        class="text-danger small"
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
