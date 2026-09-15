<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useUnsavedOrderGuard } from '../../../application/admin/shared/useUnsavedOrderGuard'
import { useAdminCaseStudies } from '../../../application/admin/caseStudies/useAdminCaseStudies'
import { useAdminTranslation } from '../../../application/admin/translation/useAdminTranslation'
import { applyTranslationDraft, collectProseFields } from '../../../application/admin/translation/proseFields'
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
import OrderHandle from '../../ui/admin/OrderHandle.vue'
import OrderToolbar from '../../ui/admin/OrderToolbar.vue'
import { LOCALE_NATIVE_NAMES, SUPPORTED_LOCALES, type Locale } from '../../../domain/portfolio/entities/Locale'
import type { AdminCaseStudy } from '../../../domain/admin/caseStudies/entities/AdminCaseStudy'

const { t } = useI18n()
const { caseStudies, isLoading, hasError, errorMessage, load, create, update, remove, reorder } =
  useAdminCaseStudies()
const { isTranslating, errorReason: translationErrorReason, translate } = useAdminTranslation()

const editingId = ref<string | null>(null)

/**
 * Typé explicitement : sans annotation, `reactive` infère `locale` au type
 * littéral de sa valeur initiale et le sélecteur de langue ne compile plus.
 *
 * `translationGroup` est la valeur du champ « Version de » : `''` pour
 * « aucune ». Le champ numérique `Position` a disparu (spec 0004, D3) — la
 * position ne se saisit plus, seul `PUT …/order` l'écrit.
 */
interface CaseStudyForm {
  locale: Locale
  translationGroup: string
  title: string
  problem: string
  solution: string
  tradeoffs: string
  measuredResult: string
}

const form = reactive<CaseStudyForm>({
  locale: SUPPORTED_LOCALES[0],
  translationGroup: '',
  title: '',
  problem: '',
  solution: '',
  tradeoffs: '',
  measuredResult: '',
})
const isSubmitting = ref(false)

/**
 * Groupe de l'entrée dont le formulaire est issu (édition, ou « Créer la
 * version XX »), `null` sur une page blanche. Distinct de
 * `form.translationGroup`, qui est la valeur *choisie* dans le sélecteur :
 * l'assistant de traduction bascule le formulaire vers l'autre locale et doit
 * y rattacher le brouillon au groupe de la **source** (D9), y compris quand
 * ce groupe n'avait aucune traduction et que le sélecteur affichait donc
 * « aucune ».
 */
const sourceGroup = ref<string | null>(null)

/**
 * Locale de l'entrée dont le formulaire courant est un brouillon traduit,
 * `null` sinon : porte la bannière « brouillon généré par IA ». Effacée dès que
 * le formulaire repart d'une entrée réelle ou d'une page blanche.
 */
const draftSourceLocale = ref<Locale | null>(null)

/**
 * Les champs que l'assistant traduit — ici, tout : une étude de cas n'a aucun
 * champ non-prose, « Créer la version XX » n'a donc rien à recopier de
 * l'entrée existante (spec 0002, D2 ; spec 0004 §7).
 *
 * Contenu du palier de base (ADR 0003 D5) : la règle éditoriale — jamais de
 * nom de client — vaut pour le brouillon autant que pour l'original, et c'est
 * la relecture humaine qui la garantit.
 */
const PROSE_FIELDS = ['title', 'problem', 'solution', 'tradeoffs', 'measuredResult'] as const

const isEditing = computed(() => null !== editingId.value)

const hasProseToTranslate = computed(() => PROSE_FIELDS.some((field) => '' !== form[field].trim()))

const translationErrorText = computed(() =>
  translationErrorReason.value ? t(`admin.translation.errors.${translationErrorReason.value}`) : null,
)

const errorText = computed(() => (errorMessage.value ? t(`admin.caseStudies.errors.${errorMessage.value.reason}`) : null))

const localeOptions = computed(() => SUPPORTED_LOCALES.map((locale) => ({ value: locale, label: locale.toUpperCase() })))

/** Une ligne de tableau par groupe de traduction (D8), toutes langues confondues. */
const rows = computed(() => groupByTranslationGroup(caseStudies.value, SUPPORTED_LOCALES))

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
  { value: '', label: t('admin.caseStudies.translationOfNone') },
  ...translationGroupOptions(
    caseStudies.value,
    form.locale,
    form.translationGroup,
    editingId.value,
    (caseStudy) => `${caseStudy.locale.toUpperCase()} · ${caseStudy.title}`,
  ),
])

