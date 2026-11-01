<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAdminQualityTraits } from '../../../application/admin/quality/useAdminQualityTraits'
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
import BaseSelect from '../../ui/BaseSelect.vue'
import TranslateEntryButton from '../../ui/admin/TranslateEntryButton.vue'
import TranslationReadonlyHint from '../../ui/admin/TranslationReadonlyHint.vue'
import EntryFormLockHint from '../../ui/admin/EntryFormLockHint.vue'
import { describedBy } from '../../ui/admin/describedBy'
import { useEntryFormLock } from '../../ui/admin/useEntryFormLock'
import { useTranslatedForm } from '../../ui/admin/useTranslatedForm'
import { QUALITY_TRAIT_PROSE_FIELDS } from './proseFields'
import OrderHandle from '../../ui/admin/OrderHandle.vue'
import OrderToolbar from '../../ui/admin/OrderToolbar.vue'
import { LOCALE_NATIVE_NAMES, SUPPORTED_LOCALES, type Locale } from '../../../domain/portfolio/entities/Locale'
import type { AdminQualityTrait } from '../../../domain/admin/quality/entities/AdminQualityTrait'

/**
 * Les traits de qualité : un formulaire, un tableau groupé par groupe de
 * traduction (toutes les langues, spec 0004 D8) et son brouillon d'ordre.
 * L'autre moitié de `AdminQualityPage`, jumelle de `AdminQualityPrinciplesSection`
 * (le trait n'a qu'un champ, `label`, qui est de la prose : rien à recopier dans
 * « Créer la version XX »).
 *
 * Mêmes règles que les principes : la langue est un champ du formulaire
 * (`traitForm.locale`), l'assistant bascule **ce** formulaire vers la locale
 * cible en gardant le groupe de la source (D9), et le verrou d'ordre (D6) est
 * global à la page : `isLocked` reçu, `orderDirtyChange` annoncé, et le refus de traduire lit
 * son propre brouillon (`isTraitOrderDirty`) tout de suite.
 */

const props = defineProps<{ isLocked: boolean; lockedHintId?: string }>()
const emit = defineEmits<{ orderDirtyChange: [isDirty: boolean] }>()

const { t } = useI18n()

const {
  traits,
  isLoading: isLoadingTraits,
  hasError: hasTraitsError,
  isLoaded: isTraitsLoaded,
  errorMessage: traitErrorMessage,
  load: loadTraits,
  create: createTrait,
  update: updateTrait,
  remove: removeTrait,
  reorder: reorderTraits,
} = useAdminQualityTraits()

const localeOptions = SUPPORTED_LOCALES.map((locale) => ({ value: locale, label: LOCALE_NATIVE_NAMES[locale] }))

interface TraitForm {
  locale: Locale
  translationGroup: string
  label: string
}

const traitForm = reactive<TraitForm>({ locale: SUPPORTED_LOCALES[0], translationGroup: '', label: '' })

const editingTraitId = ref<string | null>(null)
const isSubmittingTrait = ref(false)

/** Groupe de l'entrée dont le formulaire est issu : voir `AdminQualityPrinciplesSection` (D9). */
const traitSourceGroup = ref<string | null>(null)

/** Locale de l'entrée dont le formulaire est une proposition traduite, `null` sinon. */
const traitProposalSourceLocale = ref<Locale | null>(null)

const isEditingTrait = computed(() => null !== editingTraitId.value)
const traitLock = useEntryFormLock({
  hintId: 'admin-quality-trait-entry-lock-help',
  isEditing: isEditingTrait,
  proposalSourceLocale: traitProposalSourceLocale,
  localeHelpId: 'admin-quality-trait-locale-help',
})

/**
 * Une instance de composable de traduction par formulaire : chacun a son
 * attente et son message d'erreur.
 */
const {
  isTranslating: isTranslatingTrait,
  isExpiredCallPending: isExpiredCallPendingTrait,
  isRefused: isTraitTranslationRefused,
  translationErrorText: traitTranslationErrorText,
  proposeFrom: proposeTraitFrom,
  removeEntry: removeTraitEntry,
  renewForm: renewTraitForm,
  proseReadonlyHintId: traitProseReadonlyHintId,
  translationHintId: traitTranslationHintId,
  proseFieldAttrs: traitProseFieldAttrs,
} = useTranslatedForm({
  hintIdPrefix: 'admin-quality-trait',
  form: () => traitForm,
  proseFields: QUALITY_TRAIT_PROSE_FIELDS,
  // Le brouillon d'ordre de cette section est lu tout de suite : voir `AdminQualityPrinciplesSection`.
  isRefused: () => isSubmittingTrait.value || isTraitOrderDirty.value || props.isLocked || traitLock.isProposalLocked,
  // Ce qui fait l'identité du formulaire : une traduction dont il n'a plus cette identité est périmée. La prose n'en
  // fait pas partie : la saisie est empêchée pendant la traduction, et l'erreur d'un échec survit à la frappe.
  identity: () => ({
    entry: editingTraitId.value,
    locale: traitForm.locale,
    group: traitSourceGroup.value,
  }),
})

