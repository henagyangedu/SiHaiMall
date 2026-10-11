<script setup>
import { reactive, ref, onUnmounted, nextTick } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { useI18n } from 'vue-i18n'
import { state, logout } from '@/store'
import { getProfile, changePassword, bindContact, bindGoogle, unbindGoogle, sendSmsCode, sendEmailCode, cancelCheck, cancelAccount, exportMyData } from '@/api'
import { usePageTitle, toast, confirm } from '@/utils/page'
// #ifdef H5
import { renderGoogleButton } from '@/utils/google'
// #endif

const { t } = useI18n()
usePageTitle('security.title')

const member = ref(null)
// 当前展开的表单：'' | 'pwd' | 'email' | 'phone' | 'cancel'
const open = ref('')
const saving = ref(false)
const pwd = reactive({ old: '', next: '', again: '' })
const bind = reactive({ value: '', code: '' })

function fill(m) {
  member.value = m
  state.member = m
}
function toggle(name) {
  open.value = open.value === name ? '' : name
  Object.assign(pwd, { old: '', next: '', again: '' })
  Object.assign(bind, { value: '', code: '' })
}
// 绑定时要不要验证码：和注册的规则一致，由后台开关决定
const needCode = (type) => (type === 'email' ? state.config.email_verify_enabled : state.config.sms_enabled)
const validValue = (type) => (type === 'email' ? /^\S+@\S+\.\S+$/ : /^\d{6,15}$/).test(bind.value.trim())

async function savePassword() {
  if (saving.value) return
  if (member.value.has_password && !pwd.old) return toast(t('security.needOld'))
  if (pwd.next.length < 6) return toast(t('login.needPassword'))
  if (pwd.next !== pwd.again) return toast(t('security.pwdMismatch'))
  saving.value = true
  try {
    await changePassword({ password: pwd.next, ...(member.value.has_password ? { old_password: pwd.old } : {}) })
    toast(t('security.pwdSaved'))
    open.value = ''
    fill(await getProfile())
  } finally {
    saving.value = false
  }
}

// 获取验证码的倒计时
const wait = ref(0)
let timer = null
async function getCode(type) {
  if (wait.value > 0) return
  if (!validValue(type)) return toast(t(type === 'email' ? 'security.needEmail' : 'security.needPhone'))
  // 绑定用的验证码和注册是同一种：要求这个邮箱 / 手机号还没被注册
  if (type === 'email') await sendEmailCode({ email: bind.value.trim(), purpose: 'register' })
  else await sendSmsCode({ mobile: bind.value.trim(), purpose: 'register' })
  toast(t('login.codeSent'))
  wait.value = 60
  timer = setInterval(() => { if (--wait.value <= 0) clearInterval(timer) }, 1000)
}
onUnmounted(() => clearInterval(timer))

async function saveBind(type) {
  if (saving.value) return
  if (!validValue(type)) return toast(t(type === 'email' ? 'security.needEmail' : 'security.needPhone'))
  if (needCode(type) && !bind.code.trim()) return toast(t('auth.needCode'))
  saving.value = true
  try {
    const value = bind.value.trim()
    fill(await bindContact({ type, ...(type === 'email' ? { email: value } : { mobile: value }), ...(needCode(type) ? { code: bind.code.trim() } : {}) }))
    toast(t('security.bound'))
    open.value = ''
  } finally {
    saving.value = false
  }
}

