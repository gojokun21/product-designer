<script setup>
import { computed } from 'vue';
import { useWizardStore } from '../../stores/wizard.js';

const wizard = useWizardStore();
const i18n = computed(() => wizard.i18n);
</script>

<template>
  <section class="pd-constructor__panel" data-panel="1" aria-label="Alege o categorie">
    <h2 class="pd-panel__heading">{{ i18n.pickCategory || 'Alege o categorie' }}</h2>
    <div class="pd-categories">
      <div v-if="wizard.busy && !wizard.categories.length" class="pd-skeleton-grid" aria-hidden="true">
        <div v-for="n in 6" :key="n" class="pd-card-skeleton">
          <span class="pd-skel pd-skel--img"></span>
          <span class="pd-skel pd-skel--line"></span>
          <span class="pd-skel pd-skel--line short"></span>
        </div>
      </div>
      <p v-else-if="wizard.categoriesError" class="pd-error">{{ i18n.errorLoad || 'Eroare la încărcare. Reîncearcă.' }}</p>
      <p v-else-if="!wizard.categories.length" class="pd-empty">{{ i18n.noProducts }}</p>
      <div v-else class="pd-cards">
        <button
          v-for="cat in wizard.categories"
          :key="cat.id"
          type="button"
          class="pd-card pd-card--category"
          @click="wizard.pickCategory(cat.id, cat.name)"
        >
          <img v-if="cat.icon_url" class="pd-card__icon" :src="cat.icon_url" alt="" />
          <span v-else class="pd-card__icon pd-card__icon--placeholder" aria-hidden="true">📦</span>
          <span class="pd-card__name">{{ cat.name }}</span>
          <span class="pd-card__meta">{{ wizard.fmtCount(cat.count) }}</span>
        </button>
      </div>
    </div>
  </section>
</template>
