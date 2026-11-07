<script setup>
import { computed } from 'vue';
import { useWizardStore } from '../../stores/wizard.js';

const wizard = useWizardStore();
const i18n = computed(() => wizard.i18n);

function stripHtml(s) {
  const tmp = document.createElement('div');
  tmp.innerHTML = s || '';
  return (tmp.textContent || tmp.innerText || '').trim();
}

const preview = computed(() => wizard.previewVariation);
const previewImage = computed(() => (preview.value && preview.value.image_url) || wizard.productThumb);

const continueLabel = computed(() => {
  const base = i18n.value.customize || 'Personalizează';
  const match = wizard.matchedVariation;
  if (wizard.variationId && match && match.price_html) {
    return base + ' (' + stripHtml(match.price_html) + ')';
  }
  return base;
});
</script>

<template>
  <section class="pd-constructor__panel" data-panel="2.5" aria-label="Alege opțiunile produsului">
    <div class="pd-breadcrumb">
      <button type="button" class="pd-breadcrumb__back" @click="wizard.goBack(2)">
        <span aria-hidden="true">&larr;</span> {{ i18n.changeProduct || 'Schimbă produsul' }}
      </button>
      <span class="pd-breadcrumb__current">{{ wizard.productName }}</span>
    </div>
    <h2 class="pd-panel__heading">Alege opțiunile</h2>
    <div class="pd-variations">
      <p v-if="wizard.variationsLoading" class="pd-loading">{{ i18n.loading || 'Se încarcă...' }}</p>
      <p v-else-if="wizard.variationsError" class="pd-error">{{ i18n.errorLoad || 'Eroare la încărcare. Reîncearcă.' }}</p>
      <p v-else-if="!wizard.attributes.length" class="pd-empty">{{ i18n.noProducts }}</p>
      <div v-else class="pd-variation-layout">
        <figure v-if="previewImage" class="pd-variation-preview">
          <img class="pd-variation-preview__img" :src="previewImage" :alt="wizard.productName" />
          <figcaption v-if="preview && preview.description" class="pd-variation-preview__caption">
            {{ preview.description }}
          </figcaption>
        </figure>
        <div class="pd-variation-attrs">
          <div v-for="attr in wizard.attributes" :key="attr.name" class="pd-variation-attr">
            <label class="pd-variation-attr__label">{{ attr.label }}</label>
            <div class="pd-variation-attr__options" :data-attr-name="attr.name">
              <button
                v-for="opt in attr.options"
                :key="opt.value"
                type="button"
                class="pd-variation-option"
                :class="{ 'is-active': wizard.chosenAttrs[attr.name] === opt.value }"
                @click="wizard.chooseAttribute(attr.name, opt.value)"
              >
                {{ opt.label }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div v-if="wizard.attributes.length && !wizard.variationsLoading" class="pd-variation-actions">
      <p v-if="wizard.unavailable" class="pd-error" role="alert">
        {{ i18n.unavailable || 'Combinația aleasă nu este disponibilă. Alege alte opțiuni.' }}
      </p>
      <button type="button" class="pd-tool pd-continue-to-design" :disabled="!wizard.variationId" @click="wizard.enterDesigner()">
        {{ continueLabel }} &rarr;
      </button>
    </div>
  </section>
</template>