// #ifdef H5
// Google 账号：点“绑定”后在这一行下面画出 Google 的按钮，选完账号把凭证交给后端；已绑定的点了是解绑
async function onGoogle() {
  if (member.value.google_bound) {
    if (!(await confirm(t('security.unbindConfirm')))) return
    fill(await unbindGoogle())
    return toast(t('security.unbindDone'))
  }
  toggle('google')
  if (open.value !== 'google') return
  await nextTick()
  const ok = await renderGoogleButton(document.getElementById('gm-google-bind'), {
    clientId: state.config.google_client_id, lang: state.lang, width: Math.min(400, window.innerWidth - 96), onCredential: linkGoogle,
  })
  if (!ok) { toast(t('auth.googleFail')); open.value = '' }
}
async function linkGoogle(credential) {
  if (saving.value) return
  saving.value = true
  try {
    fill(await bindGoogle({ credential }))
    toast(t('security.bound'))
    open.value = ''
  } finally {
    saving.value = false
  }
}
// #endif

// 导出我的数据：H5 里存成 JSON 文件；小程序里存不了文件，提示到网页上导出
const exporting = ref(false)
async function exportData() {
  // #ifdef H5
  if (exporting.value) return
  exporting.value = true
  try {
    const data = await exportMyData()
    const url = URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' }))
    const a = document.createElement('a')
    a.href = url
    a.download = `my-data-${new Date().toISOString().slice(0, 10)}.json`
    document.body.appendChild(a)
    a.click()
    a.remove()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
    toast(t('privacy.exported'))
  } finally {
    exporting.value = false
  }
  // #endif
  // #ifndef H5
  toast(t('privacy.exportWeb'))
  // #endif
}

// 注销账号：展开时先问后端现在能不能注销
const check = ref(null)
const cancelPwd = ref('')
async function openCancel() {
  toggle('cancel')
  cancelPwd.value = ''
  check.value = null
  if (open.value === 'cancel') check.value = await cancelCheck()
}
async function doCancel() {
  if (saving.value) return
  if (check.value.has_password && !cancelPwd.value) return toast(t('privacy.cancelPwd'))
  if (!(await confirm(t('privacy.cancelAsk')))) return
  saving.value = true
  try {
    await cancelAccount(check.value.has_password ? { password: cancelPwd.value } : {})
    logout()
    toast(t('privacy.cancelDone'))
    setTimeout(() => uni.switchTab({ url: '/pages/index/index' }), 600)
  } finally {
    saving.value = false
  }
}

onLoad(async () => {
  fill(await getProfile())
})
</script>

