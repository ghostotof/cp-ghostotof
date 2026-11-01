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
import { switchToProposedCreation } from '../../ui/admin/proposedCreation'
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
 * (`form.locale`), l'assistant bascule **ce** formulaire vers la locale
 * cible en gardant le groupe de la source (D9), et le verrou d'ordre (D6) est
 * global à la page : `isLocked` reçu, `orderDirtyChange` annoncé, et le refus de traduire lit
 * son propre brouillon (`isOrderDirty`) tout de suite.
 */

const props = defineProps<{ isLocked: boolean; lockedHintId?: string }>()
const emit = defineEmits<{ orderDirtyChange: [isDirty: boolean] }>()

const { t } = useI18n()

const {
  traits,
  isLoading,
  hasError,
  isLoaded,
  errorMessage,
  load,
  create,
  update,
  remove,
  reorder,
} = useAdminQualityTraits()

const localeOptions = SUPPORTED_LOCALES.map((locale) => ({ value: locale, label: LOCALE_NATIVE_NAMES[locale] }))

interface TraitForm {
  locale: Locale
  translationGroup: string
  label: string
}

const form = reactive<TraitForm>({ locale: SUPPORTED_LOCALES[0], translationGroup: '', label: '' })

const editingId = ref<string | null>(null)
const isSubmitting = ref(false)

/** Groupe de l'entrée dont le formulaire est issu : voir `AdminQualityPrinciplesSection` (D9). */
const sourceGroup = ref<string | null>(null)

/** Locale de l'entrée dont le formulaire est une proposition traduite, `null` sinon. */
const proposalSourceLocale = ref<Locale | null>(null)

const isEditing = computed(() => null !== editingId.value)
const lock = useEntryFormLock({
  hintId: 'admin-quality-trait-entry-lock-help',
  isEditing,
  proposalSourceLocale,
  localeHelpId: 'admin-quality-trait-locale-help',
})

const rows = computed(() => groupByTranslationGroup(traits.value, SUPPORTED_LOCALES))

const {
  draft: orderDraft,
  isDirty: isOrderDirty,
  isSaving: isOrderSaving,
  errorReason: orderErrorReason,
  move: moveInDraft,
  reset: resetOrder,
  save: saveOrder,
} = useOrderDraft({
  serverKeys: () => rows.value.map((row) => row.key),
  reorder,
  reload: load,
})

/**
 * Une instance de composable de traduction par formulaire : chacun a son
 * attente et son message d'erreur.
 */
const {
  isTranslating,
  isExpiredCallPending,
  isRefused: isTranslationRefused,
  translationErrorText,
  proposeFrom,
  removeEntry,
  renewForm,
  proseReadonlyHintId,
  translationHintId,
  proseFieldAttrs,
} = useTranslatedForm({
  hintIdPrefix: 'admin-quality-trait',
  form,
  proseFields: QUALITY_TRAIT_PROSE_FIELDS,
  // Son brouillon d'ordre est lu tout de suite, celui de l'autre section par `props.isLocked` : la limite de cette
  // lecture est écrite une fois, `.claude/rules/frontend-backoffice.md`, « The refusal is written once ».
  isRefused: () => isSubmitting.value || isOrderDirty.value || props.isLocked || lock.isProposalLocked,
  // Ce qui fait l'identité du formulaire : une traduction dont il n'a plus cette identité est périmée. La prose n'en
  // fait pas partie : la saisie est empêchée pendant la traduction, et l'erreur d'un échec survit à la frappe.
  identity: () => ({
    entry: editingId.value,
    locale: form.locale,
    group: sourceGroup.value,
  }),
})

const errorText = computed(() => {
  const reason = errorMessage.value?.reason

  return reason ? t(`admin.quality.errors.${reason}`) : null
})

// L'ordre modifié de cette section est annoncé à la page, qui verrouille toutes les mutations ; `immediate` pour que
// l'état initial (à jour) soit posé sans attendre un premier déplacement.
watch(isOrderDirty, (isDirty) => emit('orderDirtyChange', isDirty), { immediate: true })

const {
  draggingIndex,
  onDragStart,
  onDragOver,
  onDrop,
  onDragEnd,
} = useRowDragAndDrop(moveInDraft)

const {
  registerHandleCell,
  moveRow,
  lastMove,
} = useOrderHandleFocus(moveInDraft, () => orderedRows.value.length)

const orderedRows = computed(() => orderRowsByDraft(rows.value, orderDraft.value))

const translationOptions = computed(() => [
  { value: '', label: t('admin.quality.translationOfNone') },
  ...translationGroupOptions(
    traits.value,
    form.locale,
    form.translationGroup,
    editingId.value,
    (trait) => `${trait.locale.toUpperCase()} · ${trait.label}`,
  ),
])

function resetForm(): void {
  renewForm()
  editingId.value = null
  proposalSourceLocale.value = null
  sourceGroup.value = null
  form.locale = SUPPORTED_LOCALES[0]
  form.translationGroup = ''
  form.label = ''
}

function startEdit(trait: AdminQualityTrait): void {
  renewForm()
  editingId.value = trait.id
  proposalSourceLocale.value = null
  sourceGroup.value = trait.translationGroup
  form.locale = trait.locale
  form.translationGroup = hasSibling(traits.value, trait) ? trait.translationGroup : ''
  form.label = trait.label
}

