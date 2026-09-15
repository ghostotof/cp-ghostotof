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
    modelValue: string
    label: string
    id: string
    type?: string
    required?: boolean
  }>(),
  {
    type: 'text',
    required: false,
  },
)

defineEmits<{ 'update:modelValue': [value: string] }>()
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
      :type="type"
      class="form-control"
      :value="modelValue"
      :required="required"
      @input="$emit('update:modelValue', ($event.target as HTMLInputElement).value)"
    >
  </div>
</template>
