<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useUnsavedOrderGuard } from '../../../application/admin/shared/useUnsavedOrderGuard'
import { useAdminContributions } from '../../../application/admin/contributions/useAdminContributions'
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
import { CONTRIBUTION_PROSE_FIELDS } from './proseFields'
import OrderHandle from '../../ui/admin/OrderHandle.vue'
import OrderToolbar from '../../ui/admin/OrderToolbar.vue'
import { LOCALE_NATIVE_NAMES, SUPPORTED_LOCALES, type Locale } from '../../../domain/portfolio/entities/Locale'
import type { AdminContribution } from '../../../domain/admin/contributions/entities/AdminContribution'

const { t } = useI18n()
const { contributions, isLoading, hasError, isLoaded, errorMessage, load, create, update, remove, reorder } =
  useAdminContributions()

const editingId = ref<string | null>(null)

/**
 * Typé explicitement : sans annotation, `reactive` infère `locale` au type
 * littéral de sa valeur initiale et le sélecteur de langue ne compile plus.
 *
 * `translationGroup` est la valeur du champ « Version de » : `''` pour
 * « aucune ». Le champ numérique `Position` a disparu (spec 0004, D3) — la
 * position ne se saisit plus, seul `PUT …/order` l'écrit.
 */
interface ContributionForm {
  locale: Locale
  translationGroup: string
  title: string
  project: string
  reference: string
  url: string
  summary: string
  body: string
}

const form = reactive<ContributionForm>({
  locale: SUPPORTED_LOCALES[0],
  translationGroup: '',
  title: '',
  project: '',
  reference: '',
  url: '',
  summary: '',
  body: '',
})
const isSubmitting = ref(false)

/**
 * Groupe de l'entrée dont le formulaire est issu (édition, ou « Créer la
 * version XX »), `null` sur une page blanche. Distinct de
 * `form.translationGroup`, qui est la valeur *choisie* dans le sélecteur :
 * l'assistant de traduction bascule le formulaire vers l'autre locale et doit
 * y rattacher la proposition au groupe de la **source** (D9), y compris quand
 * ce groupe n'avait aucune traduction et que le sélecteur affichait donc
 * « aucune ».
 */
const sourceGroup = ref<string | null>(null)

/**
 * Locale de l'entrée dont le formulaire courant est une proposition traduite,
 * `null` sinon : porte la bannière « proposition générée par IA ». Effacée dès que
 * le formulaire repart d'une entrée réelle ou d'une page blanche.
 */
const proposalSourceLocale = ref<Locale | null>(null)

const isEditing = computed(() => null !== editingId.value)
const lock = useEntryFormLock({
  hintId: 'admin-contribution-entry-lock-help',
  isEditing,
  proposalSourceLocale,
})

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
  hintIdPrefix: 'admin-contribution',
  form: () => form,
  proseFields: CONTRIBUTION_PROSE_FIELDS,
  isRefused: () => isSubmitting.value || isOrderDirty.value || lock.isProposalLocked,
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

  return reason ? t(`admin.contributions.errors.${reason}`) : null
})

const localeOptions = computed(() => SUPPORTED_LOCALES.map((locale) => ({ value: locale, label: locale.toUpperCase() })))

/** Une ligne de tableau par groupe de traduction (D8), toutes langues confondues. */
const rows = computed(() => groupByTranslationGroup(contributions.value, SUPPORTED_LOCALES))

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

const { draggingIndex, onDragStart, onDragOver, onDrop, onDragEnd } = useRowDragAndDrop(moveInDraft)

const orderedRows = computed(() => orderRowsByDraft(rows.value, orderDraft.value))

/**
 * Tant que l'ordre est modifié, toute mutation est verrouillée (D6) : elle
 * rechargerait la liste et perdrait le brouillon sans prévenir.
 *
 * L'aide est **rendue visible** sous la barre d'ordre, jamais portée par un
 * `title` : Bootstrap pose `pointer-events: none` sur `.btn:disabled`, donc
 * l'infobulle d'un bouton désactivé ne s'affiche jamais au survol. Les boutons
 * la désignent par `aria-describedby` — et seulement quand elle existe, sinon
 * la référence pendante serait elle-même une erreur d'accessibilité.
 */
const LOCKED_HINT_ID = 'admin-order-locked-hint'

const lockedHintId = computed(() => (isOrderDirty.value ? LOCKED_HINT_ID : undefined))

const { registerHandleCell, moveRow, lastMove } = useOrderHandleFocus(moveInDraft, () => orderedRows.value.length)

/**
 * « Version de » : les options du sélecteur (D2, cf.
 * `domain/admin/shared/ordering/translationGroupSelection.ts` pour la règle
 * exacte). L'option « aucune » est préfixée ici, son libellé étant un texte
 * traduit — le module partagé reste framework-free.
 */