<template>
  <view v-if="member">
    <view class="tip sub">{{ t('security.tip') }}</view>

    <view class="card list">
      <view class="cell" @click="toggle('pwd')">
        <text>{{ t('security.password') }}</text>
        <text class="sub">{{ t(member.has_password ? 'security.pwdSet' : 'security.pwdUnset') }}　<text class="act">{{ t(member.has_password ? 'security.change' : 'security.set') }}</text></text>
      </view>
      <view v-if="open === 'pwd'" class="form">
        <input v-if="member.has_password" v-model="pwd.old" class="input" password :placeholder="t('security.oldPwd')" />
        <input v-model="pwd.next" class="input" password :placeholder="t('auth.newPassword')" />
        <input v-model="pwd.again" class="input" password :placeholder="t('security.againPwd')" />
        <view class="btn" :class="{ disabled: saving }" @click="savePassword">{{ t('common.save') }}</view>
      </view>
    </view>

    <view v-for="type in ['email', 'phone']" :key="type" class="card list">
      <view class="cell" @click="!member[type] && toggle(type)">
        <text>{{ t(`profile.${type}`) }}</text>
        <text v-if="member[type]" class="sub">{{ type === 'phone' ? `+${member.phone_area} ` : '' }}{{ member[type] }}</text>
        <text v-else class="sub">{{ t('security.unbound') }}　<text class="act">{{ t('security.bind') }}</text></text>
      </view>
      <view v-if="open === type" class="form">
        <input v-model="bind.value" class="input" :type="type === 'phone' ? 'number' : 'text'" :placeholder="t(type === 'email' ? 'security.emailPh' : 'auth.mobile')" />
        <view v-if="needCode(type)" class="code-row">
          <input v-model="bind.code" class="input" style="flex: 1" type="number" maxlength="8" :placeholder="t('login.code')" />
          <view class="code-btn" :class="{ off: wait > 0 }" @click="getCode(type)">{{ wait > 0 ? t('login.resend', { n: wait }) : t('login.getCode') }}</view>
        </view>
        <view class="btn" :class="{ disabled: saving }" @click="saveBind(type)">{{ t('security.bind') }}</view>
      </view>
    </view>

    <!-- #ifdef H5 -->
    <!-- 后台启用了 Google 登录才能绑定；之前绑过的，停用后仍然可以在这里解绑 -->
    <view v-if="state.config.google_client_id || member.google_bound" class="card list">
      <view class="cell" data-row="google" @click="onGoogle">
        <text>{{ t('security.google') }}</text>
        <text class="sub">{{ t(member.google_bound ? 'security.googleLinked' : 'security.unbound') }}　<text class="act">{{ t(member.google_bound ? 'security.unbind' : 'security.bind') }}</text></text>
      </view>
      <view v-show="open === 'google'" class="form">
        <view class="sub google-tip">{{ t('security.googleTip') }}</view>
        <view id="gm-google-bind" class="google-box" />
      </view>
    </view>
    <!-- #endif -->

    <view class="card list">
      <view class="cell" data-row="export" @click="exportData">
        <view class="cell-main"><text>{{ t('privacy.exportTitle') }}</text><text class="sub desc">{{ t('privacy.exportTip') }}</text></view>
        <text class="act">{{ t('privacy.exportBtn') }}</text>
      </view>
    </view>

    <view class="card list">
      <view class="cell" data-row="cancel" @click="openCancel">
        <view class="cell-main"><text>{{ t('privacy.cancelTitle') }}</text><text class="sub desc">{{ t('privacy.cancelTip') }}</text></view>
        <text class="act danger">{{ t('privacy.cancelBtn') }}</text>
      </view>
      <view v-if="open === 'cancel' && check" class="form">
        <template v-if="check.can_cancel">
          <view class="warn">{{ t('privacy.cancelWarn', { points: check.points, coupons: check.coupons }) }}</view>
          <input v-if="check.has_password" v-model="cancelPwd" class="input" password :placeholder="t('privacy.cancelPwd')" />
          <view class="btn danger-btn" :class="{ disabled: saving }" @click="doCancel">{{ t('privacy.cancelConfirm') }}</view>
        </template>
        <template v-else>
          <view class="warn">{{ t('privacy.cancelBlocked') }}</view>
          <view v-for="b in check.blockers" :key="b" class="blocker">· {{ b }}</view>
        </template>
      </view>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.tip { margin: 24rpx 36rpx 0; line-height: 1.6; }
.list { padding: 0 24rpx; }
.cell { display: flex; justify-content: space-between; align-items: center; min-height: 100rpx; }
.act { color: var(--theme); white-space: nowrap; margin-left: 20rpx; }
.act.danger { color: #c0392b; }
.cell-main { display: flex; flex-direction: column; padding: 22rpx 0; }
.desc { margin-top: 6rpx; line-height: 1.5; }
.warn { padding-top: 24rpx; line-height: 1.7; font-size: 26rpx; }
.blocker { color: #c0392b; font-size: 26rpx; line-height: 1.8; }
.danger-btn { background: #c0392b; }
.form { padding: 0 0 28rpx; border-top: 2rpx solid var(--line); }
.google-tip { padding: 24rpx 0 20rpx; line-height: 1.6; }
.google-box { display: flex; justify-content: center; min-height: 88rpx; }
.input { height: 92rpx; border-bottom: 2rpx solid var(--line); font-size: 28rpx; }
.code-row { display: flex; align-items: center; gap: 20rpx; }
.code-btn { color: var(--theme); font-size: 26rpx; white-space: nowrap; }
.code-btn.off { color: var(--sub); }
.btn { margin-top: 28rpx; }
</style>
