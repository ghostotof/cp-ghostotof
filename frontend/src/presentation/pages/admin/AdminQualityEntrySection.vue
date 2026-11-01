<script setup lang="ts" generic="F extends string, E extends QualityEntry<F>">
import { computed, reactive, watch } from 'vue'
import { useI18n } from 'vue-i18n'
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
import { useEntryFormState } from '../../ui/admin/entryFormState'
import { switchToProposedCreation } from '../../ui/admin/proposedCreation'
import OrderHandle from '../../ui/admin/OrderHandle.vue'
import OrderToolbar from '../../ui/admin/OrderToolbar.vue'
import { LOCALE_NATIVE_NAMES, SUPPORTED_LOCALES, type Locale } from '../../../domain/portfolio/entities/Locale'
import {
  fieldsFrom,
  requireEveryField,
  type EntryForm,
  type QualityEntry,
  type QualityEntryField,
  type QualityEntryInput,
  type QualityEntrySource,
  type QualityEntryTexts,
} from './qualityEntrySection'

/**
 * Un contenu de qualité ordonné — les principes, ou les traits — : un formulaire, un tableau groupé par groupe de traduction
 * (toutes les langues, spec 0004 D8) et son brouillon d'ordre. Une moitié de `AdminQualityPage`, instanciée deux fois ; elle
 * ne diffère d'une instance à l'autre que par ses champs (`fields`, `qualityEntrySection.ts`), ses textes (`texts`, traduits
 * par la page) et sa lecture (`source`) : aucune branche sur `kind`, qui ne sert qu'aux identifiants `admin-quality-<kind>-*`.
 *
 * **`source` est une interface, `QualityEntrySource`, et non les deux composables** : la section dépend de ce qu'elle lit (la
 * liste, l'état de chargement, les écritures) et non de `useAdminQualityPrinciples` ni de `useAdminQualityTraits`, ni de leur
 * injection ; un test lui passe une fausse source. La page ne lui renomme que la liste (`principles`, `traits` : `entries`).
 *
 * **La langue est un champ de formulaire, pas un état de page**
 * (`form.locale`) : c'est la langue de l'entrée en cours d'édition ou
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
 * brouillon (`isOrderDirty`) tout de suite et celui de l'autre section
 * par `isLocked`, à jour après le rendu de la page : la limite est écrite une fois,
 * `.claude/rules/frontend-backoffice.md`, « The refusal is written once ».
 *
 * **Ce que la section lit une fois, à sa construction** : `source` (le composable ne change pas de main), `fields` (une
 * constante, `qualityEntrySection.ts`) et `kind`. Les libellés (`labels`), qui suivent la langue de l'interface, sont lus à
 * chaque rendu.
 */

const props = defineProps<{
  /** `principle` ou `trait` : le seul usage est l'identifiant des éléments, `admin-quality-<kind>-*`. */
  kind: 'principle' | 'trait'
  source: QualityEntrySource<E, QualityEntryInput<F>>
  /** Les champs du formulaire, dans l'ordre où le gabarit les écrit : une constante de `qualityEntrySection.ts`. */
  fields: readonly QualityEntryField<F>[]
  /** Le libellé traduit de chaque champ, un par nom de `F` (le compilateur refuse un libellé manquant ou en trop). */
  labels: Readonly<Record<F, string>>
  /** Le champ qui nomme l'entrée : sa ligne dans le tableau, « Version de », la question de la suppression. */
  titleField: F
  texts: QualityEntryTexts
  isLocked: boolean
  lockedHintId?: string
}>()
const emit = defineEmits<{ orderDirtyChange: [isDirty: boolean] }>()

const { t } = useI18n()

const {
  entries,
  isLoading,
  hasError,
  isLoaded,
  errorMessage,
  load,
  create,
  update,
  remove,
  reorder,
} = props.source

const idPrefix = `admin-quality-${props.kind}`

/** `iconKey` devient `icon-key` : l'identifiant d'un champ est `<idPrefix>-<nom en kebab>`. */
function fieldId(name: string): string {
  return `${idPrefix}-${name.replace(/[A-Z]/g, (letter) => `-${letter.toLowerCase()}`)}`
}

/** Les champs de prose : ce que l'assistant envoie et remplace, et ce qui devient lisible seulement pendant une traduction. */
const proseFields = props.fields.filter((field) => field.prose).map((field) => field.name)

