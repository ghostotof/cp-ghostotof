<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { onBeforeRouteLeave } from 'vue-router'
import { useAdminQualityPrinciples } from '../../../application/admin/quality/useAdminQualityPrinciples'
import { useAdminQualityTraits } from '../../../application/admin/quality/useAdminQualityTraits'
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
import type { AdminQualityPrinciple } from '../../../domain/admin/quality/entities/AdminQualityPrinciple'
import type { AdminQualityTrait } from '../../../domain/admin/quality/entities/AdminQualityTrait'

/**
 * Deux contenus ordonnés indépendamment sur une même page — les principes et
 * les traits de qualité —, chacun avec son tableau groupé, son brouillon
 * d'ordre et son formulaire.
 *
 * **Les tableaux affichent toutes les langues** (spec 0004, D8), une ligne par
 * groupe de traduction : l'ordre est celui du groupe et non celui d'une
 * langue, le régler en n'en regardant qu'une reviendrait à ignorer la moitié
 * du contenu déplacé. `list()` a perdu son paramètre de locale en conséquence.
 *
 * **La langue est un champ de formulaire, pas un état de page.** Chaque
 * formulaire a son propre sélecteur (`principleForm.locale`,
 * `traitForm.locale`), comme `AdminIncidentsPage.vue` : c'est la langue de
 * l'entrée en cours d'édition ou de création, et elle ne pilote rien d'autre
 * que ce formulaire et son assistant. Le sélecteur unique de page a été
 * supprimé : partagé par deux formulaires, il permettait à un geste fait dans
 * un panneau (« Modifier » sur une entrée anglaise, « Créer la version EN »,
 * une traduction) de réécrire la langue de l'entrée en cours d'édition dans
 * *l'autre* panneau, sans avertissement — d'autant plus silencieusement que,
 * depuis D8, changer de langue ne recharge plus aucune liste.
 *
 * **Conséquence sur l'assistant** : chacun bascule **son** formulaire vers la
 * locale cible (D9) et y rattache le brouillon au groupe de l'entrée source,
 * si bien que l'enregistrer lie la traduction sans geste supplémentaire. Rien
 * n'est rechargé derrière, donc rien ne vient écraser le brouillon.
 *
 * **« Modifier » sur une entrée d'une autre langue** aligne la langue du
 * formulaire sur celle de l'entrée : sans cela, l'enregistrement réécrirait
 * l'entrée anglaise en français.
 *
 * **Verrouillage (D6) : global à la page.** Un seul brouillon modifié, où
 * qu'il soit, désactive toutes les mutations des deux panneaux. Un verrou par
 * tableau serait plus fin — une création de trait ne recharge que les traits —
 * mais afficherait deux états désactivés différents pour un seul geste en
 * cours, alors que les deux panneaux partagent déjà leur sélecteur de langue.
 * Un état, une aide, une règle à retenir.
 */

const { t } = useI18n()

const {
  principles,
  isLoading: isLoadingPrinciples,
  hasError: hasPrinciplesError,
  errorMessage: principleErrorMessage,
  load: loadPrinciples,
  create: createPrinciple,
  update: updatePrinciple,
  remove: removePrinciple,
  reorder: reorderPrinciples,
} = useAdminQualityPrinciples()

const {
  traits,
  isLoading: isLoadingTraits,
  hasError: hasTraitsError,
  errorMessage: traitErrorMessage,
  load: loadTraits,
  create: createTrait,
  update: updateTrait,
  remove: removeTrait,
  reorder: reorderTraits,
} = useAdminQualityTraits()

const localeOptions = SUPPORTED_LOCALES.map((locale) => ({ value: locale, label: LOCALE_NATIVE_NAMES[locale] }))

/**
 * Une instance de composable de traduction par formulaire : chacun a son
 * attente et son message d'erreur.
 */
const {
  isTranslating: isTranslatingPrinciple,
  errorReason: principleTranslationErrorReason,
  translate: translatePrinciple,
} = useAdminTranslation()
const {
  isTranslating: isTranslatingTrait,
  errorReason: traitTranslationErrorReason,
  translate: translateTrait,
} = useAdminTranslation()

