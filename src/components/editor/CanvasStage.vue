<script setup>
import { ref, inject, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import ZoomReset from './ZoomReset.vue';
import SideTabs from './SideTabs.vue';
import ColorSwatches from './ColorSwatches.vue';

const fabric = inject('pdFabric');
const config = inject('pdConfig', null);
const emit = defineEmits(['ready', 'wrap-click']);

const i18n = computed(() => (config && config.value && config.value.i18n) || {});
const oobText = computed(() => {
  if (fabric.state.printSize && i18n.value.outOfFrame) {
    return i18n.value.outOfFrame;
  }
  return i18n.value.outOfBounds || 'O parte din design iese din zona imprimabilă și nu va fi imprimată.';
});

const cm = (n) => String(n).replace('.', ',');
// „Imprimeu mediu · 21 × 30 cm" — the frame of the side being edited.
const frameLabel = computed(() => {
  const size = fabric.state.printSize;
  const frame = fabric.state.frameCm;
  return size && frame ? size.label + ' · ' + cm(frame.w) + ' × ' + cm(frame.h) + ' ' + (i18n.value.unitCm || 'cm') : '';
});
// The variation price covers one side; say what the second one costs, and
// highlight it once both sides carry a design.
const bothSidesUsed = computed(() => fabric.state.sideCounts.front > 0 && fabric.state.sideCounts.back > 0);
const secondSideText = computed(() => {
  const size = fabric.state.printSize;
  if (!size || !size.second_side_price_text || fabric.state.availableSides.length < 2) {
    return '';
  }
  const tpl = bothSidesUsed.value ? i18n.value.secondSideOn : i18n.value.secondSideHint;
  return tpl ? tpl.replace('%s', size.second_side_price_text) : '';
});
// Until the customer first moves it, say that the frame can be moved.
const moveHint = computed(() => (fabric.state.moveFrameHint && i18n.value.moveFrame) || '');

// Bottom dock: Față / Spate and the product colours, when there is a choice.
const bothSides = computed(() => fabric.state.availableSides.length === 2);
const hasColors = computed(() => {
  const product = config && config.value && config.value.product;
  return !!(product && product.color_options && product.color_options.length);
});

// Status toast: a message shows for a few seconds; progress messages
// („Se încarcă...") stay until the next one replaces them.
const toast = ref('');
let toastTimer = null;
watch(
  () => fabric.state.status,
  (msg) => {
    clearTimeout(toastTimer);
    toast.value = msg || '';
    if (msg && !/(\.\.\.|…)$/.test(msg)) {
      toastTimer = setTimeout(() => {
        toast.value = '';
      }, 4000);
    }
  }
);

const wrapEl = ref(null);
const frontEl = ref(null);
const backEl = ref(null);

onMounted(() => {
  fabric.setElements({ wrap: wrapEl.value, front: frontEl.value, back: backEl.value });
  emit('ready');
});
onBeforeUnmount(() => clearTimeout(toastTimer));

// A click on the empty workspace around the product deselects, and closes the
// summary sheet (mobile).
function onWrapClick(e) {
  if (e.target === wrapEl.value) {
    fabric.clearSelection();
    emit('wrap-click');
  }
}
</script>

<template>
  <div class="pd-canvas-wrap" ref="wrapEl" @click="onWrapClick">
    <canvas class="pd-canvas pd-canvas--front" data-side="front" ref="frontEl"></canvas>
    <canvas class="pd-canvas pd-canvas--back" data-side="back" ref="backEl" hidden></canvas>
    <ZoomReset />
    <!-- One column at the top: print-size info, then the out-of-frame warning
         (the dock owns the bottom edge). -->
    <div v-if="frameLabel || secondSideText || fabric.state.outOfBounds" class="pd-canvas-notes">
      <div v-if="frameLabel || secondSideText" class="pd-print-info">
        <span v-if="frameLabel" class="pd-print-info__frame">
          <span class="pd-print-info__glyph" aria-hidden="true"></span>{{ frameLabel }}
        </span>
        <span v-if="secondSideText" class="pd-print-info__extra" :class="{ 'is-on': bothSidesUsed }">{{ secondSideText }}</span>
        <span v-if="moveHint" class="pd-print-info__move">{{ moveHint }}</span>
      </div>
      <div v-if="fabric.state.outOfBounds" class="pd-oob-warning" role="alert">
        <span class="pd-oob-warning__icon" aria-hidden="true">!</span>
        <span>{{ oobText }}</span>
      </div>
    </div>
    <div v-if="bothSides || hasColors" class="pd-stage-dock" :class="{ 'has-colors': hasColors }">
      <SideTabs />
      <span v-if="bothSides && hasColors" class="pd-stage-dock__sep" aria-hidden="true"></span>
      <ColorSwatches />
    </div>
    <div class="pd-status" aria-live="polite">{{ toast }}</div>
  </div>
</template>
