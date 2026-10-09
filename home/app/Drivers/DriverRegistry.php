<?php

namespace App\Drivers;

use App\Support\Exceptions\BusinessException;

/**
 * 第三方接口驱动注册表：每种类型（storage / sms / pay / express / waybill / app）有哪些驱动、各需要哪些配置项。
 * 后台“接口配置”页面按这里的 fields 自动生成表单。新增驱动只需在这里登记 + 写实现类。
 * 字段 type：text 文本 / secret 密钥（加密存储，页面脱敏） / switch 开关
 */
class DriverRegistry
{
    public static function types(): array
    {
        return [
            'storage' => [
                'label'   => '系统存储',
                'drivers' => [
                    'local' => [
                        'name'   => '本地存储',
                        'desc'   => '文件保存在服务器 public/uploads 目录，适合开发和小流量。访问地址依赖 .env 的 APP_URL，务必改成真实站点地址。',
                        'fields' => [],
                    ],
                    'aliyun' => [
                        'name'   => '阿里云 OSS',
                        'desc'   => '通过 S3 兼容协议接入阿里云对象存储。',
                        'fields' => self::s3Fields(
                            'S3 兼容端点，示例：https://s3.oss-cn-hangzhou.aliyuncs.com（具体地址以阿里云官方文档为准）',
                            'cn-hangzhou', '', '存储桶名称', false
                        ),
                    ],
                    'tencent' => [
                        'name'   => '腾讯云 COS',
                        'desc'   => '通过 S3 兼容协议接入腾讯云对象存储。',
                        'fields' => self::s3Fields(
                            'S3 兼容端点，示例：https://cos.ap-guangzhou.myqcloud.com（具体地址以腾讯云官方文档为准）',
                            'ap-guangzhou', '', '存储桶名称，格式：名称-APPID，如 mall-1250000000', false
                        ),
                    ],
                    'gcs' => [
                        'name'   => 'Google Cloud Storage',
                        'desc'   => '通过 GCS 的 S3 互操作模式接入，需要在 Google Cloud 控制台创建 HMAC 密钥。',
                        'fields' => self::s3Fields(
                            '默认 https://storage.googleapis.com',
                            'auto', 'https://storage.googleapis.com', '存储桶名称', false
                        ),
                    ],
                    's3' => [
                        'name'   => 'S3 兼容存储',
                        'desc'   => '适用于 AWS S3、Cloudflare R2、MinIO 等任何兼容 S3 协议的服务。',
                        'fields' => self::s3Fields(
                            '服务端点，如 https://s3.amazonaws.com 或 https://<账号ID>.r2.cloudflarestorage.com',
                            'auto', '', '存储桶名称', false
                        ),
                    ],
                ],
            ],
            'sms' => [
                'label'   => '短信',
                'drivers' => [
                    'gmsms' => [
                        'name'   => 'GM 短信平台',
                        'desc'   => '用于注册、短信登录、找回密码的验证码。验证码由平台生成并校验，发送频次和有效期由平台控制；目前只支持中国大陆手机号。场景标识以平台分配的为准，服务器出口 IP 如需加白名单请联系平台。',
                        'fields' => [
                            'base_url'       => ['label' => '接口地址', 'type' => 'text', 'required' => true, 'default' => 'https://sms.gmwang.com.cn/api/v1', 'hint' => '一般不用改'],
                            'app_key'        => ['label' => 'AppKey', 'type' => 'text', 'required' => true, 'default' => '', 'hint' => '平台分配的项目 AppKey；联调请用测试项目的凭证'],
                            'app_secret'     => ['label' => 'AppSecret', 'type' => 'secret', 'required' => true, 'default' => '', 'hint' => '加密保存，页面不再显示；不修改请保持原样'],
                            'scene_register' => ['label' => '注册场景标识', 'type' => 'text', 'required' => true, 'default' => 'register', 'hint' => '平台为“注册验证码”分配的 scene'],
                            'scene_login'    => ['label' => '登录场景标识', 'type' => 'text', 'required' => true, 'default' => 'login', 'hint' => '平台为“短信登录”分配的 scene'],
                            'scene_reset'    => ['label' => '找回密码场景标识', 'type' => 'text', 'required' => true, 'default' => 'reset_password', 'hint' => '平台为“找回密码”分配的 scene'],
                            'timeout'        => ['label' => '超时（秒）', 'type' => 'text', 'required' => false, 'default' => '15', 'hint' => ''],
                            'proxy'          => ['label' => '代理', 'type' => 'text', 'required' => false, 'default' => '', 'hint' => '可选。服务器在境外、直连平台不通时填写，如 http://127.0.0.1:7890'],
                        ],
                    ],
                ],
            ],
            'express' => [
                'label'   => '物流查询',
                'drivers' => [
                    'kuaidi100' => [
                        'name'   => '快递100',
                        'desc'   => '快递100 企业版“实时查询”接口，按次计费。支持顺丰、中通、圆通、申通、韵达、极兔、EMS、DHL、FedEx、UPS、USPS。商城会把查询结果缓存 30 分钟，已签收的包裹不再查询。',
                        'fields' => [
                            'customer' => ['label' => 'customer（授权码）', 'type' => 'text', 'required' => true, 'default' => '', 'hint' => '快递100 企业管理后台 → 我的信息 里的 customer'],
                            'key'      => ['label' => '授权 key', 'type' => 'secret', 'required' => true, 'default' => '', 'hint' => '加密保存，页面不再显示；不修改请保持原样'],
                            'base_url' => ['label' => '接口地址', 'type' => 'text', 'required' => false, 'default' => 'https://poll.kuaidi100.com', 'hint' => '一般不用改'],
                            'timeout'  => ['label' => '超时（秒）', 'type' => 'text', 'required' => false, 'default' => '10', 'hint' => ''],
                            'proxy'    => ['label' => '代理', 'type' => 'text', 'required' => false, 'default' => '', 'hint' => '可选。服务器在境外、直连快递100 不通时填写，如 http://127.0.0.1:7890'],
                        ],
                    ],
                ],
            ],
            'pay' => [
                'label'   => '商城支付',
                'drivers' => [
                    'stripe' => [
                        'name'   => 'Stripe（境外银行卡 / 钱包）',
                        'desc'   => '跳转 Stripe 托管收银台，支持境外信用卡与多种本地支付方式。Webhook 地址：' . self::notifyUrl('stripe') . '（在 Stripe 后台添加该地址，事件勾选 checkout.session.completed、checkout.session.async_payment_succeeded、checkout.session.async_payment_failed、checkout.session.expired，并把生成的签名密钥填到下方）。',
                        'fields' => [
                            'secret_key'     => ['label' => 'Secret Key', 'type' => 'secret', 'required' => true, 'default' => '', 'hint' => '以 sk_test_ / sk_live_ 开头；测试环境用 test 密钥。加密保存，页面不再显示'],
                            'webhook_secret' => ['label' => 'Webhook 签名密钥', 'type' => 'secret', 'required' => true, 'default' => '', 'hint' => '以 whsec_ 开头，在 Stripe 后台添加 Webhook 后获得'],
                            'currencies'     => ['label' => '限定币种', 'type' => 'text', 'required' => false, 'default' => '', 'hint' => '可选，逗号分隔，如 USD,EUR,GBP；留空表示不限制（以 Stripe 账户实际支持为准）'],
                            'api_base'       => ['label' => '接口地址', 'type' => 'text', 'required' => false, 'default' => 'https://api.stripe.com', 'hint' => '一般不用改'],
                            'timeout'        => ['label' => '超时（秒）', 'type' => 'text', 'required' => false, 'default' => '30', 'hint' => ''],
                            'proxy'          => ['label' => '代理', 'type' => 'text', 'required' => false, 'default' => '', 'hint' => '可选。测试服务器在中国大陆时通常需要代理才能访问 Stripe；是否合规请以服务商条款和当地法规为准'],
                        ],
                    ],
                    'alipay' => [
                        'name'   => '支付宝',
                        'desc'   => '支付宝开放平台（公钥模式）。手机浏览器里用“手机网站支付”，电脑浏览器里用“电脑网站支付”，都要在应用里签约对应产品。只支持人民币订单，小程序里不能使用。异步通知地址：站点域名/notify/pay/alipay，必须是公网可访问的地址。',
                        'fields' => [
                            'app_id'            => ['label' => '应用 APPID', 'type' => 'text', 'required' => true, 'default' => '', 'hint' => '开放平台控制台里应用的 APPID'],
                            'private_key'       => ['label' => '应用私钥', 'type' => 'secret_text', 'required' => true, 'default' => '', 'hint' => '用支付宝密钥工具生成的“应用私钥”全文（一长串字符即可，不需要头尾两行）。加密保存，页面不再显示'],
                            'alipay_public_key' => ['label' => '支付宝公钥', 'type' => 'textarea', 'required' => true, 'default' => '', 'hint' => '在应用的“接口加签方式”里上传应用公钥后，页面显示的“支付宝公钥”（不是应用公钥），用于验证通知确实来自支付宝'],
                            'gateway'           => ['label' => '网关地址', 'type' => 'text', 'required' => false, 'default' => 'https://openapi.alipay.com/gateway.do', 'hint' => '正式环境不用改；沙箱环境填 https://openapi-sandbox.dl.alipaydev.com/gateway.do'],
                            'timeout'           => ['label' => '超时（秒）', 'type' => 'text', 'required' => false, 'default' => '30', 'hint' => ''],
                            'proxy'             => ['label' => '代理', 'type' => 'text', 'required' => false, 'default' => '', 'hint' => '可选。服务器在境外、直连支付宝不稳定时填写'],
                        ],
                    ],
                    'wechat' => [
                        'name'   => '微信支付',
                        'desc'   => '微信支付 API v3（直连商户）。小程序里调起支付，手机浏览器里跳转微信付款（H5 支付需在商户平台开通并登记域名），在微信里打开的网页上直接调起付款（需填公众号 AppID 和 AppSecret），电脑上显示二维码扫码付款（需开通 Native 支付）。只支持人民币订单。回调地址：站点域名/notify/pay/wechat，必须是公网可访问的 HTTPS 地址。',
                        'fields' => [
                            'mch_id'        => ['label' => '商户号', 'type' => 'text', 'required' => true, 'default' => '', 'hint' => '微信支付商户平台的商户号（mchid）'],
                            'api_v3_key'    => ['label' => 'APIv3 密钥', 'type' => 'secret', 'required' => true, 'default' => '', 'hint' => '商户平台 → API 安全 里设置的 32 位密钥，用于解密回调。加密保存，页面不再显示'],
                            'serial_no'     => ['label' => '商户证书序列号', 'type' => 'text', 'required' => true, 'default' => '', 'hint' => '商户 API 证书的序列号（商户平台 → API 安全 → 管理证书）'],
                            'private_key'   => ['label' => '商户私钥', 'type' => 'secret_text', 'required' => true, 'default' => '', 'hint' => 'apiclient_key.pem 的完整内容（含 BEGIN / END 两行）。加密保存，页面不再显示'],
                            'public_key_id' => ['label' => '微信支付公钥 ID', 'type' => 'text', 'required' => true, 'default' => '', 'hint' => '以 PUB_KEY_ID_ 开头（商户平台 → API 安全 → 微信支付公钥）'],
                            'public_key'    => ['label' => '微信支付公钥', 'type' => 'textarea', 'required' => true, 'default' => '', 'hint' => '下载的 pub_key.pem 的完整内容，用于验证回调确实来自微信'],
                            'mp_app_id'     => ['label' => '小程序 AppID', 'type' => 'text', 'required' => false, 'default' => '', 'hint' => '要在小程序里收款就必须填，且该小程序已在商户平台关联'],
                            'mp_app_secret' => ['label' => '小程序 AppSecret', 'type' => 'secret', 'required' => false, 'default' => '', 'hint' => '付款时用它把小程序登录凭证换成 openid。加密保存，页面不再显示'],
                            'oa_app_id'     => ['label' => '公众号 AppID', 'type' => 'text', 'required' => false, 'default' => '', 'hint' => '要在微信里打开的网页（H5）上收款就必须填。要求：已认证的服务号，已在商户平台关联；公众号后台把 H5 的域名设为“网页授权域名”；商户平台把 H5 的地址设为“JSAPI 支付授权目录”'],
                            'oa_app_secret' => ['label' => '公众号 AppSecret', 'type' => 'secret', 'required' => false, 'default' => '', 'hint' => '付款时用它确认付款人的微信身份（openid）。加密保存，页面不再显示'],
                            'h5_app_id'     => ['label' => 'H5 / 扫码支付 AppID', 'type' => 'text', 'required' => false, 'default' => '', 'hint' => '可选。H5 支付和电脑扫码支付使用的公众号或小程序 AppID；不填则用上面的小程序 AppID'],
                            'api_base'      => ['label' => '接口地址', 'type' => 'text', 'required' => false, 'default' => 'https://api.mch.weixin.qq.com', 'hint' => '一般不用改'],
                            'timeout'       => ['label' => '超时（秒）', 'type' => 'text', 'required' => false, 'default' => '30', 'hint' => ''],
                            'proxy'         => ['label' => '代理', 'type' => 'text', 'required' => false, 'default' => '', 'hint' => '可选。服务器在境外、直连微信支付不稳定时填写'],
                        ],
                    ],
                    'paypal' => [
                        'name'   => 'PayPal',
                        'desc'   => '跳转 PayPal 授权后由商城收款。Webhook 地址：' . self::notifyUrl('paypal') . '（在 PayPal 开发者后台的应用里添加，事件勾选 Checkout order approved、Payment capture completed / denied / refunded，并把 Webhook ID 填到下方）。',
                        'fields' => [
                            'mode'          => ['label' => '环境', 'type' => 'text', 'required' => true, 'default' => 'sandbox', 'hint' => '填 sandbox（沙箱测试）或 live（正式）'],
                            'client_id'     => ['label' => 'Client ID', 'type' => 'text', 'required' => true, 'default' => '', 'hint' => ''],
                            'client_secret' => ['label' => 'Client Secret', 'type' => 'secret', 'required' => true, 'default' => '', 'hint' => '加密保存，页面不再显示'],
                            'webhook_id'    => ['label' => 'Webhook ID', 'type' => 'text', 'required' => true, 'default' => '', 'hint' => '在 PayPal 开发者后台添加 Webhook 后获得'],
                            'currencies'    => ['label' => '限定币种', 'type' => 'text', 'required' => false, 'default' => '', 'hint' => '可选，逗号分隔，如 USD,EUR；留空则按 PayPal 支持的币种自动判断'],
                            'timeout'       => ['label' => '超时（秒）', 'type' => 'text', 'required' => false, 'default' => '30', 'hint' => ''],
                            'proxy'         => ['label' => '代理', 'type' => 'text', 'required' => false, 'default' => '', 'hint' => '可选。测试服务器在中国大陆时通常需要代理才能访问 PayPal；是否合规请以服务商条款和当地法规为准'],
                        ],
                    ],
                ],
            ],
            'app' => [
                'label'   => '应用',
                'drivers' => [
                    'wechat_mini' => [
                        'name'   => '微信小程序',
                        'desc'   => '启用后，买家在小程序里可以用微信一键登录（第一次登录自动注册会员）。AppID 和 AppSecret 在微信公众平台 → 小程序后台 → 开发管理 里查看。这里的配置只用于登录；小程序里收款用的 AppID / AppSecret 在“商城支付 → 微信支付”里另外填写。',
                        'fields' => [
                            'app_id'     => ['label' => '小程序 AppID', 'type' => 'text', 'required' => true, 'default' => '', 'hint' => '以 wx 开头的 18 位字符'],
                            'app_secret' => ['label' => '小程序 AppSecret', 'type' => 'secret', 'required' => true, 'default' => '', 'hint' => '加密保存，页面不再显示；不修改请保持原样'],
                            'api_base'   => ['label' => '接口地址', 'type' => 'text', 'required' => false, 'default' => 'https://api.weixin.qq.com', 'hint' => '一般不用改'],
                            'timeout'    => ['label' => '超时（秒）', 'type' => 'text', 'required' => false, 'default' => '10', 'hint' => ''],
                            'proxy'      => ['label' => '代理', 'type' => 'text', 'required' => false, 'default' => '', 'hint' => '可选。服务器在境外、直连微信接口不通时填写，如 http://127.0.0.1:7890'],
                        ],
                    ],
                ],
            ],
            'translate' => [
                'label'   => 'AI 翻译',
                'drivers' => [
                    'openai' => [
                        'name'   => 'OpenAI（GPT）',
                        'desc'   => '调用 OpenAI 的对话接口翻译商品文案。模型名称在下方自行填写，不在代码里写死，换模型只改配置。',
                        'fields' => self::llmFields('https://api.openai.com/v1', '接口地址，OpenAI 官方为 https://api.openai.com/v1；若通过中转服务访问，改成中转地址', '模型名称，以 OpenAI 官方文档当前提供的为准'),
                    ],
                    'deepseek' => [
                        'name'   => 'DeepSeek',
                        'desc'   => '调用 DeepSeek 的 OpenAI 兼容接口翻译商品文案。',
                        'fields' => self::llmFields('https://api.deepseek.com', '接口地址，DeepSeek 官方为 https://api.deepseek.com', '模型名称，以 DeepSeek 官方文档当前提供的为准'),
                    ],
                    'claude' => [
                        'name'   => 'Claude（Anthropic）',
                        'desc'   => '调用 Anthropic 的 Messages 接口翻译。Claude 没有“强制输出 JSON”的开关，格式由提示词约束，不合格时系统会自动重试一次。',
                        'fields' => self::llmFields('https://api.anthropic.com', '接口地址，Anthropic 官方为 https://api.anthropic.com（不要带 /v1）；若通过中转服务访问，改成中转地址', '模型名称，以 Anthropic 官方文档当前提供的为准，如 claude-sonnet-5-5') + [
                            'max_tokens' => ['label' => '最大输出长度', 'type' => 'text', 'required' => false, 'default' => '8192', 'hint' => '单次回复最多多少 token。翻译长篇详情被截断时调大，上限以所选模型为准'],
                        ],
                    ],
                    'qwen' => [
                        'name'   => '通义千问（Qwen）',
                        'desc'   => '调用阿里云百炼的 OpenAI 兼容接口翻译。',
                        'fields' => self::llmFields('https://dashscope.aliyuncs.com/compatible-mode/v1', '接口地址。中国内地为 https://dashscope.aliyuncs.com/compatible-mode/v1，国际站为 https://dashscope-intl.aliyuncs.com/compatible-mode/v1（两边的 API Key 不通用）', '模型名称，以阿里云百炼文档当前提供的为准，如 qwen-plus'),
                    ],
                ],
            ],
        ];
    }

