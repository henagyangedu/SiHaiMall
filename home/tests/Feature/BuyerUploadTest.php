<?php

namespace Tests\Feature;

use App\Drivers\Contracts\StorageDriver;
use App\Drivers\DriverManager;
use App\Models\OrderRefund;
use App\Models\UploadFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\FeatureTestCase;

/** 买家上传图片，以及售后凭证只接受本人上传的图片 */
class BuyerUploadTest extends FeatureTestCase
{
    /** 1×1 的 PNG */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** @var array<string,int> 假存储里“保存”过的文件：路径 => 字节数 */
    private array $stored = [];

    protected function setUp(): void
    {
        parent::setUp();

        // 假的存储驱动：只记下路径，不往磁盘写文件
        $fake = new class($this->stored) implements StorageDriver
        {
            public function __construct(private array &$stored) {}

            public function code(): string
            {
                return 'local';
            }

            public function putFile(string $path, UploadedFile $file): void
            {
                $this->stored[$path] = (int) $file->getSize();
            }

            public function put(string $path, string $contents): void
            {
                $this->stored[$path] = strlen($contents);
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
    }

    private function png(string $name = 'proof.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG));
    }

    private function upload(?string $token, array $data, array $headers = [])
    {
        return $this->post('/api/upload/image', $data, $headers + ['Accept' => 'application/json'] + ($token ? ['Authorization' => "Bearer {$token}"] : []));
    }

    public function test_a_buyer_can_upload_an_image_into_their_own_folder(): void
    {
        [$m, $token] = $this->makeMember();

        $r = $this->upload($token, ['file' => $this->png(), 'scene' => 'refund']);

        $r->assertJsonPath('code', 0);
        $this->assertSame([1, 1], [$r->json('data.width'), $r->json('data.height')]);
        $this->assertStringStartsWith("https://cdn.test/uploads/member/{$m->id}/refund/", $r->json('data.url'));
        $this->assertStringEndsWith('.png', $r->json('data.url'));
        $this->assertCount(1, $this->stored);
        $row = UploadFile::query()->findOrFail($r->json('data.id'));
        $this->assertSame(['refund', 0, 'local'], [$row->scene, (int) $row->admin_id, $row->driver]);
        $this->assertArrayHasKey($row->path, $this->stored);
    }

    public function test_upload_requires_login_a_real_image_and_a_known_purpose(): void
    {
        [, $token] = $this->makeMember();

        $this->upload(null, ['file' => $this->png(), 'scene' => 'refund'])->assertJsonPath('code', 401);
        $this->upload($token, ['scene' => 'refund'])->assertJsonPath('code', 422);
        $this->upload($token, ['file' => $this->png(), 'scene' => 'goods'])->assertJsonPath('code', 422);
        // 改了扩展名的脚本（内容不是图片，服务层拦下）、不在白名单里的格式、超过 5MB 的文件都不收
        $this->upload($token, ['file' => UploadedFile::fake()->createWithContent('shell.png', '<?php echo 1;'), 'scene' => 'refund'])->assertJsonPath('code', 1);
        $this->upload($token, ['file' => UploadedFile::fake()->createWithContent('a.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'scene' => 'refund'])->assertJsonPath('code', 422);
        $this->upload($token, ['file' => UploadedFile::fake()->create('big.png', 6000, 'image/png'), 'scene' => 'refund'])->assertJsonPath('code', 422);

        $this->assertSame([], $this->stored);
        $this->assertSame(0, UploadFile::query()->count());
    }

    public function test_daily_upload_limit_per_member(): void
    {
        [$m, $token] = $this->makeMember();
        [, $other] = $this->makeMember();
        Cache::put("member_upload:{$m->id}:".date('Ymd'), 60, 86400);

        $limited = $this->upload($token, ['file' => $this->png(), 'scene' => 'refund'], ['X-Lang' => 'en']);
        $limited->assertJsonPath('code', 1);
        $this->assertDoesNotMatchRegularExpression('/\p{Han}/u', (string) $limited->json('msg'));

        // 别的会员不受影响
        $this->upload($other, ['file' => $this->png(), 'scene' => 'refund'])->assertJsonPath('code', 0);
    }

    public function test_refund_proof_images_must_be_the_buyers_own_uploads(): void
    {
        [$m, $token] = $this->makeMember();
        [, $otherToken] = $this->makeMember();
        $addr = $this->makeAddress($m->id);
        $order = $this->pay($this->placeOrder($token, $this->makeSku('100.00', 10), 2, $addr->id));
        $itemId = (int) DB::table('order_item')->where('order_id', $order->id)->value('id');
        $apply = fn (array $images) => $this->api('POST', "/orders/{$order->order_no}/refunds", ['order_item_id' => $itemId, 'qty' => 1, 'type' => 1, 'reason' => '有破损', 'images' => $images], $token);

        $mine = $this->upload($token, ['file' => $this->png(), 'scene' => 'refund'])->json('data.url');
        $theirs = $this->upload($otherToken, ['file' => $this->png(), 'scene' => 'refund'])->json('data.url');

        // 外站地址、别人的图、改过的地址、没上传过的路径都不收
        foreach ([
            ['https://evil.example/track.png'],
            [$theirs],
            [$mine.'?x=1'],
            ['https://evil.example/'.substr($mine, strpos($mine, 'member/'))],
            [str_replace('.png', 'x.png', $mine)],
            [$mine, 'javascript:alert(1)'],
        ] as $bad) {
            $apply($bad)->assertJsonPath('code', 1);
        }
        $this->assertSame(0, OrderRefund::query()->count());

        $ok = $apply([$mine]);
        $ok->assertJsonPath('code', 0);
        $detail = $this->api('GET', "/refunds/{$ok->json('data.refund_no')}", [], $token);
        $this->assertSame([$mine], $detail->json('data.images'));
    }

    public function test_refund_can_still_be_requested_without_images(): void
    {
        [$m, $token] = $this->makeMember();
        $addr = $this->makeAddress($m->id);
        $order = $this->pay($this->placeOrder($token, $this->makeSku('100.00', 10), 1, $addr->id));

        $this->api('POST', "/orders/{$order->order_no}/refunds", ['type' => 1, 'reason' => '不想要了'], $token)->assertJsonPath('code', 0);
    }
}
