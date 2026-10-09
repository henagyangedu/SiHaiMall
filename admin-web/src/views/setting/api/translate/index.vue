<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { listDriver, saveDriver, testDriver } from '@/api/driver'
import { languageOptions } from '@/api/language'
import {
  getTranslateSettings, saveTranslateSettings, listGlossary, createGlossary, updateGlossary, deleteGlossary, listTranslateLog,
} from '@/api/translate'

const tab = ref('models')
const languages = ref([])

// ====================== 模型配置 ======================
const drivers = ref([])
const testing = ref('')
async function loadDrivers() {
  drivers.value = await listDriver('translate')
}
// 列表上直接开关：只提交 is_enabled，其余配置保持不变；成功后才翻转开关
async function toggleEnabled(r) {
  const next = r.is_enabled === 1 ? 0 : 1
  try {
    await saveDriver('translate', r.driver, { is_enabled: next })
    r.is_enabled = next
    ElMessage.success(next === 1 ? `已启用 ${r.name}` : `已关闭 ${r.name}`)
    return true
  } catch (e) {
    return false
  }
}
const dialog = ref(false)
const row = ref(null)
const form = reactive({ is_enabled: 0, config: {} })
function openConfig(r) {
  row.value = r
  form.is_enabled = r.is_enabled
  form.config = { ...r.config }
  dialog.value = true
}
async function submitDriver() {
  if (form.is_enabled === 1) {
    for (const f of row.value.fields) {
      if (f.required && !String(form.config[f.key] ?? '').trim()) {
        ElMessage.error(`请填写「${f.label}」`)
        return
      }
    }
  }
  await saveDriver('translate', row.value.driver, { is_enabled: form.is_enabled, config: form.config })
  ElMessage.success('保存成功')
  dialog.value = false
  loadDrivers()
}
async function onTest(r) {
  testing.value = r.driver
  try {
    const d = await testDriver('translate', r.driver)
    await ElMessageBox.alert(`连接成功。\n模型：${d.model}\n测试句子“你好，欢迎光临”的译文：${d.reply}`, '测试通过', { type: 'success' })
  } finally {
    testing.value = ''
  }
}

// ====================== 路由与规则 ======================
const settings = reactive({ default_route: { primary: '', fallback: '' }, lang_routes: {}, daily_limit_chars: 0, prompt_extra: '' })
const basePrompt = ref('')
const savingSettings = ref(false)
const driverOptions = computed(() => drivers.value.map((d) => ({ value: d.driver, label: d.name + (d.is_enabled === 1 ? '' : '（未启用）') })))
async function loadSettings() {
  const d = await getTranslateSettings()
  settings.default_route = { ...d.default_route }
  settings.lang_routes = Object.fromEntries(Object.entries(d.lang_routes || {}).map(([k, v]) => [k, { ...v }]))
  settings.daily_limit_chars = d.daily_limit_chars
  settings.prompt_extra = d.prompt_extra
  basePrompt.value = d.base_prompt
  // 保证每种语言都有可编辑的行
  languages.value.forEach((l) => {
    if (!settings.lang_routes[l.code]) settings.lang_routes[l.code] = { primary: '', fallback: '' }
  })
}
async function submitSettings() {
  savingSettings.value = true
  try {
    const lang_routes = {}
    for (const [code, r] of Object.entries(settings.lang_routes)) {
      if (r.primary || r.fallback) lang_routes[code] = r
    }
    await saveTranslateSettings({
      default_route: settings.default_route,
      lang_routes,
      daily_limit_chars: settings.daily_limit_chars,
      prompt_extra: settings.prompt_extra,
    })
    ElMessage.success('保存成功')
  } finally {
    savingSettings.value = false
  }
}

