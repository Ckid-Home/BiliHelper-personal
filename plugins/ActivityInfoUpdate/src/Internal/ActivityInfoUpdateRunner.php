<?php declare(strict_types=1);

namespace Bhp\Plugin\Builtin\ActivityInfoUpdate\Internal;

use Bhp\Api\Api\X\Activity\ApiActivity;
use Bhp\Login\AuthFailureClassifier;
use Bhp\Runtime\AppContext;
use RuntimeException;

final class ActivityInfoUpdateRunner
{
    private const REFRESH_STATUS_REFRESHED = 'refreshed';
    private const REFRESH_STATUS_TRANSIENT_FAILURE = 'transient_failure';
    private const REFRESH_STATUS_EXPIRED = 'expired';
    private const REFRESH_STATUS_INVALID = 'invalid';

    private ?ApiActivity $apiActivity = null;
    /**
     * @var callable(string): string
     */
    private readonly mixed $pageHtmlFetcher;
    /**
     * @var callable(string, string, string): array<string, mixed>
     */
    private readonly mixed $lotteryInfoFetcher;
    /**
     * @var callable(): int
     */
    private readonly mixed $nowResolver;

    /**
     * 初始化 ActivityInfoUpdateRunner
     * @param AppContext $appContext
     */
    public function __construct(
        private readonly AppContext $appContext,
        private readonly ?string $resourcePath = null,
        ?callable $pageHtmlFetcher = null,
        ?callable $lotteryInfoFetcher = null,
        ?callable $nowResolver = null,
    ) {
        $this->pageHtmlFetcher = $pageHtmlFetcher ?? fn (string $url): string => $this->appContext->request()->getText('other', $url);
        $this->lotteryInfoFetcher = $lotteryInfoFetcher ?? fn (string $lotteryId, string $url, string $title): array => $this->apiActivity()->myTimes([
            'sid' => $lotteryId,
            'url' => $url,
            'title' => $title,
        ]);
        $this->nowResolver = $nowResolver ?? static fn (): int => time();
    }

