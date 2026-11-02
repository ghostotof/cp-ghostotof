<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAdminAboutMeCards } from '../../../application/admin/about/useAdminAboutMeCards'
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
import { ME_CARD_PROSE_FIELDS } from './proseFields'
import OrderHandle from '../../ui/admin/OrderHandle.vue'
import OrderToolbar from '../../ui/admin/OrderToolbar.vue'
import { LOCALE_NATIVE_NAMES, SUPPORTED_LOCALES, type Locale } from '../../../domain/portfolio/entities/Locale'
import {
  ME_CARD_CATEGORIES,
  type AdminAboutMeCard,
  type AdminAboutMeCardCategory,
} from '../../../domain/admin/about/entities/AdminAboutMeCard'

/**
 * Cartes « À propos de moi » : **un tableau par catégorie**, chacun avec son
 * propre ordre (spec 0004, D8). Ce n'est pas un choix d'affichage — c'est le
 * périmètre d'ordre du serveur : la lecture publique trie par
 * `(locale, category, position)`, et `PUT …/me-cards/order` prend une
 * `category` à côté de ses `groups`. Réordonner « Techniquement » laisse donc
 * les deux autres catégories intactes, et la règle d'ensemble exact (D4)
 * s'applique catégorie par catégorie.
 *
 * Une seule requête les charge toutes (`list()` sans filtre, D8) : la
 * répartition est un groupement local, pas trois allers-retours.
 *
 * **Les tableaux affichent toutes les langues**, une ligne par groupe de
 * traduction : l'ordre est celui du groupe et non celui d'une langue.
 *
 * **La langue est un champ de ce formulaire, pas un état de page** — même
 * raison que sur les cartes « site », dont le docblock la détaille : un
 * sélecteur partagé laissait un geste fait ici réécrire la langue d'une entrée
 * en cours d'édition là-bas.
 *
 * **Un groupe appartient à une seule catégorie.** « Version de » ne propose
 * donc que des entrées de la catégorie du formulaire, et, en création, changer
 * de catégorie remet le rattachement à zéro : le serveur refuserait un groupe
 * d'ailleurs (422), mais surtout, décrire un contenu d'une autre catégorie est
 * un autre contenu.
 *
 * **Une carte ne change pas de catégorie** (issue #578) : en édition, le
 * sélecteur est désactivé, comme celui de la langue. Un `PUT` qui changerait
 * la catégorie est refusé par le serveur (409 `me-card-category-immutable`) :
 * le changer ici viderait « Version de », le `PUT` partirait avec
 * `translationGroup: null` et détacherait la carte de sa traduction.
 *
 * **Le verrou d'ordre (D6) est global à la page** : cette section annonce son
 * `orderDirtyChange` (la somme de ses trois brouillons) et reçoit `isLocked`. Le
 * refus de traduire lit **ses** trois brouillons (`isAnyOrderDirty`) tout de suite
 * et ceux des autres sections par `isLocked`, à jour après le rendu de la page :
 * voir la limite dans `AdminQualityPage`.
 */

const props = defineProps<{ isLocked: boolean; lockedHintId?: string }>()
const emit = defineEmits<{ orderDirtyChange: [isDirty: boolean] }>()

const { t } = useI18n()

const { cards, isLoading, hasError, isLoaded, errorMessage, load, create, update, remove, reorder } = useAdminAboutMeCards()

const localeOptions = SUPPORTED_LOCALES.map((locale) => ({ value: locale, label: LOCALE_NATIVE_NAMES[locale] }))
const categoryOptions = computed(() =>
  ME_CARD_CATEGORIES.map((category) => ({ value: category, label: categoryLabel(category) })),
)

/**
 * `locale` et `category` sont typées explicitement : sans annotation,
 * `reactive` les infère au type littéral de leur valeur initiale et les
 * sélecteurs ne compilent plus. Le champ numérique `Position` a disparu
 * (spec 0004, D3).
 */
interface MeCardForm {
  locale: Locale
  category: AdminAboutMeCardCategory
  translationGroup: string
  title: string
  description: string
  iconKey: string
}

const form = reactive<MeCardForm>({
  locale: SUPPORTED_LOCALES[0],
  category: ME_CARD_CATEGORIES[0],
  translationGroup: '',
  title: '',
  description: '',
  iconKey: '',
})

/** Aide du sélecteur de catégorie en édition (issue #578). */
const CATEGORY_HELP_ID = 'admin-about-me-card-category-help'

const formState = useEntryFormState()
const { editingId, isSubmitting, sourceGroup, proposalSourceLocale } = formState

const isEditing = computed(() => null !== editingId.value)
const lock = useEntryFormLock({
  hintId: 'admin-about-me-card-entry-lock-help',
  isEditing,
  proposalSourceLocale,
  localeHelpId: 'admin-about-me-card-locale-help',
})

