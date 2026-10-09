<script setup>
import { reactive, ref, onUnmounted } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { useI18n } from 'vue-i18n'
import { state } from '@/store'
import { getProfile, changePassword, bindContact, sendSmsCode, sendEmailCode } from '@/api'
import { usePageTitle, toast } from '@/utils/page'

const { t } = useI18n()
usePageTitle('security.title')

const member = ref(null)
// 当前展开的表单：'' | 'pwd' | 'email' | 'phone'
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
  </view>
</template>

<style lang="scss" scoped>
.tip { margin: 24rpx 36rpx 0; line-height: 1.6; }
.list { padding: 0 24rpx; }
.cell { display: flex; justify-content: space-between; align-items: center; min-height: 100rpx; }
.act { color: var(--theme); }
.form { padding: 0 0 28rpx; border-top: 2rpx solid var(--line); }
.input { height: 92rpx; border-bottom: 2rpx solid var(--line); font-size: 28rpx; }
.code-row { display: flex; align-items: center; gap: 20rpx; }
.code-btn { color: var(--theme); font-size: 26rpx; white-space: nowrap; }
.code-btn.off { color: var(--sub); }
.btn { margin-top: 28rpx; }
</style>