// ====================== 术语表 ======================
const gq = reactive({ keyword: '', lang: '', page: 1, size: 20 })
const glist = ref([])
const gtotal = ref(0)
const gdialog = ref(false)
const gform = reactive({ id: 0, term: '', lang: '', translation: '' })
async function loadGlossary() {
  const d = await listGlossary(gq)
  glist.value = d.list
  gtotal.value = d.total
}
function gsearch() {
  gq.page = 1
  loadGlossary()
}
function openGlossary(r) {
  Object.assign(gform, r ? { id: r.id, term: r.term, lang: r.lang, translation: r.translation } : { id: 0, term: '', lang: languages.value.find((l) => !l.is_default)?.code || '', translation: '' })
  gdialog.value = true
}
async function submitGlossary() {
  const data = { term: gform.term, lang: gform.lang, translation: gform.translation }
  if (gform.id) await updateGlossary(gform.id, data)
  else await createGlossary(data)
  ElMessage.success('保存成功')
  gdialog.value = false
  loadGlossary()
}
async function delGlossary(r) {
  await ElMessageBox.confirm(`确定删除术语「${r.term}」吗？`, '提示', { type: 'warning' })
  await deleteGlossary(r.id)
  loadGlossary()
}

// ====================== 用量日志 ======================
const lq = reactive({ status: '', page: 1, size: 20 })
const lrange = ref([])
const llist = ref([])
const ltotal = ref(0)
const todayChars = ref(0)
async function loadLog() {
  const d = await listTranslateLog({ ...lq, date_from: lrange.value?.[0] || '', date_to: lrange.value?.[1] || '' })
  llist.value = d.list
  ltotal.value = d.total
  todayChars.value = d.today_chars
}
function lsearch() {
  lq.page = 1
  loadLog()
}

