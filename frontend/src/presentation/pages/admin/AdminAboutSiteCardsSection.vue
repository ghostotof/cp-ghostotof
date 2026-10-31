<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAdminAboutSiteCards } from '../../../application/admin/about/useAdminAboutSiteCards'
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
import { SITE_CARD_PROSE_FIELDS } from './proseFields'
import OrderHandle from '../../ui/admin/OrderHandle.vue'
import OrderToolbar from '../../ui/admin/OrderToolbar.vue'
import { LOCALE_NATIVE_NAMES, SUPPORTED_LOCALES, type Locale } from '../../../domain/portfolio/entities/Locale'
import type { AdminAboutSiteCard } from '../../../domain/admin/about/entities/AdminAboutSiteCard'

/**
 * Cartes « À propos de ce site » : un tableau groupé, un brouillon d'ordre, un
 * formulaire.
 *
 * **Le tableau affiche toutes les langues** (spec 0004, D8), une ligne par
 * groupe de traduction : l'ordre est celui du groupe et non celui d'une langue.
 * `list()` a perdu son paramètre de locale en conséquence, et changer de langue
 * ne recharge donc plus rien.
 *
 * **La langue est un champ de ce formulaire, pas un état de page.** Le
 * sélecteur ci-dessous est la langue de l'entrée en cours d'édition ou de
 * création, et il ne pilote rien d'autre que ce formulaire et son assistant.
 * La page ne le partage plus avec les cartes « moi » : un sélecteur commun
 * laissait un geste fait dans un panneau réécrire la langue d'une entrée en
 * cours d'édition dans l'autre, sans avertissement (même régression que sur la
 * page Qualité, qui la documente). Les réglages, eux, gardent la langue de
 * page : ils sont un singleton par locale, sélectionner une langue y *est*
 * choisir l'enregistrement à éditer.
 *
 * **Le verrou d'ordre (D6) est global à la page** : cette section reçoit
 * `isLocked` et annonce son propre `orderDirtyChange`, la page faisant la
 * somme. Un seul brouillon modifié, où qu'il soit, désactive toutes les
 * mutations — un état, une aide, une règle à retenir.
 */

const props = defineProps<{ isLocked: boolean; lockedHintId?: string }>()
const emit = defineEmits<{ orderDirtyChange: [isDirty: boolean] }>()

const { t } = useI18n()

const { cards, isLoading, hasError, isLoaded, errorMessage, load, create, update, remove, reorder } = useAdminAboutSiteCards()

const localeOptions = SUPPORTED_LOCALES.map((locale) => ({ value: locale, label: LOCALE_NATIVE_NAMES[locale] }))

/**
 * `locale` est typée explicitement : sans annotation, `reactive` l'infère au
 * type littéral de sa valeur initiale et le sélecteur de langue ne compile
 * plus. Le champ numérique `Position` a disparu (spec 0004, D3).
 */
interface SiteCardForm {
  locale: Locale
  translationGroup: string
  title: string
  description: string
  iconKey: string
}

const form = reactive<SiteCardForm>({
  locale: SUPPORTED_LOCALES[0],
  translationGroup: '',
  title: '',
  description: '',
  iconKey: '',
})

const editingId = ref<string | null>(null)
const isSubmitting = ref(false)

/** Locale de l'entrée dont le formulaire est une proposition traduite, `null` sinon. */
const proposalSourceLocale = ref<Locale | null>(null)

const isEditing = computed(() => null !== editingId.value)
const lock = useEntryFormLock({
  hintId: 'admin-about-site-card-entry-lock-help',
  isEditing,
  proposalSourceLocale,
  localeHelpId: 'admin-about-site-card-locale-help',
})

/**
 * Groupe de l'entrée dont le formulaire est issu (édition, ou « Créer la
 * version XX »), `null` sur une page blanche. Distinct de
 * `form.translationGroup`, qui est la valeur *choisie* dans le sélecteur :
 * l'assistant bascule le formulaire vers l'autre locale et doit y rattacher la
 * proposition au groupe de la **source** (D9), y compris quand ce groupe n'avait
 * aucune traduction et que le sélecteur affichait donc « aucune ».
 */
