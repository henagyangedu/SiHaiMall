<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 新增与编辑共用。spec_type=1 校验单规格字段（sku、prices），spec_type=2 校验多规格字段（specs、skus）。
 * 编辑时已有 SKU 的库存字段会被忽略（库存只能通过“调整库存”修改）。
 */
class GoodsSaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'category_id'   => ['required', 'integer', 'min:1'],
            'brand_id'      => ['nullable', 'integer', 'min:0'],
            'shipping_template_id' => ['nullable', 'integer', 'min:0'],
            'commission_type'   => ['nullable', 'in:0,1,2'],
            'commission_rate_1' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'commission_rate_2' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'goods_sn'      => ['nullable', 'string', 'max:60'],
            'spec_type'     => ['required', 'in:1,2'],

            'title'         => ['required', 'array'],
            'title.*'       => ['nullable', 'string', 'max:255'],
            'subtitle'      => ['nullable', 'array'],
            'subtitle.*'    => ['nullable', 'string', 'max:255'],
            'keywords'      => ['nullable', 'array'],
            'keywords.*'    => ['nullable', 'string', 'max:255'],
            'description'   => ['nullable', 'array'],
            'description.*' => ['nullable', 'string', 'max:60000'],
            'seo_title'     => ['nullable', 'array'],
            'seo_title.*'   => ['nullable', 'string', 'max:255'],
            'seo_desc'      => ['nullable', 'array'],
            'seo_desc.*'    => ['nullable', 'string', 'max:500'],

            'images'        => ['nullable', 'array', 'max:9'],
            'images.*'      => ['string', 'max:500'],
            'video'         => ['nullable', 'string', 'max:255'],
            'unit'          => ['nullable', 'string', 'max:20'],
            'is_virtual'    => ['nullable', 'in:0,1'],
            'is_on_sale'    => ['nullable', 'in:0,1'],
            'sort'          => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];

        $skuRules = fn (string $p) => [
            "{$p}.sku_sn"       => ['nullable', 'string', 'max:60'],
            "{$p}.price"        => ['required', 'numeric', 'min:0', 'lt:10000000000'],
            "{$p}.market_price" => ['nullable', 'numeric', 'min:0', 'lt:10000000000'],
            "{$p}.cost_price"   => ['nullable', 'numeric', 'min:0', 'lt:10000000000'],
            "{$p}.stock"        => ['nullable', 'integer', 'min:0', 'max:99999999'],
            "{$p}.warn_stock"   => ['nullable', 'integer', 'min:0', 'max:99999999'],
            "{$p}.weight"       => ['nullable', 'numeric', 'min:0', 'lt:1000000'],
            "{$p}.volume"       => ['nullable', 'numeric', 'min:0', 'lt:1000000'],
        ];

        if ((int) $this->input('spec_type', 1) === 2) {
            $rules += [
                'specs'                    => ['required', 'array', 'min:1', 'max:3'],
                'specs.*.id'               => ['nullable', 'integer', 'min:1'],
                'specs.*.name'             => ['required', 'array'],
                'specs.*.name.*'           => ['nullable', 'string', 'max:100'],
                'specs.*.values'           => ['required', 'array', 'min:1', 'max:30'],
                'specs.*.values.*.id'      => ['nullable', 'integer', 'min:1'],
                'specs.*.values.*.key'     => ['required', 'string', 'max:40'],
                'specs.*.values.*.name'    => ['required', 'array'],
                'specs.*.values.*.name.*'  => ['nullable', 'string', 'max:100'],

                'skus'                     => ['required', 'array', 'min:1', 'max:200'],
                'skus.*.id'                => ['nullable', 'integer', 'min:1'],
                'skus.*.value_keys'        => ['required', 'array', 'min:1', 'max:3'],
                'skus.*.value_keys.*'      => ['required', 'string', 'max:40'],
                'skus.*.image'             => ['nullable', 'string', 'max:500'],
                'skus.*.status'            => ['nullable', 'in:0,1'],
                'skus.*.prices'            => ['nullable', 'array'],
                'skus.*.prices.*'          => ['nullable', 'numeric', 'min:0', 'lt:10000000000'],
                'skus.*.region_prices'     => ['nullable', 'array'],
                'skus.*.region_prices.*'   => ['nullable', 'numeric', 'min:0', 'lt:10000000000'],
            ] + $skuRules('skus.*');
        } else {
            $rules += [
                'sku'      => ['required', 'array'],
                'prices'   => ['nullable', 'array'],
                'prices.*' => ['nullable', 'numeric', 'min:0', 'lt:10000000000'],
                'region_prices'   => ['nullable', 'array'],
                'region_prices.*' => ['nullable', 'numeric', 'min:0', 'lt:10000000000'],
            ] + $skuRules('sku');
        }

        return $rules;
    }

    public function attributes(): array
    {
        $sku = [
            'sku_sn' => '规格编码', 'price' => '售价', 'market_price' => '划线价', 'cost_price' => '成本价',
            'stock' => '库存', 'warn_stock' => '库存预警值', 'weight' => '重量', 'volume' => '体积',
        ];
        $out = [
            'category_id' => '商品分类', 'brand_id' => '品牌', 'shipping_template_id' => '运费模板', 'commission_type' => '分销佣金', 'commission_rate_1' => '一级佣金比例', 'commission_rate_2' => '二级佣金比例', 'goods_sn' => '商品编码', 'spec_type' => '规格类型',
            'title' => '商品标题', 'title.*' => '商品标题', 'subtitle.*' => '副标题', 'keywords.*' => '关键词',
            'description.*' => '商品详情', 'seo_title.*' => 'SEO 标题', 'seo_desc.*' => 'SEO 描述',
            'images' => '商品图片', 'images.*' => '商品图片', 'video' => '视频地址', 'unit' => '单位', 'sort' => '排序',
            'sku' => '价格库存', 'prices.*' => '币种定价', 'region_prices.*' => '区域价', 'skus.*.region_prices.*' => '区域价',
            'specs' => '规格项', 'specs.*.name' => '规格名称', 'specs.*.name.*' => '规格名称',
            'specs.*.values' => '规格值', 'specs.*.values.*.name' => '规格值', 'specs.*.values.*.name.*' => '规格值',
            'specs.*.values.*.key' => '规格值标识',
            'skus' => '规格组合', 'skus.*.value_keys' => '规格组合', 'skus.*.prices.*' => '币种定价',
        ];
        foreach ($sku as $k => $label) {
            $out["sku.{$k}"] = $label;
            $out["skus.*.{$k}"] = $label;
        }

        return $out;
    }
}