/** Les noms des champs, dans l'ordre de `fields` : le formulaire et le corps d'écriture en sont construits (`fieldsFrom`). */
const fieldNames = props.fields.map((field) => field.name)

const CONTROLS = { text: BaseTextInput, textarea: BaseTextarea } as const

const localeOptions = SUPPORTED_LOCALES.map((locale) => ({ value: locale, label: LOCALE_NATIVE_NAMES[locale] }))

// `EntryForm<string>` et non `EntryForm<F>` : `reactive` d'un type indexé par le `F` générique fait boucler le compilateur (mémoire
// épuisée, mesuré) et le gabarit ne lit plus un objet dont le type dépend de `F` ; le composable reçoit le `Record<F, string>` plus bas.
const form = reactive<EntryForm<string>>({
  locale: SUPPORTED_LOCALES[0],
  translationGroup: '',
  ...fieldsFrom(fieldNames, () => ''),
})

// L'état commun des formulaires par entrée (`useEntryFormState`) ; `formState` entier va à la bascule de proposition.
const formState = useEntryFormState()
const { editingId, isSubmitting, sourceGroup, proposalSourceLocale } = formState

const isEditing = computed(() => null !== editingId.value)
const lock = useEntryFormLock({
  hintId: `${idPrefix}-entry-lock-help`,
  isEditing,
  proposalSourceLocale,
  localeHelpId: `${idPrefix}-locale-help`,
})

/** Une ligne de tableau par groupe de traduction (D8), toutes langues confondues. */
const rows = computed(() => groupByTranslationGroup(entries.value, SUPPORTED_LOCALES))

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
  hintIdPrefix: idPrefix,
  // Un champ par nom de `fields` (construit de la liste, ci-dessus) : la garde le dit au compilateur, le formulaire reste le même objet.
  form: requireEveryField(form, fieldNames),
  proseFields,
  // Son brouillon d'ordre est lu tout de suite, celui de l'autre section par `props.isLocked` : voir le docblock de la section.
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

/** Les champs dont le tableau fait une colonne, dans l'ordre de `fields`. */
const columnFields = computed(() => props.fields.filter((field) => field.column))

/**
 * « Version de » : les options du sélecteur (D2, cf.
 * `domain/admin/shared/ordering/translationGroupSelection.ts` pour la règle
 * exacte). L'option « aucune » est préfixée ici, son libellé étant un texte
 * traduit — le module partagé reste framework-free.
 */
const translationOptions = computed(() => [
  { value: '', label: t('admin.quality.translationOfNone') },
  ...translationGroupOptions(
    entries.value,
    form.locale,
    form.translationGroup,
    editingId.value,
    (entry) => `${entry.locale.toUpperCase()} · ${entry[props.titleField]}`,
  ),
])

function resetForm(): void {
  renewForm()
  editingId.value = null
  proposalSourceLocale.value = null
  sourceGroup.value = null
  form.locale = SUPPORTED_LOCALES[0]
  form.translationGroup = ''
  Object.assign(form, fieldsFrom(fieldNames, () => ''))
}

// Le groupe lu est repris tel quel dès qu'il porte une traduction : le
// formulaire le renvoie alors à l'enregistrement, et le lien FR/EN survit à
// l'édition. Un groupe solitaire n'a rien à détacher : `ContentPlacement::detach`
// traite une entrée seule en non-geste (`count($members) === 1`), le groupe
// est conservé. Le sélecteur affiche donc « aucune » sans conséquence.
function startEdit(entry: E): void {
  renewForm()
  editingId.value = entry.id
  proposalSourceLocale.value = null
  sourceGroup.value = entry.translationGroup
  form.locale = entry.locale
  form.translationGroup = hasSibling(entries.value, entry) ? entry.translationGroup : ''
  for (const field of props.fields) {
    form[field.name] = entry[field.name]
  }
}

/**
 * « Créer la version XX » : formulaire en création, déjà rattaché au groupe,
 * dans la langue manquante, celle du sélecteur de ce formulaire. Les champs `copiedToNewVersion` sont recopiés de l'entrée
 * existante (les champs non traduits : une clé d'icône) et la prose reste vide :
 * il n'y a rien à traduire puisqu'il n'y a rien d'écrit encore.
 */
