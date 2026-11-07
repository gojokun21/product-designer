<script setup>
import { inject, computed } from 'vue';

const fabric = inject('pdFabric');
const config = inject('pdConfig');

const i18n = computed(() => config.value.i18n || {});
const bothSides = computed(() => fabric.state.availableSides.length === 2);

const tabs = computed(() => [
  { side: 'front', label: i18n.value.sideFront || 'Față' },
  { side: 'back', label: i18n.value.sideBack || 'Spate' },
]);
</script>

<template>
  <div class="pd-side-tabs" role="tablist" :hidden="!bothSides">
    <button
      v-for="t in tabs"
      :key="t.side"
      type="button"
      class="pd-side-tab"
      :class="{ 'is-active': fabric.state.activeSide === t.side }"
      :disabled="fabric.state.availableSides.indexOf(t.side) === -1"
      role="tab"
      :aria-selected="fabric.state.activeSide === t.side ? 'true' : 'false'"
      @click="fabric.setActiveSide(t.side)"
    >
      {{ t.label }}
      <span class="pd-side-tab__count" :hidden="fabric.state.sideCounts[t.side] <= 0">{{
        fabric.state.sideCounts[t.side]
      }}</span>
    </button>
  </div>
</template>
