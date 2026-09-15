<script setup lang="ts">
import { useId } from 'vue'
import { useI18n } from 'vue-i18n'

/**
 * Poignée de déplacement d'une ligne du tableau ordonné (spec 0004, D7) :
 * un vrai `<button>`, jamais un élément non focusable — ↑/↓ sont
 * l'alternative clavier obligatoire au glisser-déposer, pas une commodité.
 * Le déplacement lui-même (glisser-déposer ou clavier) est décidé par le
 * parent via l'événement `move` ; cette poignée ne connaît que sa position
 * et la taille du tableau, ce qui suffit à borner ↑/↓.
 *
 * L'usage des flèches est **décrit** (`aria-describedby` vers une aide
 * rendue, issue #170 F2) et non ajouté au nom : le nom reste court, la
 * description vient après pour qui la demande. Entrée et Espace n'ont
 * volontairement aucun effet — un bouton qui « fait quelque chose » au clic
 * sans dire quoi serait pire que rien.
 *
 * La poignée n'annonce plus le déplacement elle-même : une région live qui
 * vit dans la ligne déplacée est re-parentée au même cycle de rendu et peut
 * être avalée par le lecteur d'écran (#170 F3). L'annonce est portée par
 * `OrderToolbar`, une région stable par tableau, alimentée par
 * `useOrderHandleFocus`.
 */
const props = defineProps<{
  index: number
  count: number
  label: string
}>()

const emit = defineEmits<{ move: [from: number, to: number] }>()

const { t } = useI18n()

const hintId = useId()

function moveBy(delta: number): void {
  const to = props.index + delta
  if (to < 0 || to >= props.count) {
    return
  }

  emit('move', props.index, to)
}
</script>

<template>
  <div class="d-inline-flex align-items-center gap-1">
    <button
      type="button"
      class="btn btn-sm btn-outline-light"
      :aria-label="t('admin.order.moveHandle', { label })"
      :aria-describedby="hintId"
      @keydown.up.prevent="moveBy(-1)"
      @keydown.down.prevent="moveBy(1)"
    >
      <span aria-hidden="true">⠿</span>
    </button>
    <span
      :id="hintId"
      class="visually-hidden"
    >{{ t('admin.order.handleHint') }}</span>
  </div>
</template>
