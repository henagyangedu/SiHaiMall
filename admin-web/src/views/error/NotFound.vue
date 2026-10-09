<template>
  <div class="page">
    <el-card shadow="never">
      <el-result icon="warning" title="404" sub-title="页面不存在，或你没有访问权限">
        <template #extra>
          <el-button type="primary" @click="$router.replace('/')">返回首页</el-button>
        </template>
      </el-result>
    </el-card>
  </div>
</template>
