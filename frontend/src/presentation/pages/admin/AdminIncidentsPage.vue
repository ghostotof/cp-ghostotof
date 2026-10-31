<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useUnsavedOrderGuard } from '../../../application/admin/shared/useUnsavedOrderGuard'
import { useAdminIncidents } from '../../../application/admin/incidents/useAdminIncidents'
import { applyTranslationProposal, collectProseFields } from '../../../application/admin/translation/proseFields'
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
import BaseDateInput from '../../ui/BaseDateInput.vue'
import BaseSelect from '../../ui/BaseSelect.vue'
import TranslateEntryButton from '../../ui/admin/TranslateEntryButton.vue'
import TranslationReadonlyHint from '../../ui/admin/TranslationReadonlyHint.vue'
import EntryFormLockHint from '../../ui/admin/EntryFormLockHint.vue'
import { describedBy } from '../../ui/admin/describedBy'
import { useEntryFormLock } from '../../ui/admin/useEntryFormLock'
import { useTranslatedForm } from '../../ui/admin/useTranslatedForm'
import { INCIDENT_PROSE_FIELDS } from './proseFields'
import OrderHandle from '../../ui/admin/OrderHandle.vue'
import OrderToolbar from '../../ui/admin/OrderToolbar.vue'
import { LOCALE_NATIVE_NAMES, SUPPORTED_LOCALES, type Locale } from '../../../domain/portfolio/entities/Locale'
import type { AdminIncident } from '../../../domain/admin/incidents/entities/AdminIncident'

const { t } = useI18n()
const { incidents, isLoading, hasError, isLoaded, errorMessage, load, create, update, remove, reorder } = useAdminIncidents()
const {
  isTranslating,
  isDiscardPending,
  isRefused: isTranslationRefused,
  errorReason: translationErrorReason,
  translate,
  discardIfDeleted,
  renewForm,
  proseReadonlyHintId,
  translationHintId,
  proseFieldAttrs,
} = useTranslatedForm({
  hintIdPrefix: 'admin-incident',
  isRefused: () => !hasProseToTranslate.value || isSubmitting.value || isOrderDirty.value || lock.isProposalLocked,
  // Ce qui fait l'identité du formulaire : une traduction dont il n'a plus cette identité est périmée. La prose n'en
  // fait pas partie : la saisie est empêchée pendant la traduction, et l'erreur d'un échec survit à la frappe.
  identity: () => ({
    entry: editingId.value,
    locale: form.locale,
    group: sourceGroup.value,
  }),
})

const editingId = ref<string | null>(null)

/**
 * Typé explicitement : sans annotation, `reactive` infère `locale` au type
 * littéral de sa valeur initiale et le sélecteur de langue ne compile plus.
 *
 * `translationGroup` est la valeur du champ « Version de » : `''` pour
 * « aucune ». Le champ numérique `Position` a disparu (spec 0004, D3) — la
 * position ne se saisit plus, seul `PUT …/order` l'écrit.
 */
interface IncidentForm {
  locale: Locale
  translationGroup: string
  title: string
  version: string
  occurredAt: string
  impact: string
  rootCause: string
  resolution: string
  invariant: string
}

const form = reactive<IncidentForm>({
  locale: SUPPORTED_LOCALES[0],
  translationGroup: '',
  title: '',
  version: '',
  occurredAt: '',
  impact: '',
  rootCause: '',
  resolution: '',
  invariant: '',
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
  hintId: 'admin-incident-entry-lock-help',
  isEditing,
  proposalSourceLocale,
})

const hasProseToTranslate = computed(() => INCIDENT_PROSE_FIELDS.some((field) => '' !== form[field].trim()))

const translationErrorText = computed(() => {
  const reason = translationErrorReason.value

  return reason ? t(`admin.translation.errors.${reason}`) : null
})

const errorText = computed(() => {
  const reason = errorMessage.value?.reason

  return reason ? t(`admin.incidents.errors.${reason}`) : null
})

const localeOptions = computed(() => SUPPORTED_LOCALES.map((locale) => ({ value: locale, label: locale.toUpperCase() })))

/** Une ligne de tableau par groupe de traduction (D8), toutes langues confondues. */
const rows = computed(() => groupByTranslationGroup(incidents.value, SUPPORTED_LOCALES))

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
  { value: '', label: t('admin.incidents.translationOfNone') },
  ...translationGroupOptions(
    incidents.value,
    form.locale,
    form.translationGroup,
    editingId.value,
    (incident) => `${incident.locale.toUpperCase()} · ${incident.title}`,
  ),
])

