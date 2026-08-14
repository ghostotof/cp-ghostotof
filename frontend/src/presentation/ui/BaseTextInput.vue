<script setup lang="ts">
withDefaults(
  defineProps<{
    modelValue: string
    label: string
    id: string
    type?: string
    error?: string
    required?: boolean
  }>(),
  {
    type: 'text',
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
    <input
      :id="id"
      :type="type"
      class="form-control"
      :value="modelValue"
      :required="required"
      @input="$emit('update:modelValue', ($event.target as HTMLInputElement).value)"
    >
    <p
      v-if="error"
      class="text-danger small mb-0 mt-1"
      role="alert"
    >
      {{ error }}
    </p>
  </div>
</template>
