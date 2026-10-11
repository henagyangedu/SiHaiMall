<script setup>
import { reactive, ref, onMounted, onUnmounted, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { state, logout } from '@/store'
import { confirm } from '@/utils/app'
import { renderGoogleButton } from '@shared/utils/google'
import { getProfile, changePassword, bindContact, bindGoogle, unbindGoogle, sendSmsCode, sendEmailCode, cancelCheck, cancelAccount, exportMyData } from '@/api'

const { t } = useI18n()
const router = useRouter()
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
  if (member.value.has_password && !pwd.old) return ElMessage.warning(t('security.needOld'))
  if (pwd.next.length < 6) return ElMessage.warning(t('login.needPassword'))
  if (pwd.next !== pwd.again) return ElMessage.warning(t('security.pwdMismatch'))
  saving.value = true
  try {
    await changePassword({ password: pwd.next, ...(member.value.has_password ? { old_password: pwd.old } : {}) })
    ElMessage.success(t('security.pwdSaved'))
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
  if (!validValue(type)) return ElMessage.warning(t(type === 'email' ? 'security.needEmail' : 'security.needPhone'))
  // 绑定用的验证码和注册是同一种：要求这个邮箱 / 手机号还没被注册
  if (type === 'email') await sendEmailCode({ email: bind.value.trim(), purpose: 'register' })
  else await sendSmsCode({ mobile: bind.value.trim(), purpose: 'register' })
  ElMessage.success(t('login.codeSent'))
  wait.value = 60
  timer = setInterval(() => { if (--wait.value <= 0) clearInterval(timer) }, 1000)
}
onUnmounted(() => clearInterval(timer))

async function saveBind(type) {
  if (saving.value) return
  if (!validValue(type)) return ElMessage.warning(t(type === 'email' ? 'security.needEmail' : 'security.needPhone'))
  if (needCode(type) && !bind.code.trim()) return ElMessage.warning(t('auth.needCode'))
  saving.value = true
  try {
    const value = bind.value.trim()
    fill(await bindContact({ type, ...(type === 'email' ? { email: value } : { mobile: value }), ...(needCode(type) ? { code: bind.code.trim() } : {}) }))
    ElMessage.success(t('security.bound'))
    open.value = ''
  } finally {
    saving.value = false
  }
}

// Google 账号：点“绑定”后在这一行下面画出 Google 的按钮，选完账号把凭证交给后端
const googleBox = ref(null)
async function openGoogle() {
  toggle('google')
  if (open.value !== 'google') return
  await nextTick()
  const ok = await renderGoogleButton(googleBox.value, { clientId: state.config.google_client_id, lang: state.lang, width: 300, onCredential: linkGoogle })
  if (!ok) { ElMessage.error(t('auth.googleFail')); open.value = '' }
}
async function linkGoogle(credential) {
  if (saving.value) return
  saving.value = true
  try {
    fill(await bindGoogle({ credential }))
    ElMessage.success(t('security.bound'))
    open.value = ''
  } finally {
    saving.value = false
  }
}
async function unlinkGoogle() {
  if (!(await confirm(t('security.unbindConfirm')))) return
  fill(await unbindGoogle())
  ElMessage.success(t('security.unbindDone'))
}

// 导出我的数据：接口给的是一个对象，存成 JSON 文件
const exporting = ref(false)
async function exportData() {
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
    ElMessage.success(t('privacy.exported'))
  } finally {
    exporting.value = false
  }
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
  if (check.value.has_password && !cancelPwd.value) return ElMessage.warning(t('privacy.cancelPwd'))
  await ElMessageBox.confirm(t('privacy.cancelAsk'), t('privacy.cancelTitle'), { type: 'warning', confirmButtonText: t('privacy.cancelConfirm'), cancelButtonText: t('common.cancel') })
  saving.value = true
  try {
    await cancelAccount(check.value.has_password ? { password: cancelPwd.value } : {})
    logout()
    ElMessage.success(t('privacy.cancelDone'))
    router.replace('/')
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  fill(await getProfile())
})
</script>