/**
 * Tout ce qu'il faut pour ordonner une catégorie : ses lignes groupées, son
 * brouillon, son glisser-déposer et son focus clavier. Trois instances plutôt
 * que trois copies du même câblage — c'est précisément pourquoi ces mécaniques
 * sont des composables partagés (`useOrderDraft`, `useRowDragAndDrop`,
 * `useOrderHandleFocus`) et non des lignes recopiées dans ce fichier.
 */
function createCategoryOrder(category: AdminAboutMeCardCategory) {
  const rows = computed(() =>
    groupByTranslationGroup(
      cards.value.filter((card) => card.category === category),
      SUPPORTED_LOCALES,
    ),
  )

  const draft = useOrderDraft({
    serverKeys: () => rows.value.map((row) => row.key),
    // La catégorie est fermée ici : l'endpoint ne peut donc pas en recevoir
    // une autre que celle du tableau d'où vient le geste.
    reorder: (keys) => reorder(keys, category),
    reload: load,
  })

  const orderedRows = computed(() => orderRowsByDraft(rows.value, draft.draft.value))

  return {
    category,
    rows,
    orderedRows,
    draft,
    drag: useRowDragAndDrop(draft.move),
    focus: useOrderHandleFocus(draft.move, () => orderedRows.value.length),
  }
}

const categoryOrders = ME_CARD_CATEGORIES.map(createCategoryOrder)

// Le verrou est global à la page : elle seule connaît l'état des quatre
// tableaux et du formulaire des réglages, donc cette section lui annonce la
// somme de ses trois brouillons. `immediate` pour que l'état initial (à jour)
// soit posé sans attendre un premier déplacement.
const isAnyOrderDirty = computed(() => categoryOrders.some((order) => order.draft.isDirty.value))
watch(isAnyOrderDirty, (isDirty) => emit('orderDirtyChange', isDirty), { immediate: true })

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
  hintIdPrefix: 'admin-about-me-card',
  form,
  proseFields: ME_CARD_PROSE_FIELDS,
  // Les brouillons d'ordre des trois catégories sont lus tout de suite, ceux des autres sections par `props.isLocked` : la limite de cette
  // lecture est écrite une fois, `.claude/rules/frontend-backoffice.md`, « The refusal is written once ».
  isRefused: () => isSubmitting.value || isAnyOrderDirty.value || props.isLocked || lock.isProposalLocked,
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

  return reason ? t(`admin.about.errors.${reason}`) : null
})

/** Les cartes de la catégorie du formulaire : le périmètre d'un rattachement. */
const cardsOfFormCategory = computed(() => cards.value.filter((card) => card.category === form.category))

/**
 * « Version de » : les options du sélecteur (D2, cf.
 * `domain/admin/shared/ordering/translationGroupSelection.ts` pour la règle
 * exacte), restreintes à la catégorie du formulaire. L'option « aucune » est
 * préfixée ici, son libellé étant un texte traduit — le module partagé reste
 * framework-free.
 */
const translationOptions = computed(() => [
  { value: '', label: t('admin.about.translationOfNone') },
  ...translationGroupOptions(
    cardsOfFormCategory.value,
    form.locale,
    form.translationGroup,
    editingId.value,
    (card) => `${card.locale.toUpperCase()} · ${card.title}`,
  ),
])

// Remise à zéro de « Version de » quand la catégorie change, **hors édition**.
// En édition, une carte ne change pas de catégorie (#578) : le sélecteur est
// désactivé, et seul `startEdit` y écrit — celle de la carte, juste avant son
// groupe. La garde `isEditing` le dit dans le code au lieu de dépendre de
// l'ordre des affectations de `startEdit` : `editingId` y est posé en premier,
// et un groupe effacé là partirait en `translationGroup: null`, c'est-à-dire
// en détachement, que le serveur accepte (la catégorie étant la bonne).
// `flush: 'sync'` : le rattachement est remis à zéro **au moment même** où la
// catégorie change, donc avant que `startCreateVersion`/`resetForm` n'écrivent
// le groupe juste après — un watcher différé l'aurait effacé au tick suivant.
// Sans cette remise à zéro, le sélecteur afficherait une valeur absente de ses
// options et l'enregistrement partirait vers un 422. Tant que le formulaire
// porte une proposition de traduction, le sélecteur est désactivé aussi (#748,
// D-b) : le watcher ne s'y déclenche que par `resetForm` (« Annuler »).
watch(
  () => form.category,
  () => {
    if (isEditing.value) {
      return
    }
    form.translationGroup = ''
  },
  { flush: 'sync' },
)

function resetForm(): void {
  renewForm()
  editingId.value = null
  proposalSourceLocale.value = null
  sourceGroup.value = null
  form.locale = SUPPORTED_LOCALES[0]
  form.category = ME_CARD_CATEGORIES[0]
  form.translationGroup = ''
  form.title = ''
  form.description = ''
  form.iconKey = ''
}

