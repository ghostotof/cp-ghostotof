<script setup lang="ts">
withDefaults(
  defineProps<{
    modelValue: string
    label: string
    id: string
    error?: string
    required?: boolean
    rows?: number
  }>(),
  {
    error: undefined,
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
      class="form-control"
      :rows="rows"
      :value="modelValue"
      :required="required"
      @input="$emit('update:modelValue', ($event.target as HTMLTextAreaElement).value)"
    />
    <p
      v-if="error"
      class="text-danger small mb-0 mt-1"
      role="alert"
    >
      {{ error }}
    </p>
  </div>
</template>
