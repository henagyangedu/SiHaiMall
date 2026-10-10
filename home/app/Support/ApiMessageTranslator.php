<?php

namespace App\Support;

/**
 * 把返回给买家的中文提示翻译成英文（C 端接口用）。
 *
 * 业务代码里的提示统一写中文；买家的语言不是中文时，由 TranslateApiMessages 中间件在响应返回前调用这里翻译。
 * 新增买家可见的提示时，在 EXACT（固定文案）或 patterns()（带数字、单号等变量的文案）里补一条英文；
 * 没登记的提示会原样返回中文，不会报错。后台管理端的提示不翻译。
 */
final class ApiMessageTranslator
{
    /** 固定文案：中文 => 英文 */
    private const EXACT = [
        // 通用
        'ok' => 'ok',
        '保存成功' => 'Saved',
        '未登录或登录已过期' => 'Please sign in to continue',
        '资源不存在' => 'Not found',
        '服务器繁忙，请稍后再试' => 'Something went wrong. Please try again later.',
        '系统繁忙，请重试' => 'The system is busy. Please try again.',
        '操作过于频繁，请稍后再试' => 'Too many attempts. Please try again later.',
        '支付完成跳转地址不在允许范围内' => 'The return address for this payment is not allowed. Please contact support.',
        // 支付宝
        '支付宝只支持人民币订单' => 'Alipay is only available for orders in CNY',
        '支付宝暂不支持当前终端' => 'Alipay is not available on this device',
        '微信内打开的页面不能使用支付宝，请用手机浏览器打开' => 'Alipay cannot be used inside the WeChat browser. Please open this page in your phone\'s browser.',
        // 微信支付
        '微信支付只支持人民币订单' => 'WeChat Pay is only available for orders in CNY',
        '微信支付还没有配置小程序 AppID' => 'WeChat Pay is not available right now',
        '微信支付还没有配置 AppID' => 'WeChat Pay is not available right now',
        '微信支付暂不支持当前终端' => 'WeChat Pay is not available on this device',
        '发起微信支付失败，请稍后重试' => 'Could not start WeChat Pay. Please try again later.',
        '请在微信小程序里重新发起支付' => 'Please start the payment again in the WeChat mini program',
        '微信登录状态已失效，请重新发起支付' => 'Your WeChat session has expired. Please start the payment again.',
        '微信内打开的页面暂不支持直接付款，请用手机浏览器打开，或在小程序里付款' => 'Payment is not supported inside the WeChat browser. Please open this page in your phone\'s browser, or pay in the mini program.',
        // 物流
        '商家还没有开通物流查询，可以复制单号到快递公司官网查询' => 'Online tracking is not available. Please copy the tracking number and check it on the carrier\'s website.',
        '这家快递公司暂不支持在线查询，可以复制单号到快递公司官网查询' => 'Online tracking is not available for this carrier. Please copy the tracking number and check it on the carrier\'s website.',
        '这家快递公司暂不支持在线查询' => 'Online tracking is not available for this carrier',
        '物流查询服务暂时不可用，请稍后再试' => 'Tracking is temporarily unavailable. Please try again later.',
        '物流查询还没有配置完整' => 'Tracking is temporarily unavailable. Please try again later.',
        '暂时还没有物流信息，请稍后再看' => 'No tracking updates yet. Please check back later.',
        // 上传
        '不支持的图片格式' => 'Unsupported image format',
        '图片文件无效' => 'This file is not a valid image',
        '不支持的图片用途' => 'Unsupported image purpose',
        '今天上传的图片太多了，请明天再试' => 'You have uploaded too many images today. Please try again tomorrow.',
        '图片上传失败，请稍后再试' => 'We could not upload the image. Please try again later.',
        '图片无效，请重新上传' => 'This image is not valid. Please upload it again.',
        // 账号
        '账号或密码错误' => 'Incorrect account or password',
        '账号已被禁用，请联系客服' => 'This account has been disabled. Please contact support.',
        '领取成功' => 'Coupon claimed',
        '这张优惠券已停用' => 'This coupon is no longer available',
        '这张优惠券已过期' => 'This coupon has expired',
        '这张优惠券不能领取' => 'This coupon cannot be claimed',
        '不在领取时间内' => 'This coupon cannot be claimed right now',
        '已经领过了' => 'You have already claimed this coupon',
        '优惠券已经领完了' => 'This coupon has been fully claimed',
        '优惠券不存在' => 'Coupon not found',
        '这张优惠券已经用过了' => 'This coupon has already been used',
        '这张优惠券不在有效期内' => 'This coupon is not valid at this time',
        '所选的优惠券不能用于本单' => 'The selected coupon cannot be used for this order',
        '订单里没有这张券适用的商品' => 'No items in this order are eligible for this coupon',
        '这一单用不上这张券' => 'This coupon cannot be applied to this order',
        '已收藏' => 'Saved to your favourites',
        '已取消收藏' => 'Removed from your favourites',
        '评价成功' => 'Thanks for your review',
        '订单完成后才能评价' => 'You can review after the order is completed',
        '订单里没有这件商品' => 'This item is not in the order',
        '这件商品已经全部退款，不能评价' => 'This item was fully refunded and cannot be reviewed',
        '这件商品已经评价过了' => 'You have already reviewed this item',
        '评价图片最多 6 张' => 'You can attach up to 6 images',
        '签到功能未开启' => 'Daily check-in is not available',
        '今天已经签到过了' => 'You have already checked in today',
        '请输入原密码' => 'Please enter your current password',
        '已绑定邮箱，如需更换请联系客服' => 'An email is already linked. Please contact support to change it.',
        '已绑定手机号，如需更换请联系客服' => 'A phone number is already linked. Please contact support to change it.',
        '该邮箱已被其他账号使用' => 'This email is already used by another account',
        '该手机号已被其他账号使用' => 'This phone number is already used by another account',
        '目前只支持绑定中国大陆手机号' => 'Only mainland China phone numbers can be linked at the moment',
        '请在微信里重新发起支付' => 'Please start the payment again in WeChat',
        '暂未开通微信登录' => 'WeChat sign-in is not available yet',
        '微信登录凭证已失效，请重试' => 'Your WeChat sign-in has expired. Please try again.',
        '微信登录暂时不可用，请稍后再试' => 'WeChat sign-in is temporarily unavailable. Please try again later.',
        '账号已被禁用，无法下单' => 'This account has been disabled and cannot place orders.',
        '该账号已注册' => 'This account is already registered',
        '该手机号已注册，请直接登录' => 'This phone number is already registered. Please sign in.',
        '该手机号尚未注册' => 'This phone number is not registered',
        '该邮箱已注册，请直接登录' => 'This email is already registered. Please sign in.',
        '该邮箱尚未注册' => 'This email is not registered',
        '请输入短信验证码' => 'Please enter the SMS verification code',
        '请输入邮箱验证码' => 'Please enter the email verification code',
        '目前只支持中国大陆手机号注册，其他地区请使用邮箱注册' => 'Phone sign-up is only available for Mainland China numbers. Please sign up with your email.',
        '原密码不正确' => 'The current password is incorrect',
        '新密码不能与原密码相同' => 'The new password must be different from the current one',
        '不支持的语言' => 'Unsupported language',
        '不支持的币种' => 'Unsupported currency',
        '邀请码不正确' => 'Invalid invite code',
        '密码已重置，请重新登录' => 'Password reset. Please sign in again.',
        // 验证码
        '验证码已发送' => 'Verification code sent',
        '验证码已发送，请查收邮件' => 'Verification code sent. Please check your email.',
        '验证码错误或已过期' => 'The code is incorrect or has expired',
        '验证码错误次数过多，请重新获取验证码' => 'Too many incorrect attempts. Please request a new code.',
        '验证码发送过于频繁，请 1 分钟后再试' => 'Please wait a minute before requesting another code',
        '验证码发送过于频繁，请稍后再试' => 'Please wait before requesting another code',
        '验证码发送失败，请稍后再试' => 'We could not send the code. Please try again later.',
        '验证码邮件发送失败，请稍后再试' => 'We could not send the email. Please try again later.',
        '验证码校验服务暂时不可用，请稍后再试' => 'Verification is temporarily unavailable. Please try again later.',
        '该手机号今天获取验证码的次数已达上限' => 'This phone number has reached today\'s limit for verification codes',
        '该邮箱今天获取验证码的次数已达上限' => 'This email has reached today\'s limit for verification codes',
        '短信服务未开通' => 'SMS verification is not available',
        '短信验证码目前只支持中国大陆手机号' => 'SMS verification is only available for Mainland China numbers',
        '邮箱验证未开启' => 'Email verification is not available',
        '邮箱格式不正确' => 'Please enter a valid email address',
        '手机号格式不正确' => 'Please enter a valid phone number',
        '该手机号无法接收短信，请联系客服' => 'This number cannot receive SMS. Please contact support.',
        '不支持的验证码用途' => 'Unsupported verification purpose',
        // 商品、购物车
        '商品不存在或已下架' => 'This product is unavailable',
        '商品已下架或不存在' => 'This product is unavailable',
        '该商品有货，可以直接购买' => 'This item is in stock. You can order it now.',
        '到货后会通知你' => "We'll let you know when it's back in stock",
        '已取消到货提醒' => 'Back-in-stock alert removed',
        '消息不存在' => 'Message not found',
        '这件商品还没有评价，不能追评' => 'You can add a follow-up only after reviewing this item',
        '请填写追评内容' => 'Please write your follow-up review',
        '这件商品已经追评过了' => 'You have already added a follow-up review for this item',
        '追评成功' => 'Follow-up review submitted',
        // 发票
        '暂不支持申请发票' => 'Invoices are not available at the moment',
        '该币种的订单暂不支持开具发票' => 'Invoices are not available for orders in this currency',
        '订单已全额退款，不能开具发票' => 'This order has been fully refunded, so no invoice can be issued',
        '订单付款后才能申请发票' => 'You can request an invoice once the order is paid',
        '订单没有可开票的金额' => 'There is no amount left to invoice on this order',
        '请填写正确的纳税人识别号' => 'Please enter a valid tax ID',
        '发票已开出，不能修改' => 'The invoice has already been issued and can no longer be changed',
        '已经申请过发票了，请刷新后查看' => 'An invoice has already been requested. Please refresh the page.',
        '发票已开出，不能撤销' => 'The invoice has already been issued and can no longer be cancelled',
        '还没有申请发票' => 'No invoice has been requested for this order',
        '发票申请已提交' => 'Invoice request submitted',
        '已撤销发票申请' => 'Invoice request cancelled',
        // 下面两条只有后台上传发票文件时会出现，登记在这里是因为上传服务是买家和后台共用的
        '文件无效，请重新上传' => 'Invalid file. Please upload it again.',
        'PDF 文件无效' => 'Invalid PDF file',
        '已删除' => 'Deleted',
        '商品已下架，请移除' => 'This product is no longer available. Please remove it.',
        '购物车商品不存在' => 'This cart item no longer exists',
        '购物车已满，请先清理' => 'Your cart is full. Please remove some items first.',
        '请选择要结算的商品' => 'Please select items to check out',
        '内容不存在或已下线' => 'This content is unavailable',
        '协议不存在' => 'Document not found',
        // 地址
        '收货地址不存在' => 'Address not found',
        '请选择正确的省份' => 'Please select a valid province',
        '请选择正确的城市' => 'Please select a valid city',
        '请填写完整的收货信息（收件人、电话、国家、详细地址）' => 'Please complete the shipping details (recipient, phone, country, street address)',
        '部分商品不支持配送到所选地区，请更换收货地址' => 'Some items cannot be shipped to this address. Please choose another address.',
        // 下单、订单、支付
        '请选择商品' => 'Please select a product',
        '商品或数量不正确' => 'Invalid product or quantity',
        '一次最多下单 100 种商品' => 'An order can contain at most 100 different items',
        '未达到最低下单金额' => 'The order does not meet the minimum amount',
        '部分商品已下架或不存在，请刷新后重试' => 'Some items are no longer available. Please refresh and try again.',
        '商品规格不存在' => 'This product option no longer exists',
        '积分不足，请刷新后重试' => 'Not enough points. Please refresh and try again.',
        '订单正在提交，请勿重复操作' => 'Your order is being submitted. Please do not submit again.',
        '订单不存在' => 'Order not found',
        '订单当前不能取消' => 'This order cannot be cancelled now',
        '订单当前不能支付' => 'This order cannot be paid now',
        '订单已超时，请重新下单' => 'This order has expired. Please place a new one.',
        '该支付方式暂不可用或不支持当前币种' => 'This payment method is unavailable or does not support the order currency',
        '发起支付失败，请稍后重试' => 'We could not start the payment. Please try again later.',
        '支付单不存在' => 'Payment not found',
        '支付完成跳转地址不在允许范围内' => 'The return URL is not allowed',
        '会员不存在' => 'Account not found',
        // 售后
        '售后单不存在' => 'Refund request not found',
        '订单商品不存在' => 'Order item not found',
        '订单已全额退款' => 'This order has already been fully refunded',
        '订单还没有支付' => 'This order has not been paid yet',
        '订单当前状态不能申请售后' => 'A refund cannot be requested for this order right now',
        '订单已完成，不能再申请售后' => 'This order is completed and no longer eligible for a refund',
        '该商品已全部申请过售后' => 'A refund has already been requested for all of this item',
        '该商品还没有发货，请选择仅退款' => 'This item has not shipped yet. Please choose refund only.',
        '该订单不能整单退款，请按商品申请售后' => 'This order cannot be refunded as a whole. Please request a refund per item.',
        '未发货的订单只能申请仅退款' => 'Orders that have not shipped can only be refunded, not returned',
        // 分销
        '分销功能未开启' => 'The affiliate program is not available',
        '申请正在审核中，请耐心等待' => 'Your application is under review',
        '您已经是分销员' => 'You are already an affiliate',
        '分销资格已被停用，请联系客服' => 'Your affiliate account has been suspended. Please contact support.',
        '您还不是分销员' => 'You are not an affiliate yet',
        '不支持的提现方式' => 'Unsupported withdrawal method',
        '您有一笔提现正在处理中，请等待完成后再申请' => 'You already have a withdrawal in progress. Please wait until it is completed.',
        '申请已提交，请等待审核' => 'Application submitted. We will review it shortly.',
        '提现申请已提交' => 'Withdrawal requested',
        '请填写开户行' => 'Please enter the bank name',
    ];

