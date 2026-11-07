import { defineStore } from 'pinia';
import { markRaw } from 'vue';

/**
 * Cross-flow editor state: the live useFabric API (registered by EditorShell so
 * the non-Vue cart bridge can drive auto-tint) and the last saved design result.
 * The heavy reactive canvas state lives in the useFabric instance itself.
 */
export const useEditorStore = defineStore('pd-editor', {
  state: () => ({
    fabricApi: null,
    // The mockup the current variation selection should show. Null until a
    // variation is picked; applied on editor boot and live while open.
    currentMockups: { front: null, back: null },
    // Print size slug of the chosen variation ('' until one is picked); drives
    // the editor frame like currentMockups drives the mockup.
    printSize: '',
    saved: {
      design_id: '',
      preview_url: '',
      preview_back_url: '',
      json_url: '',
      mockup_color: '',
      print_size: '',
    },
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
        print_size: res.print_size || '',
      };
    },
    clearSaved() {
      this.saved = {
        design_id: '',
        preview_url: '',
        preview_back_url: '',
        json_url: '',
        mockup_color: '',
        print_size: '',
      };
    },
    /** Called by the cart bridge with the chosen variation's print size. */
    setPrintSize(slug) {
      this.printSize = slug || '';
      if (this.fabricApi) {
        this.fabricApi.setPrintSize(this.printSize);
      }
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
