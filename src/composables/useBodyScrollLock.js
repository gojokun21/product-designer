/**
 * Body scroll lock (iOS Safari-compatible). Ported from designer.js:136-160.
 *
 * The counter is module-level on purpose: Flow A (modal) and Flow B (wizard
 * step 3) both lock, and the original shared a single counter via
 * window.PDDesigner. A shared module here gives the same single source of truth.
 */
let bodyLockCount = 0;

export function lockBodyScroll() {
  if (bodyLockCount++ > 0) {
    return;
  }
  const y = window.pageYOffset || document.documentElement.scrollTop || 0;
  document.body.dataset.pdScrollY = String(y);
  document.body.style.position = 'fixed';
  document.body.style.top = -y + 'px';
  document.body.style.left = '0';
  document.body.style.right = '0';
  document.body.style.width = '100%';
  document.body.style.overscrollBehavior = 'contain';
}

export function unlockBodyScroll() {
  if (bodyLockCount > 0) {
    bodyLockCount--;
  }
  if (bodyLockCount > 0) {
    return;
  }
  const y = parseInt(document.body.dataset.pdScrollY || '0', 10);
  delete document.body.dataset.pdScrollY;
  document.body.style.position = '';
  document.body.style.top = '';
  document.body.style.left = '';
  document.body.style.right = '';
  document.body.style.width = '';
  document.body.style.overscrollBehavior = '';
  if (y) {
    window.scrollTo(0, y);
  }
}
