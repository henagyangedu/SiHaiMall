import { ElMessage } from 'element-plus'
import { getConfig } from '@/api/config'

// 订单小票：把订单拼成一张窄幅的 HTML，放进隐藏的 iframe 里调用浏览器打印。
// 打印机、纸张由浏览器的打印对话框选择；这里按“小票配置”里的纸宽排版（58mm / 80mm 热敏纸），普通打印机也能打。

const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c])

const DEFAULTS = { title: '', footer: '', paper_width: '80', show_price: 1, show_address: 1, show_remark: 1, show_admin_remark: 0 }

/** 小票的 HTML（完整文档）。order 是后台订单详情接口的返回值，cfg 是小票配置 */
export function receiptHtml(order, cfg = {}) {
  const c = { ...DEFAULTS, ...cfg }
  const on = (k) => Number(c[k]) === 1
  const width = String(c.paper_width) === '58' ? 58 : 80
  const money = (v) => `${esc(v)} ${esc(order.currency)}`
  const a = order.address

  const items = (order.items || []).map((i) => `
    <div class="item">
      <div>${esc(i.title)}${i.spec_text ? `<span class="spec">（${esc(i.spec_text)}）</span>` : ''}</div>
      <div class="row"><span>× ${esc(i.qty)}</span>${on('show_price') ? `<span>${esc(i.price)}　${esc(i.subtotal)}</span>` : ''}</div>
    </div>`).join('')

  const sums = on('show_price') ? `
    <div class="line"></div>
    <div class="row"><span>商品金额</span><span>${esc(order.goods_amount)}</span></div>
    <div class="row"><span>运费</span><span>${esc(order.shipping_fee)}</span></div>
    ${Number(order.bundle_amount) > 0 ? `<div class="row"><span>套餐优惠</span><span>-${esc(order.bundle_amount)}</span></div>` : ''}
    ${Number(order.discount_amount) > 0 ? `<div class="row"><span>优惠</span><span>-${esc(order.discount_amount)}</span></div>` : ''}
    ${Number(order.coupon_amount) > 0 ? `<div class="row"><span>优惠券</span><span>-${esc(order.coupon_amount)}</span></div>` : ''}
    ${Number(order.points_amount) > 0 ? `<div class="row"><span>积分抵扣</span><span>-${esc(order.points_amount)}</span></div>` : ''}
    <div class="row total"><span>实付</span><span>${money(order.pay_amount)}</span></div>` : ''

  const address = on('show_address') && a ? `
    <div class="line"></div>
    <div>${esc(a.name)}　+${esc(a.phone_area)} ${esc(a.phone)}</div>
    <div>${esc([a.country_code, a.province, a.city, a.district, a.address, a.address2].filter(Boolean).join(' '))}${a.postcode ? `　${esc(a.postcode)}` : ''}</div>` : ''

  const remarks = [
    on('show_remark') && order.remark ? `<div>买家留言：${esc(order.remark)}</div>` : '',
    on('show_admin_remark') && order.admin_remark ? `<div>商家备注：${esc(order.admin_remark)}</div>` : '',
  ].join('')

  return `<!doctype html><html><head><meta charset="utf-8"><title>${esc(order.order_no)}</title><style>
    @page { size: ${width}mm auto; margin: 3mm; }
    * { box-sizing: border-box; }
    body { margin: 0; width: ${width - 6}mm; font-family: 'Microsoft YaHei', 'PingFang SC', Arial, sans-serif; font-size: ${width === 58 ? 11 : 12}px; line-height: 1.5; color: #000; }
    h1 { font-size: 1.35em; text-align: center; margin: 0 0 4px; }
    .line { border-top: 1px dashed #000; margin: 6px 0; }
    .row { display: flex; justify-content: space-between; gap: 8px; }
    .item { margin-bottom: 4px; }
    .spec { color: #333; }
    .total { font-weight: bold; font-size: 1.15em; }
    .footer { text-align: center; margin-top: 8px; white-space: pre-wrap; }
    div { word-break: break-all; }
  </style></head><body>
    ${c.title ? `<h1>${esc(c.title)}</h1>` : ''}
    <div>订单号：${esc(order.order_no)}</div>
    <div>下单时间：${esc(order.created_at)}</div>
    ${order.paid_at ? `<div>支付时间：${esc(order.paid_at)}</div>` : ''}
    <div class="line"></div>
    ${items}
    ${sums}
    ${address}
    ${remarks ? `<div class="line"></div>${remarks}` : ''}
    ${c.footer ? `<div class="line"></div><div class="footer">${esc(c.footer)}</div>` : ''}
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