function startCreateVersion(row: TranslationGroupRow<E>, locale: Locale): void {
  renewForm()
  const existing = firstEntry(row, SUPPORTED_LOCALES)

  editingId.value = null
  proposalSourceLocale.value = null
  sourceGroup.value = row.key
  form.locale = locale
  form.translationGroup = row.key
  for (const field of props.fields) {
    const copied: string = existing?.[field.name] ?? ''

    form[field.name] = field.copiedToNewVersion ? copied : ''
  }
}

async function handleSubmit(): Promise<void> {
  isSubmitting.value = true

  // Le corps d'écriture : la langue, le groupe et un champ par nom de `fields` (`fieldsFrom` : le compilateur ne peut pas
  // reconstruire un `QualityEntryInput<F>` d'une liste de noms lue à l'exécution, la garde de `fieldsFrom` le fait).
  const input: QualityEntryInput<F> = {
    locale: form.locale,
    // D3 : aucun `position` n'est jamais envoyé. `null` = contenu neuf à la
    // création, détachement sur une mise à jour.
    translationGroup: '' === form.translationGroup ? null : form.translationGroup,
    ...fieldsFrom(fieldNames, (name) => form[name]),
  }

  const succeeded =
    null !== editingId.value
      ? await update(editingId.value, input)
      : await create(input)

  isSubmitting.value = false

  if (succeeded) {
    resetForm()
  }
}

async function handleTranslate(targetLocale: Locale): Promise<void> {
  await proposeFrom(targetLocale, switchToProposedCreation(formState, form, targetLocale))
}

async function handleDelete(entry: E): Promise<void> {
  if (!window.confirm(props.texts.confirmDelete(entry[props.titleField]))) {
    return
  }

  // La suppression ne change rien au formulaire : le composable périme la traduction de l'entrée supprimée ou de sa source.
  await removeEntry(entry, () => remove(entry.id))
}
</script>

<template>
  <div class="surface-panel p-3 p-sm-4">
    <h2 class="h6 fw-bold text-white mb-3">
      {{ isEditing ? texts.edit : texts.create }}
    </h2>

    <form
      novalidate
      @submit.prevent="handleSubmit"
    >
      <!-- Verrou d'édition et de proposition : voir `useEntryFormLock` (#748). -->
      <BaseSelect
        :id="`${idPrefix}-locale`"
        v-model="form.locale"
        :disabled="lock.isEntryLocked"
        :aria-describedby="lock.localeDescribedBy"
        :label="t('admin.localeLabel')"
        :options="localeOptions"
      />
      <p
        :id="`${idPrefix}-locale-help`"
        class="form-text mb-3"
      >
        {{ t('admin.quality.localeHelp') }}
      </p>
      <EntryFormLockHint
        :id="lock.hintId"
        :state="lock.state"
      />
      <BaseSelect
        :id="`${idPrefix}-translation-group`"
        v-model="form.translationGroup"
        :disabled="lock.isProposalLocked"
        :aria-describedby="lock.proposalDescribedBy"
        :label="t('admin.quality.translationOfLabel')"
        :options="translationOptions"
      />
      <component
        :is="CONTROLS[field.control]"
        v-for="field in fields"
        :id="fieldId(field.name)"
        :key="field.name"
        v-model="form[field.name]"
        v-bind="field.prose ? proseFieldAttrs() : undefined"
        :label="labels[field.name]"
        required
      />

      <!--
        Verrouillé lui aussi tant qu'un ordre est modifié : l'appel au modèle
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
      {{ texts.listTitle }}
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
      {{ texts.empty }}
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
                v-for="field in columnFields"
                :key="field.name"
                scope="col"
                class="col-md-1"
              >
                {{ labels[field.name] }}
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
                  :label="firstEntry(row, SUPPORTED_LOCALES)?.[titleField] ?? ''"
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
                    <span class="text-white admin-locale-line__text">{{ line.entry[titleField] }}</span>
                  </template>
                  <template v-else>
                    <span class="text-body-secondary admin-locale-line__text">{{ t('admin.order.missingTranslation') }}</span>
                  </template>
                </div>
              </td>
              <td
                v-for="field in columnFields"
                :key="field.name"
                class="text-nowrap"
              >
                {{ firstEntry(row, SUPPORTED_LOCALES)?.[field.name] }}
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
                      {{ texts.editAction }}
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