    /**
     * @return array{path:string,total_urls:int,valid_records:int,skipped_urls:int}
     */
    public function update(?string $filePath = null): array
    {
        $this->assertAuthenticated();
        $now = $this->now();
        $resourcePath = $this->resourcePath();
        $catalogDocument = $this->loadCatalogDocument($resourcePath);
        $ignoredUrls = $this->loadIgnoredUrls($catalogDocument);
        $existing = $this->loadExistingData($catalogDocument);
        $existingByUrl = $this->indexRecordsByUrl($existing);
        $existingUrls = $this->extractUrlsFromRecords($existing);
        $ignoredRemovedUrls = array_values(array_filter(
            $existingUrls,
            fn (string $url): bool => in_array($url, $ignoredUrls, true),
        ));
        $fileUrls = $this->loadUrlsFromFile($filePath);
        $mergedUrls = $this->mergeUrls(
            $existingUrls,
            $fileUrls,
        );
        $ignoredMatchedUrls = array_values(array_filter(
            $mergedUrls,
            fn (string $url): bool => in_array($url, $ignoredUrls, true),
        ));
        $candidateUrls = array_values(array_filter(
            $mergedUrls,
            fn (string $url): bool => !in_array($url, $ignoredUrls, true),
        ));
        $newCandidateUrls = array_values(array_filter(
            $candidateUrls,
            fn (string $url): bool => !isset($existingByUrl[$url]),
        ));
        $existingCandidateCount = count($candidateUrls) - count($newCandidateUrls);

        $this->appContext->log()->recordInfo("活动索引: 本地索引载入 " . count($existing) . " 条记录，" . count($existingUrls) . " 条 URL，忽略名单 " . count($ignoredUrls) . " 条");
        if ($filePath !== null && trim($filePath) !== '') {
            $this->appContext->log()->recordInfo("活动索引: 文件源 {$filePath} 载入 " . count($fileUrls) . " 条 URL");
        } else {
            $this->appContext->log()->recordInfo('活动索引: 未提供 --file，本次仅基于现有 catalog.json 刷新');
        }
        $this->appContext->log()->recordInfo(
            "活动索引: URL 汇总 合并去重 " . count($mergedUrls)
            . " 条，忽略 " . count($ignoredMatchedUrls)
            . " 条，待更新 " . count($candidateUrls)
            . " 条，其中已存在 {$existingCandidateCount} 条，新增候选 " . count($newCandidateUrls) . " 条"
        );
        $this->logUrlItems('活动索引: 新增候选 URL', $newCandidateUrls);

        $records = [];
        $skipped = 0;
        $refreshedCount = 0;
        $fallbackKeptUrls = [];
        $expiredRemovedItems = [];
        $invalidRemovedItems = [];
        $droppedUrls = [];
        foreach ($candidateUrls as $url) {
            $existingRecord = $existingByUrl[$url] ?? null;
            $refreshResult = $this->buildRecord($url, $now);
            if ($refreshResult['status'] !== self::REFRESH_STATUS_REFRESHED) {
                if (
                    is_array($existingRecord)
                    && $refreshResult['status'] === self::REFRESH_STATUS_TRANSIENT_FAILURE
                    && !$this->isExpiredRecord($existingRecord, $now)
                ) {
                    $records[] = $existingRecord;
                    $fallbackKeptUrls[] = $url;
                } elseif (
                    $refreshResult['status'] === self::REFRESH_STATUS_EXPIRED
                    || (is_array($existingRecord) && $this->isExpiredRecord($existingRecord, $now))
                ) {
                    if (is_array($existingRecord)) {
                        $expiredReason = $refreshResult['status'] === self::REFRESH_STATUS_EXPIRED
                            ? ($refreshResult['reason'] !== '' ? $refreshResult['reason'] : '活动时间已结束')
                            : '缓存记录已过期';
                        $expiredRemovedItems[] = [
                            'url' => $url,
                            'reason' => $expiredReason,
                        ];
                    } else {
                        $droppedUrls[] = $url;
                    }
                } elseif ($refreshResult['status'] === self::REFRESH_STATUS_INVALID) {
                    if (is_array($existingRecord)) {
                        $invalidRemovedItems[] = [
                            'url' => $url,
                            'reason' => $refreshResult['reason'] !== ''
                                ? $refreshResult['reason']
                                : '活动信息失效',
                        ];
                    } else {
                        $droppedUrls[] = $url;
                    }
                } else {
                    $droppedUrls[] = $url;
                }
                $skipped++;
                continue;
            }

            $records[] = $refreshResult['record'];
            $refreshedCount++;
        }

        $persistedUrls = $this->extractUrlsFromRecords($records);
        $persistedNewUrls = array_values(array_filter(
            $persistedUrls,
            fn (string $url): bool => !isset($existingByUrl[$url]),
        ));
        $removedExistingUrls = array_values(array_filter(
            $existingUrls,
            fn (string $url): bool => !in_array($url, $persistedUrls, true),
        ));

        $payload = [
            'code' => 200,
            'remarks' => 'generated by mode:script activity:update-infos',
            'ignore_urls' => $ignoredUrls,
            'data' => $records,
        ];
        try {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        } catch (\Throwable $throwable) {
            throw new RuntimeException("活动索引序列化失败 {$throwable->getMessage()}", 0, $throwable);
        }

        $directory = dirname($resourcePath);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("活动索引目录创建失败 {$directory}");
        }
        if (file_put_contents($resourcePath, $json . PHP_EOL) === false) {
            throw new RuntimeException("活动索引写入失败 {$resourcePath}");
        }

        $this->appContext->log()->recordInfo("活动索引: 已更新 {$resourcePath}");
        $this->appContext->log()->recordInfo(
            "活动索引: 抓取完成 成功刷新 {$refreshedCount} 条，失败回退旧记录 " . count($fallbackKeptUrls)
            . " 条，过期移除 " . count($expiredRemovedItems)
            . " 条，失效移除 " . count($invalidRemovedItems)
            . " 条，忽略移除 " . count($ignoredRemovedUrls)
            . " 条，失败丢弃 " . count($droppedUrls) . " 条"
        );
        $this->appContext->log()->recordInfo(
            "活动索引: 写回结果 当前保留 " . count($records)
            . " 条记录，新增 " . count($persistedNewUrls)
            . " 条，移除 " . count($removedExistingUrls)
            . " 条"
        );
        $this->logUrlList('活动索引: 回退保留旧记录 URL', $fallbackKeptUrls);
        $this->logUrlReasonItems('活动索引: 本次过期移除 URL', $expiredRemovedItems);
        $this->logUrlReasonItems('活动索引: 本次失效移除 URL', $invalidRemovedItems);
        $this->logUrlList('活动索引: 本次忽略移除 URL', $ignoredRemovedUrls);
        $this->logUrlList('活动索引: 抓取失败未保留 URL', $droppedUrls);
        $this->logUrlItems('活动索引: 本次新增写入 URL', $persistedNewUrls);
        $this->logUrlList('活动索引: 本次移除 URL', $removedExistingUrls);

