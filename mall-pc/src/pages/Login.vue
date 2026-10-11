<script setup>
import { ref, reactive, computed, onMounted, onUnmounted, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import { state, setToken } from '@/store'
import { login, register, loginBySms, loginByGoogle, sendSmsCode, sendEmailCode, getProtocols } from '@/api'
import { renderGoogleButton } from '@shared/utils/google'
import { refreshCartCount } from '@/utils/app'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const mode = ref('login') // login | register
// 登录方式：密码，或短信验证码（后台开启短信后才有）
const bySms = ref(false)
const form = reactive({ account: '', password: '', nickname: '', code: '', invite_code: '' })
const submitting = ref(false)
const protocols = ref([])
// 后台填了用户协议或隐私政策时，注册必须勾选同意
const agreed = ref(false)
const needAgree = computed(() => mode.value === 'register' && !!state.config.agree_required)

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
  if (!account) return ElMessage.warning(t(smsLogin.value ? 'auth.needMobile' : 'login.needAccount'))
  const purpose = smsLogin.value ? 'login' : 'register'
  if (!smsLogin.value && isEmail.value) await sendEmailCode({ email: account, purpose })
  else await sendSmsCode({ mobile: account, purpose })
  ElMessage.success(t('login.codeSent'))
  wait.value = 60
  timer = setInterval(() => { if (--wait.value <= 0) clearInterval(timer) }, 1000)
}
onUnmounted(() => clearInterval(timer))

function switchMode(m) {
  mode.value = m
  form.code = ''
}

// 登录后只回到站内地址，避免被带参数的链接引到别处
function backTo() {
  const r = String(route.query.redirect || '')
  return r.startsWith('/') && !r.startsWith('//') ? r : '/'
}

async function submit() {
  if (submitting.value) return
  const account = form.account.trim()
  if (!account) return ElMessage.warning(t(smsLogin.value ? 'auth.needMobile' : 'login.needAccount'))
  if (!smsLogin.value && form.password.length < 6) return ElMessage.warning(t('login.needPassword'))
  if (needCode.value && !form.code.trim()) return ElMessage.warning(t('auth.needCode'))
  if (needAgree.value && !agreed.value) return ElMessage.warning(t('login.needAgree'))
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
    setToken(data.token, data.info)
    refreshCartCount()
    ElMessage.success(t('login.welcome'))
    router.replace(backTo())
  } finally {
    submitting.value = false
  }
}

// Google 登录：后台启用后才有。按钮由 Google 的脚本画；脚本加载不了时这一块不显示
const googleBox = ref(null)
const googleReady = ref(false)
// 第一次用 Google 登录（要新建会员）而后台要求同意协议时，先把凭证留着，等买家点“同意并继续”
const googlePending = ref('')
async function googleLogin(credential, agree = false) {
  if (submitting.value) return
  submitting.value = true
  try {
    const data = await loginByGoogle({
      credential, ...(agree ? { agree: 1 } : {}),
      ...(form.invite_code.trim() ? { invite_code: form.invite_code.trim() } : {}),
    })
    googlePending.value = ''
    setToken(data.token, data.info)
    refreshCartCount()
    ElMessage.success(t('login.welcome'))
    router.replace(backTo())
  } catch (e) {
    if (e && e.code === 428) googlePending.value = credential
    else ElMessage.error((e && e.msg) || t('auth.googleFail'))
  } finally {
    submitting.value = false
  }
}

onMounted(() => {
  if (state.token) return router.replace(backTo())
  if (state.config.google_client_id) {
    nextTick(async () => {
      googleReady.value = await renderGoogleButton(googleBox.value, { clientId: state.config.google_client_id, lang: state.lang, width: 348, onCredential: (c) => googleLogin(c) })
    })
  }
  // 通过邀请链接进来：直接进注册并带上邀请码
  if (route.query.invite) { mode.value = 'register'; form.invite_code = String(route.query.invite) }
  // 没有填写协议时不显示“注册即表示同意”
  getProtocols().then((d) => { protocols.value = d.filter((p) => ['user_agreement', 'privacy_policy'].includes(p.key)) }).catch(() => {})
})
</script>

