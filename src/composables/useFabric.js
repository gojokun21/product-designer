/**
 * useFabric — the Fabric.js dual-canvas editor core, ported from assets/js/designer.js.
 *
 * Owns two fabric.Canvas instances (front / back), the mockup loading + tint,
 * layout refit math, touch gestures, the tool operations (add text, upload
 * image, delete, text props) and design serialization. Exposes a reactive
 * `state` for Vue components to render, plus imperative methods.
 *
 * Porting notes — the following are deliberate bug fixes, do NOT "clean up":
 *  - remove `hidden` from the <canvas> BEFORE `new fabric.Canvas`
 *  - force wrapperEl position:absolute + translate(-50%,-50%) centering
 *  - enableRetinaScaling:false (Fabric 5 HiDPI click-hit bug)
 *  - toggle visibility on `.canvas-container`, never the inner canvas `hidden`
 *  - the triple-pass scheduleRefit / aggressiveRefit timings
 *  - sameOrigin + crossOrigin + try/catch around toDataURL / applyFilters
 */
import { reactive } from 'vue';
import { fabric } from 'fabric';

const SIDES = ['front', 'back'];
const MAX_INTERNAL_DIM = 2000;
const ZOOM_MIN = 1;
const ZOOM_MAX = 4;
const FONT_MIN = 8;
const FONT_MAX = 600;
const OBJ_SCALE_MIN = 0.05;
const OBJ_SCALE_MAX = 20;

// Touch-friendly selection handles — applied on the prototype ONCE, before any
// `new fabric.Canvas`, so every canvas inherits them (designer.js:38-47).
let prototypeDefaultsApplied = false;
function applyGlobalFabricDefaults() {
  if (prototypeDefaultsApplied) {
    return;
  }
  prototypeDefaultsApplied = true;
  const coarse = typeof matchMedia === 'function' && matchMedia('(pointer: coarse)').matches;
  fabric.Object.prototype.cornerSize = coarse ? 28 : 12;
  fabric.Object.prototype.touchCornerSize = coarse ? 36 : 24;
  fabric.Object.prototype.borderColor = '#1a73e8';
  fabric.Object.prototype.cornerColor = '#1a73e8';
  fabric.Object.prototype.cornerStrokeColor = '#1a73e8';
  fabric.Object.prototype.transparentCorners = false;
  fabric.Object.prototype.padding = coarse ? 6 : 2;
}

function raf(fn) {
  return (window.requestAnimationFrame || ((f) => setTimeout(f, 16)))(fn);
}

function isMobile() {
  return typeof matchMedia === 'function' && matchMedia('(max-width: 900px)').matches;
}

function isTextObject(obj) {
  return !!obj && (obj.type === 'i-text' || obj.type === 'text' || obj.type === 'textbox');
}

function clampFont(n) {
  return Math.max(FONT_MIN, Math.min(FONT_MAX, Math.round(n)));
}

// Step grows with the size so A−/A+ feel the same at 12px and at 200px.
function fontStep(size) {
  if (size < 24) {
    return 1;
  }
  if (size < 48) {
    return 2;
  }
  if (size < 120) {
    return 4;
  }
  return 8;
}

