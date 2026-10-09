<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { listMessageTemplate, saveMessageTemplate, testMessageTemplate } from '@/api/message'
import { languageOptions } from '@/api/language'
import { hasPerm } from '@/utils/perm'

const loading = ref(false)
const info = reactive({ mailer: '', from: '' })
const list = ref([])
const languages = ref([])

async function load() {
  loading.value = true
  try {
    const d = await listMessageTemplate()
    Object.assign(info, { mailer: d.mailer, from: d.from })
    list.value = d.scenes
  } finally {
    loading.value = false
  }
}

// 列表上直接开关：只提交 is_enabled；成功后才翻转
async function toggle(row) {
  const next = row.is_enabled === 1 ? 0 : 1
  try {
    await saveMessageTemplate(row.scene, { is_enabled: next })
    row.is_enabled = next
    ElMessage.success(next === 1 ? `已开启「${row.label}」邮件通知` : `已关闭「${row.label}」邮件通知`)
    return true
  } catch (e) {
    return false
  }
}

// ---- 编辑模板
const dialog = ref(false)
const saving = ref(false)
const row = ref(null)
const tab = ref('')
const form = reactive({ is_enabled: 0, title: {}, content: {} })
function openEdit(r) {
  const obj = (v) => (v && !Array.isArray(v) ? { ...v } : {})
  row.value = r
  Object.assign(form, { is_enabled: r.is_enabled, title: obj(r.title), content: obj(r.content) })
  tab.value = languages.value[0]?.code || ''
  dialog.value = true
}
function useDefault(code) {
  form.title[code] = row.value.default_title[code] || ''
  form.content[code] = row.value.default_content[code] || ''
}
function insertVar(key) {
  form.content[tab.value] = `${form.content[tab.value] || ''}{${key}}`
}
async function submit() {
  saving.value = true
  try {
    await saveMessageTemplate(row.value.scene, { is_enabled: form.is_enabled, title: form.title, content: form.content })
    ElMessage.success('保存成功')
    dialog.value = false
    load()
  } finally {
    saving.value = false
  }
}

// ---- 测试发送
const testing = ref('')
async function onTest(r) {
  const { value } = await ElMessageBox.prompt(`用示例数据把「${r.label}」的邮件发到下面的邮箱（使用已保存的模板，默认语言）。`, '测试发送', {
    inputPlaceholder: '收件邮箱', inputPattern: /^[^\s@]+@[^\s@]+\.[^\s@]+$/, inputErrorMessage: '请填写正确的邮箱',
  })
  testing.value = r.scene
  try {
    const d = await testMessageTemplate(r.scene, { email: value })
    const note = d.mailer === 'log' ? '\n\n注意：当前发信方式是 log，邮件只写进了日志文件，没有真正发出。请先在服务器 .env 里配置 MAIL_* 再测试。' : ''
    await ElMessageBox.alert(`标题：${d.title}\n\n${d.content}${note}`, '已发送', { customStyle: { whiteSpace: 'pre-wrap' } })
  } finally {
    testing.value = ''
  }
}

onMounted(async () => {
  load()
  try { languages.value = await languageOptions() } catch (e) { /* 无权限时页签为空 */ }
})
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <el-alert :type="info.mailer === 'log' ? 'warning' : 'info'" :closable="false" show-icon style="margin-bottom: 12px">
        <template v-if="info.mailer === 'log'">当前发信方式是 log：邮件只会写进日志，不会真正发出。上线前请在服务器 .env 里配置发信邮箱（MAIL_MAILER、MAIL_HOST 等）。</template>
        <template v-else>发信方式 {{ info.mailer }}，发件人 {{ info.from }}。</template>
        开启的场景会在对应节点自动给买家发邮件，按买家的语言选模板；只有填了邮箱的会员能收到。
      </el-alert>

      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column prop="label" label="通知场景" min-width="180" />
        <el-table-column label="渠道" width="90"><template #default>邮件</template></el-table-column>
        <el-table-column label="模板" width="120">
          <template #default="{ row: r }"><el-tag :type="r.customized ? 'success' : 'info'">{{ r.customized ? '已自定义' : '内置模板' }}</el-tag></template>
        </el-table-column>
        <el-table-column label="启用" width="110">
          <template #default="{ row: r }">
            <el-switch :model-value="r.is_enabled === 1" inline-prompt active-text="开启" inactive-text="关闭" :disabled="!hasPerm('setting:message:edit')" :before-change="() => toggle(r)" />
          </template>
        </el-table-column>
        <el-table-column prop="updated_at" label="更新时间" min-width="170" />
        <el-table-column label="操作" width="180" fixed="right">
          <template #default="{ row: r }">
            <el-button v-perm="'setting:message:edit'" link type="primary" @click="openEdit(r)">编辑模板</el-button>
            <el-button v-perm="'setting:message:test'" link type="success" :loading="testing === r.scene" @click="onTest(r)">测试发送</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog v-model="dialog" :title="`邮件模板：${row?.label || ''}`" width="820px" destroy-on-close>
      <template v-if="row">
        <div class="vars">
          <span class="sub">可用变量（点击插入到正文末尾）：</span>
          <el-tag v-for="v in row.vars" :key="v.key" class="var" @click="insertVar(v.key)">{{ v.label }} {{ '{' + v.key + '}' }}</el-tag>
        </div>
        <el-tabs v-model="tab">
          <el-tab-pane v-for="l in languages" :key="l.code" :label="l.name + (l.is_default ? '（默认）' : '')" :name="l.code">
            <el-form label-width="60px">
              <el-form-item label="标题"><el-input v-model="form.title[l.code]" maxlength="255" :placeholder="row.default_title[l.code]" /></el-form-item>
              <el-form-item label="正文"><el-input v-model="form.content[l.code]" type="textarea" :rows="9" maxlength="5000" show-word-limit :placeholder="row.default_content[l.code]" /></el-form-item>
              <el-form-item>
                <el-button size="small" @click="useDefault(l.code)">填入内置模板再修改</el-button>
                <span class="sub" style="margin-left: 10px">留空 = 使用灰色显示的内置模板；正文是纯文本，换行会保留</span>
              </el-form-item>
            </el-form>
          </el-tab-pane>
        </el-tabs>
        <el-form label-width="60px">
          <el-form-item label="启用"><el-switch v-model="form.is_enabled" :active-value="1" :inactive-value="0" /></el-form-item>
        </el-form>
      </template>
      <template #footer>
        <span class="sub" style="float: left">某语言没填时，先用默认语言的内容；默认语言也没填才用内置模板</span>
        <el-button @click="dialog = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submit">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.sub { color: #909399; font-size: 12px; }
.vars { margin-bottom: 8px; line-height: 2.2; }
.var { margin-right: 6px; cursor: pointer; }
</style>
