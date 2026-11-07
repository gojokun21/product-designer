import { defineStore } from 'pinia';
import { apiGet } from '../composables/useDesignApi.js';

/**
 * Constructor wizard state machine — port of constructor.js `state` + actions.
 * Steps: 1 (category) → 2 (product) → 2.5 (variation picker) → 3 (designer).
 * The 400ms save-poll is gone: `designId` is set reactively when the editor saves.
 */
export const useWizardStore = defineStore('pd-wizard', {
  state: () => ({
    boot: null, // PDConstructorData (rest/cart/limits/i18n)
    step: 1, // 1 | 2 | 2.5 | 3
    busy: false,

    categoryId: null,
    categoryName: '',
    categories: [],
    productsLoading: false,
    products: [],
    productsError: false,
    categoriesError: false,

    productId: null,
    productName: '',
    productThumb: '',
    productPrice: '',
    productVariable: false,
    productPermalink: '',

    // Variations
    attributes: [], // [{name,label,options:[{value,label}]}]
    variations: [], // [{id,attributes,price_html,is_in_stock,image_url,description,mockup_url}]
    defaults: {}, // atributele implicite ale produsului {pa_color:'black'}
    variationsLoading: false,
    variationsError: false,
    chosenAttrs: {}, // {pa_size:'m', pa_color:'red'}
    variationId: 0,

    // Designer config (PDData-shaped) fetched per product/variation.
    designerConfig: null,
    designerError: false,
    // The variation the open editor shows. The editor stays alive behind the
    // options step, so coming back with the same choice needs no reload.
    editorVariationId: 0,

    // Design result
    designId: null,
    previewUrl: '',
    previewBackUrl: '',
    jsonUrl: '',
  }),
  getters: {
    i18n: (s) => (s.boot && s.boot.i18n) || {},
    rest: (s) => (s.boot && s.boot.rest) || {},
    // Adding to cart saves the canvas first, so no earlier save is needed.
    canAddToCart: (s) => !s.productVariable || !!s.variationId,
    matchedVariation(s) {
      return s.variations.find((v) => {
        for (const k in s.chosenAttrs) {
          if (!Object.prototype.hasOwnProperty.call(s.chosenAttrs, k)) {
            continue;
          }
          const key = 'attribute_' + k;
          const vAttr = v.attributes && v.attributes[key];
          if (vAttr && vAttr !== '' && vAttr !== s.chosenAttrs[k]) {
            return false;
          }
        }
        if (v.attributes) {
          for (const ak in v.attributes) {
            if (!Object.prototype.hasOwnProperty.call(v.attributes, ak)) {
              continue;
            }
            const clean = ak.replace(/^attribute_/, '');
            if (v.attributes[ak] && !s.chosenAttrs[clean]) {
              return false;
            }
          }
        }
        return v.is_in_stock !== false;
      });
    },
    /**
     * Variația a cărei poză e afișată ca imagine principală: cea aleasă complet,
     * altfel prima compatibilă cu alegerile de până acum, completate cu valorile
     * implicite (ex. clientul a ales doar „Imprimeu mare" → negru + mare).
     */
    previewVariation(s) {
      if (this.matchedVariation) {
        return this.matchedVariation;
      }
      const fits = (v, attrs) =>
        Object.keys(attrs).every((k) => {
          const vAttr = v.attributes && v.attributes['attribute_' + k];
          return !vAttr || vAttr === attrs[k];
        });
      const wanted = { ...s.defaults, ...s.chosenAttrs };
      return s.variations.find((v) => fits(v, wanted)) || s.variations.find((v) => fits(v, s.chosenAttrs)) || null;
    },
    // Every option is picked, yet no variation in stock matches them.
    unavailable(s) {
      return s.attributes.length > 0 && s.attributes.every((a) => s.chosenAttrs[a.name]) && !this.matchedVariation;
    },
  },
  actions: {
    init(bootData) {
      this.boot = bootData;
    },

    fmtCount(n) {
      n = parseInt(n, 10) || 0;
      return n === 1 ? this.i18n.productCount1 : (this.i18n.productsCount || '%d').replace('%d', String(n));
    },

    async loadCategories() {
      this.busy = true;
      this.categoriesError = false;
      try {
        const res = await apiGet(this.rest, 'constructor/categories');
        this.categories = (res && res.items) || [];
      } catch (e) {
        this.categoriesError = true;
      } finally {
        this.busy = false;
      }
    },

    async pickCategory(id, name) {
      this.categoryId = id;
      this.categoryName = name;
      this.step = 2;
      this.products = [];
      this.productsLoading = true;
      this.productsError = false;
      this.busy = true;
      try {
        const res = await apiGet(this.rest, 'constructor/products', { category_id: id, per_page: 24 });
        this.products = (res && res.items) || [];
      } catch (e) {
        this.productsError = true;
      } finally {
        this.productsLoading = false;
        this.busy = false;
      }
    },

    pickProduct(p) {
      this.productId = parseInt(p.id, 10);
      this.productName = p.name || '';
      this.productThumb = p.thumbnail_url || '';
      this.productPrice = p.price_html || '';
      this.productVariable = !!p.is_variable;
      this.productPermalink = p.permalink || '';
      this.resetDesign();
      this.designerConfig = null;
      this.editorVariationId = 0;
      this.variationId = 0;
      this.chosenAttrs = {};
      this.attributes = [];
      this.variations = [];
      this.defaults = {};
      if (this.productVariable) {
        this.loadVariations();
      } else {
        this.enterDesigner();
      }
    },

    async loadVariations() {
      this.step = 2.5;
      this.variationsLoading = true;
      this.variationsError = false;
      this.busy = true;
      try {
        const res = await apiGet(this.rest, 'constructor/variations', { product_id: this.productId });
        this.attributes = (res && res.attributes) || [];
        this.variations = (res && res.variations) || [];
        this.defaults = (res && res.defaults) || {};
        // Preîncărcăm pozele variațiilor: la schimbarea opțiunii imaginea
        // principală se schimbă instant, fără pauză de încărcare.
        new Set(this.variations.map((v) => v.image_url).filter(Boolean)).forEach((url) => {
          new Image().src = url;
        });
      } catch (e) {
        this.variationsError = true;
      } finally {
        this.variationsLoading = false;
        this.busy = false;
      }
    },

    chooseAttribute(attr, value) {
      this.chosenAttrs = { ...this.chosenAttrs, [attr]: value };
      const match = this.matchedVariation;
      this.variationId = match ? match.id : 0;
    },

    /**
     * The editor's colour swatches pick the garment colour: switch to the
     * variation with that mockup and the same other options, so the order
     * matches what the customer sees. Matched on the mockup URL the server
     * resolved per variation — the swatch slugs may be in another language.
     * Returns false when no such variation exists (the swatch then does nothing).
     */
    chooseColorByMockup(frontUrl) {
      if (!this.productVariable) {
        return true;
      }
      // Same options except the colour: the closest match, with at most one
      // attribute (the colour) different.
      let v = null;
      let fewest = 2;
      this.variations.forEach((x) => {
        if (!frontUrl || x.mockup_front_url !== frontUrl || x.is_in_stock === false) {
          return;
        }
        const attrs = x.attributes || {};
        const differs = Object.keys(attrs).filter((k) => attrs[k] && attrs[k] !== this.chosenAttrs[k.replace(/^attribute_/, '')]).length;
        if (differs < fewest) {
          v = x;
          fewest = differs;
        }
      });
      if (!v) {
        return false;
      }
      if (v.id !== this.variationId) {
        const next = { ...this.chosenAttrs };
        Object.keys(v.attributes || {}).forEach((k) => {
          if (v.attributes[k]) {
            next[k.replace(/^attribute_/, '')] = v.attributes[k];
          }
        });
        this.chosenAttrs = next;
        this.variationId = v.id;
        this.editorVariationId = v.id;
        // The saved preview shows the previous colour.
        this.resetDesign();
      }
      return true;
    },

    async enterDesigner() {
      // Back from the options without changing them: the editor is still open.
      if (this.designerConfig && this.editorVariationId === this.variationId) {
        this.step = 3;
        return;
      }
      // A saved design belongs to the previous variation (price, colour). The
      // canvas itself — and its per-side print sizes — is kept when the product
      // stays the same.
      this.resetDesign();
      this.step = 3;
      this.designerError = false;
      this.busy = true;
      const params = { product_id: this.productId };
      if (this.variationId) {
        params.variation_id = this.variationId;
      }
      try {
        this.designerConfig = await apiGet(this.rest, 'constructor/product-config', params);
        this.editorVariationId = this.variationId;
      } catch (e) {
        this.designerError = true;
        this.step = this.productVariable ? 2.5 : 2;
      } finally {
        this.busy = false;
      }
    },

    /** Editor save success → enable add-to-cart reactively (replaces polling). */
    applySaved(res) {
      this.designId = res.design_id || null;
      this.previewUrl = res.preview_url || '';
      this.previewBackUrl = res.preview_back_url || '';
      this.jsonUrl = res.json_url || '';
    },

    resetDesign() {
      this.designId = null;
      this.previewUrl = '';
      this.previewBackUrl = '';
      this.jsonUrl = '';
    },

    // Back to the options (2.5) keeps the editor and its design alive; back to
    // the product list drops them.
    goBack(target) {
      if (target <= 2) {
        this.resetDesign();
        this.designerConfig = null;
        this.editorVariationId = 0;
        this.variationId = 0;
        this.chosenAttrs = {};
      }
      if (target === 1) {
        this.categoryId = null;
        this.productId = null;
      }
      this.step = target;
    },

    addToCart() {
      if (!this.productId || !this.designId) {
        return;
      }
      if (this.productVariable && !this.variationId) {
        return;
      }
      const form = document.createElement('form');
      form.method = 'post';
      form.action = this.boot.cart.addToCartUrl;
      const add = (name, value) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value === undefined || value === null ? '' : String(value);
        form.appendChild(input);
      };
      add('add-to-cart', this.productId);
      add('quantity', 1);
      if (this.productVariable && this.variationId) {
        add('variation_id', this.variationId);
        for (const k in this.chosenAttrs) {
          if (Object.prototype.hasOwnProperty.call(this.chosenAttrs, k)) {
            add('attribute_' + k, this.chosenAttrs[k]);
          }
        }
      }
      add('pd_design_id', this.designId);
      add('pd_preview_url', this.previewUrl);
      add('pd_preview_back_url', this.previewBackUrl);
      add('pd_json_url', this.jsonUrl);
      add('pd_from_constructor', '1');
      document.body.appendChild(form);
      form.submit();
    },
  },
});