    /** 订单、售后单的状态名（出现在“状态已变化（当前：xx）”这类提示里） */
    private const STATUS = [
        '待支付' => 'unpaid', '待发货' => 'processing', '待收货' => 'shipped', '已完成' => 'completed', '已取消' => 'cancelled',
        '售后中' => 'after-sales', '已关闭' => 'closed', '待审核' => 'under review', '待买家退货' => 'return required',
        '待确认收货' => 'awaiting receipt', '退款中' => 'refunding', '已拒绝' => 'declined', '已撤销' => 'withdrawn', '未知' => 'unknown',
    ];

    /** 表单字段名：校验提示里的中文字段名会被替换成英文 */
    private const ATTRIBUTES = [
        '收件人' => 'recipient', '电话' => 'phone', '手机号' => 'phone number', '区号' => 'country code', '国家' => 'country', '详细地址' => 'street address',
        '邮编' => 'postal code', '收货地址' => 'shipping address', '账号' => 'account', '原密码' => 'current password', '新密码' => 'new password',
        '密码' => 'password', '昵称' => 'nickname', '头像' => 'avatar', '性别' => 'gender', '生日' => 'birthday', '邮箱' => 'email',
        '图片用途' => 'image purpose', '图片' => 'image', '验证码用途' => 'verification purpose', '验证码' => 'verification code', '邀请码' => 'invite code', '微信登录凭证' => 'WeChat sign-in code', '绑定类型' => 'type', '评价商品' => 'item', '评分' => 'rating', '评价内容' => 'review', '追评内容' => 'follow-up review', '匿名' => 'anonymous',
        '购物车商品' => 'cart items', '商品规格' => 'product option', '商品' => 'items', '数量' => 'quantity', '留言' => 'note', '使用积分' => 'use points',
        '支付方式' => 'payment method', '取消原因' => 'reason', '售后商品' => 'item', '售后类型' => 'refund type', '申请原因' => 'reason',
        '凭证图片' => 'photos', '退款金额' => 'refund amount', '快递公司' => 'carrier', '快递单号' => 'tracking number',
        '真实姓名' => 'full name', '申请说明' => 'note', '提现金额' => 'amount', '提现方式' => 'method', '收款信息' => 'payee details',
        '收款人姓名' => 'payee name', '收款账号' => 'payee account', '开户行' => 'bank',
        '抬头类型' => 'invoice type', '发票抬头' => 'billing name', '纳税人识别号' => 'tax ID', '收票邮箱' => 'email', '开票资料' => 'company details',
        '注册地址' => 'registered address', '注册电话' => 'registered phone', '开户银行' => 'bank', '银行账号' => 'bank account',
    ];

