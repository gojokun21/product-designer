<script setup>
import { computed } from 'vue';
import { useWizardStore } from '../../stores/wizard.js';

const wizard = useWizardStore();
defineProps({
  open: { type: Boolean, default: false },
  // Saving + submitting to the cart is under way.
  adding: { type: Boolean, default: false },
});
const emit = defineEmits(['save', 'add-to-cart']);
const i18n = computed(() => wizard.i18n);
// Variația aleasă în pasul 2.5: poza ei arată imprimeul la mărimea plătită.
const variation = computed(() => (wizard.variationId ? wizard.matchedVariation : null));
const thumb = computed(() => (variation.value && variation.value.image_url) || wizard.productThumb);
const price = computed(() => (variation.value && variation.value.price_html) || wizard.productPrice);
// The price covers one side; a saved design on both sides adds the print
// size's second-side price (the cart applies it).
const extraNote = computed(() => {
  const cfg = wizard.designerConfig;
  const product = cfg && cfg.product;
  const size = product && product.print_sizes && product.print_size ? product.print_sizes.sizes[product.print_size] : null;
  if (!size || !size.second_side_price_text || !wizard.previewUrl || !wizard.previewBackUrl) {
    return '';
  }
  const tpl = cfg.i18n && cfg.i18n.secondSideOn;
  return tpl ? tpl.replace('%s', size.second_side_price_text) : '';
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
      <p v-if="extraNote" class="pd-summary__note pd-summary__note--extra">{{ extraNote }}</p>
    </div>

    <div class="pd-summary__actions">
      <button type="button" class="pd-btn-secondary pd-save" @click="emit('save')">Salvează designul</button>
      <button type="button" class="pd-add-to-cart-final" :disabled="!wizard.canAddToCart || adding" @click="emit('add-to-cart')">
        {{ adding ? i18n.addingToCart || 'Se adaugă în coș...' : i18n.addToCart || 'Adaugă în coș' }}
      </button>
    </div>
  </aside>
</template>
