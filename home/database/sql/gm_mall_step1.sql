-- =====================================================================
-- 多语言分销商城 · 第一步建表（基础 + 商品 + 会员 + 订单支付 + 接口配置）
-- 约定：前缀 gm_ ；InnoDB ；utf8mb4 ；金额 decimal(12,2) ；汇率 decimal(18,8)
-- 软删除用 deleted_at（NULL=未删除）；语言编码 lang 如 zh-CN / en
-- 分销表放第二步，这里只在 gm_member / gm_order 预留字段
-- =====================================================================
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 一、系统与权限
-- ---------------------------------------------------------------------
CREATE TABLE `gm_admin` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL COMMENT '登录账号',
  `password` varchar(255) NOT NULL COMMENT '密码哈希',
  `nickname` varchar(50) NOT NULL DEFAULT '',
  `avatar` varchar(255) NOT NULL DEFAULT '',
  `phone` varchar(30) NOT NULL DEFAULT '',
  `email` varchar(100) NOT NULL DEFAULT '',
  `role_id` int unsigned NOT NULL DEFAULT 0 COMMENT '角色ID，0=超级管理员',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '1启用 0禁用',
  `last_login_at` datetime DEFAULT NULL,
  `last_login_ip` varchar(45) NOT NULL DEFAULT '',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员';

CREATE TABLE `gm_admin_role` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL COMMENT '角色名称',
  `remark` varchar(255) NOT NULL DEFAULT '',
  `status` tinyint NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理角色';

CREATE TABLE `gm_admin_menu` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `pid` int unsigned NOT NULL DEFAULT 0,
  `type` tinyint NOT NULL DEFAULT 1 COMMENT '1目录 2菜单 3按钮/权限点',
  `title_key` varchar(100) NOT NULL COMMENT '菜单名称（中文或i18n key）',
  `icon` varchar(60) NOT NULL DEFAULT '',
  `path` varchar(150) NOT NULL DEFAULT '' COMMENT '前端路由',
  `component` varchar(150) NOT NULL DEFAULT '',
  `perm` varchar(100) NOT NULL DEFAULT '' COMMENT '权限标识 如 goods:list:view',
  `api_rule` varchar(255) NOT NULL DEFAULT '' COMMENT '关联后端接口规则，多个用逗号分隔',
  `sort` int NOT NULL DEFAULT 0,
  `visible` tinyint NOT NULL DEFAULT 1,
  `status` tinyint NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pid` (`pid`),
  KEY `idx_perm` (`perm`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='后台菜单与权限点';

CREATE TABLE `gm_admin_role_menu` (
  `role_id` int unsigned NOT NULL,
  `menu_id` int unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`menu_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='角色菜单关联';

CREATE TABLE `gm_admin_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` int unsigned NOT NULL DEFAULT 0,
  `module` varchar(50) NOT NULL DEFAULT '',
  `action` varchar(100) NOT NULL DEFAULT '',
  `method` varchar(10) NOT NULL DEFAULT '',
  `url` varchar(255) NOT NULL DEFAULT '',
  `params` text COMMENT '请求参数（敏感字段需脱敏）',
  `ip` varchar(45) NOT NULL DEFAULT '',
  `user_agent` varchar(255) NOT NULL DEFAULT '',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '1成功 0失败',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_admin` (`admin_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='后台操作日志';