// Le groupe lu est repris tel quel dès qu'il porte une traduction : le
// formulaire le renvoie alors à l'enregistrement, et le lien FR/EN survit à
// l'édition. Un groupe solitaire n'a rien à détacher : `ContentPlacement::detach`
// traite une entrée seule en non-geste (`count($members) === 1`), le groupe
// est conservé. Le sélecteur affiche donc « aucune » sans conséquence.
// `hasSibling` cherche dans **toutes** les catégories : sur un groupe réparti
// (antérieur à #501), le sélecteur paraît vide, « aucune » le répare (#573).
function startEdit(card: AdminAboutMeCard): void {
  renewForm()
  editingId.value = card.id
  proposalSourceLocale.value = null
  sourceGroup.value = card.translationGroup
  form.locale = card.locale
  form.category = card.category
  form.translationGroup = hasSibling(cards.value, card) ? card.translationGroup : ''
  form.title = card.title
  form.description = card.description
  form.iconKey = card.iconKey ?? ''
}

/**
 * « Créer la version XX » : formulaire en création, déjà rattaché au groupe,
 * dans la langue manquante — qu'adopte le sélecteur du formulaire. Les champs
 * non-prose sont recopiés de l'entrée existante (`category` et `iconKey`, cf.
 * ME_CARD_PROSE_FIELDS par complément) et la prose reste vide : il n'y a rien à
 * traduire puisqu'il n'y a rien d'écrit encore.
 */
function startCreateVersion(row: TranslationGroupRow<AdminAboutMeCard>, locale: Locale): void {
  renewForm()
  const existing = firstEntry(row, SUPPORTED_LOCALES)

  editingId.value = null
  proposalSourceLocale.value = null
  sourceGroup.value = row.key
  form.locale = locale
  if (existing) {
    form.category = existing.category
  }
  form.translationGroup = row.key
  form.iconKey = existing?.iconKey ?? ''
  form.title = ''
  form.description = ''
}

async function handleSubmit(): Promise<void> {
  isSubmitting.value = true

  const input = {
    locale: form.locale,
    // D3 : aucun `position` n'est jamais envoyé. `null` = contenu neuf à la
    // création, détachement sur une mise à jour.
    translationGroup: '' === form.translationGroup ? null : form.translationGroup,
    category: form.category,
    title: form.title,
    description: form.description,
    iconKey: '' === form.iconKey ? null : form.iconKey,
  }

  const succeeded = null !== editingId.value ? await update(editingId.value, input) : await create(input)

  isSubmitting.value = false

  if (succeeded) {
    resetForm()
  }
}

/** La bascule (`switchToProposedCreation`) ne touche ni la catégorie ni l'icône du formulaire : elles restent. */
async function handleTranslate(targetLocale: Locale): Promise<void> {
  await proposeFrom(targetLocale, switchToProposedCreation(formState, form, targetLocale))
}

async function handleDelete(card: AdminAboutMeCard): Promise<void> {
  if (!window.confirm(t('admin.about.meCard.confirmDelete', { title: card.title }))) {
    return
  }

  // La suppression ne change rien au formulaire : le composable périme la traduction de l'entrée supprimée ou de sa source.
  await removeEntry(card, () => remove(card.id))
}

/** Libellé traduit d'une catégorie, réutilisé par le sélecteur et par les titres de tableaux. */
function categoryLabel(category: AdminAboutMeCardCategory): string {
  return t(`admin.about.meCard.category${category.charAt(0).toUpperCase()}${category.slice(1)}`)
}
</script>

