<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { listLevel, createLevel, updateLevel, deleteLevel } from '@/api/member'
import { languageOptions } from '@/api/language'
import LangInput from '@/components/LangInput.vue'

const list = ref([])
const loading = ref(false)
const languages = ref([])

async function load() {
  loading.value = true
  try {
    list.value = await listLevel()
  } finally {
    loading.value = false
  }
}

const dialog = ref(false)
const isEdit = ref(false)
const emptyForm = () => ({ id: 0, name: {}, level_no: 1, need_points: 0, discount: 100, status: 1 })
const form = reactive(emptyForm())

function openCreate() {
  isEdit.value = false
  Object.assign(form, emptyForm(), { level_no: (list.value.at(-1)?.level_no || 0) + 1 })
  dialog.value = true
}
function openEdit(r) {
  isEdit.value = true
  Object.assign(form, emptyForm(), { id: r.id, name: { ...r.name }, level_no: r.level_no, need_points: r.need_points, discount: Number(r.discount), status: r.status })
  dialog.value = true
}
async function submit() {
  const payload = { name: form.name, level_no: form.level_no, need_points: form.need_points, discount: form.discount, status: form.status }
  if (isEdit.value) await updateLevel(form.id, payload)
  else await createLevel(payload)
  ElMessage.success('保存成功')
  dialog.value = false
  load()
}
async function onDelete(r) {
  await ElMessageBox.confirm(`确定删除等级「${r.name_text}」吗？`, '提示', { type: 'warning' })
  await deleteLevel(r.id)
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
      <el-alert type="info" :closable="false" show-icon style="margin-bottom: 12px"
        title="折扣为百分比：100 表示无折扣，95 表示九五折。等级序号越大等级越高；「升级所需积分」供后续自动升级使用。默认等级（ID 1）不能删除。" />
      <div style="margin-bottom: 12px"><el-button v-perm="'member:level:add'" type="success" @click="openCreate">新增等级</el-button></div>

      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="level_no" label="等级序号" width="100" />
        <el-table-column prop="name_text" label="等级名称" min-width="160" />
        <el-table-column prop="need_points" label="升级所需积分" width="130" />
        <el-table-column label="折扣" width="100"><template #default="{ row }">{{ Number(row.discount) }}%</template></el-table-column>
        <el-table-column prop="member_count" label="会员数" width="90" />
        <el-table-column label="状态" width="90"><template #default="{ row }"><el-tag :type="row.status === 1 ? 'success' : 'info'">{{ row.status === 1 ? '启用' : '禁用' }}</el-tag></template></el-table-column>
        <el-table-column label="操作" width="140" fixed="right">
          <template #default="{ row }">
            <el-button v-perm="'member:level:edit'" link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button v-perm="'member:level:del'" link type="danger" :disabled="row.id === 1" @click="onDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog v-model="dialog" :title="isEdit ? '编辑等级' : '新增等级'" width="560px" destroy-on-close>
      <el-form :model="form" label-width="110px">
        <el-form-item label="等级名称" required><LangInput v-model="form.name" :languages="languages" :maxlength="50" ai field-key="level_name" /></el-form-item>
        <el-form-item label="等级序号" required><el-input-number v-model="form.level_no" :min="1" :max="999" /></el-form-item>
        <el-form-item label="升级所需积分"><el-input-number v-model="form.need_points" :min="0" :max="100000000" /></el-form-item>
        <el-form-item label="折扣（%）"><el-input-number v-model="form.discount" :min="1" :max="100" :precision="2" /></el-form-item>
        <el-form-item label="状态"><el-switch v-model="form.status" :active-value="1" :inactive-value="0" active-text="启用" inactive-text="禁用" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialog = false">取消</el-button>
        <el-button type="primary" @click="submit">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>
