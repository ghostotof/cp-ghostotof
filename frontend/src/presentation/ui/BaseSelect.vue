<script setup lang="ts">
withDefaults(
  defineProps<{
    modelValue: string
    label: string
    id: string
    options: readonly { value: string; label: string }[]
    error?: string
    required?: boolean
  }>(),
  {
    error: undefined,
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
    <p
      v-if="error"
      class="text-danger small mb-0 mt-1"
      role="alert"
    >
      {{ error }}
    </p>
  </div>
</template>
