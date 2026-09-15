<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { AdminOrderErrorReason } from '../../../domain/admin/shared/errors/AdminOrderError'
import type { OrderMove } from '../../../application/admin/shared/useOrderHandleFocus'

/**
 * Barre d'action du brouillon d'ordre (spec 0004, D6) : statut, Annuler,
 * Enregistrer l'ordre, et l'éventuelle erreur d'enregistrement. Ne connaît
 * ni le tableau ni la ressource — la page fournit `isDirty`/`isSaving`/
 * `errorReason` (venant de `useOrderDraft`) et écoute `save`/`cancel`.
 *
 * Elle porte aussi la **région live du tableau** (#170 F3) : l'annonce du
 * déplacement clavier (`lastMove`, venant de `useOrderHandleFocus`) est lue
 * ici, dans un nœud stable, et non depuis la ligne déplacée, re-parentée au
 * même cycle de rendu. La région existe dès le montage, vide : une région
 * créée au moment d'annoncer n'annonce rien.
 */
const props = defineProps<{
  isDirty: boolean
  isSaving: boolean
  errorReason: AdminOrderErrorReason | null
  lastMove?: OrderMove | null
}>()

const emit = defineEmits<{ save: []; cancel: [] }>()

const { t } = useI18n()

const statusKey = computed(() => (props.isDirty ? 'admin.order.status.dirty' : 'admin.order.status.clean'))

// Un enregistrement en cours verrouille les deux boutons, pas seulement
// « Enregistrer » : « Annuler » pendant un save() en vol abandonnerait un
// brouillon que le serveur est en train d'appliquer, et un second clic sur
// « Enregistrer » redéclencherait un reorder (useOrderDraft s'en protège
// aussi, mais l'UI ne doit pas laisser croire que c'est possible).
const saveDisabled = computed(() => !props.isDirty || props.isSaving)

// « Annuler » reste actif tant qu'une erreur est posée (#170 F4) : après un
// 422 obsolète, `useOrderDraft` a resynchronisé le brouillon — plus
// « modifié » — mais l'alerte, elle, ne s'efface que par `reset()`.
const cancelDisabled = computed(() => (!props.isDirty && null === props.errorReason) || props.isSaving)

const announcement = computed(() =>
  props.lastMove ? t('admin.order.moved', { position: props.lastMove.position, count: props.lastMove.count }) : '',
)
</script>

<template>
  <div class="d-flex flex-wrap align-items-center gap-3">
    <span class="fw-semibold">{{ t(statusKey) }}</span>
    <button
      type="button"
      class="btn btn-outline-light"
      :disabled="cancelDisabled"
      @click="emit('cancel')"
    >
      {{ t('admin.order.cancel') }}
    </button>
    <button
      type="button"
      class="btn btn-gradient"
      :disabled="saveDisabled"
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
    <span
      role="status"
      class="visually-hidden"
    >{{ announcement }}</span>
  </div>
</template>
