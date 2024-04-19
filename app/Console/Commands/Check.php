<?php

namespace App\Console\Commands;

use App\Enums\SpiderTypeEnum;
use App\Models\Site;
use Carbon\Carbon;
use Exception;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class Check extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check {--failed} {--domain=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '执行';

    /**
     * 超时时间。单位：秒。
     * @var int
     */
    public const TIMEOUT = 30;

    /**
     * 最大延迟更新天数
     */
    public const MAX_DELAY_UPDATE_DAYS = 2;

    public GuzzleClient $guzzleClient;

    public Crawler $crawler;

    public Site $site;

    /**
     * 站点是否有更新
     * @var bool
     */
    public bool $isUpdated = false;

    /**
     * 是否在线
     * @var bool
     */
    public bool $isOnline = false;

    /**
     * HTML 源码
     * @var string
     */
    public string $html = '';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->guzzleClient = new GuzzleClient([
            'timeout' => self::TIMEOUT,
        ]);
    }

    /**
     * Execute the console command.
     *
     * @throws GuzzleException
     * @throws Exception
     */
    public function handle(): void
    {
        $checkFailed = $this->option('failed');
        $onlyTheDomain = $this->option('domain');
        $sites = $this->getSitesToCheck($checkFailed, $onlyTheDomain);

        foreach ($sites as $site) {
            $this->site = $site;
            echo $site->domain;

            $this->isUpdated = false;

            $isOnline = $this->getIsOnline();

            if ($isOnline) {
                $this->isOnline = true;

                // 检查日期状态
                if (self::needCheckDate($site)) {
                    try {
                        $date = $this->crawler->filterXPath($site->date_xpath)->text();
                        $site->last_updated_at = $date;
                        $this->isUpdated = $this->checkDateStatus($date);
                    } catch (Exception) {
                        $this->isUpdated = false;
                    }
                }

                // 验证是否包含关键字
                $this->checkKeyword($site);
            }

            // 保存
            $site->is_online = $this->isOnline;
            $site->is_new = $this->isUpdated;
            $site->save();

            echo $site->is_online ? ' ✅ ' : ' ❌ ';
            echo $site->isUpdated ? ' ✅ ' : ' ❌ ';
            echo PHP_EOL;
        }

        // 重新检查一遍失败的
        if ($checkFailed === false && $onlyTheDomain === false) {
            Artisan::call('site:check', ['--failed' => true]);
        }
    }

    /**
     * @return bool 是否在线
     * @throws GuzzleException
     */
    public function getIsOnline(): bool
    {
        $site = $this->site;

        $url = $site->spider_type === SpiderTypeEnum::API ? $site->domain.$site->path : $site->domain;

        try {
            $response = $this->guzzleClient->request('GET', $url);
        } catch (Exception  $e) {
            // 跳过证书 CA 不被信任
            if (str_contains($e->getMessage(), 'unable to get local issuer certificate')) {
                echo '证书不被信任'.PHP_EOL;

                $client = new GuzzleClient([
                    'timeout' => self::TIMEOUT,
                    'verify' => false,
                ]);
                $response = $client->request('GET', $url);
            } else {
                echo $e->getMessage().PHP_EOL;
                Log::info($e->getMessage());

                return false;
            }
        }

        $this->html = $response->getBody()->getContents();
        $this->crawler = new Crawler($this->html);

        return $site->spider_type === SpiderTypeEnum::API
            ? ! empty($this->crawler->text())
            : $response->getStatusCode() === 200;
    }

    public function checkDateStatus($date): bool
    {
        $targetDate = Carbon::createFromFormat($this->site->date_format ?? 'Y-m-d H:i:s', $date);
        if ($targetDate === false) {
            return false;
        }

        $diff = Carbon::now()->diffInDays($targetDate);

        return $diff <= self::MAX_DELAY_UPDATE_DAYS;
    }

    /**
     * 是否需要检查更新日期
     * @param  Site  $site
     * @return bool
     */
    public static function needCheckDate(Site $site): bool
    {
        return $site->date_xpath || $site->path;
    }

    protected function getSitesToCheck(bool $checkFailed, string $onlyTheDomain = null): iterable
    {
        if ($checkFailed) {
            return Site::failed()->get();
        } elseif ($onlyTheDomain) {
            $this->info('只检查：'.$onlyTheDomain);

            return Site::where('domain', $onlyTheDomain)->get();
        } else {
            return Site::all();
        }
    }

    /**
     * 检查站点是否包含关键字
     *
     * @param  Site  $site
     */
    protected function checkKeyword(Site $site): void
    {
        if (is_null($site->need_string)) {
            return;
        }

        // 如果需要匹配是否包含或者不包含某个关键字
        $isInclude = str_contains($this->html, $site->need_string);
        $this->isOnline = $isInclude;
    }
}
