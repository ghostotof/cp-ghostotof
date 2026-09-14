<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { AdminOrderErrorReason } from '../../../domain/admin/shared/errors/AdminOrderError'

/**
 * Barre d'action du brouillon d'ordre (spec 0004, D6) : statut, Annuler,
 * Enregistrer l'ordre, et l'éventuelle erreur d'enregistrement. Ne connaît
 * ni le tableau ni la ressource — la page fournit `isDirty`/`isSaving`/
 * `errorReason` (venant de `useOrderDraft`) et écoute `save`/`cancel`.
 */
const props = defineProps<{
  isDirty: boolean
  isSaving: boolean
  errorReason: AdminOrderErrorReason | null
}>()

const emit = defineEmits<{ save: []; cancel: [] }>()

const { t } = useI18n()

const statusKey = computed(() => (props.isDirty ? 'admin.order.status.dirty' : 'admin.order.status.clean'))

// Un enregistrement en cours verrouille les deux boutons, pas seulement
// « Enregistrer » : « Annuler » pendant un save() en vol abandonnerait un
// brouillon que le serveur est en train d'appliquer, et un second clic sur
// « Enregistrer » redéclencherait un reorder (useOrderDraft s'en protège
// aussi, mais l'UI ne doit pas laisser croire que c'est possible).
const actionsDisabled = computed(() => !props.isDirty || props.isSaving)
</script>

<template>
  <div class="d-flex flex-wrap align-items-center gap-3">
    <span class="fw-semibold">{{ t(statusKey) }}</span>
    <button
      type="button"
      class="btn btn-outline-light"
      :disabled="actionsDisabled"
      @click="emit('cancel')"
    >
      {{ t('admin.order.cancel') }}
    </button>
    <button
      type="button"
      class="btn btn-gradient"
      :disabled="actionsDisabled"
      :aria-busy="isSaving ? 'true' : 'false'"
      @click="emit('save')"
    >
      {{ t('admin.order.save') }}
    </button>
    <p
      v-if="errorReason"
      role="alert"
      class="text-danger mb-0"
    >
      {{ t(`admin.order.errors.${errorReason}`) }}
    </p>
  </div>
</template>
