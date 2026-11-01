<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAdminQualityPrinciples } from '../../../application/admin/quality/useAdminQualityPrinciples'
import { useOrderDraft } from '../../../application/admin/shared/useOrderDraft'
import { useOrderHandleFocus } from '../../../application/admin/shared/useOrderHandleFocus'
import { useRowDragAndDrop } from '../../../application/admin/shared/useRowDragAndDrop'
import {
  groupByTranslationGroup,
  type TranslationGroupRow,
} from '../../../domain/admin/shared/ordering/groupByTranslationGroup'
import { orderRowsByDraft } from '../../../domain/admin/shared/ordering/orderRowsByDraft'
import {
  firstEntry,
  hasSibling,
  rowLines,
  translationGroupOptions,
} from '../../../domain/admin/shared/ordering/translationGroupSelection'
import BaseTextInput from '../../ui/BaseTextInput.vue'
import BaseTextarea from '../../ui/BaseTextarea.vue'
import BaseSelect from '../../ui/BaseSelect.vue'
import TranslateEntryButton from '../../ui/admin/TranslateEntryButton.vue'
import TranslationReadonlyHint from '../../ui/admin/TranslationReadonlyHint.vue'
import EntryFormLockHint from '../../ui/admin/EntryFormLockHint.vue'
import { describedBy } from '../../ui/admin/describedBy'
import { useEntryFormLock } from '../../ui/admin/useEntryFormLock'
import { useTranslatedForm } from '../../ui/admin/useTranslatedForm'
import { QUALITY_PRINCIPLE_PROSE_FIELDS } from './proseFields'
import OrderHandle from '../../ui/admin/OrderHandle.vue'
import OrderToolbar from '../../ui/admin/OrderToolbar.vue'
import { LOCALE_NATIVE_NAMES, SUPPORTED_LOCALES, type Locale } from '../../../domain/portfolio/entities/Locale'
import type { AdminQualityPrinciple } from '../../../domain/admin/quality/entities/AdminQualityPrinciple'

/**
 * Les principes de qualité : un formulaire, un tableau groupé par groupe de
 * traduction (toutes les langues, spec 0004 D8) et son brouillon d'ordre.
 * Moitié de `AdminQualityPage`, sur le modèle des sections d'« À propos ».
 *
 * **La langue est un champ de formulaire, pas un état de page**
 * (`principleForm.locale`) : c'est la langue de l'entrée en cours d'édition ou
 * de création, et elle ne pilote rien d'autre que ce formulaire et son
 * assistant. « Modifier » sur une entrée d'une autre langue aligne celle du
 * formulaire (sans cela, l'enregistrement réécrirait l'entrée anglaise en
 * français) ; l'assistant bascule **ce** formulaire vers la locale cible et y
 * rattache la proposition au groupe de l'entrée source (D9), si bien que
 * l'enregistrer lie la traduction sans geste supplémentaire. Rien n'est rechargé
 * derrière (le tableau affiche déjà toutes les langues, D8), donc rien ne vient
 * écraser la proposition.
 *
 * **Le verrou d'ordre (D6) est global à la page** : la section reçoit
 * `isLocked` (un brouillon d'ordre modifié, ici ou dans l'autre section) et
 * annonce le sien par `orderDirtyChange`, la page faisant la somme et rendant
 * l'aide une seule fois (`lockedHintId`). Le refus de traduire lit **son**
 * brouillon (`isPrincipleOrderDirty`) tout de suite et celui de l'autre section
 * par `isLocked`, à jour après le rendu de la page : voir la limite dans
 * `AdminQualityPage`.
 */

const props = defineProps<{ isLocked: boolean; lockedHintId?: string }>()
const emit = defineEmits<{ orderDirtyChange: [isDirty: boolean] }>()

const { t } = useI18n()

