<?php

namespace App\Services\Admin;

use App\Drivers\DriverManager;
use App\Http\Requests\Admin\AdSaveRequest;
use App\Http\Requests\Admin\ArticleCategorySaveRequest;
use App\Http\Requests\Admin\ArticleSaveRequest;
use App\Http\Requests\Admin\BrandSaveRequest;
use App\Http\Requests\Admin\CategorySaveRequest;
use App\Http\Requests\Admin\GoodsSaveRequest;
use App\Models\Admin;
use App\Models\AdPosition;
use App\Models\Goods;
use App\Services\Common\LocaleService;
use App\Support\Exceptions\BusinessException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * 演示数据导入（命令 mall:demo-import）。数据格式见 docs/演示数据格式.md。
 *
 * - 不直接写表：分类、品牌、商品、文章、广告都走后台对应的服务，并先用后台表单的校验规则过一遍，
 *   所以导入的数据和在后台手工录入的完全一样（SKU、多语言、分币种定价、库存流水都齐全）。
 * - 数据里用 code 互相引用（商品引用分类和品牌、广告引用商品等），导入时换成真实 ID。
 * - 图片是自动生成的占位图（SVG，纯色底 + 名称），经存储驱动保存。
 * - 导入了什么都记在清单里（storage/app/demo-import.json）；再次导入会先按清单清掉上一批，clear() 只清不导。
 *   只动清单里记录的数据，不碰其他数据。
 */
class DemoImportService
{
    private const ARTICLE_TYPES = ['article' => 1, 'notice' => 2, 'help' => 3];

    /** 占位图的底色（和前台的奶油色主题同一个色系），按名称固定选一个，同一个商品每次导入颜色不变 */
    public const COLORS = ['#E9DCCB', '#DCCFBF', '#E3C9B8', '#D5DCCB', '#C9D3C3', '#EAD7C3', '#DFD3C2', '#E6CFC0', '#CFD8CC', '#EFE3D2'];

    /** 占位图上文字的颜色 */
    public const INK = '#6B5B4E';

    /** @var array<string,array<int,mixed>> 本次导入的清单 */
    private array $manifest = [];

    /** @var array<string,array<string,int>> code → ID */
    private array $ids = [];

    private string $batch = '';

    public function __construct(
        private CategoryService $categories, private BrandService $brands, private GoodsService $goods,
        private ArticleService $articles, private AdService $ads, private LocaleService $locale, private DriverManager $drivers,
    ) {}

    /** 清单文件的位置；测试里会换成临时文件，免得动到真实的清单 */
    public static ?string $manifestFile = null;

    public static function manifestPath(): string
    {
        return self::$manifestFile ?? storage_path('app/demo-import.json');
    }

    /** 读取并解析数据文件；$path 可以是 JSON 文件，也可以是放着 data.json 的目录 */
    public function read(string $path): array
    {
        $file = is_dir($path) ? rtrim($path, '/\\') . DIRECTORY_SEPARATOR . 'data.json' : $path;
        if (!is_file($file)) {
            throw new BusinessException("找不到数据文件：{$file}");
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            throw new BusinessException('数据文件不是合法的 JSON：' . json_last_error_msg());
        }

        return $data;
    }

