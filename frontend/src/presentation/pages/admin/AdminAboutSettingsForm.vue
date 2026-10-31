<script setup lang="ts">
import { computed, nextTick, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAdminAboutSettings } from '../../../application/admin/about/useAdminAboutSettings'
import { applyTranslationProposal, collectProseFields } from '../../../application/admin/translation/proseFields'
import BaseTextInput from '../../ui/BaseTextInput.vue'
import TranslateEntryButton from '../../ui/admin/TranslateEntryButton.vue'
import TranslationReadonlyHint from '../../ui/admin/TranslationReadonlyHint.vue'
import { describedBy } from '../../ui/admin/describedBy'
import { useTranslatedForm } from '../../ui/admin/useTranslatedForm'
import { ABOUT_SETTINGS_PROSE_FIELDS } from './proseFields'
import type { AdminAboutSettings } from '../../../domain/admin/about/entities/AdminAboutSettings'
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

const { settings, isLoading, hasError, isLoaded, errorMessage, load, save, isSaving, isAnySaving } = useAdminAboutSettings()

const form = reactive({ siteEyebrow: '', meEyebrow: '', technicalSubtitle: '', personalSubtitle: '', hobbiesSubtitle: '' })
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
 *
 * L'avis « proposition générée par IA » est celui de la langue cible (`proposal.target`) : absent sous l'autre langue,
 * il part dès que des réglages arrivent du serveur, quelle que soit leur langue (R66, décision 4 ; R141b).
 */
// Une traduction dont le formulaire n'est plus celui de l'envoi (une autre langue : l'identité ne porte que la langue) est périmée.
// Le composable refuse de lui-même tout ce que le bouton refuse (R127, R127b) : l'absence de prose, un enregistrement en vol,
// le verrou d'ordre de la page et une proposition affichée, qu'une seconde traduction remplacerait sans geste d'abandon.
const {
  isTranslating,
  isDiscardPending,
  isRefused: isTranslationRefused,
  errorReason: translationErrorReason,
  translate,
  proseReadonlyHintId,
  translationHintId,
  proseFieldAttrs,
} = useTranslatedForm({
  hintIdPrefix: 'admin-about-settings',
  isRefused: () => !hasProseToTranslate.value || isAnySaving.value || props.isLocked || isProposalShown.value,
  identity: () => ({ entry: null, locale: props.locale, group: null }),
})
const pendingProposal = ref<TranslationProposal | null>(null)
// Le formulaire rendu (absent pendant une lecture) : « Abandonner » y rend le focus au premier champ une fois relu.
const formElement = ref<HTMLFormElement | null>(null)
// La proposition posée sur un formulaire (R66, St6-10) : `source` est la langue traduite, que dit l'avis, `target` la
// langue du formulaire qui la porte, celle que la page a prise pour la recevoir. L'avis n'est que celui de `target` : il
// ne s'affiche que sous elle, et il part dès que des réglages arrivent du serveur (le `watch` de `settings`, ci-dessous),
// la proposition non enregistrée étant alors perdue (décision 4 de la sixième série, R141b).
const proposal = ref<{ readonly source: Locale; readonly target: Locale } | null>(null)
// L'avis est celui de la langue montrée si c'est la cible de la proposition (R86, St7-10). Chaque lecteur est dans le
// formulaire (avis, verrou, « Abandonner », bouton), qui n'existe qu'une fois `isLoaded` : une relecture de la cible
// refusée ou interrompue laisse les réglages nuls, sans formulaire pour les porter. `translate` n'est émis que par ce bouton.
const isProposalShown = computed(() => proposal.value?.target === props.locale)
// Pendant une proposition affichée, le bouton de traduction est verrouillé et désigne ce texte, rendu seulement alors (R127).
const PROPOSAL_LOCKED_HINT_ID = 'admin-about-settings-proposal-locked-help'
const hasProseToTranslate = computed(() => ABOUT_SETTINGS_PROSE_FIELDS.some((field) => '' !== form[field].trim()))
const translationErrorText = computed(() => {
  const reason = translationErrorReason.value

  return reason ? t(`admin.translation.errors.${reason}`) : null
})

