<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import { state } from '@/store'
import { getProfile, getAddresses, deleteAddress } from '@/api'
import { confirm, addressLine } from '@/utils/app'
import AddressDialog from '@/components/AddressDialog.vue'

const { t } = useI18n()
const router = useRouter()

const ORDER_TABS = [
  { key: 'unpaid', label: 'order.tabUnpaid' }, { key: 'to_ship', label: 'order.tabToShip' },
  { key: 'shipped', label: 'order.tabShipped' }, { key: 'finished', label: 'order.tabFinished' },
]
const addresses = ref([])
const dialog = ref(false)
const editing = ref(null)

async function loadAddresses() {
  addresses.value = await getAddresses()
}
function openAddress(a = null) {
  editing.value = a
  dialog.value = true
}
async function removeAddress(a) {
  if (!(await confirm(t('address.deleteConfirm')))) return
  await deleteAddress(a.id)
  ElMessage.success(t('common.deleted'))
  loadAddresses()
}

onMounted(async () => {
  try { state.member = await getProfile() } catch (e) { /* 令牌失效时请求层已清掉登录态 */ }
  loadAddresses().catch(() => {})
})
</script>

<template>
  <div v-if="state.member" class="wrap">
    <div class="panel head">
      <img v-if="state.member.avatar" :src="state.member.avatar" alt="" class="avatar" />
      <div v-else class="avatar ph">{{ (state.member.nickname || '?').slice(0, 1) }}</div>
      <div class="who">
        <div class="nick">{{ state.member.nickname }}</div>
        <div class="sub">{{ t('pc.memberSince') }}：{{ state.member.account }}</div>
      </div>
      <div class="stat"><div class="num">{{ state.member.points }}</div><div class="sub">{{ t('pc.points') }}</div></div>
      <div v-if="Number(state.member.level_discount) < 100" class="stat"><div class="num">{{ Number(state.member.level_discount) }}%</div><div class="sub">{{ t('pc.level') }}</div></div>
    </div>

    <div class="panel">
      <div class="panel-title">{{ t('user.orders') }}<router-link :to="{ name: 'orders' }" class="link more">{{ t('common.all') }} ›</router-link></div>
      <div class="tabs">
        <a v-for="x in ORDER_TABS" :key="x.key" class="tab" @click="router.push({ name: 'orders', query: { tab: x.key } })">{{ t(x.label) }}</a>
      </div>
    </div>

    <div class="panel">
      <div class="panel-title">{{ t('user.address') }}<el-button link type="primary" class="more" @click="openAddress()">＋ {{ t('pc.newAddress') }}</el-button></div>
      <div v-if="!addresses.length" class="sub">{{ t('address.empty') }}</div>
      <div v-for="a in addresses" :key="a.id" class="addr">
        <div class="body">
          <div>{{ a.name }}　<span class="sub">+{{ a.phone_area }} {{ a.phone }}</span><el-tag v-if="a.is_default" size="small" type="danger" effect="plain" class="tag">{{ t('pc.defaultTag') }}</el-tag></div>
          <div class="sub">{{ addressLine(a) }}</div>
        </div>
        <a class="link" @click="openAddress(a)">{{ t('common.edit') }}</a>
        <a class="link" @click="removeAddress(a)">{{ t('common.delete') }}</a>
      </div>
    </div>

    <AddressDialog v-model="dialog" :address="editing" @saved="loadAddresses" />
  </div>
</template>

<style scoped>
.head { display: flex; align-items: center; gap: 20px; }
.avatar { width: 72px; height: 72px; border-radius: 50%; object-fit: cover; background: #f0f1f3; flex: none; }
.ph { text-align: center; line-height: 72px; font-size: 30px; color: var(--sub); }
.who { flex: 1; min-width: 0; }
.nick { font-size: 20px; font-weight: 600; margin-bottom: 6px; }
.stat { text-align: center; padding: 0 28px; border-left: 1px solid var(--line); }
.num { font-size: 22px; font-weight: 600; color: var(--theme); }
.more { margin-left: 16px; font-weight: 400; font-size: 13px; }
.tabs { display: flex; }
.tab { flex: 1; text-align: center; padding: 16px 0; cursor: pointer; border-radius: 8px; }
.tab:hover { background: #fcebeb; color: var(--theme); }
.addr { display: flex; align-items: center; gap: 20px; padding: 14px 0; border-bottom: 1px solid var(--line); }
.addr:last-child { border-bottom: none; }
.body { flex: 1; min-width: 0; }
.tag { margin-left: 8px; }
</style>
