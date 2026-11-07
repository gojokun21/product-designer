import { defineStore } from 'pinia';
import { markRaw } from 'vue';

const emptySaved = () => ({
  design_id: '',
  preview_url: '',
  preview_back_url: '',
  json_url: '',
  mockup_color: '',
  // side => print-size key, what the sizes add to the price (computed by the
  // server) and its summary line („Față: Mic 10 × 10 cm · Spate: …").
  print: {},
  extra_price: 0,
  print_summary: '',
});

/**
 * Cross-flow editor state: the live useFabric API (registered by EditorShell so
 * the non-Vue cart bridge can drive auto-tint) and the last saved design result.
 * The heavy reactive canvas state — including the print size picked per side —
 * lives in the useFabric instance itself.
 */
export const useEditorStore = defineStore('pd-editor', {
  state: () => ({
    fabricApi: null,
    // The mockup the current variation selection should show. Null until a
    // variation is picked; applied on editor boot and live while open.
    currentMockups: { front: null, back: null },
    saved: emptySaved(),
  }),
  getters: {
    hasDesign: (s) => !!s.saved.design_id,
  },
  actions: {
    registerFabric(api) {
      this.fabricApi = api ? markRaw(api) : null;
    },
    setSaved(res) {
      this.saved = {
        design_id: res.design_id || '',
        preview_url: res.preview_url || '',
        preview_back_url: res.preview_back_url || '',
        json_url: res.json_url || '',
        mockup_color: res.mockup_color || '',
        print: res.print && typeof res.print === 'object' ? res.print : {},
        extra_price: Number(res.extra_price) || 0,
        print_summary: res.print_summary || '',
      };
    },
    clearSaved() {
      this.saved = emptySaved();
    },
    /** Called by the cart bridge on WC found_variation / reset_data — records the
     * variation's mockup and swaps it live if the editor is already running. */
    swapMockups(frontUrl, backUrl) {
      this.currentMockups = { front: frontUrl || null, back: backUrl || null };
      if (this.fabricApi) {
        this.fabricApi.swapMockups(frontUrl, backUrl);
      }
    },

    /** Apply the recorded variation mockup after the editor boots (covers a
     * variation chosen before opening the modal, or a reopen). */
    applyCurrentMockups() {
      if (this.fabricApi && this.currentMockups.front) {
        this.fabricApi.swapMockups(this.currentMockups.front, this.currentMockups.back);
      }
    },
  },
});
