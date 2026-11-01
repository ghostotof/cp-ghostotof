<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAdminQualityPrinciples } from '../../../application/admin/quality/useAdminQualityPrinciples'
import { useAdminQualityTraits } from '../../../application/admin/quality/useAdminQualityTraits'
import { useUnsavedOrderGuard } from '../../../application/admin/shared/useUnsavedOrderGuard'
import AdminQualityEntrySection from './AdminQualityEntrySection.vue'
import {
  QUALITY_PRINCIPLE_FIELDS,
  QUALITY_TRAIT_FIELDS,
  type QualityEntryTexts,
} from './qualityEntrySection'

/**
 * Deux contenus ordonnés indépendamment sur une même page — les principes et
 * les traits de qualité —, chacun dans une instance de `AdminQualityEntrySection`
 * avec son tableau groupé, son brouillon d'ordre et son formulaire. La page
 * garde ce que les deux partagent : le verrou d'ordre et la garde de sortie, et
 * ce qui les distingue : la lecture de chaque contenu (son composable), la liste de
 * ses champs et ses textes, traduits ici avec des clés littérales.
 *
 * **Les tableaux affichent toutes les langues** (spec 0004, D8), une ligne par
 * groupe de traduction : l'ordre est celui du groupe et non celui d'une langue,
 * le régler en n'en regardant qu'une reviendrait à ignorer la moitié du contenu
 * déplacé. `list()` n'a donc aucun paramètre de locale.
 *
 * **La langue est un champ de formulaire, pas un état de page.** Chaque section
 * a son propre sélecteur, comme `AdminIncidentsPage.vue` : c'est la langue de
 * l'entrée en cours d'édition ou de création, et elle ne pilote rien d'autre que
 * ce formulaire et son assistant. Un sélecteur unique de page, partagé par deux
 * formulaires, laisserait un geste fait dans un panneau (« Modifier » sur une
 * entrée anglaise, « Créer la version EN », une traduction) réécrire la langue
 * de l'entrée en cours d'édition dans *l'autre* panneau, sans avertissement —
 * d'autant plus silencieusement que, selon D8, changer de langue ne recharge
 * aucune liste. Le détail (assistant, « Modifier » sur une autre langue) est
 * dans la section.
 *
 * **Verrouillage (D6) : global à la page.** Chaque section annonce son brouillon
 * d'ordre par `orderDirtyChange` ; la page en fait la somme, rend l'aide une
 * seule fois et la redistribue par `isLocked`/`lockedHintId`. Un seul brouillon
 * modifié, où qu'il soit, désactive toutes les mutations des deux panneaux. Un
 * verrou par tableau serait plus fin — une création de trait ne recharge que les
 * traits — mais afficherait deux états désactivés et deux aides pour un seul
 * geste en cours : un état, une aide, une règle à retenir.
 *
 * **Une section lit son propre brouillon tout de suite, celui de l'autre par
 * `isLocked`** : la prop n'est à jour qu'après l'`emit` et le rendu de la page, une limite acceptée (une micro-tâche,
 * qu'aucun geste de l'utilisateur ne traverse) que la section cite et que `.claude/rules/frontend-backoffice.md` écrit une
 * fois (« The refusal is written once »).
 *
 * La page ne possède aucune logique d'ordre : elle ne connaît que deux booléens.
 */

const { t } = useI18n()

/**
 * L'aide est **rendue visible**, jamais portée par un `title` : Bootstrap pose
 * `pointer-events: none` sur `.btn:disabled`, donc l'infobulle d'un bouton
 * désactivé ne s'affiche jamais au survol. Les boutons la désignent par
 * `aria-describedby` — et seulement quand elle existe, sinon la référence
 * pendante serait elle-même une erreur d'accessibilité.
 */
const LOCKED_HINT_ID = 'admin-order-locked-hint'

/**
 * La lecture de chaque contenu : le retour du composable, tel quel, à une clé près. La section dépend de l'interface
 * `QualityEntrySource` (elle ne connaît ni les deux composables ni leur injection) et y lit la liste sous `entries`, là où
 * chaque composable la nomme à sa façon (`principles`, `traits`) : c'est la seule différence, ajoutée ici. Chaque composable
 * est appelé ici, une fois ; ses refs restent dans l'objet (la page ne les déballe pas : seule la section les lit).
 */
const principleReading = useAdminQualityPrinciples()
const principleSource = { ...principleReading, entries: principleReading.principles }
const traitReading = useAdminQualityTraits()
const traitSource = { ...traitReading, entries: traitReading.traits }

/**
 * Les libellés des champs, à part de la liste des champs (une constante) : ils suivent la langue de l'interface. Une clé
 * littérale chacun, que `no-missing-keys` vérifie ; le type refuse un libellé manquant ou en trop.
 */
const principleLabels = computed(() => ({
  title: t('admin.quality.principle.titleLabel'),
  description: t('admin.quality.principle.descriptionLabel'),
  iconKey: t('admin.quality.principle.iconKeyLabel'),
}))
const traitLabels = computed(() => ({ label: t('admin.quality.trait.labelLabel') }))

const principleTexts = computed<QualityEntryTexts>(() => ({
  create: t('admin.quality.principle.create'),
  edit: t('admin.quality.principle.edit'),
  listTitle: t('admin.quality.principle.listTitle'),
  editAction: t('admin.quality.principle.editAction'),
  empty: t('admin.quality.principle.empty'),
  confirmDelete: (title) => t('admin.quality.principle.confirmDelete', { title }),
}))
const traitTexts = computed<QualityEntryTexts>(() => ({
  create: t('admin.quality.trait.create'),
  edit: t('admin.quality.trait.edit'),
  listTitle: t('admin.quality.trait.listTitle'),
  editAction: t('admin.quality.trait.editAction'),
  empty: t('admin.quality.trait.empty'),
  confirmDelete: (label) => t('admin.quality.trait.confirmDelete', { label }),
}))

const isPrincipleOrderDirty = ref(false)
const isTraitOrderDirty = ref(false)

const isAnyOrderDirty = computed(() => isPrincipleOrderDirty.value || isTraitOrderDirty.value)
const lockedHintId = computed(() => (isAnyOrderDirty.value ? LOCKED_HINT_ID : undefined))

useUnsavedOrderGuard(isAnyOrderDirty)
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

    <AdminQualityEntrySection
      kind="principle"
      :source="principleSource"
      :fields="QUALITY_PRINCIPLE_FIELDS"
      :labels="principleLabels"
      title-field="title"
      :texts="principleTexts"
      :is-locked="isAnyOrderDirty"
      :locked-hint-id="lockedHintId"
      @order-dirty-change="isPrincipleOrderDirty = $event"
    />
    <AdminQualityEntrySection
      kind="trait"
      :source="traitSource"
      :fields="QUALITY_TRAIT_FIELDS"
      :labels="traitLabels"
      title-field="label"
      :texts="traitTexts"
      :is-locked="isAnyOrderDirty"
      :locked-hint-id="lockedHintId"
      @order-dirty-change="isTraitOrderDirty = $event"
    />
  </div>
</template>
