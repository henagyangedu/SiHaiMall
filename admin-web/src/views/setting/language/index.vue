<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { listLanguage, createLanguage, updateLanguage, setDefaultLanguage, deleteLanguage } from '@/api/language'

const list = ref([])
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    list.value = await listLanguage()
  } finally {
    loading.value = false
  }
}

const dialog = ref(false)
const isEdit = ref(false)
const formRef = ref()
const emptyForm = () => ({ id: 0, code: '', name: '', status: 1, sort: 0 })
const form = reactive(emptyForm())
const rules = {
  code: [
    { required: true, message: '请输入语言编码', trigger: 'blur' },
    { pattern: /^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$/, message: '格式如 zh-CN、en、ja、pt-BR', trigger: 'blur' },
  ],
  name: [{ required: true, message: '请输入语言名称', trigger: 'blur' }],
}

function openCreate() {
  isEdit.value = false
  Object.assign(form, emptyForm())
  dialog.value = true
}
function openEdit(row) {
  isEdit.value = true
  Object.assign(form, { id: row.id, code: row.code, name: row.name, status: row.status, sort: row.sort })
  dialog.value = true
}
async function submit() {
  await formRef.value.validate()
  if (isEdit.value) await updateLanguage(form.id, { name: form.name, status: form.status, sort: form.sort })
  else await createLanguage({ code: form.code, name: form.name, status: form.status, sort: form.sort })
  ElMessage.success('保存成功')
  dialog.value = false
  load()
}
async function onDefault(row) {
  await ElMessageBox.confirm(`将「${row.name}」设为默认语言？默认语言用于缺少翻译时的回退显示。`, '提示', { type: 'warning' })
  await setDefaultLanguage(row.id)
  ElMessage.success('已设为默认语言')
  load()
}
async function onDelete(row) {
  await ElMessageBox.confirm(`确定删除语言「${row.name}」吗？`, '提示', { type: 'warning' })
  await deleteLanguage(row.id)
  ElMessage.success('已删除')
  load()
}

onMounted(load)
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <div class="toolbar">
        <el-button v-perm="'setting:language:add'" type="success" @click="openCreate">新增语言</el-button>
        <span style="margin-left: 12px; color: #909399">启用的语言会出现在商品、分类、协议等所有多语言表单的页签里；已有翻译数据的语言只能禁用，不能删除。</span>
      </div>
      <el-alert type="info" :closable="false" show-icon style="margin-bottom: 12px"
        title="商城页面上的按钮、提示这些固定文字已经备好的语言：简体中文（zh-CN）、英语（en）、西班牙语（es）、法语（fr）、德语（de）、日语（ja）、俄语（ru）。带地区的编码按前半段算（es-MX 用西班牙语）；其他语言的页面文字先显示英文。商品、分类、文章、协议的内容不在此列，要在各自的表单里填写或用 AI 翻译，没填的显示默认语言的。" />
      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column prop="code" label="语言编码" width="130" />
        <el-table-column prop="name" label="语言名称" min-width="160" />
        <el-table-column label="默认" width="90">
          <template #default="{ row }"><el-tag v-if="row.is_default === 1" type="warning">默认</el-tag></template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }"><el-tag :type="row.status === 1 ? 'success' : 'danger'">{{ row.status === 1 ? '启用' : '禁用' }}</el-tag></template>
        </el-table-column>
        <el-table-column prop="sort" label="排序" width="80" />
        <el-table-column label="操作" width="230" fixed="right">
          <template #default="{ row }">
            <el-button v-perm="'setting:language:edit'" link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button v-if="row.is_default !== 1" v-perm="'setting:language:edit'" link type="warning" @click="onDefault(row)">设为默认</el-button>
            <el-button v-if="row.is_default !== 1" v-perm="'setting:language:del'" link type="danger" @click="onDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog v-model="dialog" :title="isEdit ? '编辑语言' : '新增语言'" width="460px" destroy-on-close>
      <el-form ref="formRef" :model="form" :rules="rules" label-width="90px">
        <el-form-item label="语言编码" prop="code"><el-input v-model="form.code" :disabled="isEdit" placeholder="如 zh-CN、en、ja、es、fr、de、ru" /></el-form-item>
        <el-form-item label="语言名称" prop="name"><el-input v-model="form.name" placeholder="如 简体中文、English" /></el-form-item>
        <el-form-item label="排序"><el-input-number v-model="form.sort" :min="0" :max="9999" /></el-form-item>
        <el-form-item label="状态"><el-switch v-model="form.status" :active-value="1" :inactive-value="0" active-text="启用" inactive-text="禁用" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialog = false">取消</el-button>
        <el-button type="primary" @click="submit">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>
