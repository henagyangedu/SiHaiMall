<?php

namespace App\Console\Commands;

use App\Services\Admin\DemoImportService;
use App\Services\Common\LocaleService;
use App\Support\Exceptions\BusinessException;
use Illuminate\Console\Command;

class DemoImport extends Command
{
    protected $signature = 'mall:demo-import
        {path=database/demo/pet-smart : 数据文件，或放着 data.json 的目录（相对后端目录或绝对路径）}
        {--check : 只检查数据有没有问题，不写入}
        {--clear : 只清掉上一次导入的演示数据，不导入}
        {--force : 生产环境也执行}';

    protected $description = '导入演示数据（分类、品牌、商品、文章、轮播图），格式见 docs/演示数据格式.md。再次导入会先清掉上一批';

    public function handle(DemoImportService $service, LocaleService $locale): int
    {
        if (app()->isProduction() && !$this->option('force') && !$this->option('check')) {
            $this->error('当前是生产环境。确实要在这里导入或清除演示数据的话，加上 --force');

            return self::FAILURE;
        }

        try {
            if ($this->option('clear')) {
                $r = $service->clear();
                $this->info("已清除 {$r['removed']} 条演示数据");
                $this->reportKept($r['kept']);

                return self::SUCCESS;
            }

            $path = (string) $this->argument('path');
            $data = $service->read(is_file($path) || is_dir($path) ? $path : base_path($path));

            if ($this->option('check')) {
                $problems = $service->check($data);
                if ($problems === []) {
                    $this->info('检查通过，可以导入');

                    return self::SUCCESS;
                }
                $this->error('发现 ' . count($problems) . ' 处问题：');
                foreach ($problems as $p) {
                    $this->line('- ' . $p);
                }

                return self::FAILURE;
            }

            $n = $service->import($data);
            // 价格服务、语言币种都有缓存，导入后清一下，页面马上能看到
            $locale->flush();
            $this->info(sprintf(
                '导入完成：分类 %d、品牌 %d、商品 %d、文章分类 %d、文章 %d、轮播图 %d，占位图 %d 张',
                $n['categories'], $n['brands'], $n['goods'], $n['article_categories'], $n['articles'], $n['ads'], $n['images']
            ));
        } catch (BusinessException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @param string[] $kept */
    private function reportKept(array $kept): void
    {
        if ($kept !== []) {
            $this->warn('下面这些后来被别的数据用到了，没有删除：');
            foreach ($kept as $k) {
                $this->line('- ' . $k);
            }
        }
    }
}