/**
 * Prose des principes. Le complément de cette liste — `iconKey` — est ce que
 * « Créer la version XX » recopie de l'entrée existante : une clé d'icône n'a
 * pas de traduction (spec 0002, D2 ; spec 0004 §7). Une seule source de vérité.
 */
const PRINCIPLE_PROSE_FIELDS = ['title', 'description'] as const
/** Un trait n'est qu'un libellé court — mais un libellé se traduit, et c'est son seul champ. */
const TRAIT_PROSE_FIELDS = ['label'] as const

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

interface TraitForm {
  locale: Locale
  translationGroup: string
  label: string
}

const principleForm = reactive<PrincipleForm>({
  locale: SUPPORTED_LOCALES[0],
  translationGroup: '',
  title: '',
  description: '',
  iconKey: '',
})
const traitForm = reactive<TraitForm>({ locale: SUPPORTED_LOCALES[0], translationGroup: '', label: '' })

const editingPrincipleId = ref<string | null>(null)
const editingTraitId = ref<string | null>(null)
const isSubmittingPrinciple = ref(false)
const isSubmittingTrait = ref(false)

/**
 * Groupe de l'entrée dont le formulaire est issu (édition, ou « Créer la
 * version XX »), `null` sur une page blanche. Distinct de
 * `form.translationGroup`, qui est la valeur *choisie* dans le sélecteur :
 * l'assistant bascule le formulaire vers l'autre locale et doit y rattacher le
 * brouillon au groupe de la **source** (D9), y compris quand ce groupe n'avait
 * aucune traduction et que le sélecteur affichait donc « aucune ».
 */
const principleSourceGroup = ref<string | null>(null)
const traitSourceGroup = ref<string | null>(null)

/** Locale de l'entrée dont le formulaire est un brouillon traduit, `null` sinon. */
const principleDraftSourceLocale = ref<Locale | null>(null)
const traitDraftSourceLocale = ref<Locale | null>(null)

const isEditingPrinciple = computed(() => null !== editingPrincipleId.value)
const isEditingTrait = computed(() => null !== editingTraitId.value)

const hasPrincipleProse = computed(() => PRINCIPLE_PROSE_FIELDS.some((field) => '' !== principleForm[field].trim()))
const hasTraitProse = computed(() => '' !== traitForm.label.trim())

const principleErrorText = computed(() =>
  principleErrorMessage.value ? t(`admin.quality.errors.${principleErrorMessage.value.reason}`) : null,
)
const traitErrorText = computed(() =>
  traitErrorMessage.value ? t(`admin.quality.errors.${traitErrorMessage.value.reason}`) : null,
)
const principleTranslationErrorText = computed(() =>
  principleTranslationErrorReason.value ? t(`admin.translation.errors.${principleTranslationErrorReason.value}`) : null,
)
const traitTranslationErrorText = computed(() =>
  traitTranslationErrorReason.value ? t(`admin.translation.errors.${traitTranslationErrorReason.value}`) : null,
)

/** Une ligne de tableau par groupe de traduction (D8), toutes langues confondues. */
const principleRows = computed(() => groupByTranslationGroup(principles.value, SUPPORTED_LOCALES))
const traitRows = computed(() => groupByTranslationGroup(traits.value, SUPPORTED_LOCALES))

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

/**
 * Tant qu'un ordre est modifié, toute mutation de la page est verrouillée
 * (D6) : elle rechargerait une liste et perdrait le brouillon sans prévenir.
 *
 * L'aide est **rendue visible**, jamais portée par un `title` : Bootstrap pose
 * `pointer-events: none` sur `.btn:disabled`, donc l'infobulle d'un bouton
 * désactivé ne s'affiche jamais au survol. Les boutons la désignent par
 * `aria-describedby` — et seulement quand elle existe, sinon la référence
 * pendante serait elle-même une erreur d'accessibilité.
 */
const LOCKED_HINT_ID = 'admin-order-locked-hint'

