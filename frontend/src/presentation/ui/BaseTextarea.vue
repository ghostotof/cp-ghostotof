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
    required?: boolean
    rows?: number
  }>(),
  {
    required: false,
    rows: 4,
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
    <textarea
      :id="id"
      v-bind="$attrs"
      class="form-control"
      :rows="rows"
      :value="modelValue"
      :required="required"
      @input="$emit('update:modelValue', ($event.target as HTMLTextAreaElement).value)"
    />
  </div>
</template>
