// 搜索历史：只存在这台设备上，最近搜的排在前面，最多 10 条
const KEY = 'gm_search_history'
const MAX = 10

export function searchHistory() {
  try {
    const v = uni.getStorageSync(KEY)
    return Array.isArray(v) ? v.filter((w) => typeof w === 'string').slice(0, MAX) : []
  } catch (e) {
    return []
  }
}

/** 记一条搜索词，返回最新的历史 */
export function addSearchHistory(word) {
  const w = String(word || '').trim().slice(0, 50)
  if (!w) return searchHistory()
  const list = [w, ...searchHistory().filter((x) => x !== w)].slice(0, MAX)
  try { uni.setStorageSync(KEY, list) } catch (e) { /* 存不了就算了 */ }
  return list
}

export function clearSearchHistory() {
  try { uni.removeStorageSync(KEY) } catch (e) { /* 同上 */ }
  return []
}
