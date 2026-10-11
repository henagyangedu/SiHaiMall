/**
 * 第一次来的访客用什么界面语言：按他的浏览器（小程序里是微信）设置的语言，在后台启用的语言里挑一个。
 * PC 端也引用这个文件（@shared）。
 */

/** 设备上设置的语言，按买家的偏好排序，如 ['de-DE', 'de', 'en']。拿不到时是空数组 */
export function deviceLangs() {
  let list = []
  try {
    if (typeof navigator !== 'undefined') {
      list = navigator.languages && navigator.languages.length ? [...navigator.languages] : [navigator.language]
    }
  } catch (e) {
    // 小程序里没有 navigator，用下面的系统信息
  }
  if (!list.filter(Boolean).length) {
    try {
      // 微信给的是 zh_CN、en 这种
      list = [uni.getSystemInfoSync().language]
    } catch (e) {
      list = []
    }
  }
  return list.filter(Boolean).map((l) => String(l).replace(/_/g, '-'))
}

/**
 * 在启用的语言（codes）里挑一个最合适的，挑不出来返回空串。
 * 按买家的偏好一个个试：编码完全一样的 → 只有前半段一样的（es-MX 配 es，zh-TW 配 zh-CN）。
 */
export function pickLang(codes, wanted = deviceLangs()) {
  const lower = (s) => String(s).toLowerCase()
  const base = (s) => lower(s).split('-')[0]
  for (const want of wanted) {
    const exact = codes.find((c) => lower(c) === lower(want))
    if (exact) return exact
    const same = codes.filter((c) => base(c) === base(want))
    if (same.length) return same.find((c) => lower(c) === base(want)) || same[0]
  }
  return ''
}