CREATE TABLE `gm_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `group` varchar(50) NOT NULL COMMENT '分组 base/trade/distribution/receipt/protocol...',
  `key` varchar(100) NOT NULL,
  `value` text,
  `is_lang` tinyint NOT NULL DEFAULT 0 COMMENT '1=value为按语言的JSON {"zh-CN":"..","en":".."}',
  `remark` varchar(255) NOT NULL DEFAULT '',
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_group_key` (`group`,`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统设置（键值）';

CREATE TABLE `gm_language` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(10) NOT NULL COMMENT 'zh-CN / en / ja ...',
  `name` varchar(50) NOT NULL COMMENT '显示名，如 简体中文',
  `is_default` tinyint NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `sort` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='语言列表';

CREATE TABLE `gm_currency` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(10) NOT NULL COMMENT 'CNY / USD / EUR',
  `name` varchar(50) NOT NULL,
  `symbol` varchar(10) NOT NULL DEFAULT '',
  `rate` decimal(18,8) NOT NULL DEFAULT 1 COMMENT '1 基准币 = rate 本币种',
  `decimals` tinyint NOT NULL DEFAULT 2 COMMENT '小数位（JPY为0）',
  `is_base` tinyint NOT NULL DEFAULT 0 COMMENT '基准币种，商品价格以此存储',
  `rate_mode` tinyint NOT NULL DEFAULT 1 COMMENT '1手动 2自动更新',
  `status` tinyint NOT NULL DEFAULT 1,
  `sort` int NOT NULL DEFAULT 0,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='币种与汇率';

INSERT INTO `gm_language` (`code`,`name`,`is_default`,`status`,`sort`) VALUES
('zh-CN','简体中文',1,1,1),('en','English',0,1,2);
INSERT INTO `gm_currency` (`code`,`name`,`symbol`,`rate`,`decimals`,`is_base`,`status`,`sort`) VALUES
('CNY','人民币','¥',1,2,1,1,1),('USD','美元','$',0.14000000,2,0,1,2);

-- ---------------------------------------------------------------------
-- 二、商品（主表 + 翻译表）
-- ---------------------------------------------------------------------
CREATE TABLE `gm_category` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `pid` int unsigned NOT NULL DEFAULT 0,
  `image` varchar(255) NOT NULL DEFAULT '',
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pid` (`pid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品分类';

CREATE TABLE `gm_category_lang` (
  `category_id` int unsigned NOT NULL,
  `lang` varchar(10) NOT NULL,
  `name` varchar(100) NOT NULL,
  PRIMARY KEY (`category_id`,`lang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品分类-翻译';

CREATE TABLE `gm_brand` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `logo` varchar(255) NOT NULL DEFAULT '',
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='品牌';

CREATE TABLE `gm_brand_lang` (
  `brand_id` int unsigned NOT NULL,
  `lang` varchar(10) NOT NULL,
  `name` varchar(100) NOT NULL,
  PRIMARY KEY (`brand_id`,`lang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='品牌-翻译';

CREATE TABLE `gm_goods` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `category_id` int unsigned NOT NULL DEFAULT 0,
  `brand_id` int unsigned NOT NULL DEFAULT 0,
  `goods_sn` varchar(60) NOT NULL DEFAULT '' COMMENT '商品编码',
  `cover` varchar(255) NOT NULL DEFAULT '',
  `images` text COMMENT '轮播图JSON',
  `video` varchar(255) NOT NULL DEFAULT '',
  `spec_type` tinyint NOT NULL DEFAULT 1 COMMENT '1单规格 2多规格',
  `price` decimal(12,2) NOT NULL DEFAULT 0 COMMENT '展示价（基准币种，取SKU最低价）',
  `market_price` decimal(12,2) NOT NULL DEFAULT 0,
  `stock` int NOT NULL DEFAULT 0 COMMENT '总库存（SKU汇总）',
  `sales` int NOT NULL DEFAULT 0 COMMENT '销量',
  `virtual_sales` int NOT NULL DEFAULT 0,
  `unit` varchar(20) NOT NULL DEFAULT '',
  `shipping_template_id` int unsigned NOT NULL DEFAULT 0,
  `is_virtual` tinyint NOT NULL DEFAULT 0 COMMENT '1虚拟商品无需发货',
  `is_on_sale` tinyint NOT NULL DEFAULT 0 COMMENT '1上架',
  `sort` int NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_category` (`category_id`),
  KEY `idx_sale_sort` (`is_on_sale`,`sort`),
  KEY `idx_goods_sn` (`goods_sn`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品';

CREATE TABLE `gm_goods_lang` (
  `goods_id` bigint unsigned NOT NULL,
  `lang` varchar(10) NOT NULL,
  `title` varchar(255) NOT NULL,
  `subtitle` varchar(255) NOT NULL DEFAULT '',
  `keywords` varchar(255) NOT NULL DEFAULT '',
  `description` text COMMENT '商品详情（富文本）',
  `seo_title` varchar(255) NOT NULL DEFAULT '',
  `seo_desc` varchar(500) NOT NULL DEFAULT '',
  PRIMARY KEY (`goods_id`,`lang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品-翻译';

CREATE TABLE `gm_goods_spec` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `goods_id` bigint unsigned NOT NULL,
  `sort` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_goods` (`goods_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='规格名（如 颜色/尺码）';

CREATE TABLE `gm_goods_spec_lang` (
  `spec_id` bigint unsigned NOT NULL,
  `lang` varchar(10) NOT NULL,
  `name` varchar(100) NOT NULL,
  PRIMARY KEY (`spec_id`,`lang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='规格名-翻译';

CREATE TABLE `gm_goods_spec_value` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `spec_id` bigint unsigned NOT NULL,
  `goods_id` bigint unsigned NOT NULL,
  `image` varchar(255) NOT NULL DEFAULT '',
  `sort` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_spec` (`spec_id`),
  KEY `idx_goods` (`goods_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='规格值（如 红色/XL）';

CREATE TABLE `gm_goods_spec_value_lang` (
  `value_id` bigint unsigned NOT NULL,
  `lang` varchar(10) NOT NULL,
  `name` varchar(100) NOT NULL,
  PRIMARY KEY (`value_id`,`lang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='规格值-翻译';

CREATE TABLE `gm_goods_sku` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `goods_id` bigint unsigned NOT NULL,
  `spec_value_ids` varchar(255) NOT NULL DEFAULT '' COMMENT '规格值ID组合，如 12,35（单规格为空）',
  `sku_sn` varchar(60) NOT NULL DEFAULT '',
  `image` varchar(255) NOT NULL DEFAULT '',
  `price` decimal(12,2) NOT NULL DEFAULT 0 COMMENT '基准币种售价',
  `cost_price` decimal(12,2) NOT NULL DEFAULT 0,
  `market_price` decimal(12,2) NOT NULL DEFAULT 0,
  `stock` int NOT NULL DEFAULT 0,
  `warn_stock` int NOT NULL DEFAULT 0,
  `weight` decimal(10,3) NOT NULL DEFAULT 0 COMMENT 'kg',
  `volume` decimal(10,3) NOT NULL DEFAULT 0 COMMENT 'm³',
  `sales` int NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_goods` (`goods_id`),
  KEY `idx_sku_sn` (`sku_sn`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品SKU';

CREATE TABLE `gm_goods_price` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `sku_id` bigint unsigned NOT NULL,
  `currency` varchar(10) NOT NULL,
  `price` decimal(12,2) NOT NULL COMMENT '该币种单独定价；无记录则按汇率折算',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sku_currency` (`sku_id`,`currency`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='SKU分币种定价（可选）';

CREATE TABLE `gm_stock_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `goods_id` bigint unsigned NOT NULL,
  `sku_id` bigint unsigned NOT NULL,
  `type` tinyint NOT NULL COMMENT '1下单扣减 2取消回补 3售后回补 4后台调整 5导入',
  `change_qty` int NOT NULL COMMENT '正=增 负=减',
  `after_qty` int NOT NULL,
  `ref_no` varchar(64) NOT NULL DEFAULT '' COMMENT '关联订单号等',
  `operator` varchar(50) NOT NULL DEFAULT '',
  `remark` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sku` (`sku_id`),
  KEY `idx_ref` (`ref_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='库存流水';

-- ---------------------------------------------------------------------
-- 三、会员
-- ---------------------------------------------------------------------
CREATE TABLE `gm_member_level` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `level_no` int NOT NULL DEFAULT 1 COMMENT '等级序号',
  `icon` varchar(255) NOT NULL DEFAULT '',
  `need_points` int NOT NULL DEFAULT 0 COMMENT '升级所需成长值/积分',
  `discount` decimal(5,2) NOT NULL DEFAULT 100 COMMENT '折扣百分比，100=无折扣',
  `status` tinyint NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员等级';

CREATE TABLE `gm_member_level_lang` (
  `level_id` int unsigned NOT NULL,
  `lang` varchar(10) NOT NULL,
  `name` varchar(50) NOT NULL,
  PRIMARY KEY (`level_id`,`lang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员等级-翻译';

CREATE TABLE `gm_member` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account` varchar(100) NOT NULL DEFAULT '' COMMENT '用户名/邮箱',
  `phone_area` varchar(8) NOT NULL DEFAULT '86' COMMENT '手机区号',
  `phone` varchar(30) NOT NULL DEFAULT '',
  `email` varchar(100) NOT NULL DEFAULT '',
  `password` varchar(255) NOT NULL DEFAULT '',
  `nickname` varchar(60) NOT NULL DEFAULT '',
  `avatar` varchar(255) NOT NULL DEFAULT '',
  `gender` tinyint NOT NULL DEFAULT 0,
  `birthday` date DEFAULT NULL,
  `level_id` int unsigned NOT NULL DEFAULT 1,
  `points` int NOT NULL DEFAULT 0,
  `balance` decimal(12,2) NOT NULL DEFAULT 0 COMMENT '余额（基准币种）',
  `lang` varchar(10) NOT NULL DEFAULT 'zh-CN' COMMENT '语言偏好',
  `currency` varchar(10) NOT NULL DEFAULT 'CNY' COMMENT '币种偏好',
  `register_source` varchar(20) NOT NULL DEFAULT '' COMMENT 'h5/mp/app/pc',
  `parent_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT '【分销预留】推荐人',
  `distributor_status` tinyint NOT NULL DEFAULT 0 COMMENT '【分销预留】0非分销员 1分销员',
  `status` tinyint NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `last_login_ip` varchar(45) NOT NULL DEFAULT '',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_phone` (`phone_area`,`phone`),
  KEY `idx_email` (`email`),
  KEY `idx_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员';

CREATE TABLE `gm_member_auth` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `member_id` bigint unsigned NOT NULL,
  `platform` varchar(20) NOT NULL COMMENT 'wechat_mp公众号/wechat_mini小程序/wechat_app/apple/google/...',
  `app_id` varchar(64) NOT NULL DEFAULT '',
  `openid` varchar(128) NOT NULL DEFAULT '',
  `unionid` varchar(128) NOT NULL DEFAULT '',
  `extra` text COMMENT '其它第三方信息JSON',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_platform_openid` (`platform`,`app_id`,`openid`),
  KEY `idx_member` (`member_id`),
  KEY `idx_unionid` (`unionid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员第三方登录绑定';

CREATE TABLE `gm_member_address` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `member_id` bigint unsigned NOT NULL,
  `name` varchar(60) NOT NULL,
  `phone_area` varchar(8) NOT NULL DEFAULT '86',
  `phone` varchar(30) NOT NULL,
  `country_code` varchar(5) NOT NULL DEFAULT 'CN' COMMENT 'ISO国家码',
  `province` varchar(60) NOT NULL DEFAULT '',
  `city` varchar(60) NOT NULL DEFAULT '',
  `district` varchar(60) NOT NULL DEFAULT '',
  `address` varchar(255) NOT NULL DEFAULT '',
  `address2` varchar(255) NOT NULL DEFAULT '',
  `postcode` varchar(20) NOT NULL DEFAULT '',
  `is_default` tinyint NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_member` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='收货地址（支持国际格式）';

CREATE TABLE `gm_member_point_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `member_id` bigint unsigned NOT NULL,
  `type` tinyint NOT NULL COMMENT '1注册 2签到 3消费 4抵扣 5后台调整 6退款扣回',
  `change_points` int NOT NULL,
  `after_points` int NOT NULL,
  `ref_no` varchar(64) NOT NULL DEFAULT '',
  `remark` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_member` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='积分流水';

-- ---------------------------------------------------------------------
-- 四、订单、支付、发货
-- ---------------------------------------------------------------------
CREATE TABLE `gm_order` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_no` varchar(32) NOT NULL,
  `member_id` bigint unsigned NOT NULL,
  `source` varchar(10) NOT NULL DEFAULT '' COMMENT '来源端 h5/mp/app/pc',
  `lang` varchar(10) NOT NULL DEFAULT 'zh-CN' COMMENT '下单语言',
  `currency` varchar(10) NOT NULL COMMENT '下单币种',
  `rate` decimal(18,8) NOT NULL DEFAULT 1 COMMENT '下单时 基准币→下单币种 汇率（固化）',
  `goods_amount` decimal(12,2) NOT NULL DEFAULT 0 COMMENT '商品总额（下单币种）',
  `shipping_fee` decimal(12,2) NOT NULL DEFAULT 0,
  `discount_amount` decimal(12,2) NOT NULL DEFAULT 0,
  `points_amount` decimal(12,2) NOT NULL DEFAULT 0 COMMENT '积分抵扣',
  `pay_amount` decimal(12,2) NOT NULL DEFAULT 0 COMMENT '应付/实付（下单币种）',
  `base_pay_amount` decimal(12,2) NOT NULL DEFAULT 0 COMMENT '折算基准币种的实付，用于统计',
  `status` tinyint NOT NULL DEFAULT 0 COMMENT '0待支付 1待发货 2待收货 3已完成 4已取消 5售后中 6已关闭',
  `pay_status` tinyint NOT NULL DEFAULT 0 COMMENT '0未支付 1已支付 2部分退款 3已退款',
  `pay_channel` varchar(30) NOT NULL DEFAULT '' COMMENT 'alipay/wechat/paypal/stripe...',
  `paid_at` datetime DEFAULT NULL,
  `shipped_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `cancel_reason` varchar(255) NOT NULL DEFAULT '',
  `remark` varchar(500) NOT NULL DEFAULT '' COMMENT '买家留言',
  `admin_remark` varchar(500) NOT NULL DEFAULT '',
  `source_member_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT '【分销预留】推广来源会员',
  `expire_at` datetime DEFAULT NULL COMMENT '未支付自动关闭时间',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no` (`order_no`),
  KEY `idx_member` (`member_id`),
  KEY `idx_status` (`status`,`created_at`),
  KEY `idx_paid` (`paid_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单';

CREATE TABLE `gm_order_item` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint unsigned NOT NULL,
  `goods_id` bigint unsigned NOT NULL,
  `sku_id` bigint unsigned NOT NULL,
  `title` varchar(255) NOT NULL COMMENT '下单语言的商品标题（快照）',
  `spec_text` varchar(255) NOT NULL DEFAULT '' COMMENT '规格文字（快照）',
  `image` varchar(255) NOT NULL DEFAULT '',
  `price` decimal(12,2) NOT NULL COMMENT '单价（下单币种）',
  `base_price` decimal(12,2) NOT NULL DEFAULT 0 COMMENT '单价（基准币种）',
  `cost_price` decimal(12,2) NOT NULL DEFAULT 0,
  `qty` int NOT NULL,
  `weight` decimal(10,3) NOT NULL DEFAULT 0,
  `refund_qty` int NOT NULL DEFAULT 0,
  `ship_status` tinyint NOT NULL DEFAULT 0 COMMENT '0未发 1已发',
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`),
  KEY `idx_goods` (`goods_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单商品（快照）';

CREATE TABLE `gm_order_address` (
  `order_id` bigint unsigned NOT NULL,
  `name` varchar(60) NOT NULL,
  `phone_area` varchar(8) NOT NULL DEFAULT '86',
  `phone` varchar(30) NOT NULL,
  `country_code` varchar(5) NOT NULL DEFAULT 'CN',
  `province` varchar(60) NOT NULL DEFAULT '',
  `city` varchar(60) NOT NULL DEFAULT '',
  `district` varchar(60) NOT NULL DEFAULT '',
  `address` varchar(255) NOT NULL DEFAULT '',
  `address2` varchar(255) NOT NULL DEFAULT '',
  `postcode` varchar(20) NOT NULL DEFAULT '',
  PRIMARY KEY (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单收货地址快照';

CREATE TABLE `gm_order_status_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint unsigned NOT NULL,
  `from_status` tinyint NOT NULL DEFAULT 0,
  `to_status` tinyint NOT NULL,
  `operator_type` tinyint NOT NULL DEFAULT 0 COMMENT '0系统 1会员 2管理员',
  `operator_id` bigint unsigned NOT NULL DEFAULT 0,
  `remark` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单状态日志';

CREATE TABLE `gm_order_refund` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `refund_no` varchar(32) NOT NULL,
  `order_id` bigint unsigned NOT NULL,
  `order_item_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT '0=整单',
  `member_id` bigint unsigned NOT NULL,
  `type` tinyint NOT NULL DEFAULT 1 COMMENT '1仅退款 2退货退款',
  `reason` varchar(255) NOT NULL DEFAULT '',
  `images` text,
  `qty` int NOT NULL DEFAULT 0,
  `currency` varchar(10) NOT NULL,
  `refund_amount` decimal(12,2) NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 0 COMMENT '0待审核 1同意待退货 2待收货确认 3退款中 4已完成 5已拒绝 6已撤销',
  `reject_reason` varchar(255) NOT NULL DEFAULT '',
  `return_express` varchar(60) NOT NULL DEFAULT '',
  `return_express_no` varchar(60) NOT NULL DEFAULT '',
  `refund_trade_no` varchar(64) NOT NULL DEFAULT '' COMMENT '渠道退款流水号',
  `finished_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_refund_no` (`refund_no`),
  KEY `idx_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='售后/退款';

CREATE TABLE `gm_pay_trade` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `trade_no` varchar(40) NOT NULL COMMENT '商城内部支付单号',
  `order_id` bigint unsigned NOT NULL,
  `member_id` bigint unsigned NOT NULL,
  `channel` varchar(30) NOT NULL COMMENT 'alipay/wechat/paypal/stripe/...',
  `channel_trade_no` varchar(128) NOT NULL DEFAULT '' COMMENT '渠道交易号',
  `trade_type` tinyint NOT NULL DEFAULT 1 COMMENT '1支付 2退款',
  `currency` varchar(10) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `fee_amount` decimal(12,2) NOT NULL DEFAULT 0 COMMENT '渠道手续费',
  `settle_currency` varchar(10) NOT NULL DEFAULT '' COMMENT '渠道结算币种',
  `settle_amount` decimal(12,2) NOT NULL DEFAULT 0 COMMENT '渠道结算金额',
  `status` tinyint NOT NULL DEFAULT 0 COMMENT '0待支付 1成功 2失败 3已关闭',
  `paid_at` datetime DEFAULT NULL,
  `raw_response` text COMMENT '渠道返回原文',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_trade_no` (`trade_no`),
  UNIQUE KEY `uk_channel_trade` (`channel`,`channel_trade_no`,`trade_type`),
  KEY `idx_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='支付/退款流水';

CREATE TABLE `gm_pay_notify_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `channel` varchar(30) NOT NULL,
  `event_id` varchar(128) NOT NULL DEFAULT '' COMMENT '渠道事件ID，用于幂等',
  `trade_no` varchar(40) NOT NULL DEFAULT '',
  `headers` text,
  `body` mediumtext COMMENT '回调原文',
  `verify_ok` tinyint NOT NULL DEFAULT 0 COMMENT '验签结果',
  `handle_status` tinyint NOT NULL DEFAULT 0 COMMENT '0待处理 1成功 2失败 3重复忽略',
  `error_msg` varchar(500) NOT NULL DEFAULT '',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_channel_event` (`channel`,`event_id`),
  KEY `idx_trade` (`trade_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='支付回调日志（幂等）';

CREATE TABLE `gm_pay_reconcile` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `channel` varchar(30) NOT NULL,
  `bill_date` date NOT NULL,
  `channel_trade_no` varchar(128) NOT NULL DEFAULT '',
  `trade_no` varchar(40) NOT NULL DEFAULT '',
  `diff_type` tinyint NOT NULL DEFAULT 0 COMMENT '1渠道有本地无 2本地有渠道无 3金额不一致 4状态不一致',
  `local_amount` decimal(12,2) NOT NULL DEFAULT 0,
  `channel_amount` decimal(12,2) NOT NULL DEFAULT 0,
  `handle_status` tinyint NOT NULL DEFAULT 0 COMMENT '0待处理 1已处理',
  `remark` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_channel_date` (`channel`,`bill_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='对账差异（渠道账单接入后使用）';

CREATE TABLE `gm_shipping` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint unsigned NOT NULL,
  `express_code` varchar(40) NOT NULL DEFAULT '',
  `express_name` varchar(60) NOT NULL DEFAULT '',
  `express_no` varchar(60) NOT NULL DEFAULT '',
  `waybill_driver` varchar(30) NOT NULL DEFAULT '' COMMENT '电子面单驱动，空=手工填单',
  `waybill_data` text COMMENT '电子面单返回JSON（打印内容等）',
  `item_ids` varchar(500) NOT NULL DEFAULT '' COMMENT '本次发货的order_item_id，逗号分隔（支持分批发货）',
  `track_status` tinyint NOT NULL DEFAULT 0 COMMENT '0未知 1运输中 2已签收 3异常',
  `track_data` text COMMENT '最近物流轨迹缓存',
  `shipped_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`),
  KEY `idx_express_no` (`express_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='发货记录';

CREATE TABLE `gm_shipping_template` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `charge_type` tinyint NOT NULL DEFAULT 1 COMMENT '1按件 2按重量 3按体积',
  `is_free_default` tinyint NOT NULL DEFAULT 0 COMMENT '1默认包邮',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='运费模板';

CREATE TABLE `gm_shipping_template_region` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `template_id` int unsigned NOT NULL,
  `countries` varchar(500) NOT NULL DEFAULT '' COMMENT '适用国家码，逗号分隔；*=其他所有',
  `provinces` text COMMENT '适用省份（国内可细分，可空）',
  `first_unit` decimal(10,3) NOT NULL DEFAULT 1 COMMENT '首件/首重/首体积',
  `first_fee` decimal(12,2) NOT NULL DEFAULT 0 COMMENT '首费（基准币种）',
  `step_unit` decimal(10,3) NOT NULL DEFAULT 1,
  `step_fee` decimal(12,2) NOT NULL DEFAULT 0,
  `free_amount` decimal(12,2) NOT NULL DEFAULT 0 COMMENT '满额包邮，0=不启用',
  PRIMARY KEY (`id`),
  KEY `idx_template` (`template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='运费模板-地区规则';

-- ---------------------------------------------------------------------
-- 五、接口配置（驱动模式）与消息模板
-- ---------------------------------------------------------------------
CREATE TABLE `gm_driver_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(20) NOT NULL COMMENT 'sms/storage/pay/express/waybill',
  `driver` varchar(30) NOT NULL COMMENT 'aliyun/tencent/self/s3/gcs/alipay/wechat/paypal/stripe/kuaidi100/...',
  `name` varchar(60) NOT NULL DEFAULT '' COMMENT '显示名',
  `config` text COMMENT '配置JSON（密钥字段加密存储，后台展示脱敏）',
  `region` varchar(100) NOT NULL DEFAULT '' COMMENT '适用地区/币种，如 CN / overseas / USD',
  `is_enabled` tinyint NOT NULL DEFAULT 0,
  `is_default` tinyint NOT NULL DEFAULT 0 COMMENT '同类型默认驱动',
  `sort` int NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_type_driver` (`type`,`driver`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='第三方接口驱动配置';

CREATE TABLE `gm_message_template` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `scene` varchar(60) NOT NULL COMMENT '场景 order_paid/order_shipped/refund_done/sms_code...',
  `channel` varchar(20) NOT NULL COMMENT 'sms/email/wechat_mp/wechat_mini/app_push/site',
  `third_template_id` varchar(100) NOT NULL DEFAULT '' COMMENT '第三方模板ID（短信/公众号等）',
  `is_enabled` tinyint NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_scene_channel` (`scene`,`channel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='消息模板';

CREATE TABLE `gm_message_template_lang` (
  `template_id` int unsigned NOT NULL,
  `lang` varchar(10) NOT NULL,
  `title` varchar(255) NOT NULL DEFAULT '',
  `content` text COMMENT '含变量占位符，如 {order_no}',
  PRIMARY KEY (`template_id`,`lang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='消息模板-翻译';
