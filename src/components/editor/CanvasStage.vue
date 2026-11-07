<script setup>
import { ref, inject, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import ZoomReset from './ZoomReset.vue';
import SideTabs from './SideTabs.vue';
import PrintSizePicker from './PrintSizePicker.vue';
import ColorSwatches from './ColorSwatches.vue';
import { printPrice, formatPrice, PRINT_SIDES } from '../../composables/usePrintPrice.js';

const fabric = inject('pdFabric');
const config = inject('pdConfig', null);
const emit = defineEmits(['ready', 'wrap-click']);

const i18n = computed(() => (config && config.value && config.value.i18n) || {});
const product = computed(() => (config && config.value && config.value.product) || {});
const printSizes = computed(() => {
  const ps = product.value.print_sizes;
  return ps && ps.sizes && Number(ps.zone_width_cm) > 0 ? ps : null;
});
const activeSize = computed(() => fabric.state.printSizes[fabric.state.activeSide]);

const oobText = computed(() => {
  if (activeSize.value && i18n.value.outOfFrame) {
    return i18n.value.outOfFrame;
  }
  return i18n.value.outOfBounds || 'O parte din design iese din zona imprimabilă și nu va fi imprimată.';
});

// Bottom dock: Față / Spate, the print size and the product colours, when
// there is a choice.
const bothSides = computed(() => fabric.state.availableSides.length === 2);
const hasSizes = computed(() => !!printSizes.value && Object.keys(printSizes.value.sizes).length > 1);
const hasColors = computed(() => !!(product.value.color_options && product.value.color_options.length));

const cm = (n) => String(n).replace('.', ',');
// „Spate · Mare · 28 × 40 cm" — the frame of the side being edited.
const frameLabel = computed(() => {
  const size = activeSize.value;
  const frame = fabric.state.frameCm;
  if (!size || !frame) {
    return '';
  }
  const parts = [size.label, cm(frame.w) + ' × ' + cm(frame.h) + ' ' + (i18n.value.unitCm || 'cm')];
  if (bothSides.value) {
    parts.unshift(fabric.state.activeSide === 'back' ? i18n.value.sideBack || 'Spate' : i18n.value.sideFront || 'Față');
  }
  return parts.join(' · ');
});
// What the print sizes of the designed sides add to the price, live — the
// same rule the cart charges by.
const extraText = computed(() => {
  if (!printSizes.value) {
    return '';
  }
  const sizes = {};
  PRINT_SIDES.forEach((side) => {
    const size = fabric.state.printSizes[side];
    if (fabric.state.sideCounts[side] > 0 && size) {
      sizes[side] = size.slug;
    }
  });
  const extra = printPrice(printSizes.value, sizes).extra;
  const tpl = i18n.value.printExtra || 'Imprimeu: +%s la preț';
  return extra > 0 ? tpl.replace('%s', formatPrice(extra, printSizes.value.currency)) : '';
});
// Until the customer first moves it, say that the frame can be moved.
const moveHint = computed(() => (fabric.state.moveFrameHint && i18n.value.moveFrame) || '');

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
    <div v-if="frameLabel || extraText || fabric.state.outOfBounds" class="pd-canvas-notes">
      <div v-if="frameLabel || extraText" class="pd-print-info">
        <span v-if="frameLabel" class="pd-print-info__frame">
          <span class="pd-print-info__glyph" aria-hidden="true"></span>{{ frameLabel }}
        </span>
        <span v-if="extraText" class="pd-print-info__extra is-on">{{ extraText }}</span>
        <span v-if="moveHint" class="pd-print-info__move">{{ moveHint }}</span>
      </div>
      <div v-if="fabric.state.outOfBounds" class="pd-oob-warning" role="alert">
        <span class="pd-oob-warning__icon" aria-hidden="true">!</span>
        <span>{{ oobText }}</span>
      </div>
    </div>
    <div
      v-if="bothSides || hasSizes || hasColors"
      class="pd-stage-dock"
      :class="{ 'has-colors': hasColors, 'has-sizes': hasSizes }"
    >
      <SideTabs />
      <span
        v-if="bothSides && (hasSizes || hasColors)"
        class="pd-stage-dock__sep pd-stage-dock__sep--sides"
        aria-hidden="true"
      ></span>
      <PrintSizePicker />
      <span v-if="hasSizes && hasColors" class="pd-stage-dock__sep" aria-hidden="true"></span>
      <ColorSwatches />
    </div>
    <div class="pd-status" aria-live="polite">{{ toast }}</div>
  </div>
</template>