const isAnyOrderDirty = computed(() => isPrincipleOrderDirty.value || isTraitOrderDirty.value)
const lockedHintId = computed(() => (isAnyOrderDirty.value ? LOCKED_HINT_ID : undefined))

const {
  draggingIndex: draggingPrincipleIndex,
  onDragStart: onPrincipleDragStart,
  onDragOver: onPrincipleDragOver,
  onDrop: onPrincipleDrop,
  onDragEnd: onPrincipleDragEnd,
} = useRowDragAndDrop(movePrincipleInDraft)

const {
  draggingIndex: draggingTraitIndex,
  onDragStart: onTraitDragStart,
  onDragOver: onTraitDragOver,
  onDrop: onTraitDrop,
  onDragEnd: onTraitDragEnd,
} = useRowDragAndDrop(moveTraitInDraft)

const { registerHandleCell: registerPrincipleHandleCell, moveRow: movePrincipleRow } =
  useOrderHandleFocus(movePrincipleInDraft)
const { registerHandleCell: registerTraitHandleCell, moveRow: moveTraitRow } = useOrderHandleFocus(moveTraitInDraft)

const orderedPrincipleRows = computed(() => orderRowsByDraft(principleRows.value, principleOrderDraft.value))
const orderedTraitRows = computed(() => orderRowsByDraft(traitRows.value, traitOrderDraft.value))

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

function resetPrincipleForm(): void {
  editingPrincipleId.value = null
  principleDraftSourceLocale.value = null
  principleSourceGroup.value = null
  principleForm.locale = SUPPORTED_LOCALES[0]
  principleForm.translationGroup = ''
  principleForm.title = ''
  principleForm.description = ''
  principleForm.iconKey = ''
}

function resetTraitForm(): void {
  editingTraitId.value = null
  traitDraftSourceLocale.value = null
  traitSourceGroup.value = null
  traitForm.locale = SUPPORTED_LOCALES[0]
  traitForm.translationGroup = ''
  traitForm.label = ''
}

// Le groupe lu est repris tel quel dès qu'il porte une traduction : le
// formulaire le renvoie alors à l'enregistrement, et le lien FR/EN survit à
// l'édition. Un groupe solitaire n'a rien à détacher : `ContentPlacement::reattach`
// traite le `null` en non-geste (`count($members) === 1`), le groupe est
// conservé. Le sélecteur affiche donc « aucune » sans conséquence.
function startEditPrinciple(principle: AdminQualityPrinciple): void {
  editingPrincipleId.value = principle.id
  principleDraftSourceLocale.value = null
  principleSourceGroup.value = principle.translationGroup
  principleForm.locale = principle.locale
  principleForm.translationGroup = hasSibling(principles.value, principle) ? principle.translationGroup : ''
  principleForm.title = principle.title
  principleForm.description = principle.description
  principleForm.iconKey = principle.iconKey
}

function startEditTrait(trait: AdminQualityTrait): void {
  editingTraitId.value = trait.id
  traitDraftSourceLocale.value = null
  traitSourceGroup.value = trait.translationGroup
  traitForm.locale = trait.locale
  traitForm.translationGroup = hasSibling(traits.value, trait) ? trait.translationGroup : ''
  traitForm.label = trait.label
}

/**
 * « Créer la version XX » : formulaire en création, déjà rattaché au groupe,
 * dans la langue manquante — que le sélecteur de la page adopte, puisqu'il est
 * la langue des formulaires. Les champs non-prose sont recopiés de l'entrée
 * existante (cf. PRINCIPLE_PROSE_FIELDS par complément) et la prose reste vide :
 * il n'y a rien à traduire puisqu'il n'y a rien d'écrit encore.
 */
function startCreatePrincipleVersion(row: TranslationGroupRow<AdminQualityPrinciple>, locale: Locale): void {
  const existing = firstEntry(row, SUPPORTED_LOCALES)

  editingPrincipleId.value = null
  principleDraftSourceLocale.value = null
  principleSourceGroup.value = row.key
  principleForm.locale = locale
  principleForm.translationGroup = row.key
  principleForm.iconKey = existing?.iconKey ?? ''
  principleForm.title = ''
  principleForm.description = ''
}

