<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { onBeforeRouteLeave } from 'vue-router'
import { useAdminAnonymousCvSections } from '../../../application/admin/anonymousCv/useAdminAnonymousCvSections'
import { useAdminTranslation } from '../../../application/admin/translation/useAdminTranslation'
import { applyTranslationDraft, collectProseFields } from '../../../application/admin/translation/proseFields'
import { useOrderDraft } from '../../../application/admin/shared/useOrderDraft'
import { useOrderHandleFocus } from '../../../application/admin/shared/useOrderHandleFocus'
import { useRowDragAndDrop } from '../../../application/admin/shared/useRowDragAndDrop'
import {
  groupByTranslationGroup,
  type TranslationGroupRow,
} from '../../../domain/admin/shared/ordering/groupByTranslationGroup'
import {
  firstEntry,
  hasSibling,
  rowLines,
  translationGroupOptions,
} from '../../../domain/admin/shared/ordering/translationGroupSelection'
import BaseTextInput from '../../ui/BaseTextInput.vue'
import BaseTextarea from '../../ui/BaseTextarea.vue'
import BaseNumberInput from '../../ui/BaseNumberInput.vue'
import BaseSelect from '../../ui/BaseSelect.vue'
import TranslateEntryButton from '../../ui/admin/TranslateEntryButton.vue'
import OrderHandle from '../../ui/admin/OrderHandle.vue'
import OrderToolbar from '../../ui/admin/OrderToolbar.vue'
import { LOCALE_NATIVE_NAMES, SUPPORTED_LOCALES, type Locale } from '../../../domain/portfolio/entities/Locale'
import type { AdminAnonymousCvSection } from '../../../domain/admin/anonymousCv/entities/AdminAnonymousCvSection'

const { t } = useI18n()
const { sections, isLoading, hasError, errorMessage, load, create, update, remove, reorder } =
  useAdminAnonymousCvSections()
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
interface SectionForm {
  locale: Locale
  translationGroup: string
  title: string
  skills: string
  yearsOfExperience: number
  achievements: string
}