/** Même geste pour un trait, qui n'a aucun champ non-prose à recopier. */
function startCreateVersion(row: TranslationGroupRow<AdminQualityTrait>, locale: Locale): void {
  renewForm()
  editingId.value = null
  proposalSourceLocale.value = null
  sourceGroup.value = row.key
  form.locale = locale
  form.translationGroup = row.key
  form.label = ''
}

async function handleSubmit(): Promise<void> {
  isSubmitting.value = true

  const input = {
    locale: form.locale,
    translationGroup: '' === form.translationGroup ? null : form.translationGroup,
    label: form.label,
  }

  const succeeded = null !== editingId.value ? await update(editingId.value, input) : await create(input)

  isSubmitting.value = false

  if (succeeded) {
    resetForm()
  }
}

async function handleTranslate(targetLocale: Locale): Promise<void> {
  await proposeFrom(targetLocale, switchToProposedCreation({ editingId, form, sourceGroup, proposalSourceLocale }, targetLocale))
}

async function handleDelete(trait: AdminQualityTrait): Promise<void> {
  if (!window.confirm(t('admin.quality.trait.confirmDelete', { label: trait.label }))) {
    return
  }

  // La suppression ne change rien au formulaire : le composable périme la traduction de l'entrée supprimée ou de sa source.
  await removeEntry(trait, () => remove(trait.id))
}
</script>

<template>
  <div class="surface-panel p-3 p-sm-4">
    <h2 class="h6 fw-bold text-white mb-3">
      {{ isEditing ? t('admin.quality.trait.edit') : t('admin.quality.trait.create') }}
    </h2>

    <form
      novalidate
      @submit.prevent="handleSubmit"
    >
      <!-- Verrou d'édition et de proposition : voir `useEntryFormLock` (#748). -->
      <BaseSelect
        id="admin-quality-trait-locale"
        v-model="form.locale"
        :disabled="lock.isEntryLocked"
        :aria-describedby="lock.localeDescribedBy"
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
        :id="lock.hintId"
        :state="lock.state"
      />
      <BaseSelect
        id="admin-quality-trait-translation-group"
        v-model="form.translationGroup"
        :disabled="lock.isProposalLocked"
        :aria-describedby="lock.proposalDescribedBy"
        :label="t('admin.quality.translationOfLabel')"
        :options="translationOptions"
      />
      <BaseTextInput
        id="admin-quality-trait-label"
        v-model="form.label"
        v-bind="proseFieldAttrs()"
        :label="t('admin.quality.trait.labelLabel')"
        required
      />

      <div class="mb-3">
        <TranslateEntryButton
          :form-locale="form.locale"
          :is-translating="isTranslating"
          :is-expired-call-pending="isExpiredCallPending"
          :hint-id="translationHintId"
          :disabled="isTranslationRefused"
          :aria-describedby="describedBy(lockedHintId, lock.proposalDescribedBy)"
          @translate="handleTranslate"
        />
      </div>

      <TranslationReadonlyHint
        :id="proseReadonlyHintId"
        :shown="isTranslating"
      />

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

      <div class="d-flex gap-2">
        <button
          type="submit"
          class="btn btn-gradient"
          :disabled="isSubmitting || isLocked"
          :aria-describedby="lockedHintId"
        >
          {{ t('admin.quality.save') }}
        </button>
        <button
          v-if="lock.isEntryLocked"
          type="button"
          class="btn btn-outline-light"
          @click="resetForm"
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
      v-if="isLoading"
      class="text-body-secondary mb-0"
    >
      {{ t('admin.quality.loading') }}
    </p>
    <p
      v-else-if="hasError"
      class="text-danger-emphasis mb-0"
      role="alert"
    >
      {{ t('admin.quality.loadError') }}
    </p>
    <p
      v-else-if="!isLoaded"
      class="text-body-secondary mb-0"
    >
      {{ t('admin.notLoaded') }}
    </p>
    <p
      v-else-if="0 === orderedRows.length"
      class="text-body-secondary mb-0"
    >
      {{ t('admin.quality.trait.empty') }}
    </p>
    <template v-else>
      <OrderToolbar
        class="mb-3"
        :is-dirty="isOrderDirty"
        :is-saving="isOrderSaving"
        :error-reason="orderErrorReason"
        :last-move="lastMove"
        @save="saveOrder"
        @cancel="resetOrder"
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
              v-for="(row, index) in orderedRows"
              :key="row.key"
              draggable="true"
              :class="{ 'opacity-50': index === draggingIndex }"
              @dragstart="onDragStart(index)"
              @dragover="onDragOver"
              @drop="onDrop(index)"
              @dragend="onDragEnd"
            >
              <td
                :ref="registerHandleCell"
                :data-order-key="row.key"
              >
                <OrderHandle
                  :index="index"
                  :count="orderedRows.length"
                  :label="firstEntry(row, SUPPORTED_LOCALES)?.label ?? ''"
                  @move="(from, to) => moveRow(row.key, from, to)"
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
                      @click="startEdit(line.entry)"
                    >
                      {{ t('admin.quality.trait.editAction') }}
                    </button>
                    <button
                      type="button"
                      class="btn btn-sm btn-outline-danger"
                      :disabled="isLocked"
                      :aria-describedby="lockedHintId"
                      @click="handleDelete(line.entry)"
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
                      @click="startCreateVersion(row, line.locale)"
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