const translationOptions = computed(() => [
  { value: '', label: t('admin.contributions.translationOfNone') },
  ...translationGroupOptions(
    contributions.value,
    form.locale,
    form.translationGroup,
    editingId.value,
    (contribution) => `${contribution.locale.toUpperCase()} · ${contribution.title}`,
  ),
])

function resetForm(): void {
  renewForm()
  editingId.value = null
  proposalSourceLocale.value = null
  sourceGroup.value = null
  form.locale = SUPPORTED_LOCALES[0]
  form.translationGroup = ''
  form.title = ''
  form.project = ''
  form.reference = ''
  form.url = ''
  form.summary = ''
  form.body = ''
}

function startEdit(contribution: AdminContribution): void {
  renewForm()
  editingId.value = contribution.id
  proposalSourceLocale.value = null
  sourceGroup.value = contribution.translationGroup
  form.locale = contribution.locale as Locale
  // Le groupe lu est repris tel quel dès qu'il porte une traduction : le
  // formulaire le renvoie alors à l'enregistrement, et le lien FR/EN survit à
  // l'édition. Un groupe solitaire n'a rien à détacher : `ContentPlacement::detach`
  // traite une entrée seule en non-geste (`count($members) === 1`), le groupe
  // est conservé. Le sélecteur affiche donc « aucune » sans conséquence.
  form.translationGroup = hasSibling(contributions.value, contribution) ? contribution.translationGroup : ''
  form.title = contribution.title
  form.project = contribution.project
  form.reference = contribution.reference
  form.url = contribution.url
  form.summary = contribution.summary
  form.body = contribution.body
}

/**
 * « Créer la version XX » : formulaire en création, déjà rattaché au groupe,
 * dans la langue manquante, avec les champs non-prose recopiés de l'entrée
 * existante (cf. CONTRIBUTION_PROSE_FIELDS) et la prose vide — il n'y a rien à traduire
 * puisqu'il n'y a rien à écrire encore.
 */
function startCreateVersion(row: TranslationGroupRow<AdminContribution>, locale: Locale): void {
  renewForm()
  const existing = firstEntry(row, SUPPORTED_LOCALES)

  editingId.value = null
  proposalSourceLocale.value = null
  sourceGroup.value = row.key
  form.locale = locale
  form.translationGroup = row.key
  form.project = existing?.project ?? ''
  form.reference = existing?.reference ?? ''
  form.url = existing?.url ?? ''
  form.title = ''
  form.summary = ''
  form.body = ''
}

async function handleSubmit(): Promise<void> {
  isSubmitting.value = true

  const input = {
    locale: form.locale,
    // D3 : aucun `position` n'est jamais envoyé. `null` = contenu neuf à la
    // création, détachement sur une mise à jour.
    translationGroup: '' === form.translationGroup ? null : form.translationGroup,
    title: form.title,
    project: form.project,
    reference: form.reference,
    url: form.url,
    summary: form.summary,
    body: form.body,
  }

  const succeeded = null !== editingId.value ? await update(editingId.value, input) : await create(input)

  isSubmitting.value = false

  if (succeeded) {
    resetForm()
  }
}

/**
 * Ce que la page seule pose quand la proposition arrive (`proposeFrom`) : l'entrée éditée vidée, la langue cible et le groupe
 * de l'entrée source, pour que l'enregistrement rattache la version proposée sans geste supplémentaire (spec 0004, D9).
 */
async function handleTranslate(targetLocale: Locale): Promise<void> {
  await proposeFrom(targetLocale, (sourceLocale) => {
    editingId.value = null
    form.locale = targetLocale
    form.translationGroup = sourceGroup.value ?? ''
    proposalSourceLocale.value = sourceLocale
  })
}

async function handleDelete(contribution: AdminContribution): Promise<void> {
  if (!window.confirm(t('admin.contributions.confirmDelete', { title: contribution.title }))) {
    return
  }

  // La suppression ne change rien au formulaire : le composable périme la traduction de l'entrée supprimée ou de sa source.
  await removeEntry(contribution, () => remove(contribution.id))
}

useUnsavedOrderGuard(isOrderDirty)
</script>

