<?php

namespace Database\Seeders;

use App\Models\AdminMenu;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Seeder;

/**
 * 同步内置菜单与权限点（可重复执行：目录按 path、菜单和按钮按权限标识匹配，存在则更新，不存在则新增；除 OBSOLETE 里列出的以外不会删除）。
 * 对应 gm_admin_menu.md 中的 P1 部分；后续分销、内容等模块上线时在 definition() 里追加后再执行一次即可。
 */
class MenuSeeder extends Seeder
{
    private const ACTION_LABELS = [
        'add' => '新增', 'edit' => '编辑', 'del' => '删除', 'export' => '导出', 'detail' => '详情',
        'remark' => '备注', 'price' => '改价', 'cancel' => '取消订单', 'do' => '发货', 'print' => '打印',
        'audit' => '审核', 'confirm' => '确认收货', 'pay' => '执行退款', 'retry' => '重新处理',
        'status' => '禁用/启用', 'balance' => '调整余额', 'points' => '调整积分', 'sale' => '上下架',
        'stock' => '修改库存', 'rate' => '更新汇率', 'test' => '测试', 'reset' => '重置密码', 'paid' => '登记打款', 'apply' => '发起售后', 'reply' => '回复', 'grant' => '发放',
    ];

    /**
     * 早期登记过、但没有任何接口使用的权限点：在角色编辑页能勾选，勾不勾都没有效果，容易让人误以为授了权。
     * 这里把它们从菜单表和角色授权里删掉。（库存流水、支付流水、操作日志没有导出功能；
     * 菜单规则的增删改只有超级管理员能做，不走权限点；消息模板按场景固定，没有新增和删除。）
     */
    private const OBSOLETE = [
        'goods:stocklog:export', 'order:trade:export', 'setting:log:export',
        'setting:menu:add', 'setting:menu:edit', 'setting:menu:del',
        'setting:message:add', 'setting:message:del',
    ];

