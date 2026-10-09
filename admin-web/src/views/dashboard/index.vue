<script setup>
import { useUserStore } from '@/stores/user'

const store = useUserStore()
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <h3 style="margin-top: 0">欢迎回来，{{ store.info?.nickname || store.info?.username }}</h3>
      <p>当前角色：{{ store.info?.role_name }}</p>
      <p style="color: #909399">数据统计将在“数据中心”模块完成后显示在这里。</p>
    </el-card>
  </div>
</template>
