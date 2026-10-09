<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { listBrand, createBrand, updateBrand, setBrandStatus, deleteBrand } from '@/api/brand'
import { languageOptions } from '@/api/language'
import { hasPerm } from '@/utils/perm'
import LangInput from '@/components/LangInput.vue'
import ImageUpload from '@/components/ImageUpload.vue'

const query = reactive({ keyword: '', status: '', page: 1, size: 15 })
const list = ref([])
const total = ref(0)
const loading = ref(false)
const languages = ref([])

async function load() {
  loading.value = true
  try {
    const d = await listBrand(query)
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

const dialog = ref(false)
const isEdit = ref(false)
const emptyForm = () => ({ id: 0, name: {}, logo: '', sort: 0, status: 1 })
const form = reactive(emptyForm())

function openCreate() {
  isEdit.value = false
  Object.assign(form, emptyForm())
  dialog.value = true
}
function openEdit(row) {
  isEdit.value = true
  Object.assign(form, emptyForm(), { id: row.id, name: { ...row.name }, logo: row.logo, sort: row.sort, status: row.status })
  dialog.value = true
}
async function submit() {
  const payload = { name: form.name, logo: form.logo, sort: form.sort, status: form.status }
  if (isEdit.value) await updateBrand(form.id, payload)
  else await createBrand(payload)
  ElMessage.success('保存成功')
  dialog.value = false
  load()
}
async function onStatus(row, val) {
  try {
    await setBrandStatus(row.id, val)
  } finally {
    load()
  }
}
async function onDelete(row) {
  await ElMessageBox.confirm(`确定删除品牌「${row.name_text}」吗？`, '提示', { type: 'warning' })
  await deleteBrand(row.id)
  ElMessage.success('已删除')
  load()
}

onMounted(async () => {
  load()
  try { languages.value = await languageOptions() } catch (e) { /* 无权限时页签为空 */ }
})
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <el-form :inline="true" @submit.prevent="search">
        <el-form-item><el-input v-model="query.keyword" placeholder="品牌名称" clearable style="width: 200px" @clear="search" @keyup.enter="search" /></el-form-item>
        <el-form-item>
          <el-select v-model="query.status" placeholder="状态" clearable style="width: 110px" @change="search">
            <el-option label="启用" :value="1" />
            <el-option label="禁用" :value="0" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="search">查询</el-button>
          <el-button v-perm="'goods:brand:add'" type="success" @click="openCreate">新增品牌</el-button>
        </el-form-item>
      </el-form>

      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="Logo" width="90">
          <template #default="{ row }"><el-image v-if="row.logo" :src="row.logo" :preview-src-list="[row.logo]" preview-teleported fit="contain" style="width: 40px; height: 40px" /></template>
        </el-table-column>
        <el-table-column prop="name_text" label="品牌名称" min-width="180" />
        <el-table-column prop="sort" label="排序" width="80" />
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-switch :model-value="row.status" :active-value="1" :inactive-value="0" :disabled="!hasPerm('goods:brand:edit')" @change="(v) => onStatus(row, v)" />
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="创建时间" min-width="160" />
        <el-table-column label="操作" width="140" fixed="right">
          <template #default="{ row }">
            <el-button v-perm="'goods:brand:edit'" link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button v-perm="'goods:brand:del'" link type="danger" @click="onDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
      <div class="pager">
        <el-pagination v-model:current-page="query.page" v-model:page-size="query.size" :total="total" :page-sizes="[15, 30, 50]" layout="total, sizes, prev, pager, next" @current-change="load" @size-change="search" />
      </div>
    </el-card>

    <el-dialog v-model="dialog" :title="isEdit ? '编辑品牌' : '新增品牌'" width="560px" destroy-on-close>
      <el-form :model="form" label-width="90px">
        <el-form-item label="品牌名称" required><LangInput v-model="form.name" :languages="languages" :maxlength="100" /></el-form-item>
        <el-form-item label="品牌 Logo"><ImageUpload v-model="form.logo" scene="goods" :width="120" :height="80" tip="可选，建议透明背景 PNG" /></el-form-item>
        <el-form-item label="排序"><el-input-number v-model="form.sort" :min="0" :max="9999" /><span style="margin-left: 8px; color: #909399">越小越靠前</span></el-form-item>
        <el-form-item label="状态"><el-switch v-model="form.status" :active-value="1" :inactive-value="0" active-text="启用" inactive-text="禁用" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialog = false">取消</el-button>
        <el-button type="primary" @click="submit">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>