function resetForm(): void {
  // Avant toute écriture : une remise à zéro change la génération du formulaire, que la traduction en vol lit (R139).
  renewForm()
  editingId.value = null
  proposalSourceLocale.value = null
  sourceGroup.value = null
  form.locale = SUPPORTED_LOCALES[0]
  form.translationGroup = ''
  form.title = ''
  form.version = ''
  form.occurredAt = ''
  form.impact = ''
  form.rootCause = ''
  form.resolution = ''
  form.invariant = ''
}

function startEdit(incident: AdminIncident): void {
  // Avant toute écriture : ce geste remplit ou vide le formulaire, il en avance la génération que la traduction en vol lit (R152).
  renewForm()
  editingId.value = incident.id
  proposalSourceLocale.value = null
  sourceGroup.value = incident.translationGroup
  form.locale = incident.locale as Locale
  // Le groupe lu est repris tel quel dès qu'il porte une traduction : le
  // formulaire le renvoie alors à l'enregistrement, et le lien FR/EN survit à
  // l'édition. Un groupe solitaire n'a rien à détacher : `ContentPlacement::detach`
  // traite une entrée seule en non-geste (`count($members) === 1`), le groupe
  // est conservé. Le sélecteur affiche donc « aucune » sans conséquence.
  form.translationGroup = hasSibling(incidents.value, incident) ? incident.translationGroup : ''
  form.title = incident.title
  form.version = incident.version
  form.occurredAt = incident.occurredAt
  form.impact = incident.impact
  form.rootCause = incident.rootCause
  form.resolution = incident.resolution
  form.invariant = incident.invariant
}

/**
 * « Créer la version XX » : formulaire en création, déjà rattaché au groupe,
 * dans la langue manquante, avec les champs non-prose recopiés de l'entrée
 * existante (cf. INCIDENT_PROSE_FIELDS) et la prose vide — il n'y a rien à traduire
 * puisqu'il n'y a rien à écrire encore.
 */
function startCreateVersion(row: TranslationGroupRow<AdminIncident>, locale: Locale): void {
  // Avant toute écriture : ce geste remplit ou vide le formulaire, il en avance la génération que la traduction en vol lit (R152).
  renewForm()
  const existing = firstEntry(row, SUPPORTED_LOCALES)

  editingId.value = null
  proposalSourceLocale.value = null
  sourceGroup.value = row.key
  form.locale = locale
  form.translationGroup = row.key
  form.version = existing?.version ?? ''
  form.occurredAt = existing?.occurredAt ?? ''
  form.title = ''
  form.impact = ''
  form.rootCause = ''
  form.resolution = ''
  form.invariant = ''
}

