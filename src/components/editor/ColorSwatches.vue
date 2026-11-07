<script setup>
import { inject, computed } from 'vue';

const fabric = inject('pdFabric');
const config = inject('pdConfig');
// The constructor ties the colour to the ordered variation; it returns false
// for a colour that doesn't exist with the other options chosen.
const pickColor = inject('pdPickColor', null);

const options = computed(() => (config.value.product && config.value.product.color_options) || []);

// Fallback dot color from the term slug, used only when the backend didn't
// supply a real swatch hex (o.hex) for the color.
const SLUG_HEX = {
  alb: '#ffffff',
  white: '#ffffff',
  negru: '#111111',
  black: '#111111',
  gri: '#9aa0a6',
  grey: '#9aa0a6',
  gray: '#9aa0a6',
  rosu: '#e11d2a',
  red: '#e11d2a',
  albastru: '#1d4ed8',
  blue: '#1d4ed8',
  verde: '#16a34a',
  green: '#16a34a',
  galben: '#facc15',
  yellow: '#facc15',
  roz: '#ec4899',
  pink: '#ec4899',
  mov: '#7c3aed',
  purple: '#7c3aed',
  maro: '#7c4a1e',
  brown: '#7c4a1e',
  bej: '#e3d5b8',
  beige: '#e3d5b8',
  portocaliu: '#f97316',
  orange: '#f97316',
};
function dotColor(o) {
  return o.hex || SLUG_HEX[String(o.value).toLowerCase()] || '#cccccc';
}

function isActive(o) {
  return fabric.state.mockupFrontUrl === o.front_url;
}
function pick(o) {
  if (pickColor && pickColor(o) === false) {
    return;
  }
  fabric.swapMockups(o.front_url, o.back_url);
}
</script>

<template>
  <!-- Styled in designer.css, inside the stage dock. -->
  <div class="pd-color-swatches" v-if="options.length">
    <button
      v-for="o in options"
      :key="o.value"
      type="button"
      class="pd-color-swatch"
      :class="{ 'is-active': isActive(o) }"
      :title="o.label"
      :aria-pressed="isActive(o) ? 'true' : 'false'"
      @click="pick(o)"
    >
      <span class="pd-color-swatch__dot" :style="{ background: dotColor(o) }"></span>
      <span class="pd-color-swatch__label">{{ o.label }}</span>
    </button>
  </div>
</template>

