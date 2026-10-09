<script setup>
import { useRoute } from 'vue-router'

const route = useRoute()
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <el-empty :description="`「${route.meta.title || '该功能'}」正在开发中`" />
    </el-card>
  </div>
</template>