<template>
  <div class="d-flex flex-column gap-4">
    <div class="surface-panel p-3 p-sm-4">
      <h2 class="h6 fw-bold text-white mb-3">
        {{ isEditing ? t('admin.contributions.edit') : t('admin.contributions.create') }}
      </h2>

      <form
        novalidate
        @submit.prevent="handleSubmit"
      >
        <!-- Verrou d'édition et de proposition : voir `useEntryFormLock` (#748). -->
        <BaseSelect
          id="admin-contribution-locale"
          v-model="form.locale"
          :disabled="lock.isEntryLocked"
          :aria-describedby="lock.localeDescribedBy"
          :label="t('admin.contributions.localeLabel')"
          :options="localeOptions"
        />
        <EntryFormLockHint
          :id="lock.hintId"
          :state="lock.state"
        />
        <BaseSelect
          id="admin-contribution-translation-group"
          v-model="form.translationGroup"
          :disabled="lock.isProposalLocked"
          :aria-describedby="lock.proposalDescribedBy"
          :label="t('admin.contributions.translationOfLabel')"
          :options="translationOptions"
        />
        <BaseTextInput
          id="admin-contribution-title"
          v-model="form.title"
          v-bind="proseFieldAttrs()"
          :label="t('admin.contributions.titleLabel')"
          required
        />
        <BaseTextInput
          id="admin-contribution-project"
          v-model="form.project"
          :label="t('admin.contributions.projectLabel')"
          required
        />
        <BaseTextInput
          id="admin-contribution-reference"
          v-model="form.reference"
          :label="t('admin.contributions.referenceLabel')"
          required
        />
        <BaseTextInput
          id="admin-contribution-url"
          v-model="form.url"
          :label="t('admin.contributions.urlLabel')"
          required
        />
        <BaseTextarea
          id="admin-contribution-summary"
          v-model="form.summary"
          v-bind="proseFieldAttrs()"
          :label="t('admin.contributions.summaryLabel')"
          :rows="3"
          required
        />
        <BaseTextarea
          id="admin-contribution-body"
          v-model="form.body"
          v-bind="proseFieldAttrs('admin-contribution-body-help')"
          :label="t('admin.contributions.bodyLabel')"
          :rows="14"
          required
        />
        <div
          id="admin-contribution-body-help"
          class="form-text mb-3"
        >
          {{ t('admin.contributions.bodyHelp') }}
        </div>

        <!--
          Verrouillé lui aussi tant que l'ordre est modifié : l'appel au modèle
          produirait une proposition que le formulaire, verrouillé, ne pourrait pas
          enregistrer — du quota dépensé pour rien (ADR 0004, coût borné).
        -->
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
            :disabled="isSubmitting || isOrderDirty"
            :aria-describedby="lockedHintId"
          >
            {{ t('admin.contributions.save') }}
          </button>
          <button
            v-if="lock.isEntryLocked"
            type="button"
            class="btn btn-outline-light"
            @click="resetForm"
          >
            {{ t('admin.contributions.cancel') }}
          </button>
        </div>
      </form>
    </div>

    <div class="surface-panel p-3 p-sm-4">
      <h2 class="h6 fw-bold text-white mb-3">
        {{ t('admin.contributions.listTitle') }}
      </h2>

      <p
        v-if="isLoading"
        class="text-body-secondary mb-0"
      >
        {{ t('admin.contributions.loading') }}
      </p>
      <p
        v-else-if="hasError"
        class="text-danger-emphasis mb-0"
        role="alert"
      >
        {{ t('admin.contributions.loadError') }}
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
        {{ t('admin.contributions.empty') }}
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

        <p
          v-if="isOrderDirty"
          :id="LOCKED_HINT_ID"
          class="form-text mb-3"
        >
          {{ t('admin.order.lockedHint') }}
        </p>

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
                  {{ t('admin.contributions.contentLabel') }}
                </th>
                <th
                  scope="col"
                  class="col-md-1"
                >
                  {{ t('admin.contributions.projectLabel') }}
                </th>
                <th
                  scope="col"
                  class="col-md-1"
                >
                  {{ t('admin.contributions.referenceLabel') }}
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
                    :label="firstEntry(row, SUPPORTED_LOCALES)?.title ?? ''"
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
                      <span class="text-white admin-locale-line__text">{{ line.entry.title }}</span>
                    </template>
                    <template v-else>
                      <span class="text-body-secondary admin-locale-line__text">{{ t('admin.order.missingTranslation') }}</span>
                    </template>
                  </div>
                </td>
                <td class="text-nowrap">
                  {{ firstEntry(row, SUPPORTED_LOCALES)?.project }}
                </td>
                <td class="text-nowrap">
                  {{ firstEntry(row, SUPPORTED_LOCALES)?.reference }}
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
                        :disabled="isOrderDirty"
                        :aria-describedby="lockedHintId"
                        @click="startEdit(line.entry)"
                      >
                        {{ t('admin.contributions.editAction') }}
                      </button>
                      <button
                        type="button"
                        class="btn btn-sm btn-outline-danger"
                        :disabled="isOrderDirty"
                        :aria-describedby="lockedHintId"
                        @click="handleDelete(line.entry)"
                      >
                        {{ t('admin.contributions.deleteAction') }}
                      </button>
                    </template>
                    <template v-else>
                      <button
                        type="button"
                        class="btn btn-sm btn-outline-light"
                        :disabled="isOrderDirty"
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
  </div>
</template>
