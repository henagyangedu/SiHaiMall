// 收货地址可选的国家/地区（ISO 3166-1 两位代码）。[代码, 中文名, 英文名, 电话区号]
const LIST = [
  ['CN', '中国大陆', 'China (Mainland)', '86'], ['HK', '中国香港', 'Hong Kong', '852'], ['MO', '中国澳门', 'Macau', '853'], ['TW', '中国台湾', 'Taiwan', '886'],
  ['US', '美国', 'United States', '1'], ['CA', '加拿大', 'Canada', '1'], ['MX', '墨西哥', 'Mexico', '52'], ['BR', '巴西', 'Brazil', '55'],
  ['GB', '英国', 'United Kingdom', '44'], ['DE', '德国', 'Germany', '49'], ['FR', '法国', 'France', '33'], ['IT', '意大利', 'Italy', '39'],
  ['ES', '西班牙', 'Spain', '34'], ['NL', '荷兰', 'Netherlands', '31'], ['BE', '比利时', 'Belgium', '32'], ['CH', '瑞士', 'Switzerland', '41'],
  ['SE', '瑞典', 'Sweden', '46'], ['NO', '挪威', 'Norway', '47'], ['DK', '丹麦', 'Denmark', '45'], ['FI', '芬兰', 'Finland', '358'],
  ['IE', '爱尔兰', 'Ireland', '353'], ['PT', '葡萄牙', 'Portugal', '351'], ['PL', '波兰', 'Poland', '48'], ['AT', '奥地利', 'Austria', '43'],
  ['RU', '俄罗斯', 'Russia', '7'], ['TR', '土耳其', 'Türkiye', '90'],
  ['JP', '日本', 'Japan', '81'], ['KR', '韩国', 'South Korea', '82'], ['SG', '新加坡', 'Singapore', '65'], ['MY', '马来西亚', 'Malaysia', '60'],
  ['TH', '泰国', 'Thailand', '66'], ['VN', '越南', 'Vietnam', '84'], ['PH', '菲律宾', 'Philippines', '63'], ['ID', '印度尼西亚', 'Indonesia', '62'],
  ['IN', '印度', 'India', '91'], ['AE', '阿联酋', 'United Arab Emirates', '971'], ['SA', '沙特阿拉伯', 'Saudi Arabia', '966'], ['IL', '以色列', 'Israel', '972'],
  ['AU', '澳大利亚', 'Australia', '61'], ['NZ', '新西兰', 'New Zealand', '64'], ['ZA', '南非', 'South Africa', '27'],
]

/** 按界面语言返回 [{ code, name, area }] */
export function countries(lang) {
  const zh = String(lang).toLowerCase().startsWith('zh')
  return LIST.map(([code, cn, en, area]) => ({ code, name: zh ? cn : en, area }))
}

export function countryName(code, lang) {
  return countries(lang).find((c) => c.code === code)?.name || code
}