const traitErrorText = computed(() => {
  const reason = traitErrorMessage.value?.reason

  return reason ? t(`admin.quality.errors.${reason}`) : null
})

const traitRows = computed(() => groupByTranslationGroup(traits.value, SUPPORTED_LOCALES))

const {
  draft: traitOrderDraft,
  isDirty: isTraitOrderDirty,
  isSaving: isSavingTraitOrder,
  errorReason: traitOrderErrorReason,
  move: moveTraitInDraft,
  reset: resetTraitOrder,
  save: saveTraitOrder,
} = useOrderDraft({
  serverKeys: () => traitRows.value.map((row) => row.key),
  reorder: reorderTraits,
  reload: loadTraits,
})

// L'ordre modifié de cette section est annoncé à la page, qui verrouille toutes les mutations ; `immediate` pour que
// l'état initial (à jour) soit posé sans attendre un premier déplacement.
watch(isTraitOrderDirty, (isDirty) => emit('orderDirtyChange', isDirty), { immediate: true })

const {
  draggingIndex: draggingTraitIndex,
  onDragStart: onTraitDragStart,
  onDragOver: onTraitDragOver,
  onDrop: onTraitDrop,
  onDragEnd: onTraitDragEnd,
} = useRowDragAndDrop(moveTraitInDraft)

const {
  registerHandleCell: registerTraitHandleCell,
  moveRow: moveTraitRow,
  lastMove: traitLastMove,
} = useOrderHandleFocus(moveTraitInDraft, () => orderedTraitRows.value.length)

const orderedTraitRows = computed(() => orderRowsByDraft(traitRows.value, traitOrderDraft.value))

const traitTranslationOptions = computed(() => [
  { value: '', label: t('admin.quality.translationOfNone') },
  ...translationGroupOptions(
    traits.value,
    traitForm.locale,
    traitForm.translationGroup,
    editingTraitId.value,
    (trait) => `${trait.locale.toUpperCase()} · ${trait.label}`,
  ),
])

function resetTraitForm(): void {
  renewTraitForm()
  editingTraitId.value = null
  traitProposalSourceLocale.value = null
  traitSourceGroup.value = null
  traitForm.locale = SUPPORTED_LOCALES[0]
  traitForm.translationGroup = ''
  traitForm.label = ''
}

function startEditTrait(trait: AdminQualityTrait): void {
  renewTraitForm()
  editingTraitId.value = trait.id
  traitProposalSourceLocale.value = null
  traitSourceGroup.value = trait.translationGroup
  traitForm.locale = trait.locale
  traitForm.translationGroup = hasSibling(traits.value, trait) ? trait.translationGroup : ''
  traitForm.label = trait.label
}

/** Même geste pour un trait, qui n'a aucun champ non-prose à recopier. */
function startCreateTraitVersion(row: TranslationGroupRow<AdminQualityTrait>, locale: Locale): void {
  renewTraitForm()
  editingTraitId.value = null
  traitProposalSourceLocale.value = null
  traitSourceGroup.value = row.key
  traitForm.locale = locale
  traitForm.translationGroup = row.key
  traitForm.label = ''
}

async function handleSubmitTrait(): Promise<void> {
  isSubmittingTrait.value = true

  const input = {
    locale: traitForm.locale,
    translationGroup: '' === traitForm.translationGroup ? null : traitForm.translationGroup,
    label: traitForm.label,
  }

  const succeeded = null !== editingTraitId.value ? await updateTrait(editingTraitId.value, input) : await createTrait(input)

  isSubmittingTrait.value = false

  if (succeeded) {
    resetTraitForm()
  }
}

async function handleTranslateTrait(targetLocale: Locale): Promise<void> {
  await proposeTraitFrom(targetLocale, (sourceLocale) => {
    editingTraitId.value = null
    traitForm.locale = targetLocale
    traitForm.translationGroup = traitSourceGroup.value ?? ''
    traitProposalSourceLocale.value = sourceLocale
  })
}

async function handleDeleteTrait(trait: AdminQualityTrait): Promise<void> {
  if (!window.confirm(t('admin.quality.trait.confirmDelete', { label: trait.label }))) {
    return
  }

  // La suppression ne change rien au formulaire : le composable périme la traduction de l'entrée supprimée ou de sa source.
  await removeTraitEntry(trait, () => removeTrait(trait.id))
}
</script>