const {
  principles,
  isLoading: isLoadingPrinciples,
  hasError: hasPrinciplesError,
  isLoaded: isPrinciplesLoaded,
  errorMessage: principleErrorMessage,
  load: loadPrinciples,
  create: createPrinciple,
  update: updatePrinciple,
  remove: removePrinciple,
  reorder: reorderPrinciples,
} = useAdminQualityPrinciples()

const localeOptions = SUPPORTED_LOCALES.map((locale) => ({ value: locale, label: LOCALE_NATIVE_NAMES[locale] }))

/**
 * `locale` est typée explicitement : sans annotation, `reactive` l'infère au
 * type littéral de sa valeur initiale et le sélecteur de langue ne compile
 * plus. Le champ numérique `Position` a disparu (spec 0004, D3) — la position
 * ne se saisit plus, seul `PUT …/order` l'écrit.
 */
interface PrincipleForm {
  locale: Locale
  translationGroup: string
  title: string
  description: string
  iconKey: string
}

const principleForm = reactive<PrincipleForm>({
  locale: SUPPORTED_LOCALES[0],
  translationGroup: '',
  title: '',
  description: '',
  iconKey: '',
})

const editingPrincipleId = ref<string | null>(null)
const isSubmittingPrinciple = ref(false)

/**
 * Groupe de l'entrée dont le formulaire est issu (édition, ou « Créer la
 * version XX »), `null` sur une page blanche. Distinct de
 * `form.translationGroup`, qui est la valeur *choisie* dans le sélecteur :
 * l'assistant bascule le formulaire vers l'autre locale et doit y rattacher la
 * proposition au groupe de la **source** (D9), y compris quand ce groupe n'avait
 * aucune traduction et que le sélecteur affichait donc « aucune ».
 */
const principleSourceGroup = ref<string | null>(null)

/** Locale de l'entrée dont le formulaire est une proposition traduite, `null` sinon. */
const principleProposalSourceLocale = ref<Locale | null>(null)

const isEditingPrinciple = computed(() => null !== editingPrincipleId.value)
const principleLock = useEntryFormLock({
  hintId: 'admin-quality-principle-entry-lock-help',
  isEditing: isEditingPrinciple,
  proposalSourceLocale: principleProposalSourceLocale,
  localeHelpId: 'admin-quality-principle-locale-help',
})

/**
 * Une instance de composable de traduction par formulaire : chacun a son
 * attente et son message d'erreur. Après les formulaires, que le composable lit dès l'appel.
 */
const {
  isTranslating: isTranslatingPrinciple,
  isExpiredCallPending: isExpiredCallPendingPrinciple,
  isRefused: isPrincipleTranslationRefused,
  errorText: principleTranslationErrorText,
  proposeFrom: proposePrincipleFrom,
  removeEntry: removePrincipleEntry,
  renewForm: renewPrincipleForm,
  proseReadonlyHintId: principleProseReadonlyHintId,
  translationHintId: principleTranslationHintId,
  proseFieldAttrs: principleProseFieldAttrs,
} = useTranslatedForm({
  hintIdPrefix: 'admin-quality-principle',
  form: principleForm,
  proseFields: QUALITY_PRINCIPLE_PROSE_FIELDS,
  // Le brouillon d'ordre de cette section est lu tout de suite ; `props.isLocked` ne dit celui de l'autre section qu'après l'`emit`
  // et le rendu du parent (limite : une micro-tâche, qu'aucun geste ne traverse).
  isRefused: () =>
    isSubmittingPrinciple.value || isPrincipleOrderDirty.value || props.isLocked || principleLock.isProposalLocked,
  // Ce qui fait l'identité du formulaire : une traduction dont il n'a plus cette identité est périmée. La prose n'en
  // fait pas partie : la saisie est empêchée pendant la traduction, et l'erreur d'un échec survit à la frappe.
  identity: () => ({
    entry: editingPrincipleId.value,
    locale: principleForm.locale,
    group: principleSourceGroup.value,
  }),
})

const principleErrorText = computed(() => {
  const reason = principleErrorMessage.value?.reason

  return reason ? t(`admin.quality.errors.${reason}`) : null
})