<template>
  <div class="panel box">
    <el-tabs :model-value="mode" stretch @tab-change="switchMode">
      <el-tab-pane :label="t('login.title')" name="login" />
      <el-tab-pane :label="t('login.register')" name="register" />
    </el-tabs>

    <el-form size="large" @submit.prevent="submit">
      <el-form-item>
        <el-input v-model="form.account" :placeholder="t(smsLogin ? 'auth.mobile' : 'login.account')" autocomplete="username" />
      </el-form-item>
      <el-form-item v-if="!smsLogin">
        <el-input v-model="form.password" type="password" show-password :placeholder="t('login.password')" :autocomplete="mode === 'login' ? 'current-password' : 'new-password'" @keyup.enter="submit" />
      </el-form-item>
      <el-form-item v-if="needCode">
        <div class="code-row">
          <el-input v-model="form.code" maxlength="8" :placeholder="t('login.code')" @keyup.enter="submit" />
          <el-button :disabled="wait > 0" @click="getCode">{{ wait > 0 ? t('login.resend', { n: wait }) : t('login.getCode') }}</el-button>
        </div>
      </el-form-item>
      <template v-if="mode === 'register'">
        <el-form-item><el-input v-model="form.nickname" maxlength="60" :placeholder="t('login.nickname')" /></el-form-item>
        <el-form-item><el-input v-model="form.invite_code" maxlength="20" :placeholder="t('login.inviteCode')" /></el-form-item>
      </template>

      <div v-if="mode === 'login'" class="links">
        <a v-if="state.config.sms_enabled" class="link" @click="bySms = !bySms; form.code = ''">{{ t(bySms ? 'auth.pwdLogin' : 'auth.smsLogin') }}</a>
        <span v-else />
        <router-link :to="{ name: 'reset-password' }" class="link">{{ t('auth.forgot') }}</router-link>
      </div>

      <el-button type="primary" class="submit" :loading="submitting" @click="submit">{{ t(mode === 'login' ? 'login.submitLogin' : 'login.submitRegister') }}</el-button>
    </el-form>

    <div v-if="mode === 'register' && protocols.length" class="agree sub">
      <el-checkbox v-if="needAgree" v-model="agreed" class="agree-box">{{ t('login.agreeCheck') }}</el-checkbox>
      <template v-else>{{ t('login.agree') }}</template>
      <router-link v-for="p in protocols" :key="p.key" :to="{ name: 'protocols', params: { key: p.key } }" target="_blank" class="link"> {{ p.title }}</router-link>
    </div>

    <div v-if="state.config.google_client_id" v-show="googleReady" class="third">
      <div class="or sub"><span>{{ t('auth.orThird') }}</span></div>
      <div ref="googleBox" class="google" />
      <div v-if="googlePending" class="google-agree">
        <div class="sub">{{ t('auth.googleNew') }}
          <router-link v-for="p in protocols" :key="p.key" :to="{ name: 'protocols', params: { key: p.key } }" target="_blank" class="link"> {{ p.title }}</router-link>
        </div>
        <div class="google-agree-btns">
          <el-button @click="googlePending = ''">{{ t('common.cancel') }}</el-button>
          <el-button type="primary" :loading="submitting" @click="googleLogin(googlePending, true)">{{ t('auth.agreeContinue') }}</el-button>
        </div>
      </div>
    </div>

    <div class="switch">
      <a class="link" @click="switchMode(mode === 'login' ? 'register' : 'login')">{{ t(mode === 'login' ? 'login.toRegister' : 'login.toLogin') }}</a>
    </div>
  </div>
</template>

<style scoped>
.box { width: 420px; margin: 40px auto; padding: 28px 36px 32px; }
.code-row { display: flex; gap: 10px; width: 100%; }
.links { display: flex; justify-content: space-between; margin: -6px 0 14px; font-size: 13px; }
.agree { display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: 4px 8px; margin-top: 14px; }
.submit { width: 100%; }
.switch { text-align: center; margin-top: 18px; font-size: 13px; }
.third { margin-top: 20px; }
.or { display: flex; align-items: center; gap: 12px; font-size: 13px; margin-bottom: 16px; }
.or::before, .or::after { content: ''; flex: 1; height: 1px; background: var(--line); }
.google { display: flex; justify-content: center; min-height: 44px; }
.google-agree { margin-top: 14px; padding: 14px 16px; border-radius: var(--radius); background: var(--el-color-primary-light-9); font-size: 13px; line-height: 1.7; }
.google-agree .link { margin-left: 8px; }
.google-agree-btns { display: flex; justify-content: flex-end; margin-top: 10px; }
.agree-box { margin-right: 0; vertical-align: middle; height: auto; }
.agree-box :deep(.el-checkbox__label) { font-size: 13px; color: var(--sub); }
</style>
