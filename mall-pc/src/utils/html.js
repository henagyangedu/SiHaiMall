// 富文本（商品详情等，后台编辑器保存的内容）在用 v-html 渲染前先清洗。
// 后端不过滤这些内容，所以这里必须做：去掉能执行脚本或加载外部页面的标签，去掉事件属性和 javascript: 之类的地址。
const DROP = ['script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'link', 'meta', 'base', 'form', 'input', 'button', 'textarea', 'select', 'svg', 'math', 'template', 'noscript']
const URL_ATTRS = ['href', 'src', 'xlink:href', 'action', 'formaction', 'poster', 'background']
const SAFE_URL = /^(https?:|mailto:|tel:|\/|#|data:image\/(png|jpe?g|gif|webp);base64,)/i

export function safeHtml(html) {
  if (!html) return ''
  const doc = new DOMParser().parseFromString(`<div>${html}</div>`, 'text/html')
  doc.querySelectorAll(DROP.join(',')).forEach((el) => el.remove())
  doc.querySelectorAll('*').forEach((el) => {
    for (const attr of [...el.attributes]) {
      const name = attr.name.toLowerCase()
      const value = attr.value.trim()
      if (name.startsWith('on') || name === 'srcdoc') {
        el.removeAttribute(attr.name)
      } else if (URL_ATTRS.includes(name) && value !== '' && !SAFE_URL.test(value)) {
        el.removeAttribute(attr.name)
      } else if (name === 'style' && /expression\s*\(|url\s*\(\s*['"]?\s*javascript:/i.test(value)) {
        el.removeAttribute(attr.name)
      }
    }
    // 外链在新窗口打开，且不把本页的引用交给对方
    if (el.tagName === 'A' && /^https?:/i.test(el.getAttribute('href') || '')) {
      el.setAttribute('target', '_blank')
      el.setAttribute('rel', 'noopener noreferrer')
    }
  })

  return doc.body.firstElementChild?.innerHTML || ''
}