function resetForm(): void {
  editingId.value = null
  draftSourceLocale.value = null
  sourceGroup.value = null
  form.locale = SUPPORTED_LOCALES[0]
  form.translationGroup = ''
  form.title = ''
  form.problem = ''
  form.solution = ''
  form.tradeoffs = ''
  form.measuredResult = ''
}

function startEdit(caseStudy: AdminCaseStudy): void {
  editingId.value = caseStudy.id
  draftSourceLocale.value = null
  sourceGroup.value = caseStudy.translationGroup
  form.locale = caseStudy.locale as Locale
  // Le groupe lu est repris tel quel dès qu'il porte une traduction : le
  // formulaire le renvoie alors à l'enregistrement, et le lien FR/EN survit à
  // l'édition. Un groupe solitaire n'a rien à détacher : `ContentPlacement::detach`
  // traite une entrée seule en non-geste (`count($members) === 1`), le groupe
  // est conservé. Le sélecteur affiche donc « aucune » sans conséquence.
  form.translationGroup = hasSibling(caseStudies.value, caseStudy) ? caseStudy.translationGroup : ''
  form.title = caseStudy.title
  form.problem = caseStudy.problem
  form.solution = caseStudy.solution
  form.tradeoffs = caseStudy.tradeoffs
  form.measuredResult = caseStudy.measuredResult
}

/**
 * « Créer la version XX » : formulaire en création, déjà rattaché au groupe,
 * dans la langue manquante, prose vide — tout est prose ici, il n'y a rien à
 * recopier, et rien à traduire puisqu'il n'y a rien d'écrit encore.
 */
function startCreateVersion(row: TranslationGroupRow<AdminCaseStudy>, locale: Locale): void {
  editingId.value = null
  draftSourceLocale.value = null
  sourceGroup.value = row.key
  form.locale = locale
  form.translationGroup = row.key
  form.title = ''
  form.problem = ''
  form.solution = ''
  form.tradeoffs = ''
  form.measuredResult = ''
}

async function handleSubmit(): Promise<void> {
  isSubmitting.value = true

  const input = {
    locale: form.locale,
    // D3 : aucun `position` n'est jamais envoyé. `null` = contenu neuf à la
    // création, détachement sur une mise à jour.
    translationGroup: '' === form.translationGroup ? null : form.translationGroup,
    title: form.title,
    problem: form.problem,
    solution: form.solution,
    tradeoffs: form.tradeoffs,
    measuredResult: form.measuredResult,
  }

  if (null !== editingId.value) {
    await update(editingId.value, input)
  } else {
    await create(input)
  }

  isSubmitting.value = false

  if (!errorMessage.value) {
    resetForm()
  }
}

/**
 * Demande un brouillon dans l'autre locale, puis bascule le formulaire en
 * **création** avec ce brouillon : la prose est remplacée, le reste conservé —
 * y compris le groupe de l'entrée source, pour que l'enregistrement rattache
 * la version proposée sans geste supplémentaire (spec 0004, D9). Rien n'est
 * enregistré ici — seul le bouton Enregistrer habituel persiste (ADR 0004,
 * D4). En cas d'échec, le formulaire reste intact et la raison s'affiche.
 */
async function handleTranslate(targetLocale: Locale): Promise<void> {
  const sourceLocale = form.locale

  const draft = await translate(sourceLocale, targetLocale, collectProseFields(form, PROSE_FIELDS))
  if (!draft) {
    return
  }

  editingId.value = null
  form.locale = targetLocale
  form.translationGroup = sourceGroup.value ?? ''
  applyTranslationDraft(form, PROSE_FIELDS, draft)
  draftSourceLocale.value = sourceLocale
}

async function handleDelete(caseStudy: AdminCaseStudy): Promise<void> {
  if (!window.confirm(t('admin.caseStudies.confirmDelete', { title: caseStudy.title }))) {
    return
  }

  await remove(caseStudy.id)
}

useUnsavedOrderGuard(isOrderDirty)
</script>

