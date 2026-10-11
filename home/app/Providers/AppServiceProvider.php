<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 框架自己的文字（校验提示）按语言找文件时，带地区的语言编码先退到前半段：
        // es-MX 用 lang/es，zh-TW 这类用 lang/zh-CN，都没有才是英文
        $this->app->make('translator')->determineLocalesUsing(function (array $locales): array {
            $result = [];
            foreach ($locales as $locale) {
                $result[] = $locale;
                $base = strtolower(explode('-', (string) $locale)[0]);
                if ($base === 'zh') {
                    $result[] = 'zh-CN';
                } elseif ($base !== $locale) {
                    $result[] = $base;
                }
            }

            return array_values(array_unique($result));
        });
    }
}