    /**
     * 只检查不写入：结构、引用、字段规则。返回发现的问题（空数组表示可以导入）。
     * @return string[]
     */
    public function check(array $data): array
    {
        // 命令行里默认是英文环境，校验提示要中文
        app()->setLocale('zh-CN');
        $problems = [];
        $default = $this->locale->defaultLang();
        // 名称、标题这类多语言字段，默认语言必须填（后台保存时也是这个规则）
        $needDefault = function (string $at, mixed $texts, string $label) use (&$problems, $default) {
            if (!is_array($texts) || trim((string) ($texts[$default] ?? '')) === '') {
                $problems[] = "{$at}：{$label}缺少默认语言（{$default}）的内容";
            }
        };
        $codes = fn (string $key, ?array $rows = null) => array_column($rows ?? (array) ($data[$key] ?? []), 'code');
        $dup = function (string $label, array $list) use (&$problems) {
            foreach (array_count_values(array_map('strval', $list)) as $code => $n) {
                if ($n > 1) {
                    $problems[] = "{$label} 的 code 重复：{$code}";
                }
            }
        };

        $flat = $this->flattenCategories((array) ($data['categories'] ?? []));
        $catCodes = array_column($flat, 'code');
        $dup('categories', $catCodes);
        foreach ($flat as $c) {
            $needDefault("categories[{$c['code']}]", $c['name'] ?? null, 'name ');
            $this->collect($problems, "categories[{$c['code']}]", fn () => $this->validate(CategorySaveRequest::class, ['pid' => 0, 'name' => $c['name'] ?? null]));
            if ($c['depth'] > 3) {
                $problems[] = "categories[{$c['code']}]：分类最多三级";
            }
        }
        $dup('brands', $codes('brands'));
        foreach ((array) ($data['brands'] ?? []) as $b) {
            $needDefault('brands[' . ($b['code'] ?? '?') . ']', $b['name'] ?? null, 'name ');
            $this->collect($problems, 'brands[' . ($b['code'] ?? '?') . ']', fn () => $this->validate(BrandSaveRequest::class, ['name' => $b['name'] ?? null]));
        }

        $dup('goods', $codes('goods'));
        $currencies = $this->locale->currencyCodes();
        foreach ((array) ($data['goods'] ?? []) as $g) {
            $at = 'goods[' . ($g['code'] ?? '?') . ']';
            if (!preg_match('/^[a-z0-9][a-z0-9-]{1,40}$/', (string) ($g['code'] ?? ''))) {
                $problems[] = "{$at}：code 只能用小写字母、数字和连字符，2-41 个字符";
            }
            $needDefault($at, $g['title'] ?? null, 'title ');
            if (!in_array($g['category'] ?? null, $catCodes, true)) {
                $problems[] = "{$at}：category 引用了不存在的分类 " . json_encode($g['category'] ?? null);
            }
            if (($g['brand'] ?? '') !== '' && !in_array($g['brand'], $codes('brands'), true)) {
                $problems[] = "{$at}：brand 引用了不存在的品牌 {$g['brand']}";
            }
            foreach ($this->priceCurrencies($g) as $cur) {
                if (!in_array($cur, $currencies, true)) {
                    $problems[] = "{$at}：prices 里的币种 {$cur} 在商城里没有启用（已启用：" . implode('、', $currencies) . '）';
                }
            }
            $this->collect($problems, $at, function () use ($g) {
                $payload = $this->goodsPayload($g, 1, 0, []);
                $this->validate(GoodsSaveRequest::class, $payload);
                $this->assertSkuCombos($g);
            });
        }

        $acCodes = $codes('article_categories');
        $dup('article_categories', $acCodes);
        foreach ((array) ($data['article_categories'] ?? []) as $c) {
            $at = 'article_categories[' . ($c['code'] ?? '?') . ']';
            if (!in_array($c['type'] ?? '', ['article', 'help'], true)) {
                $problems[] = "{$at}：type 只能是 article 或 help（公告没有分类）";
            }
            $needDefault($at, $c['name'] ?? null, 'name ');
            $this->collect($problems, $at, fn () => $this->validate(ArticleCategorySaveRequest::class, ['name' => $c['name'] ?? null]));
        }
        $dup('articles', array_filter($codes('articles')));
        foreach ((array) ($data['articles'] ?? []) as $i => $a) {
            $at = 'articles[' . ($a['code'] ?? $i) . ']';
            if (!isset(self::ARTICLE_TYPES[$a['type'] ?? ''])) {
                $problems[] = "{$at}：type 只能是 article、notice、help";
            }
            if (($a['category'] ?? '') !== '' && !in_array($a['category'], $acCodes, true)) {
                $problems[] = "{$at}：category 引用了不存在的文章分类 {$a['category']}";
            }
            $needDefault($at, $a['title'] ?? null, 'title ');
            $needDefault($at, $a['content'] ?? null, 'content ');
            $this->collect($problems, $at, fn () => $this->validate(ArticleSaveRequest::class, $this->articlePayload($a, 0, '')));
        }

        $positions = AdPosition::query()->pluck('id', 'code')->all();
        foreach ((array) ($data['banners'] ?? []) as $i => $b) {
            $at = "banners[{$i}]";
            $needDefault($at, $b['title'] ?? null, 'title ');
            if (!isset($positions[$b['position'] ?? ''])) {
                $problems[] = "{$at}：position 不存在（可用：" . implode('、', array_keys($positions)) . '）';
            }
            $type = (string) ($b['link']['type'] ?? 'none');
            $value = (string) ($b['link']['value'] ?? '');
            $known = ['goods' => $codes('goods'), 'category' => $catCodes, 'article' => array_filter($codes('articles'))];
            if (!in_array($type, ['none', 'url', 'goods', 'category', 'article'], true)) {
                $problems[] = "{$at}：link.type 只能是 none、url、goods、category、article";
            } elseif (isset($known[$type]) && !in_array($value, $known[$type], true)) {
                $problems[] = "{$at}：link.value 引用了不存在的 {$type}：{$value}";
            }
        }

        return $problems;
    }

