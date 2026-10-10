<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { listBroadcast, createBroadcast, sendBroadcast, revokeBroadcast, levelOptions } from '@/api/member'
import { languageOptions } from '@/api/language'
import LangInput from '@/components/LangInput.vue'

// 群发消息：给会员的消息中心（站内信）发活动、公告类的消息。建好后由每分钟的定时任务分批发，也可以点“立即发送”
const STATUS = { 0: ['待发送', 'warning'], 1: ['发送中', 'primary'], 2: ['已完成', 'success'], 3: ['已撤回', 'info'] }
const LINKS = { goods: '商品', article: '文章', coupon: '领券中心' }

const query = reactive({ status: '', page: 1, size: 15 })
const list = ref([])
const total = ref(0)
const loading = ref(false)
const languages = ref([])
const levels = ref([])

async function load() {
  loading.value = true
  try {
    const d = await listBroadcast(query)
    list.value = d.list
    total.value = d.total
  } finally {
    loading.value = false
  }
}
function search() {
  query.page = 1
  load()
}

// ---- 新建
const emptyForm = () => ({ title: {}, content: {}, target_type: 1, level_ids: [], members: '', link_type: 'none', link_value: undefined })
const form = reactive(emptyForm())
const dialog = ref(false)
const saving = ref(false)

function openCreate() {
  Object.assign(form, emptyForm())
  dialog.value = true
}
async function submit() {
  const def = languages.value.find((l) => l.is_default)?.code
  if (def && !(form.title[def] || '').trim()) return ElMessage.error('请填写默认语言的标题')
  if (def && !(form.content[def] || '').trim()) return ElMessage.error('请填写默认语言的正文')
  const members = form.members.split(/[\n,，;；\s]+/).map((x) => x.trim()).filter(Boolean)
  if (form.target_type === 2 && !form.level_ids.length) return ElMessage.error('请选择会员等级')
  if (form.target_type === 3 && !members.length) return ElMessage.error('请填写要发给哪些会员')
  if (['goods', 'article'].includes(form.link_type) && !form.link_value) return ElMessage.error(`请填写要跳转的${LINKS[form.link_type]} ID`)
  const payload = { title: form.title, content: form.content, target_type: form.target_type }
  if (form.target_type === 2) payload.level_ids = form.level_ids
  if (form.target_type === 3) payload.members = members
  if (form.link_type !== 'none') payload.link_type = form.link_type
  if (['goods', 'article'].includes(form.link_type)) payload.link_value = form.link_value
  saving.value = true
  try {
    await createBroadcast(payload)
    ElMessage.success('已提交，一分钟内开始发送')
    dialog.value = false
    search()
  } finally {
    saving.value = false
  }
}

// ---- 操作
async function sendNow(r) {
  const d = await sendBroadcast(r.id)
  ElMessage.success(d.status === 2 ? `已发送给 ${d.sent_count} 位会员` : `已发送 ${d.sent_count} 位，剩下的稍后继续`)
  load()
}
async function revoke(r) {
  await ElMessageBox.confirm(`确定撤回「${r.title_text}」吗？撤回后不会再发送。`, '提示', { type: 'warning' })
  await revokeBroadcast(r.id)
  ElMessage.success('已撤回')
  load()
}

// ---- 查看内容
const view = reactive({ show: false, row: null })
function openView(r) {
  Object.assign(view, { show: true, row: r })
}
const langName = (code) => languages.value.find((l) => l.code === code)?.name || code
function linkText(r) {
  if (!r.link_type) return '不跳转'
  return LINKS[r.link_type] + (r.link_value ? ` #${r.link_value}` : '')
}

