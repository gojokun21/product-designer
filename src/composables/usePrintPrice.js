/**
 * Print-size pricing in the browser, for the live numbers in the editor and the
 * constructor summary. It mirrors Design_Storage::print_price() in PHP, which is
 * what the cart actually charges — keep the two in step.
 *
 * The variation price includes one print of the smallest size. A design on one
 * side (front or back) adds that size's `price`. A design on both sides adds
 * the front size's `price` and the back size's `back_price`: the back is a
 * second print, cheaper than a first one.
 */

export const PRINT_SIDES = ['front', 'back'];

/**
 * What a size adds on a side.
 *
 * @param {object}  size              A `print_sizes.sizes[key]` entry.
 * @param {string}  side              'front' | 'back'.
 * @param {boolean} otherSidePrinted  Whether the other side has a design too.
 */
export function sizeAmount(size, side, otherSidePrinted) {
  if (!size) {
    return 0;
  }
  const amount = side === 'back' && otherSidePrinted ? size.back_price : size.price;
  return Number(amount) || 0;
}

/**
 * @param {object|null} printSizes  `config.product.print_sizes`.
 * @param {{front?:string, back?:string}} sizes  side => size key, for every printed side.
 * @returns {{ extra:number, sides:Object<string,{size:string,label:string,amount:number}> }}
 */
export function printPrice(printSizes, sizes) {
  const out = { extra: 0, sides: {} };
  if (!printSizes || !printSizes.sizes || !sizes) {
    return out;
  }
  const both = !!sizes.front && !!sizes.back;
  PRINT_SIDES.forEach((side) => {
    const key = sizes[side];
    const size = key ? printSizes.sizes[key] : null;
    if (!size) {
      return;
    }
    const amount = sizeAmount(size, side, both);
    out.sides[side] = { size: key, label: size.label || key, amount };
    out.extra += amount;
  });
  return out;
}

/**
 * Format an amount like Design_Storage::price_text(): WooCommerce's currency
 * position and separators, no decimals for whole amounts („75 MDL").
 *
 * @param {number} amount
 * @param {object} [currency] `print_sizes.currency`.
 */
export function formatPrice(amount, currency) {
  const c = currency || {};
  const n = Number(amount) || 0;
  const decimals = Math.floor(n) === n ? 0 : Number(c.decimals) || 0;
  const parts = Math.abs(n).toFixed(decimals).split('.');
  const int = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, c.thousand || '');
  const num = (n < 0 ? '-' : '') + (parts[1] ? int + (c.decimal || ',') + parts[1] : int);
  const symbol = c.symbol || '';
  if (!symbol) {
    return num;
  }
  switch (c.position) {
    case 'left':
      return symbol + num;
    case 'left_space':
      return symbol + ' ' + num;
    case 'right':
      return num + symbol;
    default:
      return num + ' ' + symbol;
  }
}