    /**
     * 导入。会先清掉上一批演示数据。
     * @return array<string,int> 各类数据导入了多少条
     */
    public function import(array $data): array
    {
        if ($problems = $this->check($data)) {
            throw new BusinessException("数据有 " . count($problems) . " 处问题，没有导入：\n- " . implode("\n- ", $problems));
        }
        $this->clear();

        $this->manifest = ['files' => [], 'ads' => [], 'articles' => [], 'article_categories' => [], 'goods' => [], 'brands' => [], 'categories' => []];
        $this->ids = ['categories' => [], 'brands' => [], 'goods' => [], 'article_categories' => [], 'articles' => []];
        $this->batch = date('YmdHis');
        $op = Admin::query()->orderBy('id')->first() ?? throw new BusinessException('还没有管理员账号，请先执行 php artisan db:seed');

        try {
            foreach ($this->flattenCategories((array) ($data['categories'] ?? [])) as $c) {
                $cat = $this->categories->create([
                    'pid' => $c['parent'] === null ? 0 : $this->ids['categories'][$c['parent']], 'name' => $c['name'],
                    'image' => $this->decor("category-{$c['code']}", 600, 480, 'tile'), 'sort' => (int) ($c['sort'] ?? 0), 'status' => 1,
                ]);
                $this->remember('categories', $c['code'], (int) $cat->id);
            }
            foreach ((array) ($data['brands'] ?? []) as $b) {
                $brand = $this->brands->create([
                    'name' => $b['name'], 'logo' => $this->placeholder("brand-{$b['code']}", $this->label($b['name']), 300, 300), 'sort' => (int) ($b['sort'] ?? 0), 'status' => 1,
                ]);
                $this->remember('brands', $b['code'], (int) $brand->id);
            }
            foreach ((array) ($data['goods'] ?? []) as $g) {
                $images = [];
                for ($i = 1; $i <= min(max((int) ($g['images'] ?? 3), 1), 9); $i++) {
                    $images[] = $this->placeholder("goods-{$g['code']}-{$i}", $this->label($g['title']), 800, 800, $i > 1 ? (string) $i : '');
                }
                $goods = $this->goods->create(
                    $this->goodsPayload($g, $this->ids['categories'][$g['category']], ($g['brand'] ?? '') !== '' ? $this->ids['brands'][$g['brand']] : 0, $images), $op
                );
                $this->remember('goods', $g['code'], (int) $goods->id);
                // 销量是展示用的假数字，后台表单里没有这个字段，所以直接写
                if (($sales = (int) ($g['sales'] ?? 0)) > 0) {
                    DB::table('goods')->where('id', $goods->id)->update(['sales' => $sales]);
                }
            }
            foreach ((array) ($data['article_categories'] ?? []) as $c) {
                $type = self::ARTICLE_TYPES[$c['type']];
                $cat = $this->articles->saveCategory($type, 0, ['name' => $c['name'], 'sort' => (int) ($c['sort'] ?? 0), 'status' => 1]);
                $this->ids['article_categories'][$c['code']] = (int) $cat->id;
                $this->manifest['article_categories'][] = ['type' => $type, 'id' => (int) $cat->id];
            }
            foreach ((array) ($data['articles'] ?? []) as $i => $a) {
                $type = self::ARTICLE_TYPES[$a['type']];
                $code = (string) ($a['code'] ?? "n{$i}");
                $cover = $type === 2 ? '' : $this->decor("article-{$code}", 800, 600, 'tile');
                $article = $this->articles->save($type, 0, $this->articlePayload($a, ($a['category'] ?? '') !== '' ? $this->ids['article_categories'][$a['category']] : 0, $cover));
                $this->ids['articles'][$code] = (int) $article->id;
                $this->manifest['articles'][] = ['type' => $type, 'id' => (int) $article->id];
            }
            $positions = AdPosition::query()->pluck('id', 'code')->all();
            foreach ((array) ($data['banners'] ?? []) as $i => $b) {
                $type = (string) ($b['link']['type'] ?? 'none');
                $value = (string) ($b['link']['value'] ?? '');
                $map = ['goods' => 'goods', 'category' => 'categories', 'article' => 'articles'];
                $image = [];
                foreach ($this->locale->codes() as $lang) {
                    $text = trim((string) ($b['title'][$lang] ?? ''));
                    // 首页轮播是暖色的，其余广告位（首页中部、分类页顶部）用鼠尾草绿
                    $image[$lang] = $text === '' ? '' : $this->decor("banner-{$i}-{$lang}", 1920, 600, $b['position'] === 'home_banner' ? 'warm' : 'sage');
                }
                $payload = [
                    'position_id' => $positions[$b['position']], 'title' => $b['title'] ?? [], 'image' => $image,
                    'link_type' => $type, 'link_value' => isset($map[$type]) ? (string) $this->ids[$map[$type]][$value] : $value,
                    'sort' => (int) ($b['sort'] ?? 0), 'status' => 1,
                ];
                $this->validate(AdSaveRequest::class, $payload);
                $this->manifest['ads'][] = (int) $this->ads->saveAd(0, $payload)->id;
            }
        } catch (\Throwable $e) {
            // 中途失败：把已经导入的这一部分清掉，不留半截数据
            $this->saveManifest();
            $this->clear();
            throw $e;
        }
        $this->saveManifest();

        return array_map('count', array_diff_key($this->manifest, ['files' => 1])) + ['images' => count($this->manifest['files'])];
    }