/** Une ligne de tableau par groupe de traduction (D8), toutes langues confondues. */
const principleRows = computed(() => groupByTranslationGroup(principles.value, SUPPORTED_LOCALES))

const {
  draft: principleOrderDraft,
  isDirty: isPrincipleOrderDirty,
  isSaving: isSavingPrincipleOrder,
  errorReason: principleOrderErrorReason,
  move: movePrincipleInDraft,
  reset: resetPrincipleOrder,
  save: savePrincipleOrder,
} = useOrderDraft({
  serverKeys: () => principleRows.value.map((row) => row.key),
  reorder: reorderPrinciples,
  reload: loadPrinciples,
})

// L'ordre modifié de cette section est annoncé à la page, qui verrouille toutes les mutations ; `immediate` pour que
// l'état initial (à jour) soit posé sans attendre un premier déplacement.
watch(isPrincipleOrderDirty, (isDirty) => emit('orderDirtyChange', isDirty), { immediate: true })

const {
  draggingIndex: draggingPrincipleIndex,
  onDragStart: onPrincipleDragStart,
  onDragOver: onPrincipleDragOver,
  onDrop: onPrincipleDrop,
  onDragEnd: onPrincipleDragEnd,
} = useRowDragAndDrop(movePrincipleInDraft)

const {
  registerHandleCell: registerPrincipleHandleCell,
  moveRow: movePrincipleRow,
  lastMove: principleLastMove,
} = useOrderHandleFocus(movePrincipleInDraft, () => orderedPrincipleRows.value.length)

const orderedPrincipleRows = computed(() => orderRowsByDraft(principleRows.value, principleOrderDraft.value))

/**
 * « Version de » : les options du sélecteur (D2, cf.
 * `domain/admin/shared/ordering/translationGroupSelection.ts` pour la règle
 * exacte). L'option « aucune » est préfixée ici, son libellé étant un texte
 * traduit — le module partagé reste framework-free.
 */
const principleTranslationOptions = computed(() => [
  { value: '', label: t('admin.quality.translationOfNone') },
  ...translationGroupOptions(
    principles.value,
    principleForm.locale,
    principleForm.translationGroup,
    editingPrincipleId.value,
    (principle) => `${principle.locale.toUpperCase()} · ${principle.title}`,
  ),
])

function resetPrincipleForm(): void {
  // Avant toute écriture : une remise à zéro change la génération du formulaire, que la traduction en vol lit (R139).
  renewPrincipleForm()
  editingPrincipleId.value = null
  principleProposalSourceLocale.value = null
  principleSourceGroup.value = null
  principleForm.locale = SUPPORTED_LOCALES[0]
  principleForm.translationGroup = ''
  principleForm.title = ''
  principleForm.description = ''
  principleForm.iconKey = ''
}

// Le groupe lu est repris tel quel dès qu'il porte une traduction : le
// formulaire le renvoie alors à l'enregistrement, et le lien FR/EN survit à
// l'édition. Un groupe solitaire n'a rien à détacher : `ContentPlacement::detach`
// traite une entrée seule en non-geste (`count($members) === 1`), le groupe
// est conservé. Le sélecteur affiche donc « aucune » sans conséquence.
function startEditPrinciple(principle: AdminQualityPrinciple): void {
  // Avant toute écriture : ce geste remplit ou vide le formulaire, il en avance la génération que la traduction en vol lit (R152).
  renewPrincipleForm()
  editingPrincipleId.value = principle.id
  principleProposalSourceLocale.value = null
  principleSourceGroup.value = principle.translationGroup
  principleForm.locale = principle.locale
  principleForm.translationGroup = hasSibling(principles.value, principle) ? principle.translationGroup : ''
  principleForm.title = principle.title
  principleForm.description = principle.description
  principleForm.iconKey = principle.iconKey
}

