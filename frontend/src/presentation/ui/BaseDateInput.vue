<script setup lang="ts">
/**
 * Champ de date du backoffice. `<input type="date">` produit et consomme une
 * valeur au format ISO (AAAA-MM-JJ) quelle que soit la langue du navigateur,
 * qui n'affecte que l'affichage : c'est exactement le format attendu par le
 * contrat HTTP, aucune conversion n'est donc nécessaire de part et d'autre.
 */
withDefaults(
  defineProps<{
    modelValue: string
    label: string
    id: string
    required?: boolean
  }>(),
  { required: false },
)

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

function onInput(event: Event): void {
  emit('update:modelValue', (event.target as HTMLInputElement).value)
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
      type="date"
      class="form-control"
      :value="modelValue"
      :required="required"
      @input="onInput"
    >
  </div>
</template>
