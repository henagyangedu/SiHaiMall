<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  listAdPosition, createAdPosition, updateAdPosition, deleteAdPosition,
  listAd, createAd, updateAd, setAdStatus, deleteAd,
} from '@/api/content'
import { languageOptions } from '@/api/language'
import { hasPerm } from '@/utils/perm'
import ImageUpload from '@/components/ImageUpload.vue'

const LINK_TYPES = [
  { value: 'none', label: '不跳转', hint: '' },
  { value: 'goods', label: '商品', hint: '填商品 ID（商品列表里可以看到）' },
  { value: 'category', label: '商品分类', hint: '填商品分类 ID' },
  { value: 'article', label: '文章', hint: '填文章 ID（文章、公告、帮助都可以）' },
  { value: 'url', label: '自定义链接', hint: '以 https:// 开头的完整地址，或以 / 开头的站内路径' },
]

const languages = ref([])
const defaultLang = computed(() => languages.value.find((l) => l.is_default)?.code || '')
const positions = ref([])
const current = ref(null)
const ads = ref([])
const loading = ref(false)

async function loadPositions() {
  positions.value = await listAdPosition()
  current.value = positions.value.find((p) => p.id === current.value?.id) || positions.value[0] || null
  loadAds()
}
async function loadAds() {
  if (!current.value) { ads.value = []; return }
  loading.value = true
  try {
    ads.value = await listAd(current.value.id)
  } finally {
    loading.value = false
  }
}
function pick(p) {
  current.value = p
  loadAds()
}

// ---- 广告位
const posDialog = ref(false)
const posForm = reactive({ id: 0, code: '', name: '', remark: '', status: 1, built_in: false })
function openPosition(p) {
  Object.assign(posForm, p ? { ...p } : { id: 0, code: '', name: '', remark: '', status: 1, built_in: false })
  posDialog.value = true
}
async function submitPosition() {
  const payload = { code: posForm.code, name: posForm.name, remark: posForm.remark, status: posForm.status }
  if (posForm.id) await updateAdPosition(posForm.id, payload)
  else await createAdPosition(payload)
  ElMessage.success('保存成功')
  posDialog.value = false
  loadPositions()
}
async function removePosition(p) {
  await ElMessageBox.confirm(`确定删除广告位「${p.name}」吗？`, '提示', { type: 'warning' })
  await deleteAdPosition(p.id)
  ElMessage.success('已删除')
  if (current.value?.id === p.id) current.value = null
  loadPositions()
}

// ---- 广告
const dialog = ref(false)
const saving = ref(false)
const emptyForm = () => ({ id: 0, title: {}, image: {}, link_type: 'none', link_value: '', sort: 0, status: 1, range: [] })
const form = reactive(emptyForm())
const linkHint = computed(() => LINK_TYPES.find((t) => t.value === form.link_type)?.hint || '')
function openAd(row) {
  const obj = (v) => (v && !Array.isArray(v) ? { ...v } : {})
  Object.assign(form, emptyForm(), row ? {
    id: row.id, title: obj(row.title), image: obj(row.image), link_type: row.link_type, link_value: row.link_value,
    sort: row.sort, status: row.status, range: [row.start_at, row.end_at],
  } : {})
  dialog.value = true
}
async function submitAd() {
  if (!form.image[defaultLang.value]) return ElMessage.warning('请上传默认语言的图片')
  saving.value = true
  try {
    const [start, end] = form.range || []
    const payload = {
      position_id: current.value.id, title: form.title, image: form.image, link_type: form.link_type,
      link_value: form.link_type === 'none' ? '' : form.link_value, sort: form.sort, status: form.status, start_at: start || null, end_at: end || null,
    }
    if (form.id) await updateAd(form.id, payload)
    else await createAd(payload)
    ElMessage.success('保存成功')
    dialog.value = false
    loadPositions()
  } finally {
    saving.value = false
  }
}
async function onStatus(row, val) {
  try {
    await setAdStatus(row.id, val)
  } finally {
    loadAds()
  }
}
async function removeAd(row) {
  await ElMessageBox.confirm('确定删除这张图吗？', '提示', { type: 'warning' })
  await deleteAd(row.id)
  ElMessage.success('已删除')
  loadPositions()
}

onMounted(async () => {
  loadPositions()
  try { languages.value = await languageOptions() } catch (e) { /* 无权限时只显示默认语言 */ }
})
</script>