<template>
  <div class="surface-panel p-3 p-sm-4">
    <h2 class="h6 fw-bold text-white mb-3">
      {{ isEditingTrait ? t('admin.quality.trait.edit') : t('admin.quality.trait.create') }}
    </h2>

    <form
      novalidate
      @submit.prevent="handleSubmitTrait"
    >
      <!-- Verrou d'édition et de proposition : voir `useEntryFormLock` (#748). -->
      <BaseSelect
        id="admin-quality-trait-locale"
        v-model="traitForm.locale"
        :disabled="traitLock.isEntryLocked"
        :aria-describedby="traitLock.localeDescribedBy"
        :label="t('admin.localeLabel')"
        :options="localeOptions"
      />
      <p
        id="admin-quality-trait-locale-help"
        class="form-text mb-3"
      >
        {{ t('admin.quality.localeHelp') }}
      </p>
      <EntryFormLockHint
        :id="traitLock.hintId"
        :state="traitLock.state"
      />
      <BaseSelect
        id="admin-quality-trait-translation-group"
        v-model="traitForm.translationGroup"
        :disabled="traitLock.isProposalLocked"
        :aria-describedby="traitLock.proposalDescribedBy"
        :label="t('admin.quality.translationOfLabel')"
        :options="traitTranslationOptions"
      />
      <BaseTextInput
        id="admin-quality-trait-label"
        v-model="traitForm.label"
        v-bind="traitProseFieldAttrs()"
        :label="t('admin.quality.trait.labelLabel')"
        required
      />

      <div class="mb-3">
        <TranslateEntryButton
          :form-locale="traitForm.locale"
          :is-translating="isTranslatingTrait"
          :is-expired-call-pending="isExpiredCallPendingTrait"
          :hint-id="traitTranslationHintId"
          :disabled="isTraitTranslationRefused"
          :aria-describedby="describedBy(lockedHintId, traitLock.proposalDescribedBy)"
          @translate="handleTranslateTrait"
        />
      </div>

      <TranslationReadonlyHint
        :id="traitProseReadonlyHintId"
        :shown="isTranslatingTrait"
      />

      <p
        v-if="traitProposalSourceLocale"
        class="alert alert-info small"
        role="status"
      >
        {{ t('admin.translation.proposalNotice', { locale: traitProposalSourceLocale.toUpperCase() }) }}
      </p>

      <p
        v-if="traitTranslationErrorText"
        class="text-danger-emphasis small"
        role="alert"
      >
        {{ traitTranslationErrorText }}
      </p>

      <p
        v-if="traitErrorText"
        class="text-danger-emphasis small"
        role="alert"
      >
        {{ traitErrorText }}
      </p>

      <div class="d-flex gap-2">
        <button
          type="submit"
          class="btn btn-gradient"
          :disabled="isSubmittingTrait || isLocked"
          :aria-describedby="lockedHintId"
        >
          {{ t('admin.quality.save') }}
        </button>
        <button
          v-if="traitLock.isEntryLocked"
          type="button"
          class="btn btn-outline-light"
          @click="resetTraitForm"
        >
          {{ t('admin.quality.cancel') }}
        </button>
      </div>
    </form>

    <hr class="border-secondary my-4">

    <h3 class="h6 fw-bold text-white mb-3">
      {{ t('admin.quality.trait.listTitle') }}
    </h3>

    <p
      v-if="isLoadingTraits"
      class="text-body-secondary mb-0"
    >
      {{ t('admin.quality.loading') }}
    </p>
    <p
      v-else-if="hasTraitsError"
      class="text-danger-emphasis mb-0"
      role="alert"
    >
      {{ t('admin.quality.loadError') }}
    </p>
    <p
      v-else-if="!isTraitsLoaded"
      class="text-body-secondary mb-0"
    >
      {{ t('admin.notLoaded') }}
    </p>
    <p
      v-else-if="0 === orderedTraitRows.length"
      class="text-body-secondary mb-0"
    >
      {{ t('admin.quality.trait.empty') }}
    </p>
    <template v-else>
      <OrderToolbar
        class="mb-3"
        :is-dirty="isTraitOrderDirty"
        :is-saving="isSavingTraitOrder"
        :error-reason="traitOrderErrorReason"
        :last-move="traitLastMove"
        @save="saveTraitOrder"
        @cancel="resetTraitOrder"
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
                class="col-md-2 text-end"
              >
                <span class="visually-hidden">{{ t('admin.order.actionsColumn') }}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="(row, index) in orderedTraitRows"
              :key="row.key"
              draggable="true"
              :class="{ 'opacity-50': index === draggingTraitIndex }"
              @dragstart="onTraitDragStart(index)"
              @dragover="onTraitDragOver"
              @drop="onTraitDrop(index)"
              @dragend="onTraitDragEnd"
            >
              <td
                :ref="registerTraitHandleCell"
                :data-order-key="row.key"
              >
                <OrderHandle
                  :index="index"
                  :count="orderedTraitRows.length"
                  :label="firstEntry(row, SUPPORTED_LOCALES)?.label ?? ''"
                  @move="(from, to) => moveTraitRow(row.key, from, to)"
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
                    <span class="text-white admin-locale-line__text">{{ line.entry.label }}</span>
                  </template>
                  <template v-else>
                    <span class="text-body-secondary admin-locale-line__text">{{ t('admin.order.missingTranslation') }}</span>
                  </template>
                </div>
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
                      @click="startEditTrait(line.entry)"
                    >
                      {{ t('admin.quality.trait.editAction') }}
                    </button>
                    <button
                      type="button"
                      class="btn btn-sm btn-outline-danger"
                      :disabled="isLocked"
                      :aria-describedby="lockedHintId"
                      @click="handleDeleteTrait(line.entry)"
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
                      @click="startCreateTraitVersion(row, line.locale)"
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
