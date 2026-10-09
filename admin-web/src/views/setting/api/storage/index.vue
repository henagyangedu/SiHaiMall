<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { listDriver, saveDriver, testDriver } from '@/api/driver'

const TYPE = 'storage'
const list = ref([])
const loading = ref(false)
const testing = ref('')

const current = computed(() => list.value.find((d) => d.is_default === 1))

async function load() {
  loading.value = true
  try {
    list.value = await listDriver(TYPE)
  } finally {
    loading.value = false
  }
}

// ---- 配置弹窗：表单由后端返回的 fields 动态生成 ----
const dialog = ref(false)
const row = ref(null)
const form = reactive({ is_enabled: 0, is_default: 0, sort: 0, config: {} })

function openConfig(r) {
  row.value = r
  form.is_enabled = r.is_enabled
  form.is_default = r.is_default
  form.sort = r.sort
  form.config = { ...r.config }
  dialog.value = true
}
async function submit() {
  if (form.is_enabled === 1) {
    for (const f of row.value.fields) {
      if (f.required && !String(form.config[f.key] ?? '').trim()) {
        ElMessage.error(`请填写「${f.label}」`)
        return
      }
    }
  }
  await saveDriver(TYPE, row.value.driver, { ...form })
  ElMessage.success('保存成功')
  dialog.value = false
  load()
}

async function onTest(r) {
  testing.value = r.driver
  try {
    const d = await testDriver(TYPE, r.driver)
    await ElMessageBox.alert(
      `读写测试通过（写入、读取、删除均成功）。\n文件访问地址示例：${d.url_sample}\n\n注意：测试不验证外网能否访问。请在“基础信息”里上传一张 Logo，再用浏览器打开返回的图片地址确认。`,
      '测试通过',
      { type: 'success' },
    )
  } finally {
    testing.value = ''
  }
}
async function onDefault(r) {
  await ElMessageBox.confirm(
    `将「${r.name}」设为默认存储？之后新上传的文件会保存到这里；已上传的文件不会迁移，仍保留在原位置。`,
    '设为默认存储',
    { type: 'warning' },
  )
  await saveDriver(TYPE, r.driver, { is_enabled: 1, is_default: 1 })
  ElMessage.success('已设为默认存储')
  load()
}

onMounted(load)
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <el-alert type="info" :closable="false" show-icon style="margin-bottom: 12px">
        <template #title>
          当前上传使用：<b>{{ current ? current.name : '本地存储（尚未手动指定默认，系统自动使用）' }}</b>。
          阿里云、腾讯云、Google Cloud 及其它 S3 兼容服务需要后端安装 S3 依赖：<code>composer require league/flysystem-aws-s3-v3 "^3.0" --with-all-dependencies</code>
        </template>
      </el-alert>

      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column prop="name" label="存储方式" min-width="170" />
        <el-table-column prop="desc" label="说明" min-width="320" show-overflow-tooltip />
        <el-table-column label="配置" width="90">
          <template #default="{ row: r }"><el-tag :type="r.configured ? 'success' : 'info'">{{ r.configured ? '已配置' : '未配置' }}</el-tag></template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row: r }"><el-tag :type="r.is_enabled === 1 ? 'success' : 'danger'">{{ r.is_enabled === 1 ? '启用' : '未启用' }}</el-tag></template>
        </el-table-column>
        <el-table-column label="默认" width="80">
          <template #default="{ row: r }"><el-tag v-if="r.is_default === 1" type="warning">默认</el-tag></template>
        </el-table-column>
        <el-table-column label="操作" width="260" fixed="right">
          <template #default="{ row: r }">
            <el-button v-perm="'setting:api_storage:edit'" link type="primary" @click="openConfig(r)">配置</el-button>
            <el-button v-perm="'setting:api_storage:test'" link type="success" :loading="testing === r.driver" @click="onTest(r)">测试连接</el-button>
            <el-button v-if="r.is_default !== 1 && r.configured" v-perm="'setting:api_storage:edit'" link type="warning" @click="onDefault(r)">设为默认</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog v-model="dialog" :title="`配置：${row?.name || ''}`" width="620px" destroy-on-close>
      <el-form v-if="row" label-width="110px">
        <el-form-item v-if="!row.fields.length">
          <span style="color: #909399">本地存储无需额外配置。启用并设为默认后，文件保存到服务器 public/uploads 目录。</span>
        </el-form-item>

        <el-form-item v-for="f in row.fields" :key="f.key" :label="f.label" :required="f.required">
          <el-switch v-if="f.type === 'switch'" v-model="form.config[f.key]" :active-value="1" :inactive-value="0" />
          <el-input v-else v-model="form.config[f.key]" :type="f.type === 'secret' ? 'password' : 'text'" :show-password="f.type === 'secret'" />
          <div v-if="f.hint" class="hint">{{ f.hint }}</div>
        </el-form-item>

        <el-divider />
        <el-form-item label="启用">
          <el-switch v-model="form.is_enabled" :active-value="1" :inactive-value="0" :disabled="row.is_default === 1" />
          <span class="hint" style="margin-left: 12px">启用前请填写完整的必填项；默认驱动不能直接禁用</span>
        </el-form-item>
        <el-form-item label="设为默认">
          <el-switch v-model="form.is_default" :active-value="1" :inactive-value="0" :disabled="row.is_default === 1 || form.is_enabled !== 1" />
          <span class="hint" style="margin-left: 12px">新上传的文件保存到默认驱动</span>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialog = false">取消</el-button>
        <el-button type="primary" @click="submit">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.hint { color: #909399; font-size: 12px; line-height: 1.5; margin-top: 2px; width: 100%; }
code { background: #f4f4f5; padding: 1px 6px; border-radius: 3px; }
</style>
