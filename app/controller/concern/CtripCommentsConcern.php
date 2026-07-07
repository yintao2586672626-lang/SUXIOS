<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\model\OperationLog;
use think\Response;

trait CtripCommentsConcern
{
    public function fetchCtripComments(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        return $this->captureCtripCommentsBrowserData();
    }

    private function extractCtripCommentId(array $comment): string
    {
        foreach (['commentId', 'comment_id', 'id', 'reviewId', 'review_id', 'orderId'] as $field) {
            if (!empty($comment[$field])) {
                return (string)$comment[$field];
            }
        }
        return '';
    }

    private function normalizeOnlineDataDate($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $rawText = trim((string)$value);
        if (preg_match('/^(19|20)\d{6}$/', $rawText)) {
            $year = (int)substr($rawText, 0, 4);
            $month = (int)substr($rawText, 4, 2);
            $day = (int)substr($rawText, 6, 2);
            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }
        if (is_numeric($value)) {
            $timestamp = (int)$value;
            if ($timestamp > 9999999999) {
                $timestamp = (int)floor($timestamp / 1000);
            }
            return date('Y-m-d', $timestamp);
        }
        $text = $rawText;
        if (preg_match('/(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $text, $matches)) {
            return sprintf('%04d-%02d-%02d', (int)$matches[1], (int)$matches[2], (int)$matches[3]);
        }
        $timestamp = strtotime($text);
        return $timestamp === false ? '' : date('Y-m-d', $timestamp);
    }

    private function extractCtripCommentScore(array $comment): float
    {
        foreach (['score', 'rating', 'rate', 'totalScore', 'overallScore', 'commentScore', 'star'] as $field) {
            if (isset($comment[$field]) && is_numeric($comment[$field])) {
                $score = (float)$comment[$field];
                if ($score > 5 && $score <= 50) {
                    return round($score / 10, 1);
                }
                if ($score > 50 && $score <= 100) {
                    return round($score / 20, 1);
                }
                return round($score, 1);
            }
        }
        return 0.0;
    }

    public function saveCtripCommentConfig(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $data = $this->requestData();
        $systemHotelId = $this->resolveOnlineDataSystemHotelId(
            $data['system_hotel_id']
            ?? $data['systemHotelId']
            ?? $data['hotel_id']
            ?? $data['hotelId']
            ?? null
        );
        $config = [
            'request_url' => trim((string)($data['request_url'] ?? $data['requestUrl'] ?? $data['url'] ?? '')),
            'hotel_id' => trim((string)($data['hotel_id'] ?? $data['hotelId'] ?? $data['ctrip_hotel_id'] ?? $data['ctripHotelId'] ?? '')),
            'master_hotel_id' => trim((string)($data['master_hotel_id'] ?? $data['masterHotelId'] ?? '')),
            'profile_id' => trim((string)($data['profile_id'] ?? $data['profileId'] ?? '')),
            'cookies' => trim((string)($data['cookies'] ?? $data['cookie'] ?? '')),
            'spidertoken' => trim((string)($data['spidertoken'] ?? $data['spiderToken'] ?? $data['token'] ?? '')),
            'page_index' => (int)($data['page_index'] ?? $data['pageIndex'] ?? 1),
            'page_size' => (int)($data['page_size'] ?? $data['pageSize'] ?? 20),
            'payload_json' => is_array($data['payload_json'] ?? $data['payloadJson'] ?? null)
                ? json_encode($data['payload_json'] ?? $data['payloadJson'], JSON_UNESCAPED_UNICODE)
                : trim((string)($data['payload_json'] ?? $data['payloadJson'] ?? '')),
            '_fxpcqlniredt' => trim((string)($data['_fxpcqlniredt'] ?? '')),
            'x_trace_id' => trim((string)($data['x_trace_id'] ?? $data['xTraceId'] ?? '')),
            'tag_type' => trim((string)($data['tag_type'] ?? $data['tagType'] ?? '')),
            'capture_sections' => 'comment_review',
            'profile_sections' => 'comment_review',
            'system_hotel_id' => $systemHotelId,
            'scope' => 'ota_channel_review_summary',
            'privacy_boundary' => 'aggregate_metrics_only_no_review_text',
        ];

        $saved = $this->saveOtaDataConfigValue('ctrip-comments', $config, '携程点评聚合采集配置');
        OperationLog::record('online_data', 'save_ctrip_comment_config', '保存携程点评聚合采集配置', $this->currentUser->id);

        return $this->success($this->sanitizeSecretConfig($saved), '配置保存成功');
    }

    public function getCtripCommentConfigList(): Response
    {
        $this->checkPermission();
        $config = $this->readOtaDataConfigValue('ctrip-comments');
        return $this->success($config === [] ? [] : [$this->sanitizeSecretConfig($config)]);
    }
}
