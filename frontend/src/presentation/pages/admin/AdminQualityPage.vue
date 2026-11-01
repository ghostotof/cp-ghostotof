<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useUnsavedOrderGuard } from '../../../application/admin/shared/useUnsavedOrderGuard'
import AdminQualityPrinciplesSection from './AdminQualityPrinciplesSection.vue'
import AdminQualityTraitsSection from './AdminQualityTraitsSection.vue'

/**
 * Deux contenus ordonnés indépendamment sur une même page — les principes et
 * les traits de qualité —, chacun dans sa section (`AdminQualityPrinciplesSection`,
 * `AdminQualityTraitsSection`) avec son tableau groupé, son brouillon d'ordre et
 * son formulaire. La page garde ce que les deux partagent : le verrou d'ordre et
 * la garde de sortie. Le découpage suit celui d'« À propos » (`AdminAboutPage`).
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
 * dans les sections.
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
 * `isLocked`** (comme chaque section d'« À propos »). La prop n'est à jour qu'après
 * l'`emit` de la section et le rendu de la page : un « translate » émis entre un
 * déplacement de l'autre section et ce rendu n'est pas refusé. Limite acceptée
 * (une micro-tâche, qu'aucun geste de l'utilisateur ne traverse).
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

    <AdminQualityPrinciplesSection
      :is-locked="isAnyOrderDirty"
      :locked-hint-id="lockedHintId"
      @order-dirty-change="isPrincipleOrderDirty = $event"
    />
    <AdminQualityTraitsSection
      :is-locked="isAnyOrderDirty"
      :locked-hint-id="lockedHintId"
      @order-dirty-change="isTraitOrderDirty = $event"
    />
  </div>
</template>