const sourceGroup = ref<string | null>(null)

// Après le formulaire : le composable en lit l'objet dès l'appel.
const {
  isTranslating,
  isDiscardPending,
  isRefused: isTranslationRefused,
  errorText: translationErrorText,
  proposeFrom,
  removeEntry,
  renewForm,
  proseReadonlyHintId,
  translationHintId,
  proseFieldAttrs,
} = useTranslatedForm({
  hintIdPrefix: 'admin-about-site-card',
  form,
  proseFields: SITE_CARD_PROSE_FIELDS,
  isRefused: () => isSubmitting.value || props.isLocked || lock.isProposalLocked,
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

/** Une ligne de tableau par groupe de traduction (D8), toutes langues confondues. */
const rows = computed(() => groupByTranslationGroup(cards.value, SUPPORTED_LOCALES))

const {
  draft: orderDraft,
  isDirty: isOrderDirty,
  isSaving: isSavingOrder,
  errorReason: orderErrorReason,
  move: moveInDraft,
  reset: resetOrder,
  save: saveOrder,
} = useOrderDraft({
  serverKeys: () => rows.value.map((row) => row.key),
  reorder,
  reload: load,
})

// Le verrou est global à la page : elle seule connaît l'état des quatre
// tableaux et du formulaire des réglages, donc chaque section lui annonce le
// sien plutôt que d'en déduire un verrou local, qui afficherait deux états
// désactivés différents pour un seul geste en cours. `immediate` pour que
// l'état initial (à jour) soit posé sans attendre un premier déplacement.
watch(isOrderDirty, (isDirty) => emit('orderDirtyChange', isDirty), { immediate: true })

const { draggingIndex, onDragStart, onDragOver, onDrop, onDragEnd } = useRowDragAndDrop(moveInDraft)
const { registerHandleCell, moveRow, lastMove } = useOrderHandleFocus(moveInDraft, () => orderedRows.value.length)

const orderedRows = computed(() => orderRowsByDraft(rows.value, orderDraft.value))

/**
 * « Version de » : les options du sélecteur (D2, cf.
 * `domain/admin/shared/ordering/translationGroupSelection.ts` pour la règle
 * exacte). L'option « aucune » est préfixée ici, son libellé étant un texte
 * traduit — le module partagé reste framework-free.
 */
const translationOptions = computed(() => [
  { value: '', label: t('admin.about.translationOfNone') },
  ...translationGroupOptions(
    cards.value,
    form.locale,
    form.translationGroup,
    editingId.value,
    (card) => `${card.locale.toUpperCase()} · ${card.title}`,
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
  form.description = ''
  form.iconKey = ''
}

// Le groupe lu est repris tel quel dès qu'il porte une traduction : le
// formulaire le renvoie alors à l'enregistrement, et le lien FR/EN survit à
// l'édition. Un groupe solitaire n'a rien à détacher : `ContentPlacement::detach`
// traite une entrée seule en non-geste (`count($members) === 1`), le groupe
// est conservé. Le sélecteur affiche donc « aucune » sans conséquence.
function startEdit(card: AdminAboutSiteCard): void {
  // Avant toute écriture : ce geste remplit ou vide le formulaire, il en avance la génération que la traduction en vol lit (R152).
  renewForm()
  editingId.value = card.id
  proposalSourceLocale.value = null
  sourceGroup.value = card.translationGroup
  form.locale = card.locale
  form.translationGroup = hasSibling(cards.value, card) ? card.translationGroup : ''
  form.title = card.title
  form.description = card.description
  form.iconKey = card.iconKey ?? ''
}

/**
 * « Créer la version XX » : formulaire en création, déjà rattaché au groupe,
 * dans la langue manquante — qu'adopte le sélecteur du formulaire. Les champs
 * non-prose sont recopiés de l'entrée existante (ici `iconKey`, cf.
 * SITE_CARD_PROSE_FIELDS par complément) et la prose reste vide : il n'y a rien à
 * traduire puisqu'il n'y a rien d'écrit encore.
 */
function startCreateVersion(row: TranslationGroupRow<AdminAboutSiteCard>, locale: Locale): void {
  // Avant toute écriture : ce geste remplit ou vide le formulaire, il en avance la génération que la traduction en vol lit (R152).
  renewForm()
  const existing = firstEntry(row, SUPPORTED_LOCALES)

  editingId.value = null
  proposalSourceLocale.value = null
  sourceGroup.value = row.key
  form.locale = locale
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

/**
 * Demande une proposition dans l'autre locale, puis bascule le formulaire en
 * **création** avec cette proposition : la prose est remplacée, le reste conservé —
 * y compris le groupe de l'entrée source, pour que l'enregistrement rattache
 * la version proposée sans geste supplémentaire (spec 0004, D9). Rien n'est
 * enregistré ici — seul le bouton Enregistrer habituel persiste (ADR 0004,
 * D4). En cas d'échec, le formulaire reste intact et la raison s'affiche.
 */
async function handleTranslate(targetLocale: Locale): Promise<void> {
  await proposeFrom(targetLocale, (sourceLocale) => {
    editingId.value = null
    form.locale = targetLocale
    form.translationGroup = sourceGroup.value ?? ''
    proposalSourceLocale.value = sourceLocale
  })
}

async function handleDelete(card: AdminAboutSiteCard): Promise<void> {
  if (!window.confirm(t('admin.about.siteCard.confirmDelete', { title: card.title }))) {
    return
  }

  // La suppression ne change rien au formulaire : le composable périme la traduction de l'entrée supprimée ou de sa source.
  await removeEntry(card, () => remove(card.id))
}
</script>

<template>
  <div class="surface-panel p-3 p-sm-4">
    <h2 class="h6 fw-bold text-white mb-3">
      {{ isEditing ? t('admin.about.siteCard.edit') : t('admin.about.siteCard.create') }}
    </h2>

    <form
      novalidate
      @submit.prevent="handleSubmit"
    >
      <!-- Verrou d'édition et de proposition : voir `useEntryFormLock` (#748). -->
      <BaseSelect
        id="admin-about-site-card-locale"
        v-model="form.locale"
        :disabled="lock.isEntryLocked"
        :aria-describedby="lock.localeDescribedBy"
        :label="t('admin.localeLabel')"
        :options="localeOptions"
      />
      <p
        id="admin-about-site-card-locale-help"
        class="form-text mb-3"
      >
        {{ t('admin.about.localeHelp') }}
      </p>
      <EntryFormLockHint
        :id="lock.hintId"
        :state="lock.state"
      />
      <BaseSelect
        id="admin-about-site-card-translation-group"
        v-model="form.translationGroup"
        :disabled="lock.isProposalLocked"
        :aria-describedby="lock.proposalDescribedBy"
        :label="t('admin.about.translationOfLabel')"
        :options="translationOptions"
      />
      <BaseTextInput
        id="admin-about-site-card-title"
        v-model="form.title"
        v-bind="proseFieldAttrs()"
        :label="t('admin.about.siteCard.titleLabel')"
        required
      />
      <BaseTextarea
        id="admin-about-site-card-description"
        v-model="form.description"
        v-bind="proseFieldAttrs()"
        :label="t('admin.about.siteCard.descriptionLabel')"
        required
      />
      <BaseTextInput
        id="admin-about-site-card-icon-key"
        v-model="form.iconKey"
        :label="t('admin.about.siteCard.iconKeyLabel')"
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
      {{ t('admin.about.siteCard.listTitle') }}
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
    <p
      v-else-if="0 === orderedRows.length"
      class="text-body-secondary mb-0"
    >
      {{ t('admin.about.siteCard.empty') }}
    </p>
    <template v-else>
      <OrderToolbar
        class="mb-3"
        :is-dirty="isOrderDirty"
        :is-saving="isSavingOrder"
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
                {{ t('admin.about.contentLabel') }}
              </th>
              <th
                scope="col"
                class="col-md-1"
              >
                {{ t('admin.about.siteCard.iconKeyLabel') }}
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
                      {{ t('admin.about.siteCard.editAction') }}
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
  </div>
</template>
