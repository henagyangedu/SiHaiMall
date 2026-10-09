<script setup>
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <div class="panel empty">
    <div>{{ t('pc.notFound') }}</div>
    <router-link :to="{ name: 'home' }"><el-button type="primary" style="margin-top: 20px">{{ t('pc.backHome') }}</el-button></router-link>
  </div>
</template>