    public static function drivers(string $type): array
    {
        return self::types()[$type]['drivers'] ?? throw new BusinessException('未知的接口类型');
    }

    public static function driver(string $type, string $driver): array
    {
        return self::drivers($type)[$driver] ?? throw new BusinessException('未知的驱动：' . $driver);
    }

    /** 渠道回调地址（需公网可访问的 https 域名；本地开发请用内网穿透） */
    private static function notifyUrl(string $channel): string
    {
        return rtrim((string) config('app.url'), '/') . '/notify/pay/' . $channel;
    }

    private static function llmFields(string $baseUrl, string $baseHint, string $modelHint): array
    {
        return [
            'base_url'    => ['label' => '接口地址', 'type' => 'text', 'required' => true, 'default' => $baseUrl, 'hint' => $baseHint],
            'api_key'     => ['label' => 'API Key', 'type' => 'secret', 'required' => true, 'default' => '', 'hint' => '加密保存，页面不再显示；不修改请保持原样'],
            'model'       => ['label' => '模型名称', 'type' => 'text', 'required' => true, 'default' => '', 'hint' => $modelHint],
            'temperature' => ['label' => '温度', 'type' => 'text', 'required' => false, 'default' => '', 'hint' => '可选。建议 0 到 0.3 让译文稳定；部分新模型不支持自定义温度，留空即可'],
            'timeout'     => ['label' => '超时（秒）', 'type' => 'text', 'required' => false, 'default' => '60', 'hint' => '长篇详情翻译较慢，建议 60 到 120'],
            'proxy'       => ['label' => '代理', 'type' => 'text', 'required' => false, 'default' => '', 'hint' => '可选，如 http://127.0.0.1:7890。是否需要代理、是否合规请以服务商条款和当地法规为准'],
        ];
    }

