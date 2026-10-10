<?php

namespace App\Support;

/**
 * 消息通知的场景定义：每个场景有哪些变量、内置的默认模板（中文 / 英文）。
 * 后台“消息模板”里没改过的场景使用这里的默认模板；模板里的变量写成 {变量名}。
 * 新增场景：在这里登记，再在对应业务节点调用 NotifyService::send。
 */
final class MessageScenes
{
    /** 所有场景都可用的公共变量 */
    public const COMMON_VARS = ['site_name' => '站点名称', 'nickname' => '会员昵称'];

    public const ALL = [
        'order_paid' => [
            'label' => '订单支付成功',
            'vars' => ['order_no' => '订单号', 'pay_amount' => '实付金额', 'currency' => '币种'],
            'title' => ['zh' => '【{site_name}】订单 {order_no} 支付成功', 'en' => '[{site_name}] Payment received for order {order_no}'],
            'content' => [
                'zh' => "{nickname}，您好：\n\n您的订单 {order_no} 已支付成功，实付 {pay_amount} {currency}。我们会尽快为您发货。\n\n感谢您的购买！",
                'en' => "Hi {nickname},\n\nWe have received your payment of {pay_amount} {currency} for order {order_no}. We will ship it as soon as possible.\n\nThank you for your purchase!",
            ],
        ],
        'order_shipped' => [
            'label' => '订单已发货',
            'vars' => ['order_no' => '订单号', 'express_name' => '快递公司', 'express_no' => '快递单号'],
            'title' => ['zh' => '【{site_name}】订单 {order_no} 已发货', 'en' => '[{site_name}] Your order {order_no} has shipped'],
            'content' => [
                'zh' => "{nickname}，您好：\n\n您的订单 {order_no} 已发货。\n快递公司：{express_name}\n快递单号：{express_no}\n\n请留意查收。",
                'en' => "Hi {nickname},\n\nYour order {order_no} is on its way.\nCarrier: {express_name}\nTracking number: {express_no}",
            ],
        ],
        'refund_done' => [
            'label' => '退款完成',
            'vars' => ['refund_no' => '售后单号', 'order_no' => '订单号', 'refund_amount' => '退款金额', 'currency' => '币种'],
            'title' => ['zh' => '【{site_name}】订单 {order_no} 退款已完成', 'en' => '[{site_name}] Refund issued for order {order_no}'],
            'content' => [
                'zh' => "{nickname}，您好：\n\n您的售后申请 {refund_no} 已处理完成，退款 {refund_amount} {currency}。到账时间以支付渠道为准，通常需要 1 到 7 个工作日。",
                'en' => "Hi {nickname},\n\nYour refund request {refund_no} has been completed. We have refunded {refund_amount} {currency}. It may take 1-7 business days to appear in your account.",
            ],
        ],
        'refund_rejected' => [
            'label' => '售后申请被拒绝',
            'vars' => ['refund_no' => '售后单号', 'order_no' => '订单号', 'reject_reason' => '拒绝原因'],
            'title' => ['zh' => '【{site_name}】售后申请 {refund_no} 未通过', 'en' => '[{site_name}] Your refund request {refund_no} was declined'],
            'content' => [
                'zh' => "{nickname}，您好：\n\n很抱歉，您对订单 {order_no} 的售后申请 {refund_no} 未通过。\n原因：{reject_reason}\n\n如有疑问请联系客服。",
                'en' => "Hi {nickname},\n\nWe are sorry, your refund request {refund_no} for order {order_no} was declined.\nReason: {reject_reason}\n\nPlease contact us if you have any questions.",
            ],
        ],
        'goods_restock' => [
            'label' => '到货提醒',
            'vars' => ['goods_title' => '商品名称', 'spec' => '规格'],
            'title' => ['zh' => '【{site_name}】您关注的商品到货了', 'en' => '[{site_name}] An item you were waiting for is back in stock'],
            'content' => [
                'zh' => "{nickname}，您好：\n\n您订阅了到货提醒的商品已经到货：\n{goods_title}（规格：{spec}）\n\n数量有限，请尽快登录商城购买。本次提醒只发送一次。",
                'en' => "Hi {nickname},\n\nAn item you asked us to watch is back in stock:\n{goods_title} (option: {spec})\n\nStock is limited, so sign in soon to order. This reminder is only sent once.",
            ],
        ],
        'invoice_issued' => [
            'label' => '发票已开具',
            'vars' => ['order_no' => '订单号', 'title' => '发票抬头', 'invoice_no' => '发票号码', 'amount' => '开票金额'],
            'title' => ['zh' => '【{site_name}】订单 {order_no} 的发票已开具', 'en' => '[{site_name}] Your invoice for order {order_no} is ready'],
            'content' => [
                'zh' => "{nickname}，您好：\n\n您为订单 {order_no} 申请的发票已开具。\n发票抬头：{title}\n发票号码：{invoice_no}\n开票金额：{amount}\n\n请到订单详情里查看和下载。",
                'en' => "Hi {nickname},\n\nThe invoice you requested for order {order_no} has been issued.\nBilled to: {title}\nInvoice number: {invoice_no}\nAmount: {amount}\n\nYou can view and download it from the order page.",
            ],
        ],
        'invoice_rejected' => [
            'label' => '发票申请被驳回',
            'vars' => ['order_no' => '订单号', 'reject_reason' => '驳回原因'],
            'title' => ['zh' => '【{site_name}】订单 {order_no} 的发票申请未通过', 'en' => '[{site_name}] Your invoice request for order {order_no} needs changes'],
            'content' => [
                'zh' => "{nickname}，您好：\n\n您为订单 {order_no} 提交的发票申请未通过。\n原因：{reject_reason}\n\n请到订单详情里修改后重新提交。",
                'en' => "Hi {nickname},\n\nWe could not process the invoice request for order {order_no}.\nReason: {reject_reason}\n\nPlease update the details on the order page and submit again.",
            ],
        ],
        'withdraw_paid' => [
            'label' => '分销提现已打款',
            'vars' => ['withdraw_no' => '提现单号', 'amount' => '提现金额'],
            'title' => ['zh' => '【{site_name}】提现 {withdraw_no} 已打款', 'en' => '[{site_name}] Your withdrawal {withdraw_no} has been paid'],
            'content' => [
                'zh' => "{nickname}，您好：\n\n您的提现申请 {withdraw_no} 已打款，金额 {amount}。请留意查收。",
                'en' => "Hi {nickname},\n\nYour withdrawal {withdraw_no} of {amount} has been paid out. Please check your account.",
            ],
        ],
    ];