onMounted(() => {
  load()
  languageOptions().then((d) => { languages.value = d }).catch(() => {})
  levelOptions().then((d) => { levels.value = d }).catch(() => {})
})
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <el-alert type="info" :closable="false" show-icon style="margin-bottom: 12px"
        title="群发消息只发到会员的消息中心（站内信），不发邮件。提交后由定时任务在一分钟内开始分批发送，会员按自己的语言收到对应的内容；开始发送后不能撤回，发出去的内容不能修改。" />
      <el-form :inline="true" @submit.prevent="search">
        <el-form-item>
          <el-select v-model="query.status" placeholder="状态" clearable style="width: 120px" @change="search">
            <el-option v-for="(s, k) in STATUS" :key="k" :label="s[0]" :value="Number(k)" />
          </el-select>
        </el-form-item>
        <el-form-item><el-button @click="search">刷新</el-button></el-form-item>
        <el-form-item><el-button v-perm="'member:broadcast:add'" type="success" @click="openCreate">新建群发消息</el-button></el-form-item>
      </el-form>

      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="标题" min-width="240" show-overflow-tooltip>
          <template #default="{ row }"><a class="title-link" @click="openView(row)">{{ row.title_text }}</a></template>
        </el-table-column>
        <el-table-column prop="target_text" label="发给谁" min-width="160" show-overflow-tooltip />
        <el-table-column label="跳转" width="130"><template #default="{ row }">{{ linkText(row) }}</template></el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }"><el-tag :type="STATUS[row.status][1]">{{ STATUS[row.status][0] }}</el-tag></template>
        </el-table-column>
        <el-table-column prop="sent_count" label="已发人数" width="100" />
        <el-table-column prop="admin_name" label="操作人" width="110" />
        <el-table-column prop="created_at" label="提交时间" width="170" />
        <el-table-column label="操作" width="150" fixed="right">
          <template #default="{ row }">
            <el-button v-if="row.status <= 1" v-perm="'member:broadcast:add'" link type="success" @click="sendNow(row)">{{ row.status === 0 ? '立即发送' : '继续发送' }}</el-button>
            <el-button v-if="row.status === 0" v-perm="'member:broadcast:revoke'" link type="danger" @click="revoke(row)">撤回</el-button>
          </template>
        </el-table-column>
      </el-table>
      <div class="pager">
        <el-pagination v-model:current-page="query.page" v-model:page-size="query.size" :total="total" :page-sizes="[15, 30, 50]" layout="total, sizes, prev, pager, next" @current-change="load" @size-change="search" />
      </div>
    </el-card>

    <el-dialog v-model="dialog" title="新建群发消息" width="640px" destroy-on-close>
      <el-form :model="form" label-width="90px">
        <el-form-item label="标题" required><LangInput v-model="form.title" :languages="languages" :maxlength="200" ai field-key="title" /></el-form-item>
        <el-form-item label="正文" required>
          <LangInput v-model="form.content" :languages="languages" type="textarea" :rows="5" :maxlength="2000" ai field-key="summary" />
          <div class="sub">纯文字，可以换行。默认语言必填；其他语言没填的，用那种语言的会员收到默认语言的内容。</div>
        </el-form-item>
        <el-form-item label="发给谁" required>
          <el-radio-group v-model="form.target_type">
            <el-radio :value="1">全部会员</el-radio>
            <el-radio :value="2">指定等级</el-radio>
            <el-radio :value="3">指定会员</el-radio>
          </el-radio-group>
          <el-select v-if="form.target_type === 2" v-model="form.level_ids" multiple placeholder="选择会员等级" style="width: 100%; margin-top: 8px">
            <el-option v-for="l in levels" :key="l.id" :label="l.name" :value="l.id" />
          </el-select>
          <template v-if="form.target_type === 3">
            <el-input v-model="form.members" type="textarea" :rows="4" style="margin-top: 8px" placeholder="会员 ID、账号、手机号或邮箱，一行一个，或用逗号、空格分开" />
            <div class="sub">一次最多 500 位；有一个找不到（或已被禁用）就整批不发。</div>
          </template>
          <div class="sub">只发给状态正常的会员。</div>
        </el-form-item>
        <el-form-item label="点了跳去哪">
          <el-select v-model="form.link_type" style="width: 160px">
            <el-option label="不跳转" value="none" />
            <el-option v-for="(n, k) in LINKS" :key="k" :label="n" :value="k" />
          </el-select>
          <el-input-number v-if="form.link_type === 'goods' || form.link_type === 'article'" v-model="form.link_value" :min="1" :controls="false" :placeholder="`${LINKS[form.link_type]} ID`" style="width: 160px; margin-left: 8px" />
          <div v-if="form.link_type === 'article'" class="sub">文章、公告、帮助的 ID 都可以，在各自的列表里能看到。</div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialog = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submit">提交发送</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="view.show" title="消息内容" width="600px">
      <template v-if="view.row">
        <div v-for="(title, code) in view.row.title" :key="code" class="lang-block">
          <div class="sub">{{ langName(code) }}</div>
          <div class="v-title">{{ title }}</div>
          <div class="v-content">{{ view.row.content[code] }}</div>
        </div>
        <div class="sub">发给：{{ view.row.target_text }}　跳转：{{ linkText(view.row) }}</div>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.sub { color: #909399; font-size: 12px; line-height: 1.6; width: 100%; }
.title-link { color: var(--el-color-primary); cursor: pointer; }
.lang-block { margin-bottom: 16px; padding: 10px 12px; background: #f5f7fa; border-radius: 4px; }
.v-title { margin-top: 4px; font-weight: 600; }
.v-content { margin-top: 6px; line-height: 1.7; white-space: pre-wrap; word-break: break-word; }
</style>
