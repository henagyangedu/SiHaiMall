// 运费模板可选的国家/地区（ISO 3166-1 两位代码）。列表之外的国家可在下拉里直接输入两位代码添加。
export const COUNTRIES = [
  ['CN', '中国大陆'], ['HK', '中国香港'], ['MO', '中国澳门'], ['TW', '中国台湾'],
  ['US', '美国'], ['CA', '加拿大'], ['MX', '墨西哥'], ['BR', '巴西'], ['AR', '阿根廷'], ['CL', '智利'],
  ['GB', '英国'], ['DE', '德国'], ['FR', '法国'], ['IT', '意大利'], ['ES', '西班牙'], ['NL', '荷兰'],
  ['BE', '比利时'], ['CH', '瑞士'], ['AT', '奥地利'], ['SE', '瑞典'], ['NO', '挪威'], ['DK', '丹麦'],
  ['FI', '芬兰'], ['IE', '爱尔兰'], ['PT', '葡萄牙'], ['PL', '波兰'], ['CZ', '捷克'], ['GR', '希腊'],
  ['RU', '俄罗斯'], ['TR', '土耳其'],
  ['JP', '日本'], ['KR', '韩国'], ['SG', '新加坡'], ['MY', '马来西亚'], ['TH', '泰国'], ['VN', '越南'],
  ['PH', '菲律宾'], ['ID', '印度尼西亚'], ['IN', '印度'], ['PK', '巴基斯坦'], ['BD', '孟加拉国'],
  ['AE', '阿联酋'], ['SA', '沙特阿拉伯'], ['IL', '以色列'], ['QA', '卡塔尔'], ['KW', '科威特'],
  ['AU', '澳大利亚'], ['NZ', '新西兰'],
  ['ZA', '南非'], ['EG', '埃及'], ['NG', '尼日利亚'], ['KE', '肯尼亚'],
].map(([code, name]) => ({ code, name }))

export const OTHER_COUNTRIES = '*'

const NAMES = Object.fromEntries(COUNTRIES.map((c) => [c.code, c.name]))

export function countryName(code) {
  if (code === OTHER_COUNTRIES) return '其他所有国家/地区'
  return NAMES[code] ? `${NAMES[code]}（${code}）` : code
}
