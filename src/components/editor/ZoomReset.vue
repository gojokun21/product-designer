<script setup>
import { inject } from 'vue';
import PdIcon from './PdIcon.vue';
const fabric = inject('pdFabric');
</script>

<template>
  <button
    type="button"
    class="pd-zoom-reset"
    :hidden="!fabric.state.zoomVisible"
    aria-label="Resetează zoom"
    title="Resetează zoom"
    @click="fabric.resetZoom()"
  >
    <PdIcon name="fit" :size="18" />
  </button>
</template>