<template>
  <div v-if="member" class="panel">
    <div class="panel-title">{{ t('security.title') }}</div>
    <div class="sub tip">{{ t('security.tip') }}</div>

    <div class="row" data-row="pwd">
      <div class="head">
        <span class="name">{{ t('security.password') }}</span>
        <span class="val sub">{{ t(member.has_password ? 'security.pwdSet' : 'security.pwdUnset') }}</span>
        <el-button link type="primary" @click="toggle('pwd')">{{ t(member.has_password ? 'security.change' : 'security.set') }}</el-button>
      </div>
      <div v-if="open === 'pwd'" class="form">
        <el-input v-if="member.has_password" v-model="pwd.old" type="password" show-password :placeholder="t('security.oldPwd')" />
        <el-input v-model="pwd.next" type="password" show-password :placeholder="t('auth.newPassword')" />
        <el-input v-model="pwd.again" type="password" show-password :placeholder="t('security.againPwd')" @keyup.enter="savePassword" />
        <div>
          <el-button type="primary" :loading="saving" @click="savePassword">{{ t('common.save') }}</el-button>
          <el-button @click="toggle('pwd')">{{ t('common.cancel') }}</el-button>
        </div>
      </div>
    </div>

    <div v-for="type in ['email', 'phone']" :key="type" class="row" :data-row="type">
      <div class="head">
        <span class="name">{{ t(`profile.${type}`) }}</span>
        <span v-if="member[type]" class="val">{{ type === 'phone' ? `+${member.phone_area} ` : '' }}{{ member[type] }}</span>
        <template v-else>
          <span class="val sub">{{ t('security.unbound') }}</span>
          <el-button link type="primary" @click="toggle(type)">{{ t('security.bind') }}</el-button>
        </template>
      </div>
      <div v-if="open === type" class="form">
        <el-input v-model="bind.value" :placeholder="t(type === 'email' ? 'security.emailPh' : 'auth.mobile')" />
        <div v-if="needCode(type)" class="code-row">
          <el-input v-model="bind.code" maxlength="8" :placeholder="t('login.code')" />
          <el-button :disabled="wait > 0" @click="getCode(type)">{{ wait > 0 ? t('login.resend', { n: wait }) : t('login.getCode') }}</el-button>
        </div>
        <div>
          <el-button type="primary" :loading="saving" @click="saveBind(type)">{{ t('security.bind') }}</el-button>
          <el-button @click="toggle(type)">{{ t('common.cancel') }}</el-button>
        </div>
      </div>
    </div>

    <!-- 后台启用了 Google 登录才能绑定；之前绑过的，停用后仍然可以在这里解绑 -->
    <div v-if="state.config.google_client_id || member.google_bound" class="row" data-row="google">
      <div class="head">
        <span class="name">{{ t('security.google') }}</span>
        <span class="val" :class="{ sub: !member.google_bound }">{{ t(member.google_bound ? 'security.googleLinked' : 'security.unbound') }}</span>
        <el-button v-if="member.google_bound" link type="primary" @click="unlinkGoogle">{{ t('security.unbind') }}</el-button>
        <el-button v-else link type="primary" @click="openGoogle">{{ t('security.bind') }}</el-button>
      </div>
      <div v-show="open === 'google'" class="form wide">
        <div class="sub">{{ t('security.googleTip') }}</div>
        <div ref="googleBox" class="google-box" />
      </div>
    </div>

    <div class="row" data-row="export">
      <div class="head">
        <span class="name">{{ t('privacy.exportTitle') }}</span>
        <span class="val sub">{{ t('privacy.exportTip') }}</span>
        <el-button link type="primary" :loading="exporting" @click="exportData">{{ t('privacy.exportBtn') }}</el-button>
      </div>
    </div>

    <div class="row" data-row="cancel">
      <div class="head">
        <span class="name">{{ t('privacy.cancelTitle') }}</span>
        <span class="val sub">{{ t('privacy.cancelTip') }}</span>
        <el-button link type="danger" @click="openCancel">{{ t('privacy.cancelBtn') }}</el-button>
      </div>
      <div v-if="open === 'cancel' && check" class="form wide">
        <template v-if="check.can_cancel">
          <div class="warn">{{ t('privacy.cancelWarn', { points: check.points, coupons: check.coupons }) }}</div>
          <el-input v-if="check.has_password" v-model="cancelPwd" type="password" show-password :placeholder="t('privacy.cancelPwd')" style="max-width: 360px" />
          <div>
            <el-button type="danger" :loading="saving" @click="doCancel">{{ t('privacy.cancelConfirm') }}</el-button>
            <el-button @click="toggle('cancel')">{{ t('common.cancel') }}</el-button>
          </div>
        </template>
        <template v-else>
          <div class="warn">{{ t('privacy.cancelBlocked') }}</div>
          <ul class="blockers"><li v-for="b in check.blockers" :key="b">{{ b }}</li></ul>
        </template>
      </div>
    </div>
  </div>
</template>

<style scoped>
.tip { margin-bottom: 8px; }
.row { border-bottom: 1px solid var(--line); padding: 18px 0; }
.row:last-child { border-bottom: none; }
.head { display: flex; align-items: center; }
.name { width: 120px; font-weight: 600; }
.val { flex: 1; }
.form { display: flex; flex-direction: column; gap: 12px; max-width: 360px; margin: 16px 0 0 120px; }
.code-row { display: flex; gap: 10px; }
.form.wide { max-width: 640px; }
.google-box { min-height: 44px; }
.warn { line-height: 1.7; color: var(--text); }
.blockers { margin: 0; padding-left: 20px; line-height: 1.8; color: var(--el-color-danger); }
</style>