    /**
     * 按清单清掉上一批演示数据。演示商品被人下过单也没关系（订单里存的是快照）。
     * @return array{removed:int,kept:string[]} kept：因为后来被别的数据用到而没能删掉的
     */
    public function clear(): array
    {
        $path = self::manifestPath();
        if (!is_file($path)) {
            return ['removed' => 0, 'kept' => []];
        }
        $m = json_decode((string) file_get_contents($path), true) ?: [];
        $removed = 0;
        $kept = [];
        $try = function (string $what, callable $fn) use (&$removed, &$kept) {
            try {
                $fn();
                $removed++;
            } catch (BusinessException $e) {
                // “不存在”说明已经被人手工删了，不算问题；其余的（比如分类下后来加了别的商品）保留并报告
                if (!str_contains($e->getMessage(), '不存在')) {
                    $kept[] = "{$what}：{$e->getMessage()}";
                }
            }
        };

        foreach ((array) ($m['ads'] ?? []) as $id) {
            $try("广告 #{$id}", fn () => $this->ads->deleteAd((int) $id));
        }
        foreach ((array) ($m['articles'] ?? []) as $a) {
            $try("文章 #{$a['id']}", fn () => $this->articles->delete((int) $a['type'], (int) $a['id']));
        }
        foreach ((array) ($m['article_categories'] ?? []) as $c) {
            $try("文章分类 #{$c['id']}", fn () => $this->articles->deleteCategory((int) $c['type'], (int) $c['id']));
        }
        foreach ((array) ($m['goods'] ?? []) as $id) {
            $try("商品 #{$id}", fn () => $this->goods->delete((int) $id));
        }
        // 商品是软删除，货号还占着；改掉它，下次导入同一个 code 才不会撞
        if (!empty($m['goods'])) {
            Goods::withTrashed()->whereIn('id', $m['goods'])->whereNotNull('deleted_at')->update(['goods_sn' => DB::raw("CONCAT('DEL', id)")]);
        }
        foreach ((array) ($m['brands'] ?? []) as $id) {
            $try("品牌 #{$id}", fn () => $this->brands->delete((int) $id));
        }
        // 分类先删下级：清单里是先上级后下级，倒过来
        foreach (array_reverse((array) ($m['categories'] ?? [])) as $id) {
            $try("分类 #{$id}", fn () => $this->categories->delete((int) $id));
        }
        $storage = $this->drivers->storage();
        foreach ((array) ($m['files'] ?? []) as $file) {
            try {
                $storage->delete((string) $file);
            } catch (\Throwable $e) {
                // 占位图删不掉不影响使用
            }
        }
        @unlink($path);

        return ['removed' => $removed, 'kept' => $kept];
    }

