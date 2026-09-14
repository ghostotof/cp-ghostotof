<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAdminAboutSiteCards } from '../../../application/admin/about/useAdminAboutSiteCards'
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

defineProps<{ isLocked: boolean; lockedHintId?: string }>()
const emit = defineEmits<{ orderDirtyChange: [isDirty: boolean] }>()

const { t } = useI18n()

const { cards, isLoading, hasError, errorMessage, load, create, update, remove, reorder } = useAdminAboutSiteCards()

const localeOptions = SUPPORTED_LOCALES.map((locale) => ({ value: locale, label: LOCALE_NATIVE_NAMES[locale] }))

const { isTranslating, errorReason: translationErrorReason, translate } = useAdminTranslation()

/**
 * Prose d'une carte du site. Le complément de cette liste — `iconKey` — est ce
 * que « Créer la version XX » recopie de l'entrée existante : une clé d'icône
 * n'a pas de traduction (spec 0002, D2 ; spec 0004 §7). Une seule source de vérité.
 */
const PROSE_FIELDS = ['title', 'description'] as const

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
const isEditing = computed(() => null !== editingId.value)

/**
 * Groupe de l'entrée dont le formulaire est issu (édition, ou « Créer la
 * version XX »), `null` sur une page blanche. Distinct de
 * `form.translationGroup`, qui est la valeur *choisie* dans le sélecteur :
 * l'assistant bascule le formulaire vers l'autre locale et doit y rattacher le
 * brouillon au groupe de la **source** (D9), y compris quand ce groupe n'avait
 * aucune traduction et que le sélecteur affichait donc « aucune ».
 */
const sourceGroup = ref<string | null>(null)

/** Locale de l'entrée dont le formulaire est un brouillon traduit, `null` sinon. */
const draftSourceLocale = ref<Locale | null>(null)

const hasProseToTranslate = computed(() => PROSE_FIELDS.some((field) => '' !== form[field].trim()))
const errorText = computed(() => (errorMessage.value ? t(`admin.about.errors.${errorMessage.value.reason}`) : null))
const translationErrorText = computed(() =>
  translationErrorReason.value ? t(`admin.translation.errors.${translationErrorReason.value}`) : null,
)

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
const { registerHandleCell, moveRow } = useOrderHandleFocus(moveInDraft)

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
  editingId.value = null
  draftSourceLocale.value = null
  sourceGroup.value = null
  form.locale = SUPPORTED_LOCALES[0]
  form.translationGroup = ''
  form.title = ''
  form.description = ''
  form.iconKey = ''
}

// Le groupe lu est repris tel quel dès qu'il porte une traduction : le
// formulaire le renvoie alors à l'enregistrement, et le lien FR/EN survit à
// l'édition. Un groupe solitaire n'a rien à détacher : `ContentPlacement::reattach`
// traite le `null` en non-geste (`count($members) === 1`), le groupe est
// conservé. Le sélecteur affiche donc « aucune » sans conséquence.
function startEdit(card: AdminAboutSiteCard): void {
  editingId.value = card.id
  draftSourceLocale.value = null
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
 * PROSE_FIELDS par complément) et la prose reste vide : il n'y a rien à
 * traduire puisqu'il n'y a rien d'écrit encore.
 */
function startCreateVersion(row: TranslationGroupRow<AdminAboutSiteCard>, locale: Locale): void {
  const existing = firstEntry(row, SUPPORTED_LOCALES)

  editingId.value = null
  draftSourceLocale.value = null
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

async function handleDelete(card: AdminAboutSiteCard): Promise<void> {
  if (!window.confirm(t('admin.about.siteCard.confirmDelete', { title: card.title }))) {
    return
  }

  await remove(card.id)
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
      <BaseSelect
        id="admin-about-site-card-locale"
        v-model="form.locale"
        :label="t('admin.localeLabel')"
        :options="localeOptions"
      />
      <p class="form-text mb-3">
        {{ t('admin.about.localeHelp') }}
      </p>
      <BaseSelect
        id="admin-about-site-card-translation-group"
        v-model="form.translationGroup"
        :label="t('admin.about.translationOfLabel')"
        :options="translationOptions"
      />
      <BaseTextInput
        id="admin-about-site-card-title"
        v-model="form.title"
        :label="t('admin.about.siteCard.titleLabel')"
        required
      />
      <BaseTextarea
        id="admin-about-site-card-description"
        v-model="form.description"
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
        produirait un brouillon que le formulaire, verrouillé, ne pourrait pas
        enregistrer — du quota dépensé pour rien (ADR 0004, coût borné).
      -->
      <div class="mb-3">
        <TranslateEntryButton
          :form-locale="form.locale"
          :is-translating="isTranslating"
          :disabled="!hasProseToTranslate || isSubmitting || isLocked"
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
          :disabled="isSubmitting || isLocked"
          :aria-describedby="lockedHintId"
        >
          {{ t('admin.about.save') }}
        </button>
        <button
          v-if="isEditing"
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
      class="text-danger mb-0"
      role="alert"
    >
      {{ t('admin.about.loadError') }}
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
        @save="saveOrder"
        @cancel="resetOrder"
      />

      <div class="table-responsive">
        <table class="table table-dark align-middle mb-0">
          <thead>
            <tr>
              <th scope="col">
                <span class="visually-hidden">{{ t('admin.order.columnHeader') }}</span>
              </th>
              <th scope="col">
                {{ t('admin.about.contentLabel') }}
              </th>
              <th scope="col">
                {{ t('admin.about.siteCard.iconKeyLabel') }}
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
                    <span class="text-body-secondary">{{ t('admin.order.missingTranslation') }}</span>
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
              <td class="text-nowrap">
                {{ firstEntry(row, SUPPORTED_LOCALES)?.iconKey ?? '—' }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>
