<script setup>
import { computed } from 'vue';
import { useWizardStore } from '../../stores/wizard.js';

const wizard = useWizardStore();
const i18n = computed(() => wizard.i18n);
</script>

<template>
  <section class="pd-constructor__panel" data-panel="2" aria-label="Alege un produs">
    <div class="pd-breadcrumb">
      <button type="button" class="pd-breadcrumb__back" @click="wizard.goBack(1)">
        <span aria-hidden="true">&larr;</span> {{ i18n.changeCat || 'Schimbă categoria' }}
      </button>
      <span class="pd-breadcrumb__current">{{ wizard.categoryName }}</span>
    </div>
    <h2 class="pd-panel__heading">{{ i18n.pickProduct || 'Alege un produs de bază' }}</h2>
    <div class="pd-products">
      <div v-if="wizard.productsLoading" class="pd-skeleton-grid pd-skeleton-grid--products" aria-hidden="true">
        <div v-for="n in 6" :key="n" class="pd-card-skeleton">
          <span class="pd-skel pd-skel--img"></span>
          <span class="pd-skel pd-skel--line"></span>
          <span class="pd-skel pd-skel--line short"></span>
        </div>
      </div>
      <p v-else-if="wizard.productsError" class="pd-error">{{ i18n.errorLoad || 'Eroare la încărcare. Reîncearcă.' }}</p>
      <p v-else-if="!wizard.products.length" class="pd-empty">{{ i18n.noProducts }}</p>
      <div v-else class="pd-cards pd-cards--products">
        <button
          v-for="p in wizard.products"
          :key="p.id"
          type="button"
          class="pd-card pd-card--product"
          @click="wizard.pickProduct(p)"
        >
          <img v-if="p.thumbnail_url" class="pd-card__thumb" :src="p.thumbnail_url" alt="" loading="lazy" />
          <span v-else class="pd-card__thumb pd-card__thumb--placeholder" aria-hidden="true"></span>
          <span class="pd-card__name">{{ p.name }}</span>
          <span class="pd-card__price" v-html="p.price_html"></span>
        </button>
      </div>
    </div>
  </section>
</template>