    // ------------------------------------------------------------------ 内部
    /** 把后台表单的那套校验规则用在导入的数据上 */
    private function validate(string $requestClass, array $payload): void
    {
        /** @var FormRequest $req */
        $req = $requestClass::create('/', 'POST', $payload);
        Validator::make($payload, $req->rules(), [], $req->attributes())->validate();
    }

    private function collect(array &$problems, string $at, callable $fn): void
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            foreach ($e->validator->errors()->all() as $msg) {
                $problems[] = "{$at}：{$msg}";
            }
        } catch (BusinessException $e) {
            $problems[] = "{$at}：{$e->getMessage()}";
        }
    }

    /** 分类树摊平成“先上级后下级”的列表 */
    private function flattenCategories(array $nodes, ?string $parent = null, int $depth = 1): array
    {
        $out = [];
        foreach ($nodes as $i => $n) {
            $code = (string) ($n['code'] ?? '');
            $out[] = ['code' => $code, 'name' => $n['name'] ?? null, 'sort' => $n['sort'] ?? ($i + 1) * 10, 'parent' => $parent, 'depth' => $depth];
            $out = array_merge($out, $this->flattenCategories((array) ($n['children'] ?? []), $code, $depth + 1));
        }

        return $out;
    }

    /** 数据文件里的一个商品 → 后台“新增商品”表单提交的内容 */
    private function goodsPayload(array $g, int $categoryId, int $brandId, array $images): array
    {
        $sku = fn (array $s) => array_filter([
            'price' => $s['price'] ?? null, 'market_price' => $s['market_price'] ?? null, 'cost_price' => $s['cost_price'] ?? null,
            'stock' => $s['stock'] ?? 0, 'weight' => $s['weight'] ?? null, 'volume' => $s['volume'] ?? null,
        ], fn ($v) => $v !== null);
        $base = [
            'category_id' => $categoryId, 'brand_id' => $brandId, 'goods_sn' => 'DEMO-' . ($g['code'] ?? ''),
            'title' => $g['title'] ?? null, 'subtitle' => $g['subtitle'] ?? [], 'keywords' => $g['keywords'] ?? [], 'description' => $g['description'] ?? [],
            'images' => $images, 'unit' => (string) ($g['unit'] ?? ''), 'is_on_sale' => 1, 'sort' => (int) ($g['sort'] ?? 0),
        ];
        if (empty($g['specs'])) {
            return $base + ['spec_type' => 1, 'sku' => $sku($g) + ['sku_sn' => 'DEMO-' . ($g['code'] ?? '')], 'prices' => (array) ($g['prices'] ?? [])];
        }

        return $base + [
            'spec_type' => 2,
            'specs' => array_map(fn ($s) => ['name' => $s['name'] ?? null, 'values' => array_map(fn ($v) => ['key' => (string) ($v['key'] ?? ''), 'name' => $v['name'] ?? null], (array) ($s['values'] ?? []))], (array) $g['specs']),
            'skus' => array_map(fn ($s) => $sku($s) + [
                'value_keys' => array_map('strval', (array) ($s['values'] ?? [])), 'sku_sn' => 'DEMO-' . ($g['code'] ?? '') . '-' . implode('-', (array) ($s['values'] ?? [])),
                'status' => 1, 'prices' => (array) ($s['prices'] ?? []),
            ], (array) ($g['skus'] ?? [])),
        ];
    }

    /** 多规格：每个 SKU 要从每个规格里各选一个值，而且不能重复 */
    private function assertSkuCombos(array $g): void
    {
        if (empty($g['specs'])) {
            return;
        }
        $seen = [];
        foreach ((array) ($g['skus'] ?? []) as $n => $s) {
            $values = array_map('strval', (array) ($s['values'] ?? []));
            if (count($values) !== count($g['specs'])) {
                throw new BusinessException("第 " . ($n + 1) . ' 个 SKU 的 values 要和 specs 一一对应（每个规格选一个值）');
            }
            foreach ($values as $i => $key) {
                if (!in_array($key, array_column((array) ($g['specs'][$i]['values'] ?? []), 'key'), true)) {
                    throw new BusinessException("第 " . ($n + 1) . " 个 SKU 的 values 里的 {$key} 不是第 " . ($i + 1) . ' 个规格的值');
                }
            }
            if (isset($seen[$k = implode('|', $values)])) {
                throw new BusinessException("SKU 重复：" . implode(' + ', $values));
            }
            $seen[$k] = true;
        }
    }

    /** @return string[] 商品里出现的所有分币种定价的币种 */
    private function priceCurrencies(array $g): array
    {
        $out = array_keys((array) ($g['prices'] ?? []));
        foreach ((array) ($g['skus'] ?? []) as $s) {
            $out = array_merge($out, array_keys((array) ($s['prices'] ?? [])));
        }

        return array_values(array_unique(array_map('strval', $out)));
    }

    private function articlePayload(array $a, int $categoryId, string $cover): array
    {
        return [
            'category_id' => $categoryId, 'title' => $a['title'] ?? null, 'summary' => $a['summary'] ?? [], 'content' => $a['content'] ?? null,
            'cover' => $cover, 'author' => (string) ($a['author'] ?? ''), 'is_top' => empty($a['is_top']) ? 0 : 1, 'sort' => (int) ($a['sort'] ?? 0), 'status' => 1,
        ];
    }

    private function remember(string $kind, string $code, int $id): void
    {
        $this->ids[$kind][$code] = $id;
        $this->manifest[$kind][] = $id;
    }

    private function saveManifest(): void
    {
        file_put_contents(self::manifestPath(), json_encode($this->manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** 占位图上写的字：优先默认语言的名称 */
    private function label(mixed $texts): string
    {
        $texts = (array) $texts;

        return trim((string) ($texts[$this->locale->defaultLang()] ?? '')) ?: trim((string) (reset($texts) ?: ''));
    }

    /** 生成一张不带字的装饰图并保存，返回它的访问地址。用在页面会自己叠加标题的位置：轮播图、横幅、分类入口 */
    private function decor(string $name, int $w, int $h, string $tone): string
    {
        $path = "demo/{$this->batch}/{$name}.svg";
        $storage = $this->drivers->storage();
        $storage->put($path, self::decorSvg($w, $h, $tone, $name));
        $this->manifest['files'][] = $path;

        return $storage->url($path);
    }

    /**
     * 装饰图：柔和的底色，右侧几个圆和一道拱形，像一个简化的家居角落。同一个 $seed 每次画出来都一样。
     * @param string $tone warm 奶油底 + 陶土色（首页轮播） / sage 鼠尾草绿（主题横幅） / tile 分类入口的小图
     */
    public static function decorSvg(int $w, int $h, string $tone, string $seed): string
    {
        [$bg, $a, $b, $c] = match ($tone) {
            'sage' => ['#A7B7A0', '#B9C6B2', '#C9D3C3', '#8FA088'],
            'tile' => ['#EFE6D8', '#E3D2BF', '#D9B8A2', '#C8CFBE'],
            default => ['#F1E7D9', '#E7D3BF', '#D9A88F', '#BFC9B6'],
        };
        $r = crc32($seed);
        // 版式的重心放在右侧 60%～90% 之间，左边留给页面叠加的文字
        $cx = $w * (0.66 + ($r % 17) / 100);
        $base = $h * 0.86;
        $big = $h * (0.34 + (($r >> 4) % 10) / 100);
        $arcW = $h * (0.26 + (($r >> 8) % 8) / 100);
        $arcX = $cx + $big * (0.75 + (($r >> 12) % 20) / 100);
        $small = $h * (0.10 + (($r >> 16) % 6) / 100);
        if ($tone === 'tile') {
            [$cx, $arcX] = [$w * 0.42, $w * 0.70];
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%2$d" viewBox="0 0 %1$d %2$d">'
            . '<rect width="100%%" height="100%%" fill="%3$s"/>'
            . '<rect y="%7$.1f" width="100%%" height="%8$.1f" fill="%4$s" fill-opacity="0.55"/>'
            . '<circle cx="%9$.1f" cy="%10$.1f" r="%11$.1f" fill="%4$s"/>'
            . '<path d="M%12$.1f %7$.1f v-%13$.1f a%14$.1f %14$.1f 0 0 1 %15$.1f 0 v%13$.1f z" fill="%5$s"/>'
            . '<circle cx="%16$.1f" cy="%17$.1f" r="%18$.1f" fill="%6$s"/>'
            . '<circle cx="%19$.1f" cy="%20$.1f" r="%21$.1f" fill="#fff" fill-opacity="0.45"/>'
            . '</svg>',
            $w, $h, $bg, $a, $b, $c,
            $base, $h - $base,
            $cx, $base - $big, $big,
            $arcX, $arcW * 0.9, $arcW / 2, $arcW,
            $cx - $big * 0.95, $base - $small, $small,
            $arcX + $arcW * 1.35, $h * 0.24, $small * 0.8
        );
    }

    /** 生成一张占位图并保存，返回它的访问地址 */
    private function placeholder(string $name, string $text, int $w, int $h, string $corner = ''): string
    {
        $color = self::COLORS[crc32($text) % count(self::COLORS)];
        // 按宽度估算字号和每行字数（中文按 1 个字宽、英文按半个算），最多三行
        $size = max(18, (int) round($w / 14));
        $perLine = max(4, (int) floor($w * 0.8 / $size));
        $lines = [];
        $line = '';
        $width = 0.0;
        foreach (mb_str_split($text) as $ch) {
            $cw = strlen($ch) > 1 ? 1.0 : 0.55;
            if ($width + $cw > $perLine && $line !== '') {
                $lines[] = $line;
                [$line, $width] = ['', 0.0];
            }
            $line .= $ch;
            $width += $cw;
        }
        $lines[] = $line;
        if (count($lines) > 3) {
            $lines = array_slice($lines, 0, 3);
            $lines[2] = mb_substr($lines[2], 0, max(1, mb_strlen($lines[2]) - 1)) . '…';
        }
        $y = $h / 2 - (count($lines) - 1) * $size * 0.65;
        $tspans = '';
        foreach ($lines as $i => $l) {
            $tspans .= sprintf('<tspan x="%d" y="%d">%s</tspan>', $w / 2, $y + $i * $size * 1.3, htmlspecialchars(trim($l), ENT_XML1));
        }
        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%2$d" viewBox="0 0 %1$d %2$d"><rect width="100%%" height="100%%" fill="%3$s"/>'
            . '<text fill="' . self::INK . '" font-family="-apple-system,BlinkMacSystemFont,Segoe UI,PingFang SC,Microsoft YaHei,sans-serif" font-size="%4$d" font-weight="600" text-anchor="middle" dominant-baseline="middle">%5$s</text>%6$s</svg>',
            $w, $h, $color, $size, $tspans,
            $corner === '' ? '' : sprintf('<text x="%d" y="%d" fill="' . self::INK . '" fill-opacity="0.6" font-family="sans-serif" font-size="%d" text-anchor="end">%s</text>', $w - 24, $h - 24, (int) ($size * 0.6), htmlspecialchars($corner, ENT_XML1))
        );

        $path = "demo/{$this->batch}/{$name}.svg";
        $storage = $this->drivers->storage();
        $storage->put($path, $svg);
        $this->manifest['files'][] = $path;

        return $storage->url($path);
    }
}