    private static function s3Fields(string $endpointHint, string $region, string $endpoint, string $bucketHint, bool $pathStyle): array
    {
        return [
            'endpoint'   => ['label' => '服务端点', 'type' => 'text', 'required' => true, 'default' => $endpoint, 'hint' => $endpointHint],
            'region'     => ['label' => '地域', 'type' => 'text', 'required' => true, 'default' => $region, 'hint' => '地域代码，以服务商文档为准'],
            'bucket'     => ['label' => '存储桶', 'type' => 'text', 'required' => true, 'default' => '', 'hint' => $bucketHint],
            'access_key' => ['label' => 'AccessKey', 'type' => 'text', 'required' => true, 'default' => '', 'hint' => ''],
            'secret_key' => ['label' => 'SecretKey', 'type' => 'secret', 'required' => true, 'default' => '', 'hint' => '加密保存，页面不再显示；不修改请保持原样'],
            'path_style' => ['label' => '路径风格访问', 'type' => 'switch', 'required' => false, 'default' => $pathStyle ? 1 : 0, 'hint' => '多数云厂商保持关闭；自建 MinIO 通常需要开启'],
            'domain'     => ['label' => '访问域名', 'type' => 'text', 'required' => false, 'default' => '', 'hint' => '对外访问或 CDN 域名（含 https://）；不填则按存储桶地址生成，此时存储桶需允许公开读取'],
        ];
    }
}