const form = reactive<SectionForm>({
  locale: SUPPORTED_LOCALES[0],
  translationGroup: '',
  title: '',
  skills: '',
  yearsOfExperience: 0,
  achievements: '',
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
 * Les champs que l'assistant traduit — la prose. Le complément de cette liste
 * (`yearsOfExperience`) est ce que « Créer la version XX » recopie de
 * l'entrée existante : un nombre d'années n'a pas de traduction (spec 0002,
 * D2 ; spec 0004 §7). Une seule source de vérité par page.
 *
 * Contenu du palier de base (ADR 0003 D5) : la règle éditoriale — ni nom, ni
 * employeur, ni client — vaut pour le brouillon autant que pour l'original,
 * et c'est la relecture humaine qui la garantit.
 */
const PROSE_FIELDS = ['title', 'skills', 'achievements'] as const

const isEditing = computed(() => null !== editingId.value)

const hasProseToTranslate = computed(() => PROSE_FIELDS.some((field) => '' !== form[field].trim()))

const translationErrorText = computed(() =>
  translationErrorReason.value ? t(`admin.translation.errors.${translationErrorReason.value}`) : null,
)

const errorText = computed(() => (errorMessage.value ? t(`admin.anonymousCv.errors.${errorMessage.value.reason}`) : null))

const localeOptions = computed(() => SUPPORTED_LOCALES.map((locale) => ({ value: locale, label: locale.toUpperCase() })))

/** Une ligne de tableau par groupe de traduction (D8), toutes langues confondues. */
const rows = computed(() => groupByTranslationGroup(sections.value, SUPPORTED_LOCALES))

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

/**
 * Les lignes dans l'ordre du **brouillon**, pas dans celui du serveur : c'est
 * le glisser-déposer en cours que l'admin doit voir, l'ordre serveur ne
 * revenant qu'après « Annuler » ou un enregistrement.
 */
const orderedRows = computed(() => {
  const byKey = new Map(rows.value.map((row) => [row.key, row]))

  return orderDraft.value
    .map((key) => byKey.get(key))
    .filter((row): row is TranslationGroupRow<AdminAnonymousCvSection> => undefined !== row)
})

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

const { registerHandleCell, moveRow } = useOrderHandleFocus(moveInDraft)

/**
 * « Version de » : les options du sélecteur (D2, cf.
 * `domain/admin/shared/ordering/translationGroupSelection.ts` pour la règle
 * exacte). L'option « aucune » est préfixée ici, son libellé étant un texte
 * traduit — le module partagé reste framework-free.
 */
const translationOptions = computed(() => [
  { value: '', label: t('admin.anonymousCv.translationOfNone') },
  ...translationGroupOptions(
    sections.value,
    form.locale,
    form.translationGroup,
    editingId.value,
    (section) => `${section.locale.toUpperCase()} · ${section.title}`,
  ),
])

function resetForm(): void {
  editingId.value = null
  draftSourceLocale.value = null
  sourceGroup.value = null
  form.locale = SUPPORTED_LOCALES[0]
  form.translationGroup = ''
  form.title = ''
  form.skills = ''
  form.yearsOfExperience = 0
  form.achievements = ''
}

function startEdit(section: AdminAnonymousCvSection): void {
  editingId.value = section.id
  draftSourceLocale.value = null
  sourceGroup.value = section.translationGroup
  form.locale = section.locale as Locale
  // Le groupe lu est repris tel quel dès qu'il porte une traduction : le
  // formulaire le renvoie alors à l'enregistrement, et le lien FR/EN survit à
  // l'édition. Un groupe solitaire n'a rien à détacher : `ContentPlacement::reattach`
  // traite le `null` en non-geste (`count($members) === 1`), le groupe est
  // conservé. Le sélecteur affiche donc « aucune » sans conséquence.
  form.translationGroup = hasSibling(sections.value, section) ? section.translationGroup : ''
  form.title = section.title
  form.skills = section.skills
  form.yearsOfExperience = section.yearsOfExperience
  form.achievements = section.achievements
}

/**
 * « Créer la version XX » : formulaire en création, déjà rattaché au groupe,
 * dans la langue manquante, avec les champs non-prose recopiés de l'entrée
 * existante (cf. PROSE_FIELDS) et la prose vide — il n'y a rien à traduire
 * puisqu'il n'y a rien à écrire encore.
 */
function startCreateVersion(row: TranslationGroupRow<AdminAnonymousCvSection>, locale: Locale): void {
  const existing = firstEntry(row, SUPPORTED_LOCALES)

  editingId.value = null
  draftSourceLocale.value = null
  sourceGroup.value = row.key
  form.locale = locale
  form.translationGroup = row.key
  form.yearsOfExperience = existing?.yearsOfExperience ?? 0
  form.title = ''
  form.skills = ''
  form.achievements = ''
}

async function handleSubmit(): Promise<void> {
  isSubmitting.value = true

  const input = {
    locale: form.locale,
    // D3 : aucun `position` n'est jamais envoyé. `null` = contenu neuf à la
    // création, détachement sur une mise à jour.
    translationGroup: '' === form.translationGroup ? null : form.translationGroup,
    title: form.title,
    skills: form.skills,
    yearsOfExperience: form.yearsOfExperience,
    achievements: form.achievements,
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

async function handleDelete(section: AdminAnonymousCvSection): Promise<void> {
  if (!window.confirm(t('admin.anonymousCv.confirmDelete', { title: section.title }))) {
    return
  }

  await remove(section.id)
}

/**
 * Quitter la page avec un ordre modifié l'abandonnerait sans rien dire : la
 * navigation interne demande confirmation (D6), la fermeture de l'onglet passe
 * par `beforeunload`, que le navigateur traduit en sa propre boîte de dialogue.
 */
function confirmLeaving(): boolean {
  return !isOrderDirty.value || window.confirm(t('admin.order.leaveConfirm'))
}

onBeforeRouteLeave(() => confirmLeaving())

function warnBeforeUnload(event: BeforeUnloadEvent): void {
  if (!isOrderDirty.value) {
    return
  }

  event.preventDefault()
  event.returnValue = ''
}

onMounted(() => window.addEventListener('beforeunload', warnBeforeUnload))
onBeforeUnmount(() => window.removeEventListener('beforeunload', warnBeforeUnload))
</script>

<template>
  <div class="d-flex flex-column gap-4">
    <div class="surface-panel p-3 p-sm-4">
      <h2 class="h6 fw-bold text-white mb-3">
        {{ isEditing ? t('admin.anonymousCv.edit') : t('admin.anonymousCv.create') }}
      </h2>

      <!-- Rappel de la règle éditoriale (ADR 0003 D5) : ce qui rend le contenu
           non identifiant se décide ici, à la saisie — pas par un filtre. -->
      <p class="form-text mb-3">
        {{ t('admin.anonymousCv.identityHelp') }}
      </p>

      <form
        novalidate
        @submit.prevent="handleSubmit"
      >
        <BaseSelect
          id="admin-anonymous-cv-locale"
          v-model="form.locale"
          :label="t('admin.anonymousCv.localeLabel')"
          :options="localeOptions"
        />
        <BaseSelect
          id="admin-anonymous-cv-translation-group"
          v-model="form.translationGroup"
          :label="t('admin.anonymousCv.translationOfLabel')"
          :options="translationOptions"
        />
        <BaseTextInput
          id="admin-anonymous-cv-title"
          v-model="form.title"
          :label="t('admin.anonymousCv.titleLabel')"
          required
        />
        <BaseTextarea
          id="admin-anonymous-cv-skills"
          v-model="form.skills"
          :label="t('admin.anonymousCv.skillsLabel')"
          :rows="3"
          required
        />
        <BaseNumberInput
          id="admin-anonymous-cv-years"
          v-model="form.yearsOfExperience"
          :label="t('admin.anonymousCv.yearsLabel')"
          required
        />
        <BaseTextarea
          id="admin-anonymous-cv-achievements"
          v-model="form.achievements"
          :label="t('admin.anonymousCv.achievementsLabel')"
          :rows="6"
          required
          aria-describedby="admin-anonymous-cv-achievements-help"
        />
        <div
          id="admin-anonymous-cv-achievements-help"
          class="form-text mb-3"
        >
          {{ t('admin.anonymousCv.achievementsHelp') }}
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
            {{ t('admin.anonymousCv.save') }}
          </button>
          <button
            v-if="isEditing"
            type="button"
            class="btn btn-outline-light"
            @click="resetForm"
          >
            {{ t('admin.anonymousCv.cancel') }}
          </button>
        </div>
      </form>
    </div>

    <div class="surface-panel p-3 p-sm-4">
      <h2 class="h6 fw-bold text-white mb-3">
        {{ t('admin.anonymousCv.listTitle') }}
      </h2>

      <p
        v-if="isLoading"
        class="text-body-secondary mb-0"
      >
        {{ t('admin.anonymousCv.loading') }}
      </p>
      <p
        v-else-if="hasError"
        class="text-danger mb-0"
        role="alert"
      >
        {{ t('admin.anonymousCv.loadError') }}
      </p>
      <p
        v-else-if="0 === orderedRows.length"
        class="text-body-secondary mb-0"
      >
        {{ t('admin.anonymousCv.empty') }}
      </p>
      <template v-else>
        <OrderToolbar
          class="mb-3"
          :is-dirty="isOrderDirty"
          :is-saving="isOrderSaving"
          :error-reason="orderErrorReason"
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
                <th scope="col">
                  <span class="visually-hidden">{{ t('admin.order.columnHeader') }}</span>
                </th>
                <th scope="col">
                  {{ t('admin.anonymousCv.contentLabel') }}
                </th>
                <th scope="col">
                  {{ t('admin.anonymousCv.yearsLabel') }}
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
                    class="d-flex flex-wrap align-items-center gap-2 py-1"
                  >
                    <span
                      class="badge text-bg-secondary"
                      aria-hidden="true"
                    >{{ line.locale.toUpperCase() }}</span>
                    <span class="visually-hidden">{{ line.nativeName }}</span>
                    <template v-if="line.entry">
                      <span class="text-white">{{ line.entry.title }}</span>
                      <button
                        type="button"
                        class="btn btn-sm btn-outline-light"
                        :disabled="isOrderDirty"
                        :aria-describedby="lockedHintId"
                        @click="startEdit(line.entry)"
                      >
                        {{ t('admin.anonymousCv.editAction') }}
                      </button>
                      <button
                        type="button"
                        class="btn btn-sm btn-outline-danger"
                        :disabled="isOrderDirty"
                        :aria-describedby="lockedHintId"
                        @click="handleDelete(line.entry)"
                      >
                        {{ t('admin.anonymousCv.deleteAction') }}
                      </button>
                    </template>
                    <template v-else>
                      <span class="text-body-secondary">{{ t('admin.order.missingTranslation') }}</span>
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
                <td class="text-nowrap">
                  {{ firstEntry(row, SUPPORTED_LOCALES)?.yearsOfExperience }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </div>
  </div>
</template>
