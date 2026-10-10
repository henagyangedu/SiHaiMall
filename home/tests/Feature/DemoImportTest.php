<?php

namespace Tests\Feature;

use App\Drivers\Contracts\StorageDriver;
use App\Drivers\DriverManager;
use App\Models\Goods;
use App\Services\Admin\DemoImportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\FeatureTestCase;

/** 演示数据导入：一份小的示例数据（tests/Fixtures/demo/data.json，不随正式的演示数据变化）能导入、买家端能看到、能清除、能重复导入；有问题的数据会被拦住并说清楚 */
class DemoImportTest extends FeatureTestCase
{
    /** @var array<string,string> 假存储里的文件：路径 => 内容 */
    private array $stored = [];

    private string $manifest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin(); // 导入商品时需要一个操作人

        $fake = new class($this->stored) implements StorageDriver
        {
            public function __construct(private array &$stored) {}

            public function code(): string
            {
                return 'local';
            }

            public function putFile(string $path, UploadedFile $file): void
            {
                $this->stored[$path] = (string) $file->getContent();
            }

            public function put(string $path, string $contents): void
            {
                $this->stored[$path] = $contents;
            }

            public function exists(string $path): bool
            {
                return isset($this->stored[$path]);
            }

            public function delete(string $path): void
            {
                unset($this->stored[$path]);
            }

            public function url(string $path): string
            {
                return 'https://cdn.test/uploads/'.ltrim($path, '/');
            }
        };
        $manager = Mockery::mock(DriverManager::class)->makePartial();
        $manager->shouldReceive('storage')->andReturn($fake);
        $this->app->instance(DriverManager::class, $manager);