function normalizeColor(value) {
  if (!value || typeof value !== 'string') {
    return '';
  }
  if (value.charAt(0) === '#' && value.length === 7) {
    return value;
  }
  const tmp = document.createElement('div');
  tmp.style.color = value;
  document.body.appendChild(tmp);
  const rgb = getComputedStyle(tmp).color;
  document.body.removeChild(tmp);
  const m = /^rgba?\((\d+),\s*(\d+),\s*(\d+)/.exec(rgb);
  if (!m) {
    return '';
  }
  const toHex = (n) => {
    const h = parseInt(n, 10).toString(16);
    return h.length === 1 ? '0' + h : h;
  };
  return '#' + toHex(m[1]) + toHex(m[2]) + toHex(m[3]);
}

export function useFabric() {
  applyGlobalFabricDefaults();

  const canvases = { front: null, back: null };

  // DOM refs supplied by CanvasStage via setElements().
  const els = { wrap: null, front: null, back: null };

  let config = null;

  const state = reactive({
    activeSide: 'front',
    availableSides: ['front'],
    mockupFrontUrl: '',
    hasSelection: false,
    selectionIsText: false,
    sideCounts: { front: 0, back: 0 },
    outOfBounds: false,
    // Chosen print size ({ slug, label, w, h, anchor, second_side_price_text })
    // and the active side's frame in cm, or null when the product has none.
    printSize: null,
    frameCm: null,
    // The active side's frame can be moved and the customer hasn't yet.
    moveFrameHint: false,
    zoomVisible: false,
    isMobile: isMobile(),
    status: '',
    // Mirror of the active text object's props for two-way control binding.
    text: {
      value: '',
      fill: '#111111',
      fontSize: 48,
      fontFamily: 'Arial',
      textAlign: 'left',
      bold: false,
      italic: false,
      underline: false,
    },
  });

  // Tiny event bus so EditorShell can react to canvas events (mobile sheets etc.)
  const listeners = {};
  function on(name, cb) {
    (listeners[name] = listeners[name] || []).push(cb);
    return () => {
      listeners[name] = (listeners[name] || []).filter((f) => f !== cb);
    };
  }
  function emit(name, payload) {
    (listeners[name] || []).forEach((f) => f(payload));
  }

  function say(msg) {
    state.status = msg || '';
  }
  function activeCanvas() {
    return canvases[state.activeSide];
  }
  function sameOrigin(url) {
    try {
      return new URL(url, window.location.href).origin === window.location.origin;
    } catch (e) {
      return true;
    }
  }

  // ---- mockup aspect tracking (--pd-mockup-aspect on the wrap) ----
  const mockupAspect = { front: null, back: null };
  function applyMockupAspectToWrap(side) {
    if (!els.wrap) {
      return;
    }
    const aspect = mockupAspect[side];
    if (aspect) {
      els.wrap.style.setProperty('--pd-mockup-aspect', aspect);
    }
  }

  // ==========================================================================
  // Canvas init
  // ==========================================================================

  function canvasElFor(side) {
    return side === 'front' ? els.front : els.back;
  }

  function applyContainerVisibility(side) {
    const canvasEl = canvasElFor(side);
    if (!canvasEl) {
      return;
    }
    canvasEl.hidden = false;
    canvasEl.removeAttribute('hidden');
    const container =
      canvasEl.parentNode &&
      canvasEl.parentNode.classList &&
      canvasEl.parentNode.classList.contains('canvas-container')
        ? canvasEl.parentNode
        : canvasEl;
    container.style.display = side === state.activeSide ? '' : 'none';
  }

  function initCanvasForSide(side) {
    const canvasEl = canvasElFor(side);
    if (!canvasEl) {
      console.error('[PD] Canvas element pentru "' + side + '" nu există în DOM.');
      return;
    }
    // CRITICAL: drop `hidden` before Fabric init (designer.js:204-209).
    canvasEl.hidden = false;
    canvasEl.removeAttribute('hidden');

    const c = new fabric.Canvas(canvasEl, {
      preserveObjectStacking: true,
      backgroundColor: '#fff',
      enableRetinaScaling: false,
    });
    c.pdSide = side;
    attachTouchGestures(c);

    if (c.wrapperEl) {
      c.wrapperEl.style.position = 'absolute';
      c.wrapperEl.style.top = '50%';
      c.wrapperEl.style.left = '50%';
      c.wrapperEl.style.transform = 'translate(-50%, -50%)';
    }

    applyContainerVisibility(side);

    c.setDimensions({ width: config.canvas.width, height: config.canvas.height });

    if (els.wrap && !els.wrap.style.getPropertyValue('--pd-mockup-aspect')) {
      els.wrap.style.setProperty('--pd-mockup-aspect', config.canvas.width + ' / ' + config.canvas.height);
    }

    c.on('selection:created', onSelection);
    c.on('selection:updated', onSelection);
    c.on('selection:cleared', () => {
      state.hasSelection = false;
      state.selectionIsText = false;
      emit('selectionCleared');
    });
    c.on('text:editing:entered', () => {
      if (!isMobile()) {
        return;
      }
      document.documentElement.classList.add('pd-text-editing');
      emit('textEditEnter');
    });
    c.on('text:editing:exited', () => {
      if (!isMobile()) {
        return;
      }
      document.documentElement.classList.remove('pd-text-editing');
      scheduleRefit();
      emit('textEditExit');
    });
    c.on('object:added', (e) => {
      updateSideCount(side);
      const t = e && e.target;
      if (isUserObject(t)) {
        // Clip fresh objects to the zone, and keep the guide on top of them.
        if (!t.clipPath || !t.clipPath.pdClip) {
          const clip = makeClipRect(side);
          if (clip) {
            t.clipPath = clip;
          }
        }
        const b = boundaryOf(side);
        if (b) {
          c.bringToFront(b);
        }
      }
      updateBoundsWarning();
    });
    c.on('object:removed', () => {
      updateSideCount(side);
      updateBoundsWarning();
    });
    // Live warning while the customer drags/scales/rotates an object out of zone.
    // Moving the design against the frame's edge pushes the frame along first.
    c.on('object:moving', () => {
      followDesign(side);
      updateBoundsWarning();
    });
    c.on('object:scaling', updateBoundsWarning);
    c.on('object:rotating', updateBoundsWarning);
    c.on('object:modified', () => {
      followDesign(side);
      updateBoundsWarning();
    });
    // Dragging an empty spot inside the frame moves the frame, design included.
    c.on('mouse:down:before', (opt) => startFrameDrag(c, opt));
    c.on('mouse:move', (opt) => dragFrame(c, opt));
    c.on('mouse:up', endFrameDrag);
    // Corner-drag on a text object leaves scaleX/scaleY != 1, so the "Mărime"
    // control and the printed size would drift apart. Fold the scale back into
    // fontSize once the drag settles.
    c.on('object:modified', (e) => bakeTextScale(e && e.target));

    canvases[side] = c;

    const url = side === 'front' ? config.product.mockup_front_url : config.product.mockup_back_url;
    if (url) {
      loadMockupForSide(side, url);
    } else {
      refreshPrintArea(side);
      c.renderAll();
    }
  }

  function loadMockupForSide(side, url) {
    if (side === 'front') {
      state.mockupFrontUrl = url || '';
    }
    const native = new Image();
    if (!sameOrigin(url)) {
      native.crossOrigin = 'anonymous';
    }
    native.onload = function () {
      const c = canvases[side];
      if (!c) {
        return;
      }
      const nw = native.naturalWidth;
      const nh = native.naturalHeight;
      if (!nw || !nh) {
        console.error('[PD] Mockup ' + side + ' invalid:', url);
        say('Mockup invalid.');
        c.renderAll();
        return;
      }
      const cap = Math.min(MAX_INTERNAL_DIM / nw, MAX_INTERNAL_DIM / nh, 1);
      const w = Math.round(nw * cap);
      const h = Math.round(nh * cap);
      c.setDimensions({ width: w, height: h });

      mockupAspect[side] = w + ' / ' + h;
      if (side === state.activeSide) {
        applyMockupAspectToWrap(side);
      }

      const fImg = new fabric.Image(native, {
        left: 0,
        top: 0,
        scaleX: cap,
        scaleY: cap,
        originX: 'left',
        originY: 'top',
        selectable: false,
        evented: false,
        hoverCursor: 'default',
        excludeFromExport: true,
      });
      fImg.pdMockup = true;
      c.add(fImg);
      c.sendToBack(fImg);

      // Canvas dims are now final for this mockup — (re)draw the zone + re-clip.
      refreshPrintArea(side);

      if (side === state.activeSide) {
        fitCanvasToWrap(w, h);
        scheduleRefit();
      }
      c.renderAll();
      observeWrap();
    };
    native.onerror = function () {
      console.error('[PD] Mockup ' + side + ' n-a putut fi încărcat:', url);
      say('Mockup nu s-a putut încărca. Verifică consola.');
      if (canvases[side]) {
        canvases[side].renderAll();
      }
    };
    native.src = url;
  }

  // ==========================================================================
  // Mockup swap (per-color variation): replace the mockup image without losing
  // the user's design objects. Used when the customer picks a color variation.
  // ==========================================================================

  function mockupImageOf(side) {
    const c = canvases[side];
    if (!c) {
      return null;
    }
    const objs = c.getObjects();
    for (let i = 0; i < objs.length; i++) {
      if (objs[i].pdMockup) {
        return objs[i];
      }
    }
    return null;
  }

  function removeMockup(side) {
    const c = canvases[side];
    if (!c) {
      return;
    }
    // Remove ALL mockup objects, not just the first — a previous swap/boot race
    // can leave a stale mockup that would otherwise cover the new one.
    c.getObjects()
      .filter((o) => o.pdMockup)
      .forEach((o) => c.remove(o));
  }

  /**
   * Swap the front/back mockup images (e.g. white shirt → black shirt) keeping
   * all user objects in place. Pass the URLs for the chosen variation.
   */
  function swapMockups(frontUrl, backUrl) {
    const urls = { front: frontUrl, back: backUrl };
    state.availableSides.forEach((side) => {
      removeMockup(side);
      const url = urls[side];
      if (url) {
        loadMockupForSide(side, url);
      } else if (canvases[side]) {
        canvases[side].renderAll();
      }
    });
  }

  // ==========================================================================
  // Side switching
  // ==========================================================================

  function setActiveSide(side) {
    if (SIDES.indexOf(side) === -1 || side === state.activeSide) {
      return;
    }
    if (state.availableSides.indexOf(side) === -1) {
      return;
    }
    const prev = state.activeSide;
    state.activeSide = side;
    applyMockupAspectToWrap(state.activeSide);

    if (canvases[prev]) {
      canvases[prev].discardActiveObject();
      canvases[prev].requestRenderAll();
    }
    state.hasSelection = false;
    state.selectionIsText = false;

    SIDES.forEach(applyContainerVisibility);

    if (canvases[state.activeSide]) {
      canvases[state.activeSide].requestRenderAll();
    }
    updateBoundsWarning();
    state.frameCm = frameCmFor(state.activeSide);
    updateMoveHint();
    scheduleRefit();
  }

  function updateSideCount(side) {
    const c = canvases[side];
    if (!c) {
      return;
    }
    state.sideCounts[side] = c.getObjects().filter((o) => !o.excludeFromExport).length;
  }

  // ==========================================================================
  // Layout / refit
  // ==========================================================================

  let lastInnerW = 0;
  let lastInnerH = 0;
  let lastWrapW = 0;
  let lastWrapH = 0;
  let wrapObserver = null;
  let wrapObserverTimer = null;

  function fitCanvasToWrap(innerW, innerH) {
    const wrap = els.wrap;
    const c = activeCanvas();
    if (!wrap || !c) {
      return;
    }
    innerW = innerW || lastInnerW || c.getWidth();
    innerH = innerH || lastInnerH || c.getHeight();
    if (!innerW || !innerH) {
      return;
    }
    lastInnerW = innerW;
    lastInnerH = innerH;

    const rect = wrap.getBoundingClientRect();
    const style = window.getComputedStyle ? window.getComputedStyle(wrap) : null;
    const padX = style ? (parseFloat(style.paddingLeft) || 0) + (parseFloat(style.paddingRight) || 0) : 0;
    const padY = style ? (parseFloat(style.paddingTop) || 0) + (parseFloat(style.paddingBottom) || 0) : 0;
    const availW = Math.max(50, rect.width - padX);
    const availH = Math.max(50, rect.height - padY);
    const scale = Math.min(availW / innerW, availH / innerH, 1);
    const cssW = Math.floor(innerW * scale);
    const cssH = Math.floor(innerH * scale);

    c.setDimensions({ width: cssW, height: cssH }, { cssOnly: true });

    const px = (n) => n + 'px';
    if (c.lowerCanvasEl) {
      c.lowerCanvasEl.style.width = px(cssW);
      c.lowerCanvasEl.style.height = px(cssH);
    }
    if (c.upperCanvasEl) {
      c.upperCanvasEl.style.width = px(cssW);
      c.upperCanvasEl.style.height = px(cssH);
    }
    if (c.wrapperEl) {
      c.wrapperEl.style.width = px(cssW);
      c.wrapperEl.style.height = px(cssH);
      c.wrapperEl.style.position = 'absolute';
      c.wrapperEl.style.top = '50%';
      c.wrapperEl.style.left = '50%';
      c.wrapperEl.style.transform = 'translate(-50%, -50%)';
    }
  }

  function scheduleRefit() {
    if (!activeCanvas()) {
      return;
    }
    raf(() => {
      raf(() => {
        fitCanvasToWrap();
        setTimeout(fitCanvasToWrap, 0);
      });
    });
  }

  function aggressiveRefit() {
    if (!activeCanvas()) {
      return;
    }
    [0, 80, 200, 400].forEach((ms) => {
      setTimeout(() => {
        if (activeCanvas()) {
          fitCanvasToWrap();
        }
      }, ms);
    });
  }

  function observeWrap() {
    if (typeof window.ResizeObserver === 'undefined' || !els.wrap) {
      return;
    }
    if (wrapObserver) {
      try {
        wrapObserver.disconnect();
      } catch (e) {
        /* ignore */
      }
    }
    wrapObserver = new ResizeObserver(() => {
      if (!activeCanvas()) {
        return;
      }
      clearTimeout(wrapObserverTimer);
      wrapObserverTimer = setTimeout(() => {
        if (!els.wrap) {
          return;
        }
        const r = els.wrap.getBoundingClientRect();
        if (Math.abs(r.width - lastWrapW) < 1 && Math.abs(r.height - lastWrapH) < 1) {
          return;
        }
        lastWrapW = r.width;
        lastWrapH = r.height;
        fitCanvasToWrap();
      }, 80);
    });
    wrapObserver.observe(els.wrap);
  }

  // ==========================================================================
  // Touch gestures: pinch-zoom + 2-finger pan
  // ==========================================================================

  function attachTouchGestures(c) {
    if (!c || !c.upperCanvasEl) {
      return;
    }
    const el = c.upperCanvasEl;
    // Active pinch, or null. `mode` is decided at touchstart and never changes
    // mid-gesture: 'object' when something is selected, 'canvas' otherwise.
    let g = null;

    function dist(a, b) {
      const dx = b.clientX - a.clientX;
      const dy = b.clientY - a.clientY;
      return Math.sqrt(dx * dx + dy * dy);
    }
    function midpoint(a, b) {
      const r = el.getBoundingClientRect();
      return {
        x: (a.clientX + b.clientX) / 2 - r.left,
        y: (a.clientY + b.clientY) / 2 - r.top,
      };
    }

    el.addEventListener(
      'touchstart',
      (e) => {
        if (e.touches.length !== 2) {
          return;
        }
        e.preventDefault();
        // The first finger may have grabbed the frame — a pinch is not a drag.
        endFrameDrag();
        const startDist = dist(e.touches[0], e.touches[1]);
        if (!startDist) {
          return;
        }
        const obj = c.getActiveObject();
        if (obj && !obj.pdMockup) {
          // Pinch scales the selection — the mockup itself is never a target.
          g = {
            mode: 'object',
            obj,
            startDist,
            isText: isTextObject(obj),
            startFontSize: (obj.fontSize || 48) * (obj.scaleX || 1),
            startScaleX: obj.scaleX || 1,
            startScaleY: obj.scaleY || 1,
            center: obj.getCenterPoint(),
          };
          return;
        }
        g = {
          mode: 'canvas',
          startDist,
          startZoom: c.getZoom(),
          center: midpoint(e.touches[0], e.touches[1]),
        };
        c.discardActiveObject();
        c.requestRenderAll();
      },
      { passive: false }
    );
    el.addEventListener(
      'touchmove',
      (e) => {
        if (!g || e.touches.length !== 2) {
          return;
        }
        e.preventDefault();
        const ratio = dist(e.touches[0], e.touches[1]) / g.startDist;

        if (g.mode === 'object') {
          const o = g.obj;
          if (g.isText) {
            // Write fontSize directly instead of scaleX — nothing to bake later,
            // and the size readout tracks the fingers live.
            const size = clampFont(g.startFontSize * ratio);
            o.set({ fontSize: size, scaleX: 1, scaleY: 1 });
            state.text.fontSize = size;
          } else {
            const clamp = (n) => Math.max(OBJ_SCALE_MIN, Math.min(OBJ_SCALE_MAX, n));
            o.set({ scaleX: clamp(g.startScaleX * ratio), scaleY: clamp(g.startScaleY * ratio) });
          }
          o.setPositionByOrigin(g.center, 'center', 'center');
          o.setCoords();
          c.requestRenderAll();
          return;
        }

        const z = Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, g.startZoom * ratio));
        c.zoomToPoint(new fabric.Point(g.center.x, g.center.y), z);
        state.zoomVisible = c.getZoom() > 1.05;
      },
      { passive: false }
    );
    function endGesture(e) {
      if (e.touches && e.touches.length >= 2) {
        return;
      }
      if (g && g.mode === 'object') {
        c.fire('object:modified', { target: g.obj });
      }
      g = null;
    }
    el.addEventListener('touchend', endGesture);
    el.addEventListener('touchcancel', endGesture);
  }

  function resetZoom() {
    const c = activeCanvas();
    if (!c) {
      return;
    }
    c.setZoom(1);
    c.absolutePan(new fabric.Point(0, 0));
    c.requestRenderAll();
    state.zoomVisible = false;
  }

  // ==========================================================================
  // Selection / text controls
  // ==========================================================================

  function onSelection() {
    const c = activeCanvas();
    if (!c) {
      return;
    }
    const obj = c.getActiveObject();
    state.hasSelection = !!obj;
    const isText = isTextObject(obj);
    state.selectionIsText = isText;
    if (isText) {
      syncTextControls(obj);
      if (isMobile()) {
        emit('selectText');
      }
    }
  }

  function syncTextControls(obj) {
    if (!isTextObject(obj)) {
      return;
    }
    state.text.value = obj.text || '';
    state.text.fill = normalizeColor(obj.fill) || '#111111';
    state.text.fontSize = Math.round(obj.fontSize || 48);
    state.text.fontFamily = obj.fontFamily || 'Arial';
    state.text.textAlign = obj.textAlign || 'left';
    state.text.bold = obj.fontWeight === 'bold' || obj.fontWeight >= 600;
    state.text.italic = obj.fontStyle === 'italic';
    state.text.underline = !!obj.underline;
  }

  /**
   * Fold a text object's scaleX/scaleY back into fontSize and reset scale to 1,
   * keeping the object visually anchored on its center. Fabric's corner scaling
   * is uniform (canvas.uniformScaling), so scaleX is the authoritative factor.
   */
  function bakeTextScale(obj) {
    if (!isTextObject(obj)) {
      return;
    }
    const sx = obj.scaleX || 1;
    if (Math.abs(sx - 1) > 0.001) {
      const center = obj.getCenterPoint();
      obj.set({ fontSize: clampFont((obj.fontSize || 48) * sx), scaleX: 1, scaleY: 1 });
      // Textbox keeps an explicit width that must follow the same factor;
      // IText/Text auto-size from the glyphs so they need nothing here.
      if (obj.type === 'textbox' && obj.width) {
        obj.set('width', obj.width * sx);
      }
      obj.setPositionByOrigin(center, 'center', 'center');
      obj.setCoords();
    }
    syncTextControls(obj);
    const c = activeCanvas();
    if (c) {
      c.requestRenderAll();
    }
  }

  function applyToActiveText(mutator) {
    const c = activeCanvas();
    if (!c) {
      return;
    }
    const obj = c.getActiveObject();
    if (!isTextObject(obj)) {
      return;
    }
    mutator(obj);
    obj.setCoords();
    // A new size or text changes the footprint just like a drag does.
    followDesign(state.activeSide);
    updateBoundsWarning();
    c.requestRenderAll();
  }

  // Text setters used by TextControls (keep state.text in sync too).
  function setText(v) {
    state.text.value = v;
    applyToActiveText((o) => o.set('text', v));
  }
  function setFill(v) {
    state.text.fill = v;
    applyToActiveText((o) => o.set('fill', v));
  }
  // Lower bound stays at 1 (not FONT_MIN) so typing "1" on the way to "18" isn't
  // rewritten under the cursor; the upper bound is hard.
  function setFontSize(v) {
    const size = parseInt(v, 10);
    if (!size || size < 1) {
      return;
    }
    const next = Math.min(size, FONT_MAX);
    state.text.fontSize = next;
    applyToActiveText((o) => {
      const center = o.getCenterPoint();
      o.set({ fontSize: next, scaleX: 1, scaleY: 1 });
      o.setPositionByOrigin(center, 'center', 'center');
    });
  }

  /** A− / A+ : dir is -1 or +1. Works off the live object, not the mirror. */
  function nudgeFontSize(dir) {
    const c = activeCanvas();
    const obj = c ? c.getActiveObject() : null;
    const current = isTextObject(obj)
      ? (obj.fontSize || 48) * (obj.scaleX || 1)
      : parseInt(state.text.fontSize, 10) || 48;
    setFontSize(clampFont(current + fontStep(current) * (dir < 0 ? -1 : 1)));
  }
  function setFontFamily(v) {
    state.text.fontFamily = v;
    applyToActiveText((o) => o.set('fontFamily', v));
  }
  function setTextAlign(v) {
    state.text.textAlign = v;
    applyToActiveText((o) => o.set('textAlign', v));
  }
  function toggleBold() {
    applyToActiveText((o) => {
      const makeBold = o.fontWeight !== 'bold';
      o.set('fontWeight', makeBold ? 'bold' : 'normal');
      state.text.bold = makeBold;
    });
  }
  function toggleItalic() {
    applyToActiveText((o) => {
      const makeItalic = o.fontStyle !== 'italic';
      o.set('fontStyle', makeItalic ? 'italic' : 'normal');
      state.text.italic = makeItalic;
    });
  }
  function toggleUnderline() {
    applyToActiveText((o) => {
      const makeU = !o.underline;
      o.set('underline', makeU);
      state.text.underline = makeU;
    });
  }

  // ==========================================================================
  // Printable area (zonă imprimabilă delimitată): a dashed boundary drawn over
  // the mockup, plus a clipPath on every user object so anything outside the
  // zone is not printed. Defined in config as fractions (0..1) of the canvas, so
  // it survives per-color mockup swaps and the MAX_INTERNAL_DIM downscale.
  // ==========================================================================

  function printAreaFractionFor(side) {
    const pa = config && config.product && config.product.print_area;
    if (!pa || typeof pa !== 'object') {
      return null;
    }
    const f = pa[side];
    if (!f) {
      return null;
    }
    const x = Number(f.x);
    const y = Number(f.y);
    const w = Number(f.w);
    const h = Number(f.h);
    if (!isFinite(x) || !isFinite(y) || !(w > 0) || !(h > 0)) {
      return null;
    }
    const cx = Math.max(0, Math.min(1, x));
    const cy = Math.max(0, Math.min(1, y));
    return { x: cx, y: cy, w: Math.min(1 - cx, w), h: Math.min(1 - cy, h) };
  }

  function zonePixelRect(side) {
    const f = printAreaFractionFor(side);
    const c = canvases[side];
    if (!f || !c) {
      return null;
    }
    const W = c.getWidth();
    const H = c.getHeight();
    return { left: f.x * W, top: f.y * H, width: f.w * W, height: f.h * H };
  }

  // ---- print sizes: the frame shrinks to the print size the customer pays
  // for. The zone above is the largest print and `zone_width_cm` wide; a size
  // gets w × h cm at that scale, capped by the zone (e.g. hoodie front, above
  // the pocket). A frame smaller than the zone can be moved anywhere inside it.
  let printSizeSlug = '';

  // Where the frame sits in the zone, per side, as a fraction of the room it
  // can travel (0 = left/top edge, 1 = right/bottom edge); null = the size's
  // anchor. A fraction rather than pixels, so it survives mockup swaps and a
  // frame stays inside the next bigger size when the print size changes.
  const framePos = { front: null, back: null };

  function anchorPos(side, size) {
    if (size.anchor === 'center') {
      return { x: 0.5, y: 0.5 };
    }
    if (size.anchor === 'chest' && side === 'front') {
      // Wearer's left chest is on the viewer's right.
      return { x: 1, y: 0 };
    }
    return { x: 0.5, y: 0 };
  }

  function printSizesConfig() {
    const ps = config && config.product && config.product.print_sizes;
    return ps && ps.sizes && Number(ps.zone_width_cm) > 0 ? ps : null;
  }

  function resolvePrintSize(slug) {
    const ps = printSizesConfig();
    return ps && slug && ps.sizes[slug] ? { slug, ...ps.sizes[slug] } : null;
  }

  function pxPerCm(side) {
    const zone = zonePixelRect(side);
    const ps = printSizesConfig();
    return zone && ps ? zone.width / Number(ps.zone_width_cm) : 0;
  }

  // The printable rectangle in canvas pixels: the print-size frame when one is
  // chosen, else the whole zone.
  function printAreaPixelRect(side) {
    const zone = zonePixelRect(side);
    const size = resolvePrintSize(printSizeSlug);
    if (!zone || !size) {
      return zone;
    }
    const k = pxPerCm(side);
    const width = Math.min(zone.width, Number(size.w) * k);
    const height = Math.min(zone.height, Number(size.h) * k);
    const pos = framePos[side] || anchorPos(side, size);
    return {
      left: zone.left + (zone.width - width) * pos.x,
      top: zone.top + (zone.height - height) * pos.y,
      width,
      height,
    };
  }

  function frameIsMovable(side) {
    const zone = zonePixelRect(side);
    if (!zone || !resolvePrintSize(printSizeSlug)) {
      return false;
    }
    const px = printAreaPixelRect(side);
    return zone.width - px.width > 0.5 || zone.height - px.height > 0.5;
  }

  function updateMoveHint() {
    const side = state.activeSide;
    state.moveFrameHint = !framePos[side] && frameIsMovable(side);
  }

  /**
   * Move the frame so its top-left corner lands on (left, top), kept inside
   * the zone, and carry the guide and the clip paths along. Returns how far it
   * actually moved.
   */
  function placeFrame(side, left, top) {
    const zone = zonePixelRect(side);
    const size = resolvePrintSize(printSizeSlug);
    if (!zone || !size) {
      return { dx: 0, dy: 0 };
    }
    const from = printAreaPixelRect(side);
    const roomX = zone.width - from.width;
    const roomY = zone.height - from.height;
    const pos = framePos[side] || anchorPos(side, size);
    const clamp01 = (n) => Math.max(0, Math.min(1, n));
    framePos[side] = {
      x: roomX > 0.5 ? clamp01((left - zone.left) / roomX) : pos.x,
      y: roomY > 0.5 ? clamp01((top - zone.top) / roomY) : pos.y,
    };
    const to = printAreaPixelRect(side);
    const dx = to.left - from.left;
    const dy = to.top - from.top;
    if (dx || dy) {
      const b = boundaryOf(side);
      if (b) {
        b.set({ left: to.left, top: to.top });
        b.setCoords();
      }
      clipUserObjectsForSide(side);
    }
    if (side === state.activeSide) {
      updateMoveHint();
    }
    return { dx, dy };
  }

  // Bounding box of the given objects in canvas pixels, or null when empty.
  function designBounds(objs) {
    if (!objs.length) {
      return null;
    }
    let l = Infinity;
    let t = Infinity;
    let r = -Infinity;
    let b = -Infinity;
    objs.forEach((o) => {
      o.setCoords();
      const br = o.getBoundingRect(true, true);
      l = Math.min(l, br.left);
      t = Math.min(t, br.top);
      r = Math.max(r, br.left + br.width);
      b = Math.max(b, br.top + br.height);
    });
    return { l, t, r, b };
  }

  // Dragging the design against the frame's edge pushes the frame along, as
  // long as the whole design still fits in it — otherwise the frame stays and
  // the red warning tells the customer.
  function followDesign(side) {
    const c = canvases[side];
    if (!c || !frameIsMovable(side)) {
      return;
    }
    const box = designBounds(c.getObjects().filter(isUserObject));
    const px = printAreaPixelRect(side);
    const eps = 0.5;
    if (!box || box.r - box.l > px.width + eps || box.b - box.t > px.height + eps) {
      return;
    }
    let left = px.left;
    let top = px.top;
    if (box.l < left) {
      left = box.l;
    } else if (box.r > left + px.width) {
      left = box.r - px.width;
    }
    if (box.t < top) {
      top = box.t;
    } else if (box.b > top + px.height) {
      top = box.b - px.height;
    }
    if (left !== px.left || top !== px.top) {
      placeFrame(side, left, top);
    }
  }

  // ---- dragging the frame by an empty spot inside it (the mockup and the
  // guide are not evented, so Fabric reports no target there).
  let frameDrag = null;

  function pointInFrame(side, p) {
    const px = printAreaPixelRect(side);
    return !!px && p.x >= px.left && p.x <= px.left + px.width && p.y >= px.top && p.y <= px.top + px.height;
  }

  function startFrameDrag(c, opt) {
    const e = opt.e || {};
    const p = opt.absolutePointer;
    if (frameDrag || opt.target || !p || e.button > 0 || (e.touches && e.touches.length > 1)) {
      return;
    }
    const side = c.pdSide;
    if (!frameIsMovable(side) || !pointInFrame(side, p)) {
      return;
    }
    const px = printAreaPixelRect(side);
    frameDrag = { c, x: p.x, y: p.y, left: px.left, top: px.top, selection: c.selection };
    // No rubber-band selection while the frame is being dragged.
    c.selection = false;
  }

  function dragFrame(c, opt) {
    const p = opt.absolutePointer;
    if (!p) {
      return;
    }
    if (!frameDrag) {
      if (!opt.target && frameIsMovable(c.pdSide) && pointInFrame(c.pdSide, p)) {
        c.setCursor('move');
      }
      return;
    }
    if (frameDrag.c !== c) {
      return;
    }
    const moved = placeFrame(c.pdSide, frameDrag.left + p.x - frameDrag.x, frameDrag.top + p.y - frameDrag.y);
    if (moved.dx || moved.dy) {
      c.getObjects()
        .filter(isUserObject)
        .forEach((o) => {
          o.set({ left: o.left + moved.dx, top: o.top + moved.dy });
          o.setCoords();
        });
      c.requestRenderAll();
    }
    c.setCursor('move');
  }

  function endFrameDrag() {
    if (!frameDrag) {
      return;
    }
    frameDrag.c.selection = frameDrag.selection;
    frameDrag = null;
    updateBoundsWarning();
  }

  function frameCmFor(side) {
    const px = printAreaPixelRect(side);
    const k = pxPerCm(side);
    if (!px || !k || !resolvePrintSize(printSizeSlug)) {
      return null;
    }
    const round = (n) => Math.round(n * 10) / 10;
    return { w: round(px.width / k), h: round(px.height / k) };
  }

  /**
   * Switch the frame to another print size. The new frame goes where the
   * design already is; a design too big for it is scaled down and centered in
   * it, so the customer never pays for a size smaller than what they see.
   */
  function setPrintSize(slug) {
    const next = slug || '';
    if (next === printSizeSlug && (state.printSize || !next)) {
      return;
    }
    printSizeSlug = next;
    state.printSize = resolvePrintSize(next);
    let shrunk = false;
    state.availableSides.forEach((side) => {
      if (!canvases[side]) {
        return;
      }
      refreshPrintArea(side);
      followDesign(side);
      if (fitObjectsToFrame(side)) {
        shrunk = true;
      }
    });
    if (shrunk) {
      say((config.i18n || {}).fittedToFrame || '');
    }
  }

  // Pull the design inside the frame, scaling it down if needed. Returns
  // whether it had to be scaled down.
  function fitObjectsToFrame(side) {
    const c = canvases[side];
    const px = printAreaPixelRect(side);
    if (!c || !px) {
      return false;
    }
    const objs = c.getObjects().filter(isUserObject);
    const box = designBounds(objs);
    if (!box) {
      return false;
    }
    const { l, t, r, b } = box;
    const eps = 0.5;
    if (l >= px.left - eps && t >= px.top - eps && r <= px.left + px.width + eps && b <= px.top + px.height + eps) {
      return false;
    }
    const k = Math.min(1, px.width / (r - l), px.height / (b - t));
    const left = px.left + (px.width - (r - l) * k) / 2;
    const top = px.top + (px.height - (b - t) * k) / 2;
    objs.forEach((o) => {
      const center = o.getCenterPoint();
      if (isTextObject(o)) {
        const sx = o.scaleX || 1;
        o.set({ fontSize: clampFont((o.fontSize || 48) * sx * k), scaleX: 1, scaleY: 1 });
        if (o.type === 'textbox' && o.width) {
          o.set('width', o.width * sx * k);
        }
      } else {
        o.set({ scaleX: (o.scaleX || 1) * k, scaleY: (o.scaleY || 1) * k });
      }
      o.setPositionByOrigin(new fabric.Point(left + (center.x - l) * k, top + (center.y - t) * k), 'center', 'center');
      o.setCoords();
    });
    c.requestRenderAll();
    if (side === state.activeSide) {
      updateBoundsWarning();
      // Keep the "Mărime" control in step with a selected text we just shrank.
      syncTextControls(c.getActiveObject());
    }
    return k < 1;
  }

  function boundaryOf(side) {
    const c = canvases[side];
    if (!c) {
      return null;
    }
    return c.getObjects().find((o) => o.pdPrintArea) || null;
  }

  function isUserObject(o) {
    return !!o && !o.pdMockup && !o.pdPrintArea && !o.excludeFromExport;
  }

  function makeClipRect(side) {
    const px = printAreaPixelRect(side);
    if (!px) {
      return null;
    }
    const clip = new fabric.Rect({
      left: px.left,
      top: px.top,
      width: px.width,
      height: px.height,
      absolutePositioned: true,
    });
    clip.pdClip = true;
    return clip;
  }

  // Give every user object a clipPath matching the zone (or drop it when the
  // product has no zone). Updates geometry in place on a mockup swap so existing
  // objects re-clip to the new canvas dimensions.
  function clipUserObjectsForSide(side) {
    const c = canvases[side];
    if (!c) {
      return;
    }
    const px = printAreaPixelRect(side);
    c.getObjects().forEach((o) => {
      if (!isUserObject(o)) {
        return;
      }
      if (!px) {
        if (o.clipPath && o.clipPath.pdClip) {
          o.clipPath = null;
        }
        return;
      }
      if (o.clipPath && o.clipPath.pdClip) {
        o.clipPath.set({ left: px.left, top: px.top, width: px.width, height: px.height });
      } else {
        o.clipPath = makeClipRect(side);
      }
    });
  }

  // (Re)build the dashed boundary rect for a side from the current canvas dims,
  // and refresh clipping. Called after every dimension change (mockup load/swap).
  function refreshPrintArea(side) {
    const c = canvases[side];
    if (!c) {
      return;
    }
    c.getObjects().filter((o) => o.pdPrintArea).forEach((o) => c.remove(o));
    const px = printAreaPixelRect(side);
    if (px) {
      const line = Math.max(1, Math.round(Math.min(px.width, px.height) * 0.008));
      const rect = new fabric.Rect({
        left: px.left,
        top: px.top,
        width: px.width,
        height: px.height,
        fill: 'rgba(0,0,0,0)',
        stroke: '#1a73e8',
        strokeDashArray: [line * 2, Math.max(1, Math.round(line * 1.5))],
        strokeWidth: line,
        strokeUniform: true,
        selectable: false,
        evented: false,
        hoverCursor: 'default',
        excludeFromExport: true,
        objectCaching: false,
      });
      rect.pdPrintArea = true;
      c.add(rect);
      c.bringToFront(rect);
    }
    clipUserObjectsForSide(side);
    c.requestRenderAll();
    if (side === state.activeSide) {
      updateBoundsWarning();
      state.frameCm = frameCmFor(side);
      updateMoveHint();
    }
  }

  // Does the object stick out of the printable rectangle (in absolute canvas
  // coords)? Used to warn the customer that part of the design won't be printed.
  function objectExceedsZone(o, px) {
    const br = o.getBoundingRect(true, true);
    const eps = 0.5;
    return (
      br.left < px.left - eps ||
      br.top < px.top - eps ||
      br.left + br.width > px.left + px.width + eps ||
      br.top + br.height > px.top + px.height + eps
    );
  }

  const OOB_COLOR = '#e53935';
  const IN_COLOR = '#1a73e8';

  // Re-evaluate every user object on the active side: highlight the ones that
  // spill outside the zone in red and toggle the reactive warning flag.
  function updateBoundsWarning() {
    const side = state.activeSide;
    const c = canvases[side];
    const px = printAreaPixelRect(side);
    if (!c || !px) {
      state.outOfBounds = false;
      return;
    }
    let any = false;
    let changed = false;
    c.getObjects().forEach((o) => {
      if (!isUserObject(o)) {
        return;
      }
      const out = objectExceedsZone(o, px);
      const color = out ? OOB_COLOR : IN_COLOR;
      if (o.borderColor !== color) {
        o.set({ borderColor: color, cornerColor: color, cornerStrokeColor: color });
        changed = true;
      }
      if (out) {
        any = true;
      }
    });
    state.outOfBounds = any;
    if (changed) {
      c.requestRenderAll();
    }
  }

  function defaultSpawnArea() {
    // Spawn new objects inside the printable zone when the product defines one.
    const px = printAreaPixelRect(state.activeSide);
    if (px) {
      return {
        x: Math.round(px.left),
        y: Math.round(px.top),
        width: Math.round(px.width),
        height: Math.round(px.height),
      };
    }
    const c = activeCanvas();
    const canvasW = c ? c.getWidth() : 600;
    const canvasH = c ? c.getHeight() : 700;
    const w = Math.round(canvasW * 0.6);
    const h = Math.round(canvasH * 0.6);
    return { x: Math.round((canvasW - w) / 2), y: Math.round((canvasH - h) / 2), width: w, height: h };
  }

  // ==========================================================================
  // Tools
  // ==========================================================================

  function addText() {
    const c = activeCanvas();
    if (!c) {
      console.warn('[PD] addText: canvas not ready yet.');
      return;
    }
    try {
      const a = defaultSpawnArea();
      const sizeFromCtrl = parseInt(state.text.fontSize, 10);
      const fontSize = sizeFromCtrl >= 8 ? sizeFromCtrl : Math.max(24, Math.round(a.height * 0.25));
      const t = new fabric.IText(config.i18n.placeholder || 'Textul tău aici', {
        left: a.x + 10,
        top: a.y + 10,
        fontSize: fontSize,
        fill: state.text.fill || '#111111',
        fontFamily: state.text.fontFamily || 'Arial',
        textAlign: state.text.textAlign || 'left',
        fontWeight: state.text.bold ? 'bold' : 'normal',
        fontStyle: state.text.italic ? 'italic' : 'normal',
        underline: state.text.underline,
        originX: 'left',
        originY: 'top',
        editable: !isMobile(),
      });
      // Only corner handles: middle handles scale non-uniformly, which fontSize
      // (a single scalar) cannot represent once we bake the scale in.
      t.setControlsVisibility({ mt: false, mb: false, ml: false, mr: false });
      // A small print frame (e.g. 10 × 10 cm) is narrower than the default
      // text: shrink it to fit and center it, so it starts inside the frame.
      const maxW = a.width * 0.9;
      if (t.width > maxW) {
        t.set('fontSize', Math.max(FONT_MIN, Math.floor((fontSize * maxW) / t.width)));
      }
      t.set({ left: a.x + Math.max(0, (a.width - t.width) / 2), top: a.y + Math.min(10, a.height * 0.05) });
      c.add(t);
      c.setActiveObject(t);
      c.requestRenderAll();
      if (isMobile()) {
        // Fresh text is empty of intent — go straight to the full sheet so the
        // user can type. Tapping an existing text only shows the quick bar.
        emit('requestTextSheet');
        setTimeout(() => emit('focusTextValue'), 50);
      }
    } catch (err) {
      console.error('[PD] addText a eșuat:', err);
      say('Eroare la adăugarea textului. Verifică consola.');
    }
  }

  function deleteSelected() {
    const c = activeCanvas();
    if (!c) {
      return;
    }
    const obj = c.getActiveObject();
    if (!obj || obj.pdMockup) {
      return;
    }
    if (obj.type === 'activeSelection') {
      obj.forEachObject((o) => {
        if (!o.pdMockup) {
          c.remove(o);
        }
      });
      c.discardActiveObject();
    } else {
      c.remove(obj);
    }
    c.requestRenderAll();
  }

  // Fires selection:cleared, so the selection mirror and sheets follow.
  function clearSelection() {
    const c = activeCanvas();
    if (!c || !c.getActiveObject()) {
      return;
    }
    c.discardActiveObject();
    c.requestRenderAll();
  }

  function addUploadedImage(url) {
    const c = activeCanvas();
    if (!c) {
      return;
    }
    const native = new Image();
    if (!sameOrigin(url)) {
      native.crossOrigin = 'anonymous';
    }
    native.onload = function () {
      try {
        const a = defaultSpawnArea();
        const maxW = a.width * 0.8;
        const maxH = a.height * 0.8;
        const scale = Math.min(maxW / native.naturalWidth, maxH / native.naturalHeight, 1);
        const fImg = new fabric.Image(native, {
          left: a.x + 10,
          top: a.y + 10,
          originX: 'left',
          originY: 'top',
          scaleX: scale,
          scaleY: scale,
        });
        c.add(fImg);
        c.setActiveObject(fImg);
        c.requestRenderAll();
        say('');
      } catch (err) {
        console.error('[PD] addUploadedImage a eșuat:', err);
        say('Eroare la adăugarea imaginii. Verifică consola.');
      }
    };
    native.onerror = function () {
      console.error('[PD] Imaginea uploadată nu s-a putut încărca pe canvas:', url);
      say('Imaginea nu s-a putut afișa.');
    };
    native.src = url;
  }

  // ==========================================================================
  // Serialize (both sides)
  // ==========================================================================

  // Print size + the frame of each designed side (fractions of the mockup and
  // cm), stored with the design so production knows where and how big to print.
  function printDetails(design) {
    if (!state.printSize) {
      return null;
    }
    const frames = {};
    SIDES.forEach((side) => {
      const c = canvases[side];
      const px = printAreaPixelRect(side);
      const cm = frameCmFor(side);
      if (!design[side] || !c || !px || !cm) {
        return;
      }
      const W = c.getWidth();
      const H = c.getHeight();
      frames[side] = { x: px.left / W, y: px.top / H, w: px.width / W, h: px.height / H, w_cm: cm.w, h_cm: cm.h };
    });
    return { size: state.printSize.slug, frames };
  }

  function serialize() {
    const design = { version: 2, front: null, back: null };
    const preview = { front: '', back: '' };
    let totalObjects = 0;

    // Hide the printable-area guides so the dashed border never bleeds into the
    // exported preview PNGs; restore them afterwards.
    const hiddenGuides = [];
    SIDES.forEach((side) => {
      const b = boundaryOf(side);
      if (b && b.visible !== false) {
        b.visible = false;
        hiddenGuides.push(b);
      }
    });

    try {
      SIDES.forEach((side) => {
        const c = canvases[side];
        if (!c) {
          return;
        }
        const sideJson = c.toJSON(['selectable', 'evented']);
        const userObjects = (sideJson.objects || []).filter((o) => !o.excludeFromExport);
        if (userObjects.length === 0) {
          return;
        }
        sideJson.objects = userObjects;
        let dataUri;
        try {
          dataUri = c.toDataURL({ format: 'png', multiplier: 1 });
        } catch (err) {
          console.error('[PD] toDataURL ' + side + ' a eșuat (canvas tainted?):', err);
          say('Eroare generare preview ' + side + '. Verifică consola.');
          throw err;
        }
        design[side] = sideJson;
        preview[side] = dataUri;
        totalObjects += userObjects.length;
      });
    } finally {
      hiddenGuides.forEach((b) => {
        b.visible = true;
      });
      if (hiddenGuides.length) {
        SIDES.forEach((side) => {
          const c = canvases[side];
          if (c) {
            c.requestRenderAll();
          }
        });
      }
    }

    return { design, preview, totalObjects, print: printDetails(design) };
  }

  // ==========================================================================
  // Boot / lifecycle
  // ==========================================================================

  function determineAvailableSides(data) {
    const sides = [];
    if (data && data.product) {
      if (data.product.mockup_front_url) {
        sides.push('front');
      }
      if (data.product.mockup_back_url) {
        sides.push('back');
      }
    }
    if (sides.length === 0) {
      if (data && data.product && data.product.mockup_url) {
        data.product.mockup_front_url = data.product.mockup_url;
      }
      sides.push('front');
    }
    return sides;
  }

  function disposeAllCanvases() {
    SIDES.forEach((s) => {
      if (canvases[s]) {
        try {
          canvases[s].dispose();
        } catch (e) {
          /* ignore */
        }
        canvases[s] = null;
      }
    });
  }

  function setElements(next) {
    els.wrap = next.wrap || els.wrap;
    els.front = next.front || els.front;
    els.back = next.back || els.back;
  }

  /**
   * (Re)boot the editor with a fresh PDData-shaped config. Elements must be set
   * first (CanvasStage does this on mount). Initializes canvases immediately —
   * CanvasStage only calls boot() when its DOM is present and visible.
   */
  function boot(nextConfig) {
    if (!nextConfig) {
      return;
    }
    config = nextConfig;

    disposeAllCanvases();

    state.hasSelection = false;
    state.selectionIsText = false;
    state.sideCounts = { front: 0, back: 0 };
    state.outOfBounds = false;
    state.zoomVisible = false;
    state.status = '';
    state.isMobile = isMobile();

    printSizeSlug = (config.product && config.product.print_size) || '';
    state.printSize = resolvePrintSize(printSizeSlug);
    state.frameCm = null;
    state.moveFrameHint = false;
    framePos.front = null;
    framePos.back = null;
    frameDrag = null;

    lastInnerW = 0;
    lastInnerH = 0;
    lastWrapW = 0;
    lastWrapH = 0;

    state.availableSides = determineAvailableSides(config);
    state.activeSide = state.availableSides.indexOf('front') !== -1 ? 'front' : state.availableSides[0];

    state.availableSides.forEach((s) => initCanvasForSide(s));
    aggressiveRefit();
    scheduleRefit();
    observeWrap();
  }

  function dispose() {
    if (wrapObserver) {
      try {
        wrapObserver.disconnect();
      } catch (e) {
        /* ignore */
      }
      wrapObserver = null;
    }
    disposeAllCanvases();
  }

  // Sync inline-edit availability + refit across the mobile/desktop threshold.
  function handleViewportResize() {
    state.isMobile = isMobile();
    if (!activeCanvas()) {
      return;
    }
    fitCanvasToWrap();
    state.zoomVisible = activeCanvas().getZoom() > 1.05;
    const mobile = isMobile();
    SIDES.forEach((s) => {
      const cv = canvases[s];
      if (!cv) {
        return;
      }
      cv.getObjects().forEach((o) => {
        if (isTextObject(o)) {
          o.editable = !mobile;
        }
      });
    });
  }

  return {
    state,
    on,
    say,
    setElements,
    boot,
    dispose,
    setActiveSide,
    swapMockups,
    setPrintSize,
    hasPrintSizes: () => !!printSizesConfig(),
    addText,
    addUploadedImage,
    deleteSelected,
    clearSelection,
    setText,
    setFill,
    setFontSize,
    nudgeFontSize,
    setFontFamily,
    setTextAlign,
    toggleBold,
    toggleItalic,
    toggleUnderline,
    serialize,
    scheduleRefit,
    fitCanvasToWrap,
    aggressiveRefit,
    resetZoom,
    handleViewportResize,
    isMobile,
  };
}
