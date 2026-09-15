<script setup lang="ts">
/**
 * Issue #164 : les attributs non déclarés (`aria-describedby` vers un texte
 * d'aide, `disabled`…) doivent atteindre le champ, pas le `<div>` racine.
 * Sans `inheritAttrs: false`, Vue les poserait sur le conteneur et
 * l'association champ → aide n'existerait pas pour un lecteur d'écran.
 */
defineOptions({ inheritAttrs: false })

withDefaults(
  defineProps<{
    modelValue: number
    label: string
    id: string
    required?: boolean
    step?: number
  }>(),
  {
    required: false,
    step: 0.1,
  },
)

const emit = defineEmits<{ 'update:modelValue': [value: number] }>()

function onInput(event: Event): void {
  const value = (event.target as HTMLInputElement).value
  emit('update:modelValue', '' === value ? 0 : Number(value))
}
</script>

<template>
  <div class="mb-3">
    <label
      :for="id"
      class="form-label text-body-secondary"
    >
      {{ label }}
    </label>
    <input
      :id="id"
      v-bind="$attrs"
      type="number"
      class="form-control"
      :value="modelValue"
      :step="step"
      :required="required"
      @input="onInput"
    >
  </div>
</template>
