<?php
declare(strict_types=1);

namespace app\controller\concern;

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

        return $this->error(
            'Legacy Ctrip comment Cookie/API config storage is disabled. Use Ctrip platform configuration and browser Profile collection.',
            410
        );
    }

    public function getCtripCommentConfigList(): Response
    {
        $this->checkPermission();
        return $this->success([]);
    }
}
