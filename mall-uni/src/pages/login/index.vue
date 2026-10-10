<script setup>
import { ref, reactive, computed, onUnmounted } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { useI18n } from 'vue-i18n'
import { state, setToken } from '@/store'
import { login, register, loginBySms, loginByWechatMini, sendSmsCode, sendEmailCode, getProtocols } from '@/api'
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
// 后台填了用户协议或隐私政策时，注册（和小程序里微信一键登录的首次登录）必须勾选同意
const agreed = ref(false)
const needAgree = computed(() => !!state.config.agree_required)

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
  if (mode.value === 'register' && needAgree.value && !agreed.value) return toast(t('login.needAgree'))
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
        ...(needAgree.value ? { agree: 1 } : {}),
      })
    }
    afterLogin(data)
  } finally {
    submitting.value = false
  }
}

function afterLogin(data) {
  setToken(data.token, data.info)
  refreshCartCount()
  toast(t('login.welcome'))
  // 回到进入登录页之前的页面；直接打开登录页时回首页
  setTimeout(() => (getCurrentPages().length > 1 ? uni.navigateBack() : uni.switchTab({ url: '/pages/index/index' })), 400)
}

// #ifdef MP-WEIXIN
// 微信一键登录：向微信要一个一次性的登录凭证交给后端，第一次登录时后端会自动注册
async function wechatLogin() {
  if (submitting.value) return
  // 一键登录的第一次就是注册，所以也要先同意
  if (needAgree.value && !agreed.value) return toast(t('login.needAgree'))
  submitting.value = true
  try {
    const code = await new Promise((resolve) => uni.login({ provider: 'weixin', success: (x) => resolve(x.code || ''), fail: () => resolve('') }))
    if (!code) return toast(t('auth.wechatFail'))
    afterLogin(await loginByWechatMini({ code, ...(form.invite_code.trim() ? { invite_code: form.invite_code.trim() } : {}), ...(needAgree.value ? { agree: 1 } : {}) }))
  } finally {
    submitting.value = false
  }
}
// #endif

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
    <!-- #ifdef MP-WEIXIN -->
    <view v-if="state.config.wechat_login_enabled" class="wx">
      <view class="btn wx-btn" :class="{ disabled: submitting }" @click="wechatLogin">{{ t('auth.wechatLogin') }}</view>
      <view v-if="needAgree && protocols.length" class="agree" @click="agreed = !agreed">
        <text class="tick" :class="{ on: agreed }">{{ agreed ? '✓' : '' }}</text>{{ t('login.agreeCheck') }}
        <text v-for="p in protocols" :key="p.key" class="link" @click.stop="nav(`/pages/protocol/index?key=${p.key}`)"> {{ p.title }}</text>
      </view>
      <view class="wx-or">{{ t('auth.orAccount') }}</view>
    </view>
    <!-- #endif -->
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
      <view v-if="mode === 'register' && protocols.length" class="agree" @click="needAgree && (agreed = !agreed)">
        <template v-if="needAgree"><text class="tick" :class="{ on: agreed }">{{ agreed ? '✓' : '' }}</text>{{ t('login.agreeCheck') }}</template>
        <template v-else>{{ t('login.agree') }}</template>
        <text v-for="p in protocols" :key="p.key" class="link" @click.stop="nav(`/pages/protocol/index?key=${p.key}`)"> {{ p.title }}</text>
      </view>
      <view class="switch" @click="switchMode(mode === 'login' ? 'register' : 'login')">{{ t(mode === 'login' ? 'login.toRegister' : 'login.toLogin') }}</view>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.page { background: #fff; min-height: 100vh; padding: 60rpx 48rpx; box-sizing: border-box; }
.wx { margin-bottom: 56rpx; }
.wx-btn { background: #07c160; }
.wx-or { text-align: center; color: var(--sub); font-size: 24rpx; margin-top: 28rpx; }
.tabs { display: flex; gap: 48rpx; margin-bottom: 48rpx; }
.tab { font-size: 34rpx; color: var(--sub); padding-bottom: 12rpx; border-bottom: 6rpx solid transparent; }
.tab.on { color: var(--text); font-weight: 600; border-bottom-color: var(--theme); font-size: 40rpx; }
.input { height: 96rpx; border-bottom: 2rpx solid var(--line); font-size: 30rpx; margin-bottom: 12rpx; }
.code-row { display: flex; align-items: center; gap: 20rpx; }
.code-btn { color: var(--theme); font-size: 26rpx; white-space: nowrap; }
.code-btn.off { color: var(--sub); }
.links { display: flex; justify-content: space-between; color: #6a665e; font-size: 26rpx; margin-top: 12rpx; }
.agree { text-align: center; color: var(--sub); font-size: 24rpx; margin-top: 24rpx; }
.link { color: var(--theme); margin-left: 12rpx; }
.tick { display: inline-block; width: 30rpx; height: 30rpx; line-height: 28rpx; border: 2rpx solid #cfc7b8; border-radius: 8rpx; margin-right: 12rpx; vertical-align: -6rpx; font-size: 22rpx; color: #fff; box-sizing: border-box; }
.tick.on { background: var(--theme); border-color: var(--theme); }
.switch { text-align: center; color: var(--sub); margin-top: 32rpx; font-size: 26rpx; }
</style>