        return [
            'path' => $resourcePath,
            'total_urls' => count($candidateUrls),
            'valid_records' => count($records),
            'skipped_urls' => $skipped,
        ];
    }

    /**
     * 断言Authenticated
     * @return void
     */
    private function assertAuthenticated(): void
    {
        $cookie = $this->appContext->auth('cookie');
        if ($cookie === '' || $this->appContext->csrf() === '' || $this->appContext->uid() === '') {
            throw new RuntimeException('activity:update-infos 需要当前 profile 已登录');
        }
    }

    /**
     * 处理资源Path
     * @return string
     */
    private function resourcePath(): string
    {
        if ($this->resourcePath !== null && trim($this->resourcePath) !== '') {
            return str_replace('\\', '/', $this->resourcePath);
        }

        $appRoot = rtrim(str_replace('\\', '/', $this->appContext->appRoot()), '/');

        return $appRoot . '/resources/plugins/ActivityLottery/catalog.json';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadExistingData(array $decoded): array
    {
        $data = $decoded['data'] ?? [];
        if (!is_array($data)) {
            return [];
        }

        return array_values(array_filter($data, static fn (mixed $item): bool => is_array($item)));
    }

    /**
     * @return string[]
     */
    private function loadIgnoredUrls(array $decoded): array
    {
        $ignoreUrls = $decoded['ignore_urls'] ?? [];
        if (!is_array($ignoreUrls)) {
            return [];
        }

        return $this->normalizeUrlList(array_map(
            static fn (mixed $url): string => is_scalar($url) ? (string)$url : '',
            $ignoreUrls,
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @return array<string, array<string, mixed>>
     */
    private function indexRecordsByUrl(array $records): array
    {
        $indexed = [];
        foreach ($records as $record) {
            $url = $this->normalizeUrl((string)($record['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $indexed[$url] = $record;
        }

        return $indexed;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @return string[]
     */
    private function extractUrlsFromRecords(array $records): array
    {
        $urls = [];
        foreach ($records as $record) {
            $url = $this->normalizeUrl((string)($record['url'] ?? ''));
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * @return string[]
     */
    private function loadUrlsFromFile(?string $path): array
    {
        if ($path === null || trim($path) === '') {
            return [];
        }

        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException("链接文件不可读取 {$path}");
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $urls = [];
        foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $urls[] = $line;
        }

        return $this->normalizeUrlList($urls);
    }

    /**
     * @param string[] ...$urlLists
     * @return string[]
     */
    private function mergeUrls(array ...$urlLists): array
    {
        $merged = [];
        $seen = [];
        foreach ($urlLists as $urlList) {
            foreach ($urlList as $url) {
                if ($url === '' || isset($seen[$url])) {
                    continue;
                }

                $seen[$url] = true;
                $merged[] = $url;
            }
        }

        return $merged;
    }

    /**
     * @param string[] $urls
     * @return string[]
     */
    private function normalizeUrlList(array $urls): array
    {
        $normalized = [];
        foreach ($urls as $url) {
            $value = $this->normalizeUrl($url);
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param string[] $urls
     * @return void
     */
    private function logUrlList(string $prefix, array $urls): void
    {
        if ($urls === []) {
            return;
        }

        $this->appContext->log()->recordInfo($prefix . ' [' . count($urls) . ']: ' . implode(', ', $urls));
    }

    /**
     * @param string[] $urls
     * @return void
     */
    private function logUrlItems(string $prefix, array $urls): void
    {
        foreach ($urls as $url) {
            $this->appContext->log()->recordInfo($prefix . ' ' . $url);
        }
    }

    /**
     * @param array<int, array{url:string, reason:string}> $items
     */
    private function logUrlReasonItems(string $prefix, array $items): void
    {
        foreach ($items as $item) {
            $url = trim((string)($item['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $reason = trim((string)($item['reason'] ?? ''));
            $suffix = $reason !== '' ? " [原因: {$reason}]" : '';
            $this->appContext->log()->recordInfo($prefix . ' ' . $url . $suffix);
        }
    }

    /**
     * 标准化URL
     * @param string $url
     * @return string
     */
    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }

        if (!preg_match('~^https?://~i', $url)) {
            return '';
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return '';
        }

        $host = strtolower((string)($parts['host'] ?? ''));
        $path = (string)($parts['path'] ?? '');
        if (!in_array($host, ['www.bilibili.com', 'live.bilibili.com'], true) || !str_contains($path, '/blackboard/era/')) {
            return '';
        }

        return 'https://' . $host . $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadCatalogDocument(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $throwable) {
            throw new RuntimeException("读取 ActivityLottery catalog.json 失败 {$throwable->getMessage()}");
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array{status:string,record:?array<string,mixed>,reason:string}
     */
    private function buildRecord(string $url, int $now): array
    {
        try {
            $html = ($this->pageHtmlFetcher)($url);
        } catch (\Throwable $throwable) {
            $this->appContext->log()->recordWarning("活动索引: 拉取活动页失败 {$url} -> {$throwable->getMessage()}");
            return [
                'status' => self::REFRESH_STATUS_TRANSIENT_FAILURE,
                'record' => null,
                'reason' => '活动页拉取失败',
            ];
        }

        $page = (new EraActivityPageParser())->parse($html);
        if ($page === null) {
            $this->appContext->log()->recordWarning("活动索引: 活动页解析失败 {$url}");
            return [
                'status' => self::REFRESH_STATUS_TRANSIENT_FAILURE,
                'record' => null,
                'reason' => '活动页解析失败',
            ];
        }

        $lotteryId = trim($page->lotteryId);
        if ($lotteryId === '') {
            $this->appContext->log()->recordWarning("活动索引: 活动缺少抽奖ID {$url}");
            return [
                'status' => self::REFRESH_STATUS_INVALID,
                'record' => null,
                'reason' => '活动缺少抽奖ID',
            ];
        }

        $response = ($this->lotteryInfoFetcher)($lotteryId, $url, $page->title);
        (new AuthFailureClassifier())->assertNotAuthFailure($response, "活动索引: 获取{$page->title}抽奖信息时账号未登录");
        if (($response['code'] ?? -1) !== 0 || !is_array($response['data'] ?? null)) {
            $code = $response['code'] ?? 'unknown';
            $message = is_string($response['message'] ?? null) ? $response['message'] : 'unknown';
            $this->appContext->log()->recordWarning("活动索引: 抽奖信息获取失败 {$page->title} {$code} -> {$message}");
            return [
                'status' => self::REFRESH_STATUS_TRANSIENT_FAILURE,
                'record' => null,
                'reason' => '抽奖信息获取失败',
            ];
        }

        $startTime = (int)($response['data']['stime'] ?? 0);
        $endTime = (int)($response['data']['etime'] ?? 0);
        if ($startTime <= 0 || $endTime <= 0) {
            $this->appContext->log()->recordWarning("活动索引: 活动时间窗口无效 {$page->title}");
            return [
                'status' => self::REFRESH_STATUS_INVALID,
                'record' => null,
                'reason' => '活动时间窗口无效',
            ];
        }

        if ($endTime <= $now) {
            return [
                'status' => self::REFRESH_STATUS_EXPIRED,
                'record' => null,
                'reason' => '活动时间已结束',
            ];
        }

        return [
            'status' => self::REFRESH_STATUS_REFRESHED,
            'record' => [
                'title' => trim($page->title),
                'url' => $url,
                'page_id' => trim($page->pageId),
                'activity_id' => trim($page->activityId),
                'lottery_id' => $lotteryId,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'update_time' => date('Y-m-d H:i:s', $now),
            ],
            'reason' => '',
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    private function isExpiredRecord(array $record, int $now): bool
    {
        return (int)($record['end_time'] ?? 0) > 0
            && (int)($record['end_time'] ?? 0) <= $now;
    }

    private function now(): int
    {
        return max(0, (int)($this->nowResolver)());
    }

    /**
     * 处理APIActivity
     * @return ApiActivity
     */
    private function apiActivity(): ApiActivity
    {
        return $this->apiActivity ??= new ApiActivity($this->appContext->request());
    }
}