<template>
  <div class="surface-panel p-3 p-sm-4">
    <h2 class="h6 fw-bold text-white mb-3">
      {{ isEditing ? t('admin.about.meCard.edit') : t('admin.about.meCard.create') }}
    </h2>

    <form
      novalidate
      @submit.prevent="handleSubmit"
    >
      <!-- Verrou d'édition et de proposition : voir `useEntryFormLock` (#748). -->
      <BaseSelect
        id="admin-about-me-card-locale"
        v-model="form.locale"
        :disabled="lock.isEntryLocked"
        :aria-describedby="lock.localeDescribedBy"
        :label="t('admin.localeLabel')"
        :options="localeOptions"
      />
      <p
        id="admin-about-me-card-locale-help"
        class="form-text mb-3"
      >
        {{ t('admin.about.localeHelp') }}
      </p>
      <EntryFormLockHint
        :id="lock.hintId"
        :state="lock.state"
      />
      <!--
        Désactivée en édition, comme la langue (issue #578) : une carte ne
        change pas de catégorie, et le serveur refuse le `PUT` qui en porte une
        autre (409). Désactivée aussi tant que le formulaire porte une proposition
        de traduction (#748, D-b de l'auteur) : une version dans l'autre langue ne
        change pas de catégorie (#501, #561) — la changer vidait « Version de »
        (le watcher ci-dessus) et l'enregistrement créait une carte détachée de sa
        source avec la prose générée. « Créer la version XX » reste libre. L'aide
        propre à la catégorie n'existe qu'en édition ; pendant une proposition, la
        catégorie désigne le texte du verrou du formulaire (R93) — jamais une
        référence vers un élément absent.
      -->
      <BaseSelect
        id="admin-about-me-card-category"
        v-model="form.category"
        :disabled="lock.isEntryLocked"
        :aria-describedby="isEditing ? CATEGORY_HELP_ID : lock.proposalDescribedBy"
        :label="t('admin.about.meCard.categoryLabel')"
        :options="categoryOptions"
      />
      <p
        v-if="isEditing"
        :id="CATEGORY_HELP_ID"
        class="form-text mb-3"
      >
        {{ t('admin.about.meCard.categoryLockedHelp') }}
      </p>
      <BaseSelect
        id="admin-about-me-card-translation-group"
        v-model="form.translationGroup"
        :disabled="lock.isProposalLocked"
        :aria-describedby="lock.proposalDescribedBy"
        :label="t('admin.about.translationOfLabel')"
        :options="translationOptions"
      />
      <BaseTextInput
        id="admin-about-me-card-title"
        v-model="form.title"
        v-bind="proseFieldAttrs()"
        :label="t('admin.about.meCard.titleLabel')"
        required
      />
      <BaseTextarea
        id="admin-about-me-card-description"
        v-model="form.description"
        v-bind="proseFieldAttrs()"
        :label="t('admin.about.meCard.descriptionLabel')"
        required
      />
      <BaseTextInput
        id="admin-about-me-card-icon-key"
        v-model="form.iconKey"
        :label="t('admin.about.meCard.iconKeyLabel')"
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
          {{ t('admin.about.save') }}
        </button>
        <button
          v-if="lock.isEntryLocked"
          type="button"
          class="btn btn-outline-light"
          @click="resetForm"
        >
          {{ t('admin.about.cancel') }}
        </button>
      </div>
    </form>

    <hr class="border-secondary my-4">

    <h3 class="h6 fw-bold text-white mb-3">
      {{ t('admin.about.meCard.listTitle') }}
    </h3>

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
    <template v-else>
      <!-- Un tableau, un brouillon et un enregistrement par catégorie (D8). -->
      <section
        v-for="order in categoryOrders"
        :key="order.category"
        class="mb-4"
      >
        <h4 class="h6 fw-semibold text-body-secondary mb-3">
          {{ categoryLabel(order.category) }}
        </h4>

        <p
          v-if="0 === order.orderedRows.value.length"
          class="text-body-secondary mb-0"
        >
          {{ t('admin.about.meCard.empty') }}
        </p>
        <template v-else>
          <OrderToolbar
            class="mb-3"
            :is-dirty="order.draft.isDirty.value"
            :is-saving="order.draft.isSaving.value"
            :error-reason="order.draft.errorReason.value"
            :last-move="order.focus.lastMove.value"
            @save="order.draft.save"
            @cancel="order.draft.reset"
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
                    {{ t('admin.about.contentLabel') }}
                  </th>
                  <th
                    scope="col"
                    class="col-md-1"
                  >
                    {{ t('admin.about.meCard.iconKeyLabel') }}
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
                  v-for="(row, index) in order.orderedRows.value"
                  :key="row.key"
                  draggable="true"
                  :class="{ 'opacity-50': index === order.drag.draggingIndex.value }"
                  @dragstart="order.drag.onDragStart(index)"
                  @dragover="order.drag.onDragOver"
                  @drop="order.drag.onDrop(index)"
                  @dragend="order.drag.onDragEnd"
                >
                  <td
                    :ref="order.focus.registerHandleCell"
                    :data-order-key="row.key"
                  >
                    <OrderHandle
                      :index="index"
                      :count="order.orderedRows.value.length"
                      :label="firstEntry(row, SUPPORTED_LOCALES)?.title ?? ''"
                      @move="(from, to) => order.focus.moveRow(row.key, from, to)"
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
                    {{ firstEntry(row, SUPPORTED_LOCALES)?.iconKey ?? '—' }}
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
                          {{ t('admin.about.meCard.editAction') }}
                        </button>
                        <button
                          type="button"
                          class="btn btn-sm btn-outline-danger"
                          :disabled="isLocked"
                          :aria-describedby="lockedHintId"
                          @click="handleDelete(line.entry)"
                        >
                          {{ t('admin.about.delete') }}
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
      </section>
    </template>
  </div>
</template>
