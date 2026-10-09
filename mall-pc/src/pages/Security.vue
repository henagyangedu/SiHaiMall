<script setup>
import { reactive, ref, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import { state } from '@/store'
import { getProfile, changePassword, bindContact, sendSmsCode, sendEmailCode } from '@/api'

const { t } = useI18n()
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
</style>