/** Même geste pour un trait, qui n'a aucun champ non-prose à recopier. */
function startCreateTraitVersion(row: TranslationGroupRow<AdminQualityTrait>, locale: Locale): void {
  editingTraitId.value = null
  traitDraftSourceLocale.value = null
  traitSourceGroup.value = row.key
  traitForm.locale = locale
  traitForm.translationGroup = row.key
  traitForm.label = ''
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

  if (null !== editingPrincipleId.value) {
    await updatePrinciple(editingPrincipleId.value, input)
  } else {
    await createPrinciple(input)
  }

  isSubmittingPrinciple.value = false

  if (!principleErrorMessage.value) {
    resetPrincipleForm()
  }
}

async function handleSubmitTrait(): Promise<void> {
  isSubmittingTrait.value = true

  const input = {
    locale: traitForm.locale,
    translationGroup: '' === traitForm.translationGroup ? null : traitForm.translationGroup,
    label: traitForm.label,
  }

  if (null !== editingTraitId.value) {
    await updateTrait(editingTraitId.value, input)
  } else {
    await createTrait(input)
  }

  isSubmittingTrait.value = false

  if (!traitErrorMessage.value) {
    resetTraitForm()
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
async function handleTranslatePrinciple(targetLocale: Locale): Promise<void> {
  const sourceLocale = principleForm.locale

  const draft = await translatePrinciple(
    sourceLocale,
    targetLocale,
    collectProseFields(principleForm, PRINCIPLE_PROSE_FIELDS),
  )
  if (!draft) {
    return
  }

  editingPrincipleId.value = null
  principleForm.locale = targetLocale
  principleForm.translationGroup = principleSourceGroup.value ?? ''
  applyTranslationDraft(principleForm, PRINCIPLE_PROSE_FIELDS, draft)
  principleDraftSourceLocale.value = sourceLocale
}

async function handleTranslateTrait(targetLocale: Locale): Promise<void> {
  const sourceLocale = traitForm.locale

  const draft = await translateTrait(sourceLocale, targetLocale, collectProseFields(traitForm, TRAIT_PROSE_FIELDS))
  if (!draft) {
    return
  }

  editingTraitId.value = null
  traitForm.locale = targetLocale
  traitForm.translationGroup = traitSourceGroup.value ?? ''
  applyTranslationDraft(traitForm, TRAIT_PROSE_FIELDS, draft)
  traitDraftSourceLocale.value = sourceLocale
}

async function handleDeletePrinciple(principle: AdminQualityPrinciple): Promise<void> {
  if (!window.confirm(t('admin.quality.principle.confirmDelete', { title: principle.title }))) {
    return
  }

  await removePrinciple(principle.id)
}

async function handleDeleteTrait(trait: AdminQualityTrait): Promise<void> {
  if (!window.confirm(t('admin.quality.trait.confirmDelete', { label: trait.label }))) {
    return
  }

  await removeTrait(trait.id)
}

/**
 * Quitter la page avec un ordre modifié l'abandonnerait sans rien dire : la
 * navigation interne demande confirmation (D6), la fermeture de l'onglet passe
 * par `beforeunload`, que le navigateur traduit en sa propre boîte de dialogue.
 */
function confirmLeaving(): boolean {
  return !isAnyOrderDirty.value || window.confirm(t('admin.order.leaveConfirm'))
}

onBeforeRouteLeave(() => confirmLeaving())

function warnBeforeUnload(event: BeforeUnloadEvent): void {
  if (!isAnyOrderDirty.value) {
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
    <!--
      Le verrou est global à la page : son aide l'est aussi, rendue exactement
      quand `lockedHintId` est défini — une référence `aria-describedby`
      pendante serait elle-même une erreur d'accessibilité.
    -->
    <p
      v-if="isAnyOrderDirty"
      :id="LOCKED_HINT_ID"
      class="alert alert-warning mb-0"
    >
      {{ t('admin.order.lockedHint') }}
    </p>

    <div class="surface-panel p-3 p-sm-4">
      <h2 class="h6 fw-bold text-white mb-3">
        {{ isEditingPrinciple ? t('admin.quality.principle.edit') : t('admin.quality.principle.create') }}
      </h2>

      <form
        novalidate
        @submit.prevent="handleSubmitPrinciple"
      >
        <BaseSelect
          id="admin-quality-principle-locale"
          v-model="principleForm.locale"
          :label="t('admin.localeLabel')"
          :options="localeOptions"
        />
        <p class="form-text mb-3">
          {{ t('admin.quality.localeHelp') }}
        </p>
        <BaseSelect
          id="admin-quality-principle-translation-group"
          v-model="principleForm.translationGroup"
          :label="t('admin.quality.translationOfLabel')"
          :options="principleTranslationOptions"
        />
        <BaseTextInput
          id="admin-quality-principle-title"
          v-model="principleForm.title"
          :label="t('admin.quality.principle.titleLabel')"
          required
        />
        <BaseTextarea
          id="admin-quality-principle-description"
          v-model="principleForm.description"
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
          produirait un brouillon que le formulaire, verrouillé, ne pourrait pas
          enregistrer — du quota dépensé pour rien (ADR 0004, coût borné).
        -->
        <div class="mb-3">
          <TranslateEntryButton
            :form-locale="principleForm.locale"
            :is-translating="isTranslatingPrinciple"
            :disabled="!hasPrincipleProse || isSubmittingPrinciple || isAnyOrderDirty"
            :aria-describedby="lockedHintId"
            @translate="handleTranslatePrinciple"
          />
        </div>

        <p
          v-if="principleDraftSourceLocale"
          class="alert alert-info small"
          role="status"
        >
          {{ t('admin.translation.draftNotice', { locale: principleDraftSourceLocale.toUpperCase() }) }}
        </p>

        <p
          v-if="principleTranslationErrorText"
          class="text-danger small"
          role="alert"
        >
          {{ principleTranslationErrorText }}
        </p>

        <p
          v-if="principleErrorText"
          class="text-danger small"
          role="alert"
        >
          {{ principleErrorText }}
        </p>

        <div class="d-flex gap-2">
          <button
            type="submit"
            class="btn btn-gradient"
            :disabled="isSubmittingPrinciple || isAnyOrderDirty"
            :aria-describedby="lockedHintId"
          >
            {{ t('admin.quality.save') }}
          </button>
          <button
            v-if="isEditingPrinciple"
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
        class="text-danger mb-0"
        role="alert"
      >
        {{ t('admin.quality.loadError') }}
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
          @save="savePrincipleOrder"
          @cancel="resetPrincipleOrder"
        />

        <div class="table-responsive">
          <table class="table table-dark align-middle mb-0">
            <thead>
              <tr>
                <th scope="col">
                  <span class="visually-hidden">{{ t('admin.order.columnHeader') }}</span>
                </th>
                <th scope="col">
                  {{ t('admin.quality.contentLabel') }}
                </th>
                <th scope="col">
                  {{ t('admin.quality.principle.iconKeyLabel') }}
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
                        :disabled="isAnyOrderDirty"
                        :aria-describedby="lockedHintId"
                        @click="startEditPrinciple(line.entry)"
                      >
                        {{ t('admin.quality.principle.editAction') }}
                      </button>
                      <button
                        type="button"
                        class="btn btn-sm btn-outline-danger"
                        :disabled="isAnyOrderDirty"
                        :aria-describedby="lockedHintId"
                        @click="handleDeletePrinciple(line.entry)"
                      >
                        {{ t('admin.quality.delete') }}
                      </button>
                    </template>
                    <template v-else>
                      <span class="text-body-secondary">{{ t('admin.order.missingTranslation') }}</span>
                      <button
                        type="button"
                        class="btn btn-sm btn-outline-light"
                        :disabled="isAnyOrderDirty"
                        :aria-describedby="lockedHintId"
                        @click="startCreatePrincipleVersion(row, line.locale)"
                      >
                        {{ t('admin.order.createVersion', { locale: line.locale.toUpperCase() }) }}
                      </button>
                    </template>
                  </div>
                </td>
                <td class="text-nowrap">
                  {{ firstEntry(row, SUPPORTED_LOCALES)?.iconKey }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </div>

    <div class="surface-panel p-3 p-sm-4">
      <h2 class="h6 fw-bold text-white mb-3">
        {{ isEditingTrait ? t('admin.quality.trait.edit') : t('admin.quality.trait.create') }}
      </h2>

      <form
        novalidate
        @submit.prevent="handleSubmitTrait"
      >
        <BaseSelect
          id="admin-quality-trait-locale"
          v-model="traitForm.locale"
          :label="t('admin.localeLabel')"
          :options="localeOptions"
        />
        <p class="form-text mb-3">
          {{ t('admin.quality.localeHelp') }}
        </p>
        <BaseSelect
          id="admin-quality-trait-translation-group"
          v-model="traitForm.translationGroup"
          :label="t('admin.quality.translationOfLabel')"
          :options="traitTranslationOptions"
        />
        <BaseTextInput
          id="admin-quality-trait-label"
          v-model="traitForm.label"
          :label="t('admin.quality.trait.labelLabel')"
          required
        />

        <div class="mb-3">
          <TranslateEntryButton
            :form-locale="traitForm.locale"
            :is-translating="isTranslatingTrait"
            :disabled="!hasTraitProse || isSubmittingTrait || isAnyOrderDirty"
            :aria-describedby="lockedHintId"
            @translate="handleTranslateTrait"
          />
        </div>

        <p
          v-if="traitDraftSourceLocale"
          class="alert alert-info small"
          role="status"
        >
          {{ t('admin.translation.draftNotice', { locale: traitDraftSourceLocale.toUpperCase() }) }}
        </p>

        <p
          v-if="traitTranslationErrorText"
          class="text-danger small"
          role="alert"
        >
          {{ traitTranslationErrorText }}
        </p>

        <p
          v-if="traitErrorText"
          class="text-danger small"
          role="alert"
        >
          {{ traitErrorText }}
        </p>

        <div class="d-flex gap-2">
          <button
            type="submit"
            class="btn btn-gradient"
            :disabled="isSubmittingTrait || isAnyOrderDirty"
            :aria-describedby="lockedHintId"
          >
            {{ t('admin.quality.save') }}
          </button>
          <button
            v-if="isEditingTrait"
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
        class="text-danger mb-0"
        role="alert"
      >
        {{ t('admin.quality.loadError') }}
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
          @save="saveTraitOrder"
          @cancel="resetTraitOrder"
        />

        <div class="table-responsive">
          <table class="table table-dark align-middle mb-0">
            <thead>
              <tr>
                <th scope="col">
                  <span class="visually-hidden">{{ t('admin.order.columnHeader') }}</span>
                </th>
                <th scope="col">
                  {{ t('admin.quality.contentLabel') }}
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
                    class="d-flex flex-wrap align-items-center gap-2 py-1"
                  >
                    <span
                      class="badge text-bg-secondary"
                      aria-hidden="true"
                    >{{ line.locale.toUpperCase() }}</span>
                    <span class="visually-hidden">{{ line.nativeName }}</span>
                    <template v-if="line.entry">
                      <span class="text-white">{{ line.entry.label }}</span>
                      <button
                        type="button"
                        class="btn btn-sm btn-outline-light"
                        :disabled="isAnyOrderDirty"
                        :aria-describedby="lockedHintId"
                        @click="startEditTrait(line.entry)"
                      >
                        {{ t('admin.quality.trait.editAction') }}
                      </button>
                      <button
                        type="button"
                        class="btn btn-sm btn-outline-danger"
                        :disabled="isAnyOrderDirty"
                        :aria-describedby="lockedHintId"
                        @click="handleDeleteTrait(line.entry)"
                      >
                        {{ t('admin.quality.delete') }}
                      </button>
                    </template>
                    <template v-else>
                      <span class="text-body-secondary">{{ t('admin.order.missingTranslation') }}</span>
                      <button
                        type="button"
                        class="btn btn-sm btn-outline-light"
                        :disabled="isAnyOrderDirty"
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
  </div>
</template>