async function handleSubmit(): Promise<void> {
  isSubmitting.value = true

  const input = {
    locale: form.locale,
    // D3 : aucun `position` n'est jamais envoyé. `null` = contenu neuf à la
    // création, détachement sur une mise à jour.
    translationGroup: '' === form.translationGroup ? null : form.translationGroup,
    title: form.title,
    version: form.version,
    occurredAt: form.occurredAt,
    impact: form.impact,
    rootCause: form.rootCause,
    resolution: form.resolution,
    invariant: form.invariant,
  }

  const succeeded = null !== editingId.value ? await update(editingId.value, input) : await create(input)

  isSubmitting.value = false

  if (succeeded) {
    resetForm()
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
async function handleTranslate(targetLocale: Locale): Promise<void> {
  const sourceLocale = form.locale

  const proposal = await translate(sourceLocale, targetLocale, collectProseFields(form, INCIDENT_PROSE_FIELDS))
  if (!proposal) {
    return
  }

  editingId.value = null
  form.locale = targetLocale
  form.translationGroup = sourceGroup.value ?? ''
  applyTranslationProposal(form, INCIDENT_PROSE_FIELDS, proposal)
  proposalSourceLocale.value = sourceLocale
}

async function handleDelete(incident: AdminIncident): Promise<void> {
  if (!window.confirm(t('admin.incidents.confirmDelete', { title: incident.title }))) {
    return
  }

  // La suppression ne change rien au formulaire : le composable périme la traduction de l'entrée supprimée ou de sa source.
  discardIfDeleted(incident)

  await remove(incident.id)
}

useUnsavedOrderGuard(isOrderDirty)
</script>

<template>
  <div class="d-flex flex-column gap-4">
    <div class="surface-panel p-3 p-sm-4">
      <h2 class="h6 fw-bold text-white mb-3">
        {{ isEditing ? t('admin.incidents.edit') : t('admin.incidents.create') }}
      </h2>

      <form
        novalidate
        @submit.prevent="handleSubmit"
      >
        <!-- Verrou d'édition et de proposition : voir `useEntryFormLock` (#748). -->
        <BaseSelect
          id="admin-incident-locale"
          v-model="form.locale"
          :disabled="lock.isEntryLocked"
          :aria-describedby="lock.localeDescribedBy"
          :label="t('admin.incidents.localeLabel')"
          :options="localeOptions"
        />
        <EntryFormLockHint
          :id="lock.hintId"
          :state="lock.state"
        />
        <BaseSelect
          id="admin-incident-translation-group"
          v-model="form.translationGroup"
          :disabled="lock.isProposalLocked"
          :aria-describedby="lock.proposalDescribedBy"
          :label="t('admin.incidents.translationOfLabel')"
          :options="translationOptions"
        />
        <BaseTextInput
          id="admin-incident-title"
          v-model="form.title"
          v-bind="proseFieldAttrs()"
          :label="t('admin.incidents.titleLabel')"
          required
        />
        <BaseTextInput
          id="admin-incident-version"
          v-model="form.version"
          :label="t('admin.incidents.versionLabel')"
          required
        />
        <BaseDateInput
          id="admin-incident-occurred-at"
          v-model="form.occurredAt"
          :label="t('admin.incidents.occurredAtLabel')"
          required
        />
        <BaseTextarea
          id="admin-incident-impact"
          v-model="form.impact"
          v-bind="proseFieldAttrs()"
          :label="t('admin.incidents.impactLabel')"
          :rows="3"
          required
        />
        <BaseTextarea
          id="admin-incident-root-cause"
          v-model="form.rootCause"
          v-bind="proseFieldAttrs()"
          :label="t('admin.incidents.rootCauseLabel')"
          :rows="5"
          required
        />
        <BaseTextarea
          id="admin-incident-resolution"
          v-model="form.resolution"
          v-bind="proseFieldAttrs()"
          :label="t('admin.incidents.resolutionLabel')"
          :rows="3"
          required
        />
        <BaseTextarea
          id="admin-incident-invariant"
          v-model="form.invariant"
          v-bind="proseFieldAttrs('admin-incident-invariant-help')"
          :label="t('admin.incidents.invariantLabel')"
          :rows="4"
          required
        />
        <div
          id="admin-incident-invariant-help"
          class="form-text mb-3"
        >
          {{ t('admin.incidents.invariantHelp') }}
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
            :is-discard-pending="isDiscardPending"
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
            {{ t('admin.incidents.save') }}
          </button>
          <button
            v-if="lock.isEntryLocked"
            type="button"
            class="btn btn-outline-light"
            @click="resetForm"
          >
            {{ t('admin.incidents.cancel') }}
          </button>
        </div>
      </form>
    </div>

    <div class="surface-panel p-3 p-sm-4">
      <h2 class="h6 fw-bold text-white mb-3">
        {{ t('admin.incidents.listTitle') }}
      </h2>

      <p
        v-if="isLoading"
        class="text-body-secondary mb-0"
      >
        {{ t('admin.incidents.loading') }}
      </p>
      <p
        v-else-if="hasError"
        class="text-danger-emphasis mb-0"
        role="alert"
      >
        {{ t('admin.incidents.loadError') }}
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
        {{ t('admin.incidents.empty') }}
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
                  {{ t('admin.incidents.contentLabel') }}
                </th>
                <th
                  scope="col"
                  class="col-md-1"
                >
                  {{ t('admin.incidents.occurredAtLabel') }}
                </th>
                <th
                  scope="col"
                  class="col-md-1"
                >
                  {{ t('admin.incidents.versionLabel') }}
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
                  {{ firstEntry(row, SUPPORTED_LOCALES)?.occurredAt }}
                </td>
                <td class="text-nowrap">
                  {{ firstEntry(row, SUPPORTED_LOCALES)?.version }}
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
                        {{ t('admin.incidents.editAction') }}
                      </button>
                      <button
                        type="button"
                        class="btn btn-sm btn-outline-danger"
                        :disabled="isOrderDirty"
                        :aria-describedby="lockedHintId"
                        @click="handleDelete(line.entry)"
                      >
                        {{ t('admin.incidents.deleteAction') }}
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