    /** 需要翻译时返回英文；中文语言或没登记的提示原样返回 */
    public static function translate(string $message, string $lang): string
    {
        if ($message === '' || str_starts_with(strtolower($lang), 'zh') || !preg_match('/[\x{4e00}-\x{9fff}]/u', $message)) {
            return $message;
        }
        if (isset(self::EXACT[$message])) {
            return self::EXACT[$message];
        }
        foreach (self::patterns() as $regex => $build) {
            if (preg_match($regex, $message, $m)) {
                return $build($m);
            }
        }

        // 校验提示：句子本身已经是框架的英文，只剩字段名是中文，把字段名换掉。
        // 纯中文的未登记提示不做替换，否则会变成中英文混杂的半截话
        return preg_match('/[A-Za-z]{3,}/', $message) ? strtr($message, self::ATTRIBUTES) : $message;
    }

    /**
     * 带变量的文案：正则 => 生成英文的函数。
     * @return array<string,\Closure(array):string>
     */
    private static function patterns(): array
    {
        $status = fn (string $zh) => self::STATUS[$zh] ?? $zh;

        return [
            '/^登录失败次数过多，请 (\d+) 分钟后再试$/u' => fn ($m) => "Too many failed sign-in attempts. Please try again in {$m[1]} minutes.",
            '/^到货提醒最多 (\d+) 个，请先取消一些$/u' => fn ($m) => "You can have up to {$m[1]} back-in-stock alerts. Please remove some first.",
            '/^最多保存 (\d+) 个收货地址$/u' => fn ($m) => "You can save up to {$m[1]} addresses",
            '/^单个商品最多加入 (\d+) 件$/u' => fn ($m) => "You can add at most {$m[1]} of each item",
            '/^库存不足，当前库存 (\d+) 件$/u' => fn ($m) => "Not enough stock. Only {$m[1]} available.",
            '/^库存不足，仅剩 (\d+) 件$/u' => fn ($m) => "Not enough stock. Only {$m[1]} left.",
            '/^库存不足：(.+)，剩余 (\d+)$/u' => fn ($m) => "Not enough stock for {$m[1]}. Only {$m[2]} left.",
            '/^不支持的币种：(.+)$/u' => fn ($m) => "Unsupported currency: {$m[1]}",
            '/^优惠券只能用于(.+)订单$/u' => fn ($m) => "Coupons can only be used on {$m[1]} orders",
            '/^适用商品还差 (.+) ([A-Z]{3}) 可用$/u' => fn ($m) => "Add {$m[2]} {$m[1]} more of eligible items to use this coupon",
            '/^适用商品还差 (.+) 元可用$/u' => fn ($m) => "Add {$m[1]} more of eligible items to use this coupon",
            '/^这张优惠券不能用于本单：(.+)$/u' => fn ($m) => 'This coupon cannot be used for this order: ' . self::translate($m[1], 'en'),
            '/^商品价格异常，暂时无法下单：(.+)$/u' => fn ($m) => "This product cannot be ordered right now: {$m[1]}",
            '/^订单状态已变化（当前：(.+)），请刷新后重试$/u' => fn ($m) => 'The order status has changed (now ' . $status($m[1]) . '). Please refresh and try again.',
            '/^售后单状态已变化（当前：(.+)），请刷新后重试$/u' => fn ($m) => 'The refund status has changed (now ' . $status($m[1]) . '). Please refresh and try again.',
            '/^订单完成已超过 (\d+) 天，不能再申请售后$/u' => fn ($m) => "This order was completed more than {$m[1]} days ago and is no longer eligible for a refund",
            '/^该商品最多还能申请 (\d+) 件$/u' => fn ($m) => "You can request a refund for at most {$m[1]} more of this item",
            '/^退款金额必须大于 0 且不超过 (\S+) (\S+)$/u' => fn ($m) => "The refund amount must be greater than 0 and no more than {$m[1]} {$m[2]}",
            '/^提现金额不能低于 (\S+)$/u' => fn ($m) => "The minimum withdrawal amount is {$m[1]}",
            '/^可提现佣金不足（当前 (\S+)）$/u' => fn ($m) => "Not enough available commission (currently {$m[1]})",
        ];
    }
}