/**
 * « Créer la version XX » : formulaire en création, déjà rattaché au groupe,
 * dans la langue manquante, celle du sélecteur de ce formulaire. Les champs non-prose sont recopiés de l'entrée
 * existante (cf. QUALITY_PRINCIPLE_PROSE_FIELDS par complément) et la prose reste vide :
 * il n'y a rien à traduire puisqu'il n'y a rien d'écrit encore.
 */
function startCreatePrincipleVersion(row: TranslationGroupRow<AdminQualityPrinciple>, locale: Locale): void {
  // Avant toute écriture : ce geste remplit ou vide le formulaire, il en avance la génération que la traduction en vol lit (R152).
  renewPrincipleForm()
  const existing = firstEntry(row, SUPPORTED_LOCALES)

  editingPrincipleId.value = null
  principleProposalSourceLocale.value = null
  principleSourceGroup.value = row.key
  principleForm.locale = locale
  principleForm.translationGroup = row.key
  principleForm.iconKey = existing?.iconKey ?? ''
  principleForm.title = ''
  principleForm.description = ''
}

async function handleSubmitPrinciple(): Promise<void> {
  isSubmittingPrinciple.value = true

  const input = {
    locale: principleForm.locale,
    // D3 : aucun `position` n'est jamais envoyé. `null` = contenu neuf à la
    // création, détachement sur une mise à jour.
    translationGroup: '' === principleForm.translationGroup ? null : principleForm.translationGroup,
    title: principleForm.title,
    description: principleForm.description,
    iconKey: principleForm.iconKey,
  }

  const succeeded =
    null !== editingPrincipleId.value
      ? await updatePrinciple(editingPrincipleId.value, input)
      : await createPrinciple(input)

  isSubmittingPrinciple.value = false

  if (succeeded) {
    resetPrincipleForm()
  }
}

/**
 * Demande une proposition dans l'autre locale, puis bascule le formulaire en
 * **création** avec cette proposition : la prose est remplacée, le reste conservé —
 * y compris le groupe de l'entrée source, pour que l'enregistrement rattache
 * la version proposée sans geste supplémentaire (spec 0004, D9). Rien n'est
 * enregistré ici — seul le bouton Enregistrer habituel persiste (ADR 0004,
 * D4). En cas d'échec, le formulaire reste intact et la raison s'affiche.
 */
async function handleTranslatePrinciple(targetLocale: Locale): Promise<void> {
  await proposePrincipleFrom(targetLocale, (sourceLocale) => {
    editingPrincipleId.value = null
    principleForm.locale = targetLocale
    principleForm.translationGroup = principleSourceGroup.value ?? ''
    principleProposalSourceLocale.value = sourceLocale
  })
}

async function handleDeletePrinciple(principle: AdminQualityPrinciple): Promise<void> {
  if (!window.confirm(t('admin.quality.principle.confirmDelete', { title: principle.title }))) {
    return
  }

  // La suppression ne change rien au formulaire : le composable périme la traduction de l'entrée supprimée ou de sa source.
  await removePrincipleEntry(principle, () => removePrinciple(principle.id))
}
</script>