        // 清单写到临时文件，不动真实环境里的那份
        $this->manifest = tempnam(sys_get_temp_dir(), 'gm-demo-');
        unlink($this->manifest);
        DemoImportService::$manifestFile = $this->manifest;
    }

    protected function tearDown(): void
    {
        @unlink($this->manifest);
        DemoImportService::$manifestFile = null;
        parent::tearDown();
    }

    /** 测试用的固定示例。正式的演示数据（database/demo/pet-smart/data.json）会被不断改写，测试不依赖它的内容 */
    private const SAMPLE = 'tests/Fixtures/demo/data.json';

    private function sample(): array
    {
        return app(DemoImportService::class)->read(base_path(self::SAMPLE));
    }

    /** 把一份数据写成临时文件，返回路径 */
    private function file(array $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'gm-demo-data-');
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE));

        return $path;
    }

    public function test_bundled_sample_imports_and_shows_up_for_buyers(): void
    {
        $this->artisan('mall:demo-import', ['path' => self::SAMPLE, '--check' => true])->expectsOutput('检查通过，可以导入')->assertSuccessful();
        $this->assertSame(0, Goods::query()->count(), '--check 不写入');

        $this->artisan('mall:demo-import', ['path' => self::SAMPLE])->expectsOutputToContain('导入完成：分类 5、品牌 2、商品 3、文章分类 2、文章 3、轮播图 2')->assertSuccessful();

        // 商品列表和详情：多语言、分币种定价、占位图、销量
        $list = $this->api('GET', '/goods?size=50');
        $this->assertSame(3, $list->json('data.total'));
        $feeder = Goods::query()->where('goods_sn', 'DEMO-feeder-5l-cam')->firstOrFail();
        $zh = $this->api('GET', "/goods/{$feeder->id}")->json('data');
        $this->assertSame(['PawLink 可视自动喂食器 5L', 1280], [$zh['title'], (int) $zh['sales']]);
        $this->assertCount(4, $zh['images']);
        $this->assertStringEndsWith('.svg', $zh['images'][0]);
        $en = $this->api('GET', "/goods/{$feeder->id}", [], null, ['X-Lang' => 'en', 'X-Currency' => 'USD'])->json('data');
        $this->assertSame('PawLink 5L Automatic Feeder with Camera', $en['title']);
        $this->assertSame('89.99', (string) $en['skus'][0]['price'], '美元用的是数据里单独给的价格，不是按汇率折算的');

        // 多规格商品：2 个规格、4 个 SKU，库存各自独立
        $fountain = Goods::query()->where('goods_sn', 'DEMO-fountain-2l')->firstOrFail();
        $detail = $this->api('GET', "/goods/{$fountain->id}")->json('data');
        $this->assertSame([2, 4], [count($detail['specs']), count($detail['skus'])]);
        $this->assertSame(410, (int) DB::table('goods_sku')->where('goods_id', $fountain->id)->sum('stock'));

        // 占位图是一张写着商品名的 SVG
        $svg = $this->stored[collect(array_keys($this->stored))->first(fn ($p) => str_contains($p, 'goods-feeder-5l-cam-1'))];
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('PawLink', $svg);
        // 轮播图和分类图是不带字的装饰图：页面会自己把标题压在图上
        $banner = $this->stored[collect(array_keys($this->stored))->first(fn ($p) => str_contains($p, 'banner-0-zh-CN'))];
        $this->assertStringContainsString('<svg', $banner);
        $this->assertStringNotContainsString('<text', $banner);
        $this->assertStringNotContainsString('<text', $this->stored[collect(array_keys($this->stored))->first(fn ($p) => str_contains($p, 'category-feeding'))]);

        // 分类是两级；文章、公告、帮助、轮播图都在
        $tree = $this->api('GET', '/categories')->json('data');
        $this->assertSame(['智能喂养', '智能清洁'], array_column($tree, 'name'));
        $this->assertSame(['自动喂食器', '智能饮水机'], array_column($tree[0]['children'], 'name'));
        $this->assertSame(1, $this->api('GET', '/articles?type=article')->json('data.total'));
        $this->assertSame(1, $this->api('GET', '/articles?type=notice')->json('data.total'));
        $banners = $this->api('GET', '/ads?position=home_banner')->json('data.home_banner');
        $this->assertCount(2, $banners);
        // 轮播图用 code 引用的商品已经换成了真实的商品 ID
        $litter = (int) Goods::query()->where('goods_sn', 'DEMO-litter-box-pro')->value('id');
        $this->assertContains((string) $litter, array_map('strval', array_column($banners, 'link_value')));

        // 导入的商品能正常下单
        [$m, $token] = $this->makeMember();
        $sku = (int) DB::table('goods_sku')->where('goods_id', $feeder->id)->value('id');
        $order = $this->placeOrder($token, $sku, 1, (int) $this->makeAddress($m->id)->id);
        $this->assertSame('599.00', (string) $order->goods_amount);
    }

    public function test_importing_again_replaces_the_previous_batch_and_clear_removes_it(): void
    {
        // 库里原本就有的数据不受影响
        $ownSku = $this->makeSku('10.00', 5);
        $ownGoods = (int) DB::table('goods_sku')->where('id', $ownSku)->value('goods_id');

        $this->artisan('mall:demo-import', ['path' => self::SAMPLE])->assertSuccessful();
        $first = Goods::query()->where('goods_sn', 'like', 'DEMO-%')->pluck('id')->all();
        $files = count($this->stored);

        $this->artisan('mall:demo-import', ['path' => self::SAMPLE])->assertSuccessful();
        $second = Goods::query()->where('goods_sn', 'like', 'DEMO-%')->pluck('id')->all();
        $this->assertCount(3, $second);
        $this->assertSame([], array_intersect($first, $second), '第二次导入的是新的一批');
        $this->assertSame(4, $this->api('GET', '/goods?size=50')->json('data.total'), '3 个演示商品 + 原有的 1 个，没有重复');
        $this->assertCount($files, $this->stored, '上一批的占位图已经删掉');
        $this->assertCount(2, $this->api('GET', '/ads?position=home_banner')->json('data.home_banner'));

        $this->artisan('mall:demo-import', ['--clear' => true])->expectsOutputToContain('已清除')->assertSuccessful();
        $this->assertSame([$ownGoods], array_column($this->api('GET', '/goods?size=50')->json('data.list'), 'id'));
        $this->assertSame([], $this->api('GET', '/categories')->json('data') === [] ? [] : array_filter(array_column($this->api('GET', '/categories')->json('data'), 'name'), fn ($n) => str_starts_with((string) $n, '智能')));
        $this->assertSame(0, $this->api('GET', '/articles?type=article')->json('data.total'));
        $this->assertSame([], $this->stored);
        $this->assertFileDoesNotExist($this->manifest);

        // 没有可清的也不报错
        $this->artisan('mall:demo-import', ['--clear' => true])->expectsOutput('已清除 0 条演示数据')->assertSuccessful();
    }

    public function test_problems_are_reported_together_and_nothing_is_imported(): void
    {
        $data = $this->sample();
        $data['goods'][0]['category'] = 'no-such-category';
        $data['goods'][0]['prices'] = ['JPY' => '9999'];
        $data['goods'][0]['price'] = 'free';
        $data['goods'][1]['skus'][1]['values'] = ['white', '2l'];   // 和第一个 SKU 重复
        $data['goods'][2]['title'] = ['en' => 'Only English'];      // 缺默认语言
        $data['goods'][] = ['code' => 'Bad Code!', 'category' => 'feeder', 'title' => ['zh-CN' => '编码不合法'], 'price' => '1'];
        $data['goods'][] = $data['goods'][2];                        // code 重复
        $data['articles'][0]['type'] = 'blog';
        $data['banners'][0]['link'] = ['type' => 'goods', 'value' => 'ghost'];
        $data['banners'][1]['position'] = 'nowhere';

        $problems = app(DemoImportService::class)->check($data);
        $text = implode("\n", $problems);
        foreach (['no-such-category', 'JPY', 'goods[feeder-5l-cam]', 'SKU 重复', 'goods[litter-box-pro]', 'Bad Code!', 'code 重复：litter-box-pro', 'articles[choose-feeder]', 'ghost', 'position 不存在'] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }

        $this->artisan('mall:demo-import', ['path' => $this->file($data), '--check' => true])->assertFailed();
        $this->artisan('mall:demo-import', ['path' => $this->file($data)])->assertFailed();
        $this->assertSame(0, Goods::query()->count());
        $this->assertSame(0, DB::table('category')->count());
        $this->assertSame([], $this->stored);

        // 文件不存在、不是 JSON
        $this->artisan('mall:demo-import', ['path' => 'database/demo/none'])->expectsOutputToContain('找不到数据文件')->assertFailed();
        $bad = tempnam(sys_get_temp_dir(), 'gm-demo-bad-');
        file_put_contents($bad, '{not json');
        $this->artisan('mall:demo-import', ['path' => $bad])->expectsOutputToContain('不是合法的 JSON')->assertFailed();
    }

    public function test_production_needs_force(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('mall:demo-import', ['path' => self::SAMPLE])->expectsOutputToContain('--force')->assertFailed();
        $this->artisan('mall:demo-import', ['--clear' => true])->assertFailed();
        $this->assertSame(0, Goods::query()->count());
        // 只检查不写入，生产环境也可以
        $this->artisan('mall:demo-import', ['path' => self::SAMPLE, '--check' => true])->assertSuccessful();
    }
}
