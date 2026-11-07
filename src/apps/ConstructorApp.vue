<script setup>
import { computed, onMounted } from 'vue';
import StepProgress from '../components/wizard/StepProgress.vue';
import CategoryStep from '../components/wizard/CategoryStep.vue';
import ProductStep from '../components/wizard/ProductStep.vue';
import VariationStep from '../components/wizard/VariationStep.vue';
import DesignerStep from '../components/wizard/DesignerStep.vue';
import { useWizardStore } from '../stores/wizard.js';

const props = defineProps({
  config: { type: Object, required: true },
});

const wizard = useWizardStore();
const title = computed(() => props.config.title || 'Constructor produse');
const dataStep = computed(() => String(wizard.step));

onMounted(() => {
  wizard.init(props.config);
  wizard.loadCategories();
});
</script>

<template>
  <div class="pd-constructor" :data-step="dataStep" :aria-busy="wizard.busy ? 'true' : 'false'">
    <header class="pd-constructor__header">
      <h1 class="pd-constructor__title">{{ title }}</h1>
      <StepProgress />
      <p class="pd-constructor__sub-label"></p>
    </header>

    <CategoryStep v-if="wizard.step === 1" />
    <ProductStep v-else-if="wizard.step === 2" />
    <VariationStep v-else-if="wizard.step === 2.5" />
    <!-- Stays alive behind the options step: changing the size, colour or print
         size and coming back keeps the design. -->
    <DesignerStep v-if="wizard.step === 3 || wizard.designerConfig" v-show="wizard.step === 3" />
  </div>
</template>