onMounted(async () => {
  // 先把每种语言的路由行补齐，再赋值 languages：否则表格先渲染、lang_routes[code] 还是 undefined，会抛错并导致页面切换后内容不更新
  const langs = await languageOptions()
  langs.forEach((l) => {
    if (!settings.lang_routes[l.code]) settings.lang_routes[l.code] = { primary: '', fallback: '' }
  })
  languages.value = langs
  await loadDrivers()
  await Promise.all([loadSettings(), loadGlossary(), loadLog()])
})
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <el-tabs v-model="tab">
        <!-- 模型配置 -->
        <el-tab-pane label="模型配置" name="models">
          <el-alert type="info" :closable="false" show-icon style="margin-bottom: 12px"
            title="填写接口地址、API Key 和模型名称后启用即可。模型名称不写死在系统里，换模型只需改这里。密钥加密保存，页面不再显示。" />
          <el-table :data="drivers" border stripe>
            <el-table-column prop="name" label="模型服务" min-width="170" />
            <el-table-column prop="desc" label="说明" min-width="300" show-overflow-tooltip />
            <el-table-column label="配置" width="90"><template #default="{ row: r }"><el-tag :type="r.configured ? 'success' : 'info'">{{ r.configured ? '已配置' : '未配置' }}</el-tag></template></el-table-column>
            <el-table-column label="状态" width="110">
              <template #default="{ row: r }">
                <span :title="r.configured ? '' : '请先点「配置」填写完整后再启用'">
                  <el-switch
                    :model-value="r.is_enabled === 1"
                    inline-prompt active-text="开启" inactive-text="关闭"
                    :disabled="!r.configured && r.is_enabled !== 1"
                    :before-change="() => toggleEnabled(r)"
                  />
                </span>
              </template>
            </el-table-column>
            <el-table-column label="操作" width="180">
              <template #default="{ row: r }">
                <el-button v-perm="'setting:api_translate:edit'" link type="primary" @click="openConfig(r)">配置</el-button>
                <el-button v-perm="'setting:api_translate:test'" link type="success" :loading="testing === r.driver" @click="onTest(r)">测试连接</el-button>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>

        <!-- 路由与规则 -->
        <el-tab-pane label="路由与规则" name="route">
          <el-form label-width="130px" style="max-width: 860px">
            <el-form-item label="默认模型">
              <el-select v-model="settings.default_route.primary" clearable placeholder="主模型（留空取已启用的第一个）" style="width: 240px">
                <el-option v-for="o in driverOptions" :key="o.value" :label="o.label" :value="o.value" />
              </el-select>
              <el-select v-model="settings.default_route.fallback" clearable placeholder="备用模型（可选）" style="width: 240px; margin-left: 12px">
                <el-option v-for="o in driverOptions" :key="o.value" :label="o.label" :value="o.value" />
              </el-select>
            </el-form-item>
            <el-form-item label="按目标语言指定">
              <el-table :data="languages.filter((l) => !l.is_default)" border size="small" style="width: 100%">
                <el-table-column prop="name" label="目标语言" width="140" />
                <el-table-column label="主模型" width="220">
                  <template #default="{ row: l }">
                    <el-select v-model="settings.lang_routes[l.code].primary" clearable placeholder="跟随默认" size="small" style="width: 100%">
                      <el-option v-for="o in driverOptions" :key="o.value" :label="o.label" :value="o.value" />
                    </el-select>
                  </template>
                </el-table-column>
                <el-table-column label="备用模型" width="220">
                  <template #default="{ row: l }">
                    <el-select v-model="settings.lang_routes[l.code].fallback" clearable placeholder="跟随默认" size="small" style="width: 100%">
                      <el-option v-for="o in driverOptions" :key="o.value" :label="o.label" :value="o.value" />
                    </el-select>
                  </template>
                </el-table-column>
              </el-table>
              <div class="hint">例如：English 的主模型选 GPT，简体中文选 DeepSeek。主模型失败（网络、鉴权、输出格式异常）时自动改用备用模型。</div>
            </el-form-item>
            <el-form-item label="每日额度（字符）">
              <el-input-number v-model="settings.daily_limit_chars" :min="0" :step="10000" />
              <span class="hint-inline">0 表示不限制；今日已用 {{ todayChars }} 字符（命中缓存的不计）</span>
            </el-form-item>
            <el-form-item label="附加规则">
              <el-input v-model="settings.prompt_extra" type="textarea" :rows="4" maxlength="2000" show-word-limit placeholder="追加到系统提示词后面的规则，如：服装类目的尺码保持原写法；语气偏专业。不能改变输出格式。" />
            </el-form-item>
            <el-form-item label="基础提示词（只读）">
              <el-collapse style="width: 100%"><el-collapse-item title="点击查看系统内置的提示词约束"><pre class="prompt">{{ basePrompt }}</pre></el-collapse-item></el-collapse>
            </el-form-item>
            <el-form-item><el-button v-perm="'setting:api_translate:edit'" type="primary" :loading="savingSettings" @click="submitSettings">保存设置</el-button></el-form-item>
          </el-form>
        </el-tab-pane>

        <!-- 术语表 -->
        <el-tab-pane label="术语表" name="glossary">
          <el-form :inline="true" @submit.prevent="gsearch">
            <el-form-item><el-input v-model="gq.keyword" placeholder="术语 / 译法" clearable style="width: 200px" @keyup.enter="gsearch" /></el-form-item>
            <el-form-item>
              <el-select v-model="gq.lang" clearable placeholder="全部语言" style="width: 140px" @change="gsearch">
                <el-option v-for="l in languages.filter((x) => !x.is_default)" :key="l.code" :label="l.name" :value="l.code" />
              </el-select>
            </el-form-item>
            <el-form-item>
              <el-button type="primary" @click="gsearch">查询</el-button>
              <el-button v-perm="'setting:api_translate:edit'" type="success" @click="openGlossary(null)">新增术语</el-button>
            </el-form-item>
          </el-form>
          <el-table :data="glist" border stripe>
            <el-table-column prop="term" label="原文术语（默认语言）" min-width="200" />
            <el-table-column prop="lang" label="目标语言" width="110" />
            <el-table-column prop="translation" label="固定译法" min-width="200" />
            <el-table-column prop="created_at" label="创建时间" width="170" />
            <el-table-column label="操作" width="130">
              <template #default="{ row: r }">
                <el-button v-perm="'setting:api_translate:edit'" link type="primary" @click="openGlossary(r)">编辑</el-button>
                <el-button v-perm="'setting:api_translate:edit'" link type="danger" @click="delGlossary(r)">删除</el-button>
              </template>
            </el-table-column>
          </el-table>
          <div class="pager"><el-pagination v-model:current-page="gq.page" v-model:page-size="gq.size" :total="gtotal" layout="total, prev, pager, next" @current-change="loadGlossary" /></div>
          <div class="hint">品牌名、型号、专有名词在这里指定固定译法；翻译时只会带入原文里出现过的术语。</div>
        </el-tab-pane>

        <!-- 用量日志 -->
        <el-tab-pane label="用量日志" name="log">
          <el-form :inline="true" @submit.prevent="lsearch">
            <el-form-item>
              <el-select v-model="lq.status" clearable placeholder="结果" style="width: 110px">
                <el-option label="成功" :value="1" />
                <el-option label="失败" :value="0" />
              </el-select>
            </el-form-item>
            <el-form-item><el-date-picker v-model="lrange" type="daterange" value-format="YYYY-MM-DD" range-separator="至" start-placeholder="开始日期" end-placeholder="结束日期" /></el-form-item>
            <el-form-item><el-button type="primary" @click="lsearch">查询</el-button></el-form-item>
            <el-form-item><span class="hint-inline">今日已用 {{ todayChars }} 字符</span></el-form-item>
          </el-form>
          <el-table :data="llist" border stripe size="small">
            <el-table-column prop="created_at" label="时间" width="170" />
            <el-table-column prop="admin" label="操作人" width="100" />
            <el-table-column prop="driver" label="模型服务" width="100" />
            <el-table-column prop="model" label="模型" min-width="150" show-overflow-tooltip />
            <el-table-column label="方向" width="130"><template #default="{ row: r }">{{ r.from_lang }} → {{ r.to_lang }}</template></el-table-column>
            <el-table-column prop="input_chars" label="原文字符" width="100" />
            <el-table-column prop="output_chars" label="译文字符" width="100" />
            <el-table-column label="缓存" width="70"><template #default="{ row: r }">{{ r.cached ? '命中' : '' }}</template></el-table-column>
            <el-table-column label="结果" width="80"><template #default="{ row: r }"><el-tag :type="r.status === 1 ? 'success' : 'danger'">{{ r.status === 1 ? '成功' : '失败' }}</el-tag></template></el-table-column>
            <el-table-column prop="error" label="错误信息" min-width="220" show-overflow-tooltip />
          </el-table>
          <div class="pager"><el-pagination v-model:current-page="lq.page" v-model:page-size="lq.size" :total="ltotal" layout="total, prev, pager, next" @current-change="loadLog" /></div>
        </el-tab-pane>
      </el-tabs>
    </el-card>

    <!-- 模型配置弹窗 -->
    <el-dialog v-model="dialog" :title="`配置：${row?.name || ''}`" width="620px" destroy-on-close>
      <el-form v-if="row" label-width="110px">
        <el-form-item v-for="f in row.fields" :key="f.key" :label="f.label" :required="f.required">
          <el-input v-model="form.config[f.key]" :type="f.type === 'secret' ? 'password' : 'text'" :show-password="f.type === 'secret'" />
          <div v-if="f.hint" class="hint">{{ f.hint }}</div>
        </el-form-item>
        <el-form-item label="启用">
          <el-switch v-model="form.is_enabled" :active-value="1" :inactive-value="0" />
          <span class="hint-inline">启用前请填写完整的必填项</span>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialog = false">取消</el-button>
        <el-button type="primary" @click="submitDriver">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.hint { color: #909399; font-size: 12px; line-height: 1.5; margin-top: 4px; width: 100%; }
.hint-inline { margin-left: 10px; color: #909399; font-size: 12px; }
.prompt { white-space: pre-wrap; font-size: 12px; line-height: 1.6; margin: 0; background: #f7f8fa; padding: 10px; border-radius: 4px; }
</style>
