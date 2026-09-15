<script setup lang="ts">
/**
 * Champ de date du backoffice. `<input type="date">` produit et consomme une
 * valeur au format ISO (AAAA-MM-JJ) quelle que soit la langue du navigateur,
 * qui n'affecte que l'affichage : c'est exactement le format attendu par le
 * contrat HTTP, aucune conversion n'est donc nécessaire de part et d'autre.
 */
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
      v-bind="$attrs"
      type="date"
      class="form-control"
      :value="modelValue"
      :required="required"
      @input="onInput"
    >
  </div>
</template>