<template>
  <div class="page layout">
    <el-card shadow="never" class="left">
      <div class="head"><b>广告位</b><el-button v-perm="'content:ad:add'" size="small" @click="openPosition(null)">新增</el-button></div>
      <div v-for="p in positions" :key="p.id" class="pos" :class="{ active: current?.id === p.id }" @click="pick(p)">
        <div class="pos-name">{{ p.name }}<el-tag v-if="!p.status" type="info" size="small" style="margin-left: 6px">停用</el-tag></div>
        <div class="sub">{{ p.code }} · {{ p.ad_count }} 张</div>
        <div class="pos-ops" @click.stop>
          <el-button v-perm="'content:ad:edit'" link size="small" type="primary" @click="openPosition(p)">编辑</el-button>
          <el-button v-if="!p.built_in && hasPerm('content:ad:del')" link size="small" type="danger" @click="removePosition(p)">删除</el-button>
        </div>
      </div>
    </el-card>

    <el-card shadow="never" class="right">
      <template v-if="current">
        <div class="head">
          <div><b>{{ current.name }}</b><span class="sub" style="margin-left: 10px">前端按编码 {{ current.code }} 取图<template v-if="current.remark">　{{ current.remark }}</template></span></div>
          <el-button v-perm="'content:ad:add'" type="success" @click="openAd(null)">添加图片</el-button>
        </div>
        <el-table v-loading="loading" :data="ads" border stripe empty-text="这个广告位还没有图片">
          <el-table-column label="图片" width="150">
            <template #default="{ row }"><el-image v-if="row.image_url" :src="row.image_url" :preview-src-list="[row.image_url]" preview-teleported fit="cover" style="width: 120px; height: 56px" /></template>
          </el-table-column>
          <el-table-column prop="title_text" label="标题" min-width="140" />
          <el-table-column label="跳转" min-width="170"><template #default="{ row }">{{ row.link_type_text }}<span v-if="row.link_value" class="sub">　{{ row.link_value }}</span></template></el-table-column>
          <el-table-column prop="sort" label="排序" width="70" />
          <el-table-column label="展示时间" min-width="170">
            <template #default="{ row }">
              <template v-if="row.start_at || row.end_at"><div class="sub">{{ row.start_at || '不限' }}</div><div class="sub">至 {{ row.end_at || '不限' }}</div></template>
              <span v-else class="sub">长期</span>
            </template>
          </el-table-column>
          <el-table-column label="启用" width="120">
            <template #default="{ row }">
              <el-switch :model-value="row.status" :active-value="1" :inactive-value="0" :disabled="!hasPerm('content:ad:edit')" @change="(v) => onStatus(row, v)" />
              <div class="sub" :style="{ color: row.showing ? '#67c23a' : '#909399' }">{{ row.showing ? '展示中' : '未展示' }}</div>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="120" fixed="right">
            <template #default="{ row }">
              <el-button v-perm="'content:ad:edit'" link type="primary" @click="openAd(row)">编辑</el-button>
              <el-button v-perm="'content:ad:del'" link type="danger" @click="removeAd(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
      </template>
      <el-empty v-else description="请先在左侧选择或新增广告位" />
    </el-card>

    <el-dialog v-model="posDialog" :title="posForm.id ? '编辑广告位' : '新增广告位'" width="520px" destroy-on-close>
      <el-form label-width="90px">
        <el-form-item label="名称" required><el-input v-model="posForm.name" maxlength="100" placeholder="如：购物车底部" /></el-form-item>
        <el-form-item label="编码" required>
          <el-input v-model="posForm.code" maxlength="50" :disabled="posForm.built_in" placeholder="字母、数字、下划线，如 cart_bottom" />
          <div class="sub">前端页面按这个编码取图。{{ posForm.built_in ? '预置广告位的编码不能改。' : '保存后会统一转成小写。' }}</div>
        </el-form-item>
        <el-form-item label="说明"><el-input v-model="posForm.remark" maxlength="255" placeholder="建议尺寸等，给运营看的" /></el-form-item>
        <el-form-item label="状态"><el-switch v-model="posForm.status" :active-value="1" :inactive-value="0" active-text="启用" inactive-text="停用" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="posDialog = false">取消</el-button>
        <el-button type="primary" @click="submitPosition">保存</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="dialog" :title="form.id ? '编辑图片' : '添加图片'" width="760px" destroy-on-close>
      <el-form label-width="90px">
        <el-form-item label="图片与标题" required>
          <div class="langs">
            <div v-for="l in languages" :key="l.code" class="lang">
              <div class="sub">{{ l.name }}{{ l.is_default ? '（默认，必传）' : '（不传则用默认语言的图）' }}</div>
              <ImageUpload v-model="form.image[l.code]" scene="banner" :width="220" :height="104" />
              <el-input v-model="form.title[l.code]" maxlength="255" placeholder="标题（可选）" style="width: 220px; margin-top: 6px" />
            </div>
          </div>
        </el-form-item>
        <el-form-item label="点击跳转">
          <el-select v-model="form.link_type" style="width: 160px">
            <el-option v-for="t in LINK_TYPES" :key="t.value" :label="t.label" :value="t.value" />
          </el-select>
          <el-input v-if="form.link_type !== 'none'" v-model="form.link_value" maxlength="500" style="width: 320px; margin-left: 10px" />
          <div v-if="linkHint" class="sub" style="width: 100%">{{ linkHint }}</div>
        </el-form-item>
        <el-form-item label="展示时间">
          <el-date-picker v-model="form.range" type="datetimerange" value-format="YYYY-MM-DD HH:mm:ss" start-placeholder="开始（留空不限）" end-placeholder="结束（留空不限）" />
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="form.sort" :min="0" :max="9999" /><span class="sub" style="margin-left: 10px">越小越靠前</span>
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" active-text="启用" inactive-text="停用" style="margin-left: 24px" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialog = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submitAd">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.layout { display: flex; gap: 16px; align-items: flex-start; }
.left { width: 260px; flex: none; }
.right { flex: 1; min-width: 0; margin-top: 0 !important; }
.head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
.sub { color: #909399; font-size: 12px; line-height: 1.6; }
.pos { padding: 10px 12px; border-radius: 6px; cursor: pointer; border: 1px solid transparent; }
.pos:hover { background: #f5f7fa; }
.pos.active { background: #ecf5ff; border-color: #b3d8ff; }
.pos-name { font-weight: 600; }
.langs { display: flex; flex-wrap: wrap; gap: 16px; }
</style>