// `flush: 'sync'` : le formulaire est rempli au moment même où les réglages
// arrivent, et non au prochain tick — ainsi, quand `isLoaded` se lève (après la
// pose des réglages), la copie a déjà eu lieu et la proposition en attente peut
// se poser par-dessus sans être écrasée ensuite.
//
// Ces réglages sont ceux du serveur : tout réglage qui arrive — la réponse d'un enregistrement réussi, un
// rechargement, un retour sur une langue — remplace le formulaire et fait perdre la proposition, donc son avis
// (R66, décision 4). Un enregistrement refusé ne relit rien et la garde (F11). La proposition en attente se pose
// après, au `watch` d'`isLoaded` : la copie qui la précède ne la retire pas.
watch(
  settings,
  (value) => {
    if (value) {
      copySettingsToForm(value)

      // Tout réglage qui arrive retire la proposition, quelle que soit sa langue (R141b, décision de l'auteur révisant la
      // décision 2 de la neuvième série : des réglages de l'autre langue la laissaient). Que la condition soit retirée ne se
      // voit pas : l'avis n'est rendu que sous la cible, et le retour sur elle relit ses réglages, qui la retirent de toute façon.
      proposal.value = null
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

/** Recopie les réglages lus du serveur dans le formulaire : les valeurs enregistrées de la langue montrée. */
function copySettingsToForm(value: AdminAboutSettings): void {
  form.siteEyebrow = value.siteEyebrow
  form.meEyebrow = value.meEyebrow
  form.technicalSubtitle = value.technicalSubtitle
  form.personalSubtitle = value.personalSubtitle
  form.hobbiesSubtitle = value.hobbiesSubtitle
}

/** Pose la proposition en attente si la locale qui vient d'être chargée est sa cible. */
function applyPendingProposal(loadedLocale: Locale): void {
  if (!pendingProposal.value || loadedLocale !== pendingProposal.value.targetLocale) {
    return
  }

  applyTranslationProposal(form, ABOUT_SETTINGS_PROSE_FIELDS, pendingProposal.value)
  proposal.value = { source: pendingProposal.value.sourceLocale, target: pendingProposal.value.targetLocale }
  pendingProposal.value = null
}

async function handleTranslate(targetLocale: Locale): Promise<void> {
  const translated = await translate(props.locale, targetLocale, collectProseFields(form, ABOUT_SETTINGS_PROSE_FIELDS))
  if (!translated) {
    return
  }

  pendingProposal.value = translated
  emit('switchLocale', targetLocale)
}

/**
 * « Abandonner » (R127c, décision de l'auteur du 2026-10-31) : retire la proposition affichée et relit le formulaire de
 * la langue montrée, dont les valeurs enregistrées reviennent. Le bouton n'existe que pendant la proposition, et la
 * proposition est la cible de la langue montrée : la relire est le retrait que fait déjà le retour sur cette langue
 * (R66, décision 4), sans l'aller-retour. La recopie est refaite ici et pas laissée au seul `watch` des réglages, qui ne
 * se déclenche pas si la lecture rend l'objet déjà détenu. Aucune traduction n'est en vol à ce moment : le bouton « Proposer »
 * est refusé tant que la proposition est affichée (`isProposalShown`), seule condition où « Abandonner » existe.
 * Aucune confirmation (pas de `confirm`) : la proposition n'est jamais enregistrée, la perdre ne coûte qu'un appel.
 *
 * Le bouton disparaît sous le focus (le formulaire est masqué pendant la lecture) : une fois relu, le focus va au
 * premier champ, jamais à `body`. Comme « Annuler » des formulaires par entrée, l'abandon n'est pas annoncé.
 */
async function handleDiscardProposal(): Promise<void> {
  const locale = props.locale
  pendingProposal.value = null
  await load(locale)

  // La personne a changé de langue pendant la lecture : le formulaire n'est plus celui qu'on a relu.
  if (locale !== props.locale || !isLoaded.value || !settings.value) {
    return
  }

  copySettingsToForm(settings.value)
  // Sans condition : le bouton n'existe que pendant la proposition affichée, et la langue montrée n'a pas changé depuis.
  // Le `watch` de `settings` la retire aussi, mais pas quand la lecture rend l'objet déjà détenu (voir ci-dessus).
  proposal.value = null

  await nextTick()
  formElement.value?.querySelector<HTMLElement>('input')?.focus()
}

async function handleSubmit(): Promise<void> {
  // La langue enregistrée est celle du départ : `props.locale` peut changer pendant l'attente.
  const locale = props.locale
  // L'avis de la proposition n'est pas retiré ici. La réponse réussie d'un enregistrement de la langue montrée est
  // écrite dans `settings` et repasse par le `watch` ci-dessus, qui le retire. Celle d'un enregistrement de la langue
  // cible, parti pendant qu'une autre était montrée, ne touche pas `settings` (`write` ne pose que sous la clé
  // courante) : la proposition reste en mémoire, mais la langue cible est relue au retour avant tout rendu (le
  // formulaire attend `isLoaded`), ce qui la retire aussi (CR7-8). Un enregistrement refusé (hors ligne, session
  // finie) ne relit rien, le formulaire porte toujours la proposition et l'avis reste dit (F11).
  await save(locale, { ...form })
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
      ref="formElement"
      novalidate
      @submit.prevent="handleSubmit"
    >
      <BaseTextInput
        id="admin-about-site-eyebrow"
        v-model="form.siteEyebrow"
        v-bind="proseFieldAttrs()"
        :label="t('admin.about.settings.siteEyebrowLabel')"
        required
      />
      <BaseTextInput
        id="admin-about-me-eyebrow"
        v-model="form.meEyebrow"
        v-bind="proseFieldAttrs()"
        :label="t('admin.about.settings.meEyebrowLabel')"
        required
      />
      <BaseTextInput
        id="admin-about-technical-subtitle"
        v-model="form.technicalSubtitle"
        v-bind="proseFieldAttrs()"
        :label="t('admin.about.settings.technicalSubtitleLabel')"
        required
      />
      <BaseTextInput
        id="admin-about-personal-subtitle"
        v-model="form.personalSubtitle"
        v-bind="proseFieldAttrs()"
        :label="t('admin.about.settings.personalSubtitleLabel')"
        required
      />
      <BaseTextInput
        id="admin-about-hobbies-subtitle"
        v-model="form.hobbiesSubtitle"
        v-bind="proseFieldAttrs()"
        :label="t('admin.about.settings.hobbiesSubtitleLabel')"
        required
      />

      <div class="mb-3">
        <!--
          Retenu tant qu'une langue enregistre, la montrée comme la **cible** (R67, R98, décision 3 de la sixième série) : la réponse de cet
          enregistrement est écrite dans les réglages et recopiée par-dessus la proposition que la traduction pose sur
          son formulaire. Un enregistrement de la cible lancé pendant que la traduction court la recouvre encore, et
          l'avis part avec elle (R66) : rien n'est dit de faux.
        -->
        <TranslateEntryButton
          :form-locale="locale"
          :is-translating="isTranslating"
          :is-discard-pending="isDiscardPending"
          :hint-id="translationHintId"
          :disabled="isTranslationRefused"
          :aria-describedby="describedBy(lockedHintId, isProposalShown ? PROPOSAL_LOCKED_HINT_ID : undefined)"
          @translate="handleTranslate"
        />
        <button
          v-if="isProposalShown"
          type="button"
          class="btn btn-outline-light ms-2"
          @click="handleDiscardProposal"
        >
          {{ t('admin.about.settings.proposalDiscard') }}
        </button>
        <p
          v-if="isProposalShown"
          :id="PROPOSAL_LOCKED_HINT_ID"
          class="form-text mb-0"
        >
          {{ t('admin.about.settings.proposalLockedHelp') }}
        </p>
      </div>

      <TranslationReadonlyHint
        :id="proseReadonlyHintId"
        :shown="isTranslating"
      />

      <p
        v-if="proposal && isProposalShown"
        class="alert alert-info small"
        role="status"
      >
        {{ t('admin.translation.proposalNotice', { locale: proposal.source.toUpperCase() }) }}
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
        :disabled="isSaving || isLocked"
        :aria-describedby="lockedHintId"
      >
        {{ t('admin.about.save') }}
      </button>
    </form>
  </div>
</template>
