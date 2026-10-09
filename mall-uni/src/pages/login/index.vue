<script setup>
import { ref, reactive, computed, onUnmounted } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { useI18n } from 'vue-i18n'
import { state, setToken } from '@/store'
import { login, register, loginBySms, sendSmsCode, sendEmailCode, getProtocols } from '@/api'
import { refreshCartCount } from '@/utils/app'
import { usePageTitle, toast, nav } from '@/utils/page'

const { t } = useI18n()
const mode = ref('login') // login | register
// 登录方式：密码，或短信验证码（后台开启短信后才有）
const bySms = ref(false)
usePageTitle(() => t(mode.value === 'login' ? 'login.title' : 'login.register'))

const form = reactive({ account: '', password: '', nickname: '', code: '', invite_code: '' })
const submitting = ref(false)
const protocols = ref([])

const isEmail = computed(() => form.account.includes('@'))
const isPhone = computed(() => /^\d{6,15}$/.test(form.account.trim()))
const smsLogin = computed(() => mode.value === 'login' && bySms.value)
// 什么时候要填验证码：短信登录；或注册时手机号开了短信验证、邮箱开了邮箱验证
const needCode = computed(() => smsLogin.value || (mode.value === 'register'
  && ((isPhone.value && state.config.sms_enabled) || (isEmail.value && state.config.email_verify_enabled))))

// 获取验证码的倒计时
const wait = ref(0)
let timer = null
async function getCode() {
  if (wait.value > 0) return
  const account = form.account.trim()
  if (!account) return toast(t(smsLogin.value ? 'auth.needMobile' : 'login.needAccount'))
  const purpose = smsLogin.value ? 'login' : 'register'
  if (!smsLogin.value && isEmail.value) await sendEmailCode({ email: account, purpose })
  else await sendSmsCode({ mobile: account, purpose })
  toast(t('login.codeSent'))
  wait.value = 60
  timer = setInterval(() => { if (--wait.value <= 0) clearInterval(timer) }, 1000)
}
onUnmounted(() => clearInterval(timer))

function switchMode(m) {
  mode.value = m
  form.code = ''
}

async function submit() {
  const account = form.account.trim()
  if (!account) return toast(t(smsLogin.value ? 'auth.needMobile' : 'login.needAccount'))
  if (!smsLogin.value && form.password.length < 6) return toast(t('login.needPassword'))
  if (needCode.value && !form.code.trim()) return toast(t('auth.needCode'))
  submitting.value = true
  try {
    let data
    if (smsLogin.value) {
      data = await loginBySms({ mobile: account, code: form.code.trim() })
    } else if (mode.value === 'login') {
      data = await login({ account, password: form.password })
    } else {
      data = await register({
        account, password: form.password, nickname: form.nickname.trim(),
        ...(needCode.value ? { code: form.code.trim() } : {}),
        ...(form.invite_code.trim() ? { invite_code: form.invite_code.trim() } : {}),
      })
    }
    setToken(data.token, data.info)
    refreshCartCount()
    toast(t('login.welcome'))
    // 回到进入登录页之前的页面；直接打开登录页时回首页
    setTimeout(() => (getCurrentPages().length > 1 ? uni.navigateBack() : uni.switchTab({ url: '/pages/index/index' })), 400)
  } finally {
    submitting.value = false
  }
}

onLoad(async (q) => {
  // 通过邀请链接进来：直接进注册并带上邀请码
  if (q.invite) { mode.value = 'register'; form.invite_code = q.invite }
  try {
    protocols.value = (await getProtocols()).filter((p) => ['user_agreement', 'privacy_policy'].includes(p.key))
  } catch (e) { /* 没有协议时不显示 */ }
})
</script>

<template>
  <view class="page">
    <view class="tabs">
      <text class="tab" :class="{ on: mode === 'login' }" @click="switchMode('login')">{{ t('login.title') }}</text>
      <text class="tab" :class="{ on: mode === 'register' }" @click="switchMode('register')">{{ t('login.register') }}</text>
    </view>

    <view class="form">
      <input v-model="form.account" class="input" :type="smsLogin ? 'number' : 'text'" :placeholder="t(smsLogin ? 'auth.mobile' : 'login.account')" />
      <input v-if="!smsLogin" v-model="form.password" class="input" password :placeholder="t('login.password')" />
      <view v-if="needCode" class="code-row">
        <input v-model="form.code" class="input" style="flex: 1" type="number" maxlength="8" :placeholder="t('login.code')" />
        <view class="code-btn" :class="{ off: wait > 0 }" @click="getCode">{{ wait > 0 ? t('login.resend', { n: wait }) : t('login.getCode') }}</view>
      </view>
      <template v-if="mode === 'register'">
        <input v-model="form.nickname" class="input" maxlength="60" :placeholder="t('login.nickname')" />
        <input v-model="form.invite_code" class="input" maxlength="20" :placeholder="t('login.inviteCode')" />
      </template>

      <view v-if="mode === 'login'" class="links">
        <text v-if="state.config.sms_enabled" @click="bySms = !bySms; form.code = ''">{{ t(bySms ? 'auth.pwdLogin' : 'auth.smsLogin') }}</text>
        <text v-else />
        <text @click="nav('/pages/login/reset')">{{ t('auth.forgot') }}</text>
      </view>

      <view class="btn" :class="{ disabled: submitting }" style="margin-top: 40rpx" @click="!submitting && submit()">
        {{ t(mode === 'login' ? 'login.submitLogin' : 'login.submitRegister') }}
      </view>
      <view v-if="mode === 'register' && protocols.length" class="agree">
        {{ t('login.agree') }}
        <text v-for="p in protocols" :key="p.key" class="link" @click="nav(`/pages/protocol/index?key=${p.key}`)"> {{ p.title }}</text>
      </view>
      <view class="switch" @click="switchMode(mode === 'login' ? 'register' : 'login')">{{ t(mode === 'login' ? 'login.toRegister' : 'login.toLogin') }}</view>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.page { background: #fff; min-height: 100vh; padding: 60rpx 48rpx; box-sizing: border-box; }
.tabs { display: flex; gap: 48rpx; margin-bottom: 48rpx; }
.tab { font-size: 34rpx; color: var(--sub); padding-bottom: 12rpx; border-bottom: 6rpx solid transparent; }
.tab.on { color: var(--text); font-weight: 600; border-bottom-color: var(--theme); font-size: 40rpx; }
.input { height: 96rpx; border-bottom: 2rpx solid var(--line); font-size: 30rpx; margin-bottom: 12rpx; }
.code-row { display: flex; align-items: center; gap: 20rpx; }
.code-btn { color: var(--theme); font-size: 26rpx; white-space: nowrap; }
.code-btn.off { color: var(--sub); }
.links { display: flex; justify-content: space-between; color: #606266; font-size: 26rpx; margin-top: 12rpx; }
.agree { text-align: center; color: var(--sub); font-size: 24rpx; margin-top: 24rpx; }
.link { color: var(--theme); margin-left: 12rpx; }
.switch { text-align: center; color: var(--sub); margin-top: 32rpx; font-size: 26rpx; }
</style>