    /**
     * 站内信点了之后跳去哪：场景 => [跳转类型, 取哪个变量的值]。
     * 买家端按类型打开订单详情（order）、售后详情（refund）、商品详情（goods）、分销中心（withdraw，不需要值）。
     */
    public const LINKS = [
        'order_paid' => ['order', 'order_no'],
        'order_shipped' => ['order', 'order_no'],
        'refund_done' => ['refund', 'refund_no'],
        'refund_rejected' => ['refund', 'refund_no'],
        'goods_restock' => ['goods', 'goods_id'],
        'withdraw_paid' => ['withdraw', ''],
        'invoice_issued' => ['order', 'order_no'],
        'invoice_rejected' => ['order', 'order_no'],
    ];

    /** @return array{0:string,1:string} [跳转类型, 值]；场景没有登记跳转时是两个空串 */
    public static function link(string $scene, array $vars): array
    {
        [$type, $key] = self::LINKS[$scene] ?? ['', ''];

        return [$type, $key === '' ? '' : (string) ($vars[$key] ?? '')];
    }

    /** 内置默认模板：中文语言用中文，其他语言一律用英文 */
    public static function default(string $scene, string $field, string $lang): string
    {
        return self::ALL[$scene][$field][str_starts_with(strtolower($lang), 'zh') ? 'zh' : 'en'] ?? '';
    }

    /** 把模板里的 {变量名} 换成实际值；模板里出现了没提供的变量时原样保留，方便发现写错的变量名 */
    public static function render(string $template, array $vars): string
    {
        return preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0], $template);
    }
}
