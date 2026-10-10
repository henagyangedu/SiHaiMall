import { ElMessage } from 'element-plus'
import { getConfig } from '@/api/config'

// 订单小票：把订单拼成一张窄幅的 HTML，放进隐藏的 iframe 里调用浏览器打印。
// 打印机、纸张由浏览器的打印对话框选择；这里按“小票配置”里的纸宽排版（58mm / 80mm 热敏纸），普通打印机也能打。

const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c])

const DEFAULTS = { title: '', footer: '', paper_width: '80', show_price: 1, show_address: 1, show_remark: 1, show_admin_remark: 0 }

/**
 * 小票的 HTML（完整文档）。order 是后台订单详情接口的返回值，cfg 是小票配置。
 * 版式（从上到下）：抬头 → 订单号和时间 → 商品（品名一行，下面一行是规格和 数量 / 单价 / 金额 三列）→ 金额合计 → 收货信息 → 留言和备注 → 页脚。
 * 只用黑色、实线和虚线，不用底色和灰字：热敏小票机打不出灰度。
 * 屏幕上看（小票配置页的预览）时四周多留 3 毫米，对应打印时的页边距，这样预览和打出来的是一个样子。
 */
export function receiptHtml(order, cfg = {}) {
  const c = { ...DEFAULTS, ...cfg }
  const on = (k) => Number(c[k]) === 1
  const width = String(c.paper_width) === '58' ? 58 : 80
  const narrow = width === 58
  const price = on('show_price')
  const a = order.address

  // 有规格的商品占两行（品名一行，规格和数字一行）；没有规格的品名和数字在同一行，省纸
  const items = (order.items || []).map((i) => {
    const nums = `<span class="num">${price ? '' : '× '}${esc(i.qty)}</span>${price ? `<span class="num">${esc(i.price)}</span><span class="num">${esc(i.subtotal)}</span>` : ''}`
    return i.spec_text
      ? `<div class="item"><div class="name">${esc(i.title)}</div><div class="cols"><span class="spec">${esc(i.spec_text)}</span>${nums}</div></div>`
      : `<div class="item"><div class="cols"><span class="name">${esc(i.title)}</span>${nums}</div></div>`
  }).join('')

  const sum = (label, value, minus = false) => `<div class="row"><span>${label}</span><span>${minus ? '-' : ''}${esc(value)}</span></div>`
  const sums = price ? `
    <div class="line"></div>
    ${sum('商品金额', order.goods_amount)}
    ${sum('运费', order.shipping_fee)}
    ${Number(order.bundle_amount) > 0 ? sum('套餐优惠', order.bundle_amount, true) : ''}
    ${Number(order.discount_amount) > 0 ? sum('优惠', order.discount_amount, true) : ''}
    ${Number(order.coupon_amount) > 0 ? sum('优惠券', order.coupon_amount, true) : ''}
    ${Number(order.points_amount) > 0 ? sum('积分抵扣', order.points_amount, true) : ''}
    ${Number(order.tax_amount) > 0 && !order.tax_included ? sum(`${esc(order.tax_name)} ${esc(order.tax_rate)}%`, order.tax_amount) : ''}
    <div class="row total"><span>实付</span><span>${esc(order.pay_amount)}<small> ${esc(order.currency)}</small></span></div>
    ${Number(order.tax_amount) > 0 && order.tax_included ? sum(`其中含 ${esc(order.tax_name)} ${esc(order.tax_rate)}%`, order.tax_amount) : ''}` : ''

  // 国内地址不印国家码；境外的印在最前面
  const region = a ? [String(a.country_code || '').toUpperCase() === 'CN' ? '' : a.country_code, a.province, a.city, a.district].filter(Boolean).join(' ') : ''
  const address = on('show_address') && a ? `
    <div class="line"></div>
    <div class="kv"><span class="k">收货人</span><span class="v strong">${esc(a.name)}　+${esc(a.phone_area)} ${esc(a.phone)}</span></div>
    <div class="kv"><span class="k">地址</span><span class="v">${esc([region, a.address, a.address2].filter(Boolean).join(' '))}${a.postcode ? ` <span class="nowrap">${esc(a.postcode)}</span>` : ''}</span></div>` : ''

  const remarks = [
    on('show_remark') && order.remark ? `<div class="kv"><span class="k">买家留言</span><span class="v">${esc(order.remark)}</span></div>` : '',
    on('show_admin_remark') && order.admin_remark ? `<div class="kv"><span class="k">商家备注</span><span class="v">${esc(order.admin_remark)}</span></div>` : '',
  ].join('')

  // 数量 / 单价 / 金额三列的宽度；不打印金额时只有数量一列
  const cols = price ? (narrow ? '1fr 2em 4.2em 4.6em' : '1fr 2.6em 5em 5.4em') : '1fr 4em'

  return `<!doctype html><html><head><meta charset="utf-8"><title>${esc(order.order_no)}</title><style>
    @page { size: ${width}mm auto; margin: 3mm; }
    * { box-sizing: border-box; }
    html { background: #fff; }
    body { margin: 0; width: ${width - 6}mm; font-family: 'Microsoft YaHei', 'PingFang SC', Arial, sans-serif; font-size: ${narrow ? 11 : 12}px; line-height: 1.55; color: #000; }
    @media screen { html { padding: 3mm; } }
    div, span { overflow-wrap: anywhere; }
    .nowrap { white-space: nowrap; }
    h1 { font-size: 1.5em; line-height: 1.3; text-align: center; margin: 0 0 2px; letter-spacing: 1px; }
    .sub { text-align: center; margin-bottom: 6px; letter-spacing: 4px; }
    .line { border-top: 1px dashed #000; margin: 7px 0; }
    .solid { border-top: 1px solid #000; margin: 7px 0 4px; }
    .row { display: flex; justify-content: space-between; gap: 8px; }
    .kv { display: flex; gap: 8px; }
    .k { flex: none; width: 4.2em; }
    .v { flex: 1; min-width: 0; }
    .strong { font-weight: bold; }
    .cols { display: grid; grid-template-columns: ${cols}; column-gap: 4px; align-items: baseline; }
    .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .head { font-weight: bold; padding-bottom: 3px; border-bottom: 1px solid #000; margin-bottom: 5px; }
    .item { margin-bottom: 5px; }
    .name { font-weight: bold; }
    .spec { font-size: 0.92em; }
    .total { font-weight: bold; font-size: 1.35em; line-height: 1.6; margin-top: 2px; }
    .total small { font-size: 0.6em; font-weight: normal; }
    .footer { text-align: center; white-space: pre-wrap; margin-top: 2px; }
    .end { text-align: center; letter-spacing: 2px; margin-top: 8px; }
  </style></head><body>
    ${c.title ? `<h1>${esc(c.title)}</h1>` : ''}
    <div class="sub">订单小票</div>
    <div class="kv"><span class="k">订单号</span><span class="v strong">${esc(order.order_no)}</span></div>
    <div class="kv"><span class="k">下单时间</span><span class="v">${esc(order.created_at)}</span></div>
    ${order.paid_at ? `<div class="kv"><span class="k">支付时间</span><span class="v">${esc(order.paid_at)}</span></div>` : ''}
    <div class="solid"></div>
    <div class="cols head"><span>商品</span><span class="num">数量</span>${price ? '<span class="num">单价</span><span class="num">金额</span>' : ''}</div>
    ${items}
    ${sums}
    ${address}
    ${remarks ? `<div class="line"></div>${remarks}` : ''}
    ${c.footer ? `<div class="line"></div><div class="footer">${esc(c.footer)}</div>` : ''}
    <div class="end">- - - - - - - -</div>
  </body></html>`
}

/** 把一段完整的 HTML 交给浏览器打印。iframe 留在页面里直到下一次打印，打印对话框关掉后不影响页面 */
export function printHtml(html) {
  document.getElementById('gm-print-frame')?.remove()
  const frame = document.createElement('iframe')
  frame.id = 'gm-print-frame'
  frame.style.cssText = 'position: fixed; right: 0; bottom: 0; width: 0; height: 0; border: 0;'
  frame.onload = () => {
    frame.contentWindow.focus()
    frame.contentWindow.print()
  }
  frame.srcdoc = html
  document.body.appendChild(frame)
}

/** 打印一张订单小票：读取小票配置（读不到就用默认样式），然后打印 */
export async function printOrderReceipt(order) {
  let cfg = {}
  try {
    cfg = await getConfig('receipt')
  } catch (e) {
    ElMessage.warning('没有读到小票配置，按默认样式打印')
  }
  printHtml(receiptHtml(order, cfg))
}
