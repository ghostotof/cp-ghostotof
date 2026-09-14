<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'

/**
 * Poignée de déplacement d'une ligne du tableau ordonné (spec 0004, D7) :
 * un vrai `<button>`, jamais un élément non focusable — ↑/↓ sont
 * l'alternative clavier obligatoire au glisser-déposer, pas une commodité.
 * Le déplacement lui-même (glisser-déposer ou clavier) est décidé par le
 * parent via l'événement `move` ; cette poignée ne connaît que sa position
 * et la taille du tableau, ce qui suffit à borner ↑/↓ et à formuler
 * l'annonce.
 *
 * La zone `role="status"` annonce la nouvelle position après chaque
 * déplacement clavier : le glisser-déposer natif ne prévient jamais les
 * technologies d'assistance de lui-même, et un déplacement silencieux
 * laisserait un utilisateur de lecteur d'écran sans retour.
 */
const props = defineProps<{
  index: number
  count: number
  label: string
}>()

const emit = defineEmits<{ move: [from: number, to: number] }>()

const { t } = useI18n()

const announcement = ref('')

function moveBy(delta: number): void {
  const to = props.index + delta
  if (to < 0 || to >= props.count) {
    return
  }

  emit('move', props.index, to)
  announcement.value = t('admin.order.moved', { position: to + 1, count: props.count })
}
</script>

<template>
  <div class="d-inline-flex align-items-center gap-1">
    <button
      type="button"
      class="btn btn-sm btn-outline-light"
      :aria-label="t('admin.order.moveHandle', { label })"
      @keydown.up.prevent="moveBy(-1)"
      @keydown.down.prevent="moveBy(1)"
    >
      <span aria-hidden="true">⠿</span>
    </button>
    <span
      role="status"
      class="visually-hidden"
    >{{ announcement }}</span>
  </div>
</template>
