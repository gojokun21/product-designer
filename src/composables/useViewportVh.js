/**
 * Viewport units fallback (--pd-vh) for iOS Safari < 15.4 / older WebViews.
 * Ported from designer.js:116-130. Call install() once per page; returns an
 * uninstall fn. Pass an onResize callback to refit canvases when the virtual
 * keyboard / address bar changes the visual viewport height.
 */
export function installViewportVh(onResize) {
  function setVhVar() {
    const h = (window.visualViewport && window.visualViewport.height) || window.innerHeight;
    document.documentElement.style.setProperty('--pd-vh', h * 0.01 + 'px');
  }
  setVhVar();

  const onWin = () => setVhVar();
  window.addEventListener('resize', onWin);
  window.addEventListener('orientationchange', onWin);

  let onVv = null;
  if (window.visualViewport) {
    onVv = () => {
      setVhVar();
      if (typeof onResize === 'function') {
        onResize();
      }
    };
    window.visualViewport.addEventListener('resize', onVv);
  }

  return function uninstall() {
    window.removeEventListener('resize', onWin);
    window.removeEventListener('orientationchange', onWin);
    if (onVv && window.visualViewport) {
      window.visualViewport.removeEventListener('resize', onVv);
    }
  };
}
