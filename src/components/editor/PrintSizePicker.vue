<script setup>
import { inject, computed } from 'vue';
import { sizeAmount, formatPrice } from '../../composables/usePrintPrice.js';

/**
 * Print size of the side being edited: [Mic | Mediu | Mare], each with what it
 * adds to the price right now — the back counts as a second print once the
 * front has a design (see usePrintPrice.js). Lives in the stage dock.
 */
const fabric = inject('pdFabric');
const config = inject('pdConfig');

const i18n = computed(() => config.value.i18n || {});
const printSizes = computed(() => {
  const ps = config.value.product && config.value.product.print_sizes;
  return ps && ps.sizes && Number(ps.zone_width_cm) > 0 ? ps : null;
});

const side = computed(() => fabric.state.activeSide);
const otherSidePrinted = computed(() => fabric.state.sideCounts[side.value === 'front' ? 'back' : 'front'] > 0);
const current = computed(() => {
  const size = fabric.state.printSizes[side.value];
  return size ? size.slug : '';
});

const cm = (n) => String(n).replace('.', ',');
const options = computed(() => {
  const ps = printSizes.value;
  if (!ps) {
    return [];
  }
  return Object.keys(ps.sizes).map((key) => {
    const size = ps.sizes[key];
    const amount = sizeAmount(size, side.value, otherSidePrinted.value);
    return {
      key,
      label: size.label || key,
      dims: cm(size.w) + ' × ' + cm(size.h) + ' ' + (i18n.value.unitCm || 'cm'),
      price: amount > 0 ? '+' + formatPrice(amount, ps.currency) : i18n.value.printIncluded || 'inclus',
    };
  });
});

const ariaLabel = computed(() => {
  const name = side.value === 'back' ? i18n.value.sideBack || 'Spate' : i18n.value.sideFront || 'Față';
  return (i18n.value.printSize || 'Mărime imprimeu') + ' — ' + name;
});

function pick(key) {
  fabric.setPrintSize(side.value, key);
}
</script>

<template>
  <!-- One size leaves nothing to choose; the frame chip still names it. -->
  <div v-if="options.length > 1" class="pd-size-picker" role="radiogroup" :aria-label="ariaLabel">
    <button
      v-for="o in options"
      :key="o.key"
      type="button"
      class="pd-size-opt"
      :class="{ 'is-active': o.key === current }"
      role="radio"
      :aria-checked="o.key === current ? 'true' : 'false'"
      :title="o.label + ' · ' + o.dims"
      @click="pick(o.key)"
    >
      <span class="pd-size-opt__name">{{ o.label }}</span>
      <span class="pd-size-opt__price">{{ o.price }}</span>
    </button>
  </div>
</template>