<template>
  <div class="surface-panel p-3 p-sm-4">
    <h2 class="h6 fw-bold text-white mb-3">
      {{ isEditingPrinciple ? t('admin.quality.principle.edit') : t('admin.quality.principle.create') }}
    </h2>

    <form
      novalidate
      @submit.prevent="handleSubmitPrinciple"
    >
      <!-- Verrou d'édition et de proposition : voir `useEntryFormLock` (#748). -->
      <BaseSelect
        id="admin-quality-principle-locale"
        v-model="principleForm.locale"
        :disabled="principleLock.isEntryLocked"
        :aria-describedby="principleLock.localeDescribedBy"
        :label="t('admin.localeLabel')"
        :options="localeOptions"
      />
      <p
        id="admin-quality-principle-locale-help"
        class="form-text mb-3"
      >
        {{ t('admin.quality.localeHelp') }}
      </p>
      <EntryFormLockHint
        :id="principleLock.hintId"
        :state="principleLock.state"
      />
      <BaseSelect
        id="admin-quality-principle-translation-group"
        v-model="principleForm.translationGroup"
        :disabled="principleLock.isProposalLocked"
        :aria-describedby="principleLock.proposalDescribedBy"
        :label="t('admin.quality.translationOfLabel')"
        :options="principleTranslationOptions"
      />
      <BaseTextInput
        id="admin-quality-principle-title"
        v-model="principleForm.title"
        v-bind="principleProseFieldAttrs()"
        :label="t('admin.quality.principle.titleLabel')"
        required
      />
      <BaseTextarea
        id="admin-quality-principle-description"
        v-model="principleForm.description"
        v-bind="principleProseFieldAttrs()"
        :label="t('admin.quality.principle.descriptionLabel')"
        required
      />
      <BaseTextInput
        id="admin-quality-principle-icon-key"
        v-model="principleForm.iconKey"
        :label="t('admin.quality.principle.iconKeyLabel')"
        required
      />

      <!--
        Verrouillé lui aussi tant qu'un ordre est modifié : l'appel au modèle
        produirait une proposition que le formulaire, verrouillé, ne pourrait pas
        enregistrer — du quota dépensé pour rien (ADR 0004, coût borné).
      -->
      <div class="mb-3">
        <TranslateEntryButton
          :form-locale="principleForm.locale"
          :is-translating="isTranslatingPrinciple"
          :is-expired-call-pending="isExpiredCallPendingPrinciple"
          :hint-id="principleTranslationHintId"
          :disabled="isPrincipleTranslationRefused"
          :aria-describedby="describedBy(lockedHintId, principleLock.proposalDescribedBy)"
          @translate="handleTranslatePrinciple"
        />
      </div>

      <TranslationReadonlyHint
        :id="principleProseReadonlyHintId"
        :shown="isTranslatingPrinciple"
      />

      <p
        v-if="principleProposalSourceLocale"
        class="alert alert-info small"
        role="status"
      >
        {{ t('admin.translation.proposalNotice', { locale: principleProposalSourceLocale.toUpperCase() }) }}
      </p>

      <p
        v-if="principleTranslationErrorText"
        class="text-danger-emphasis small"
        role="alert"
      >
        {{ principleTranslationErrorText }}
      </p>

      <p
        v-if="principleErrorText"
        class="text-danger-emphasis small"
        role="alert"
      >
        {{ principleErrorText }}
      </p>

      <div class="d-flex gap-2">
        <button
          type="submit"
          class="btn btn-gradient"
          :disabled="isSubmittingPrinciple || isLocked"
          :aria-describedby="lockedHintId"
        >
          {{ t('admin.quality.save') }}
        </button>
        <button
          v-if="principleLock.isEntryLocked"
          type="button"
          class="btn btn-outline-light"
          @click="resetPrincipleForm"
        >
          {{ t('admin.quality.cancel') }}
        </button>
      </div>
    </form>

    <hr class="border-secondary my-4">

    <h3 class="h6 fw-bold text-white mb-3">
      {{ t('admin.quality.principle.listTitle') }}
    </h3>

    <p
      v-if="isLoadingPrinciples"
      class="text-body-secondary mb-0"
    >
      {{ t('admin.quality.loading') }}
    </p>
    <p
      v-else-if="hasPrinciplesError"
      class="text-danger-emphasis mb-0"
      role="alert"
    >
      {{ t('admin.quality.loadError') }}
    </p>
    <p
      v-else-if="!isPrinciplesLoaded"
      class="text-body-secondary mb-0"
    >
      {{ t('admin.notLoaded') }}
    </p>
    <p
      v-else-if="0 === orderedPrincipleRows.length"
      class="text-body-secondary mb-0"
    >
      {{ t('admin.quality.principle.empty') }}
    </p>
    <template v-else>
      <OrderToolbar
        class="mb-3"
        :is-dirty="isPrincipleOrderDirty"
        :is-saving="isSavingPrincipleOrder"
        :error-reason="principleOrderErrorReason"
        :last-move="principleLastMove"
        @save="savePrincipleOrder"
        @cancel="resetPrincipleOrder"
      />

      <div class="table-responsive">
        <table class="table table-dark align-middle mb-0">
          <thead>
            <tr>
              <th
                scope="col"
                class="col-md-1"
              >
                <span class="visually-hidden">{{ t('admin.order.columnHeader') }}</span>
              </th>
              <th scope="col">
                {{ t('admin.quality.contentLabel') }}
              </th>
              <th
                scope="col"
                class="col-md-1"
              >
                {{ t('admin.quality.principle.iconKeyLabel') }}
              </th>
              <th
                scope="col"
                class="col-md-2 text-end"
              >
                <span class="visually-hidden">{{ t('admin.order.actionsColumn') }}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="(row, index) in orderedPrincipleRows"
              :key="row.key"
              draggable="true"
              :class="{ 'opacity-50': index === draggingPrincipleIndex }"
              @dragstart="onPrincipleDragStart(index)"
              @dragover="onPrincipleDragOver"
              @drop="onPrincipleDrop(index)"
              @dragend="onPrincipleDragEnd"
            >
              <td
                :ref="registerPrincipleHandleCell"
                :data-order-key="row.key"
              >
                <OrderHandle
                  :index="index"
                  :count="orderedPrincipleRows.length"
                  :label="firstEntry(row, SUPPORTED_LOCALES)?.title ?? ''"
                  @move="(from, to) => movePrincipleRow(row.key, from, to)"
                />
              </td>
              <td>
                <div
                  v-for="line in rowLines(row, SUPPORTED_LOCALES, LOCALE_NATIVE_NAMES)"
                  :key="line.locale"
                  class="admin-locale-line"
                >
                  <span
                    class="badge text-bg-secondary"
                    aria-hidden="true"
                  >{{ line.locale.toUpperCase() }}</span>
                  <span class="visually-hidden">{{ line.nativeName }}</span>
                  <template v-if="line.entry">
                    <span class="text-white admin-locale-line__text">{{ line.entry.title }}</span>
                  </template>
                  <template v-else>
                    <span class="text-body-secondary admin-locale-line__text">{{ t('admin.order.missingTranslation') }}</span>
                  </template>
                </div>
              </td>
              <td class="text-nowrap">
                {{ firstEntry(row, SUPPORTED_LOCALES)?.iconKey }}
              </td>
              <!-- Une ligne par langue, en face de celle de la cellule de contenu :
                   même v-for, même hauteur minimale (`.admin-locale-line`). -->
              <td class="text-end">
                <div
                  v-for="line in rowLines(row, SUPPORTED_LOCALES, LOCALE_NATIVE_NAMES)"
                  :key="line.locale"
                  class="admin-locale-line justify-content-end text-nowrap"
                >
                  <template v-if="line.entry">
                    <button
                      type="button"
                      class="btn btn-sm btn-outline-light"
                      :disabled="isLocked"
                      :aria-describedby="lockedHintId"
                      @click="startEditPrinciple(line.entry)"
                    >
                      {{ t('admin.quality.principle.editAction') }}
                    </button>
                    <button
                      type="button"
                      class="btn btn-sm btn-outline-danger"
                      :disabled="isLocked"
                      :aria-describedby="lockedHintId"
                      @click="handleDeletePrinciple(line.entry)"
                    >
                      {{ t('admin.quality.delete') }}
                    </button>
                  </template>
                  <template v-else>
                    <button
                      type="button"
                      class="btn btn-sm btn-outline-light"
                      :disabled="isLocked"
                      :aria-describedby="lockedHintId"
                      @click="startCreatePrincipleVersion(row, line.locale)"
                    >
                      {{ t('admin.order.createVersion', { locale: line.locale.toUpperCase() }) }}
                    </button>
                  </template>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>
