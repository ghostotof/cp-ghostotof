<script setup lang="ts">
withDefaults(
  defineProps<{
    modelValue: number
    label: string
    id: string
    error?: string
    required?: boolean
    step?: number
  }>(),
  {
    error: undefined,
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
      type="number"
      class="form-control"
      :value="modelValue"
      :step="step"
      :required="required"
      @input="onInput"
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
