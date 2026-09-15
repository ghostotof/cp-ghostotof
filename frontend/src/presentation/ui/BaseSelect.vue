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
    options: readonly { value: string; label: string }[]
    required?: boolean
  }>(),
  {
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
    <select
      :id="id"
      v-bind="$attrs"
      class="form-select"
      :value="modelValue"
      :required="required"
      @change="$emit('update:modelValue', ($event.target as HTMLSelectElement).value)"
    >
      <option
        v-for="option in options"
        :key="option.value"
        :value="option.value"
      >
        {{ option.label }}
      </option>
    </select>
  </div>
</template>
