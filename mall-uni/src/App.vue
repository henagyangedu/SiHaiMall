<script>
import { bootstrap } from '@/utils/app'
import { showStorageNotice } from '@/utils/storageNotice'

export default {
  onLaunch() {
    // 启动配置和登录态；各页面不等它完成也能渲染，拿到后界面自动更新
    // 本地存储告知条要等配置（后台有没有开启）和语言都定了再显示
    bootstrap().then(showStorageNotice, () => {})
  },
  // 全局错误钩子。接口返回业务错误时，请求层已经弹过提示并以 {code, msg} 拒绝，
  // 页面里不必每处都 catch；这里把它们接住，不当成程序错误，其余的照常输出
  onError(err) {
    if (err && typeof err === 'object' && 'code' in err && 'msg' in err) return
    console.error(err)
  },
}
</script>

<style lang="scss">
/* 全局样式：主题色和几个通用小部件，页面里的样式各自写 scoped */
page {
  /* “温暖生活方式”配色，和 PC 端一致（mall-pc/src/styles/main.css） */
  --theme: #c56948;        /* 陶土橙：主色 */
  --theme-dark: #a9563a;
  --bg: #f7f3eb;           /* 奶油白：页面底色 */
  --cream-deep: #f0e9dd;   /* 深一档的奶油色：公告条、图片占位、输入框 */
  --sage: #a7b7a0;         /* 鼠尾草绿：点缀色 */
  --text: #292b27;         /* 炭灰：正文 */
  --sub: #8b877d;
  --line: #ebe4d7;
  background: var(--bg);
  color: var(--text);
  font-size: 28rpx;
  line-height: 1.5;
}
.price { color: var(--theme); font-weight: 600; }
.sub { color: var(--sub); font-size: 24rpx; }
.card { background: #fff; border-radius: 24rpx; margin: 20rpx 24rpx; padding: 24rpx; }
.btn {
  background: var(--theme); color: #fff; border-radius: 44rpx; height: 88rpx; line-height: 88rpx; text-align: center; font-size: 30rpx;
  &.plain { background: #fff; color: var(--theme); border: 2rpx solid var(--theme); }
  &.gray { background: #fff; color: var(--text); border: 2rpx solid #ded6c8; }
  &.small { height: 60rpx; line-height: 56rpx; padding: 0 28rpx; font-size: 26rpx; display: inline-block; }
  &.disabled { opacity: 0.5; }
}
.empty { text-align: center; color: var(--sub); padding: 120rpx 0; }
.ellipsis { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ellipsis-2 { overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.safe-bottom { padding-bottom: env(safe-area-inset-bottom); }
</style>