    public function run(): void
    {
        $ids = AdminMenu::query()->where('type', 3)->whereIn('perm', self::OBSOLETE)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('admin_role_menu')->whereIn('menu_id', $ids)->delete();
            AdminMenu::query()->whereIn('id', $ids)->delete();
        }
        $this->sync($this->definition(), 0);
        $this->command?->info('菜单与权限点已同步');
    }

    private function sync(array $nodes, int $pid): void
    {
        foreach ($nodes as $i => $n) {
            $sort = ($i + 1) * 10;

            if (isset($n['children'])) {
                $dir = AdminMenu::query()->updateOrCreate(
                    ['type' => 1, 'path' => $n['path']],
                    ['pid' => $pid, 'title_key' => $n['t'], 'icon' => $n['icon'] ?? '', 'component' => '',
                     'perm' => '', 'api_rule' => '', 'sort' => $sort, 'visible' => 1, 'status' => 1]
                );
                $this->sync($n['children'], (int) $dir->id);
                continue;
            }

            $menu = AdminMenu::query()->updateOrCreate(
                ['type' => 2, 'perm' => $n['perm'] . ':view'],
                ['pid' => $pid, 'title_key' => $n['t'], 'icon' => $n['icon'] ?? '', 'path' => $n['path'],
                 'component' => ltrim($n['path'], '/'), 'api_rule' => '', 'sort' => $sort, 'visible' => 1, 'status' => 1]
            );

            foreach (($n['acts'] ?? []) as $j => $act) {
                AdminMenu::query()->updateOrCreate(
                    ['type' => 3, 'perm' => $n['perm'] . ':' . $act],
                    ['pid' => (int) $menu->id, 'title_key' => self::ACTION_LABELS[$act] ?? $act, 'icon' => '',
                     'path' => '', 'component' => '', 'api_rule' => '', 'sort' => ($j + 1) * 10, 'visible' => 1, 'status' => 1]
                );
            }
        }
    }

    private function definition(): array
    {
        return [
            ['t' => '商品', 'path' => '/goods', 'icon' => 'Goods', 'children' => [
                ['t' => '商品列表', 'path' => '/goods/list', 'perm' => 'goods:list', 'acts' => ['add', 'edit', 'del', 'sale', 'stock']],
                ['t' => '商品分类', 'path' => '/goods/category', 'perm' => 'goods:category', 'acts' => ['add', 'edit', 'del']],
                ['t' => '商品品牌', 'path' => '/goods/brand', 'perm' => 'goods:brand', 'acts' => ['add', 'edit', 'del']],
                ['t' => '销售区域', 'path' => '/goods/region', 'perm' => 'goods:region', 'acts' => ['add', 'edit', 'del']],
                ['t' => '商品评价', 'path' => '/goods/review', 'perm' => 'goods:review', 'acts' => ['edit', 'reply']],
                ['t' => '库存流水', 'path' => '/goods/stocklog', 'perm' => 'goods:stocklog'],
            ]],
            ['t' => '订单', 'path' => '/order', 'icon' => 'Tickets', 'children' => [
                ['t' => '订单列表', 'path' => '/order/list', 'perm' => 'order:list', 'acts' => ['detail', 'remark', 'price', 'cancel', 'export']],
                ['t' => '发货管理', 'path' => '/order/ship', 'perm' => 'order:ship', 'acts' => ['do', 'edit', 'print']],
                ['t' => '售后订单', 'path' => '/order/refund', 'perm' => 'order:refund', 'acts' => ['apply', 'audit', 'confirm', 'pay']],
                ['t' => '支付流水', 'path' => '/order/trade', 'perm' => 'order:trade'],
                ['t' => '回调日志', 'path' => '/order/notify', 'perm' => 'order:notify', 'acts' => ['retry']],
            ]],
            ['t' => '会员', 'path' => '/member', 'icon' => 'User', 'children' => [
                ['t' => '会员列表', 'path' => '/member/list', 'perm' => 'member:list', 'acts' => ['detail', 'edit', 'status', 'balance', 'points', 'export']],
                ['t' => '会员等级', 'path' => '/member/level', 'perm' => 'member:level', 'acts' => ['add', 'edit', 'del']],
                ['t' => '积分记录', 'path' => '/member/points', 'perm' => 'member:points'],
            ]],
            ['t' => '营销', 'path' => '/marketing', 'icon' => 'Present', 'children' => [
                ['t' => '优惠券', 'path' => '/marketing/coupon', 'perm' => 'marketing:coupon', 'acts' => ['add', 'edit', 'del', 'grant']],
            ]],
            ['t' => '分销', 'path' => '/distribution', 'icon' => 'Share', 'children' => [
                ['t' => '分销员', 'path' => '/distribution/distributor', 'perm' => 'distribution:distributor', 'acts' => ['audit', 'status', 'edit']],
                ['t' => '佣金明细', 'path' => '/distribution/commission', 'perm' => 'distribution:commission'],
                ['t' => '提现管理', 'path' => '/distribution/withdraw', 'perm' => 'distribution:withdraw', 'acts' => ['audit', 'paid']],
                ['t' => '分销设置', 'path' => '/distribution/setting', 'perm' => 'distribution:setting', 'acts' => ['edit']],
            ]],
            ['t' => '内容', 'path' => '/content', 'icon' => 'Document', 'children' => [
                ['t' => '文章管理', 'path' => '/content/article', 'perm' => 'content:article', 'acts' => ['add', 'edit', 'del']],
                ['t' => '公告管理', 'path' => '/content/notice', 'perm' => 'content:notice', 'acts' => ['add', 'edit', 'del']],
                ['t' => '帮助中心', 'path' => '/content/help', 'perm' => 'content:help', 'acts' => ['add', 'edit', 'del']],
                ['t' => '轮播图与广告', 'path' => '/content/ad', 'perm' => 'content:ad', 'acts' => ['add', 'edit', 'del']],
            ]],
            ['t' => '数据', 'path' => '/data', 'icon' => 'DataLine', 'children' => [
                ['t' => '数据概览', 'path' => '/data/overview', 'perm' => 'data:overview'],
            ]],
            ['t' => '应用', 'path' => '/app', 'icon' => 'Iphone', 'children' => [
                ['t' => '小程序', 'path' => '/app/mini', 'perm' => 'app:mini', 'acts' => ['edit', 'test']],
            ]],
            ['t' => '设置', 'path' => '/setting', 'icon' => 'Setting', 'children' => [
                ['t' => '系统设置', 'path' => '/setting/system', 'children' => [
                    ['t' => '基础信息', 'path' => '/setting/base', 'perm' => 'setting:base', 'acts' => ['edit']],
                    ['t' => '语言管理', 'path' => '/setting/language', 'perm' => 'setting:language', 'acts' => ['add', 'edit', 'del']],
                    ['t' => '币种与汇率', 'path' => '/setting/currency', 'perm' => 'setting:currency', 'acts' => ['add', 'edit', 'del', 'rate']],
                    ['t' => '交易设置', 'path' => '/setting/trade', 'perm' => 'setting:trade', 'acts' => ['edit']],
                ]],
                ['t' => '消息模板', 'path' => '/setting/message', 'perm' => 'setting:message', 'acts' => ['edit', 'test']],
                ['t' => '协议管理', 'path' => '/setting/protocol', 'perm' => 'setting:protocol', 'acts' => ['edit']],
                ['t' => '小票配置', 'path' => '/setting/receipt', 'perm' => 'setting:receipt', 'acts' => ['edit']],
                ['t' => '管理权限', 'path' => '/setting/auth', 'children' => [
                    ['t' => '管理员', 'path' => '/setting/admin', 'perm' => 'setting:admin', 'acts' => ['add', 'edit', 'del', 'reset']],
                    ['t' => '角色管理', 'path' => '/setting/role', 'perm' => 'setting:role', 'acts' => ['add', 'edit', 'del']],
                    ['t' => '菜单规则', 'path' => '/setting/menu', 'perm' => 'setting:menu'],
                    ['t' => '操作日志', 'path' => '/setting/log', 'perm' => 'setting:log'],
                ]],
                ['t' => '发货设置', 'path' => '/setting/delivery', 'children' => [
                    ['t' => '运费模板', 'path' => '/setting/shipping', 'perm' => 'setting:shipping', 'acts' => ['add', 'edit', 'del']],
                ]],
                ['t' => '接口配置', 'path' => '/setting/api', 'children' => [
                    ['t' => '短信', 'path' => '/setting/api/sms', 'perm' => 'setting:api_sms', 'acts' => ['edit', 'test']],
                    ['t' => '系统存储', 'path' => '/setting/api/storage', 'perm' => 'setting:api_storage', 'acts' => ['edit', 'test']],
                    ['t' => '物流查询', 'path' => '/setting/api/express', 'perm' => 'setting:api_express', 'acts' => ['edit', 'test']],
                    ['t' => '商城支付', 'path' => '/setting/api/pay', 'perm' => 'setting:api_pay', 'acts' => ['edit', 'test']],
                    ['t' => 'AI 翻译', 'path' => '/setting/api/translate', 'perm' => 'setting:api_translate', 'acts' => ['edit', 'test']],
                ]],
            ]],
        ];
    }
}
