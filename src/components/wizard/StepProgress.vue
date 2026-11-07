<script setup>
import { computed } from 'vue';
import { useWizardStore } from '../../stores/wizard.js';

const wizard = useWizardStore();
// Step 2.5 maps to visual step 2.
const progressN = computed(() => (wizard.step === 2.5 ? 2 : parseInt(wizard.step, 10)));

const steps = computed(() => [
  { target: 1, label: 'Categorie' },
  { target: 2, label: 'Produs' },
  { target: 3, label: 'Personalizare' },
]);
</script>

<template>
  <ol class="pd-progress" role="list">
    <li
      v-for="s in steps"
      :key="s.target"
      class="pd-progress__step"
      :class="{ 'is-active': s.target === progressN, 'is-done': s.target < progressN }"
      :data-target="s.target"
    >
      <span class="pd-progress__num"><span>{{ s.target }}</span></span>
      <span class="pd-progress__label">{{ s.label }}</span>
    </li>
  </ol>
</template>
