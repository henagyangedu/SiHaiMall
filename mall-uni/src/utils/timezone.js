/**
 * 买家设备的时区，放在请求头 X-Timezone 里；后端据此把接口里的时间换成买家当地的时间（见后端 LocalizeApiTimes）。
 * 优先用 IANA 名称（如 America/New_York，夏令时准确）；拿不到时（个别小程序基础库没有 Intl）退回当前的偏移（如 -05:00）。
 * PC 端也引用这个文件（@shared）。
 */
export function deviceTimezone() {
  try {
    const name = Intl.DateTimeFormat().resolvedOptions().timeZone
    if (name) return name
  } catch (e) {
    // 没有 Intl，用下面的偏移
  }
  const min = -new Date().getTimezoneOffset()
  const abs = Math.abs(min)
  const pad = (n) => String(n).padStart(2, '0')
  return `${min < 0 ? '-' : '+'}${pad(Math.floor(abs / 60))}:${pad(abs % 60)}`
}