<template>
  <div class="d-flex flex-column gap-4">
    <div class="surface-panel p-3 p-sm-4">
      <h2 class="h6 fw-bold text-white mb-3">
        {{ isEditing ? t('admin.caseStudies.edit') : t('admin.caseStudies.create') }}
      </h2>

      <!-- Rappel de la règle éditoriale (ADR 0003 D5) : ce qui rend le contenu
           non identifiant se décide ici, à la saisie — pas par un filtre. -->
      <p class="form-text mb-3">
        {{ t('admin.caseStudies.identityHelp') }}
      </p>

      <form
        novalidate
        @submit.prevent="handleSubmit"
      >
        <BaseSelect
          id="admin-case-study-locale"
          v-model="form.locale"
          :disabled="isEditing"
          :label="t('admin.caseStudies.localeLabel')"
          :options="localeOptions"
        />
        <BaseSelect
          id="admin-case-study-translation-group"
          v-model="form.translationGroup"
          :label="t('admin.caseStudies.translationOfLabel')"
          :options="translationOptions"
        />
        <BaseTextInput
          id="admin-case-study-title"
          v-model="form.title"
          :label="t('admin.caseStudies.titleLabel')"
          required
        />
        <BaseTextarea
          id="admin-case-study-problem"
          v-model="form.problem"
          :label="t('admin.caseStudies.problemLabel')"
          :rows="5"
          required
        />
        <BaseTextarea
          id="admin-case-study-solution"
          v-model="form.solution"
          :label="t('admin.caseStudies.solutionLabel')"
          :rows="6"
          required
        />
        <BaseTextarea
          id="admin-case-study-tradeoffs"
          v-model="form.tradeoffs"
          :label="t('admin.caseStudies.tradeoffsLabel')"
          :rows="4"
          required
        />
        <BaseTextarea
          id="admin-case-study-measured-result"
          v-model="form.measuredResult"
          :label="t('admin.caseStudies.measuredResultLabel')"
          :rows="3"
          required
          aria-describedby="admin-case-study-measured-result-help"
        />
        <div
          id="admin-case-study-measured-result-help"
          class="form-text mb-3"
        >
          {{ t('admin.caseStudies.measuredResultHelp') }}
        </div>

        <!--
          Verrouillé lui aussi tant que l'ordre est modifié : l'appel au modèle
          produirait un brouillon que le formulaire, verrouillé, ne pourrait pas
          enregistrer — du quota dépensé pour rien (ADR 0004, coût borné).
        -->
        <div class="mb-3">
          <TranslateEntryButton
            :form-locale="form.locale"
            :is-translating="isTranslating"
            :disabled="!hasProseToTranslate || isSubmitting || isOrderDirty"
            :aria-describedby="lockedHintId"
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

        <div class="d-flex gap-2">
          <button
            type="submit"
            class="btn btn-gradient"
            :disabled="isSubmitting || isOrderDirty"
            :aria-describedby="lockedHintId"
          >
            {{ t('admin.caseStudies.save') }}
          </button>
          <button
            v-if="isEditing"
            type="button"
            class="btn btn-outline-light"
            @click="resetForm"
          >
            {{ t('admin.caseStudies.cancel') }}
          </button>
        </div>
      </form>
    </div>

    <div class="surface-panel p-3 p-sm-4">
      <h2 class="h6 fw-bold text-white mb-3">
        {{ t('admin.caseStudies.listTitle') }}
      </h2>

      <p
        v-if="isLoading"
        class="text-body-secondary mb-0"
      >
        {{ t('admin.caseStudies.loading') }}
      </p>
      <p
        v-else-if="hasError"
        class="text-danger mb-0"
        role="alert"
      >
        {{ t('admin.caseStudies.loadError') }}
      </p>
      <p
        v-else-if="0 === orderedRows.length"
        class="text-body-secondary mb-0"
      >
        {{ t('admin.caseStudies.empty') }}
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
                  {{ t('admin.caseStudies.contentLabel') }}
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
                        {{ t('admin.caseStudies.editAction') }}
                      </button>
                      <button
                        type="button"
                        class="btn btn-sm btn-outline-danger"
                        :disabled="isOrderDirty"
                        :aria-describedby="lockedHintId"
                        @click="handleDelete(line.entry)"
                      >
                        {{ t('admin.caseStudies.deleteAction') }}
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
