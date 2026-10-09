<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { state } from '@/store'
import { ElMessage } from 'element-plus'
import { getProfile, getPointLogs, getSignIn, signIn } from '@/api'

const { t, te } = useI18n()
const list = ref([])
const page = reactive({ no: 1, size: 20, total: 0, loading: false })

// 流水类型由前端按类型码翻译；遇到没登记的类型显示“其他”
const typeText = (type) => (te(`pointType.${type}`) ? t(`pointType.${type}`) : t('points.other'))

async function load() {
  page.loading = true
  try {
    const d = await getPointLogs({ page: page.no, size: page.size })
    list.value = d.list
    page.total = d.total
  } finally {
    page.loading = false
  }
}

// 每日签到：后台没开放时不显示按钮
const sign = ref({ enabled: false, points: 0, signed_today: false })
const signing = ref(false)
async function onSignIn() {
  if (signing.value) return
  signing.value = true
  try {
    const r = await signIn()
    ElMessage.success(t('points.signOk', { n: r.points }))
    sign.value.signed_today = true
    getProfile().then((m) => { state.member = m }).catch(() => {})
    page.no = 1
    load()
  } finally {
    signing.value = false
  }
}

onMounted(() => {
  getProfile().then((m) => { state.member = m }).catch(() => {})
  getSignIn().then((s) => { sign.value = s }).catch(() => {})
  load()
})
</script>

<template>
  <div class="panel">
    <div class="panel-title">{{ t('points.title') }}</div>
    <div class="balance">
      {{ t('points.balance') }}<span class="num">{{ state.member?.points ?? 0 }}</span>
      <el-button v-if="sign.enabled" class="sign" type="primary" round :disabled="sign.signed_today" :loading="signing" @click="onSignIn">
        {{ sign.signed_today ? t('points.signed') : t('points.signIn', { n: sign.points }) }}
      </el-button>
      <div v-if="state.member?.level_name" class="sub level">
        {{ state.member.level_name }}　{{ t('points.growth', { n: state.member.growth }) }}　{{ state.member.next_level
          ? t('points.nextLevel', { n: state.member.next_level.remain, name: state.member.next_level.name }) : t('points.topLevel') }}
      </div>
    </div>

    <el-table v-loading="page.loading" :data="list" :empty-text="t('common.empty')">
      <el-table-column :label="t('pc.timeCol')" prop="created_at" width="180" />
      <el-table-column :label="t('pc.typeCol')" width="160"><template #default="{ row }">{{ typeText(row.type) }}</template></el-table-column>
      <el-table-column :label="t('pc.refCol')" prop="ref_no" min-width="200" />
      <el-table-column :label="t('pc.changeCol')" width="120" align="right">
        <template #default="{ row }"><span :class="{ price: row.change_points > 0 }">{{ row.change_points > 0 ? '+' : '' }}{{ row.change_points }}</span></template>
      </el-table-column>
      <el-table-column :label="t('pc.balanceCol')" prop="after_points" width="120" align="right" />
    </el-table>
    <div v-if="page.total > page.size" class="pager">
      <el-pagination v-model:current-page="page.no" :page-size="page.size" :total="page.total" layout="prev, pager, next" background @current-change="load" />
    </div>
  </div>
</template>

<style scoped>
.balance { background: #fcf5f5; border-radius: 8px; padding: 18px 20px; margin-bottom: 16px; }
.num { font-size: 28px; font-weight: 600; color: var(--theme); margin-left: 14px; }
.level { margin-top: 8px; }
.sign { margin-left: 24px; vertical-align: 4px; }
</style>
