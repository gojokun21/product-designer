<script setup>
import { inject, computed } from 'vue';
import PdIcon from './PdIcon.vue';

const fabric = inject('pdFabric');
const config = inject('pdConfig');

// Fără sheet-ul de sumar (modalul de pe pagina de produs) butonul "Comandă"
// n-ar deschide nimic — acolo comanda se face din pagina de produs, nu din editor.
defineProps({
  showSummary: { type: Boolean, default: false },
});
const emit = defineEmits(['add-image', 'toggle-sheet', 'toggle-side']);

const i18n = computed(() => config.value.i18n || {});
const bothSides = computed(() => fabric.state.availableSides.length === 2);
const sideLabel = computed(() =>
  fabric.state.activeSide === 'front' ? i18n.value.sideBack || 'Spate' : i18n.value.sideFront || 'Față'
);
</script>

<template>
  <nav class="pd-bottom-bar" role="toolbar" aria-label="Editor — acțiuni">
    <button type="button" class="pd-bb-btn pd-bb-add-text" @click="fabric.addText()">
      <span class="pd-bb-btn__icon"><PdIcon name="text" :size="22" /></span>
      <span class="pd-bb-btn__label">Text</span>
    </button>
    <button type="button" class="pd-bb-btn pd-bb-add-image" @click="emit('add-image')">
      <span class="pd-bb-btn__icon"><PdIcon name="image" :size="22" /></span>
      <span class="pd-bb-btn__label">Imagine</span>
    </button>
    <button
      type="button"
      class="pd-bb-btn pd-bb-delete"
      :disabled="!fabric.state.hasSelection"
      @click="fabric.deleteSelected()"
    >
      <span class="pd-bb-btn__icon"><PdIcon name="trash" :size="22" /></span>
      <span class="pd-bb-btn__label">Șterge</span>
    </button>
    <button type="button" class="pd-bb-btn pd-bb-toggle-side" :hidden="!bothSides" @click="emit('toggle-side')">
      <span class="pd-bb-btn__icon"><PdIcon name="swap" :size="22" /></span>
      <span class="pd-bb-btn__label">{{ sideLabel }}</span>
    </button>
    <button
      v-if="showSummary"
      type="button"
      class="pd-bb-btn pd-bb-summary"
      @click="emit('toggle-sheet', 'summary')"
    >
      <span class="pd-bb-btn__icon"><PdIcon name="cart" :size="22" /></span>
      <span class="pd-bb-btn__label">Comandă</span>
    </button>
  </nav>
</template>
