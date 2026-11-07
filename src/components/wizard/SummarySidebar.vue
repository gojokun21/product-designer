<script setup>
import { computed, inject } from 'vue';
import { useWizardStore } from '../../stores/wizard.js';
import { printPrice, formatPrice, PRINT_SIDES } from '../../composables/usePrintPrice.js';

const wizard = useWizardStore();
// Rendered in EditorShell's summary slot, so the live editor is injectable.
const fabric = inject('pdFabric', null);
defineProps({
  open: { type: Boolean, default: false },
  // Saving + submitting to the cart is under way.
  adding: { type: Boolean, default: false },
});
const emit = defineEmits(['save', 'add-to-cart']);
const i18n = computed(() => wizard.i18n);
const editorI18n = computed(() => (wizard.designerConfig && wizard.designerConfig.i18n) || {});
// Variația aleasă în pasul 2.5 (mărime + culoare).
const variation = computed(() => (wizard.variationId ? wizard.matchedVariation : null));
const thumb = computed(() => (variation.value && variation.value.image_url) || wizard.productThumb);
const price = computed(() => (variation.value && variation.value.price_html) || wizard.productPrice);

// Live breakdown of the print sizes on the designed sides and the total — the
// same rule the cart charges by (usePrintPrice.js mirrors the PHP).
const printSizes = computed(() => {
  const ps = wizard.designerConfig && wizard.designerConfig.product && wizard.designerConfig.product.print_sizes;
  return ps && ps.sizes && Number(ps.zone_width_cm) > 0 ? ps : null;
});
const breakdown = computed(() => {
  if (!printSizes.value || !fabric) {
    return null;
  }
  const sizes = {};
  PRINT_SIDES.forEach((side) => {
    const size = fabric.state.printSizes[side];
    if (fabric.state.sideCounts[side] > 0 && size) {
      sizes[side] = size.slug;
    }
  });
  const result = printPrice(printSizes.value, sizes);
  const t = editorI18n.value;
  const currency = printSizes.value.currency;
  const lines = PRINT_SIDES.filter((side) => result.sides[side]).map((side) => {
    const s = result.sides[side];
    return {
      side,
      label: (side === 'back' ? t.printBack || 'Imprimeu spate' : t.printFront || 'Imprimeu față') + ': ' + s.label,
      amount: s.amount > 0 ? '+' + formatPrice(s.amount, currency) : t.printIncluded || 'inclus',
    };
  });
  const base = variation.value ? Number(variation.value.price) || 0 : 0;
  return {
    lines,
    total: base > 0 && result.extra > 0 ? formatPrice(base + result.extra, currency) : '',
  };
});
</script>

<template>
  <aside class="pd-summary" :class="{ 'is-open': open }">
    <h3 class="pd-panel-title">{{ i18n.orderTitle || 'Comanda ta' }}</h3>
    <div class="pd-summary__product">
      <img v-if="thumb" class="pd-summary__thumb" :src="thumb" alt="" />
      <div class="pd-summary__name">{{ wizard.productName }}</div>
      <div class="pd-summary__price" v-html="price"></div>
      <p v-if="variation && variation.description" class="pd-summary__note">{{ variation.description }}</p>
      <ul v-if="breakdown && breakdown.lines.length" class="pd-summary__print">
        <li v-for="l in breakdown.lines" :key="l.side" class="pd-summary__print-line">
          <span>{{ l.label }}</span>
          <span class="pd-summary__print-amount">{{ l.amount }}</span>
        </li>
        <li v-if="breakdown.total" class="pd-summary__print-line pd-summary__print-line--total">
          <span>{{ editorI18n.printTotal || 'Total' }}</span>
          <span class="pd-summary__print-amount">{{ breakdown.total }}</span>
        </li>
      </ul>
    </div>

    <div class="pd-summary__actions">
      <button type="button" class="pd-btn-secondary pd-save" @click="emit('save')">Salvează designul</button>
      <button type="button" class="pd-add-to-cart-final" :disabled="!wizard.canAddToCart || adding" @click="emit('add-to-cart')">
        {{ adding ? i18n.addingToCart || 'Se adaugă în coș...' : i18n.addToCart || 'Adaugă în coș' }}
      </button>
    </div>
  </aside>
</template>
