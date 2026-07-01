<?php

namespace app\controller\concern;

use app\service\OnlineTrafficDataExtractionService;

trait CtripCapturedPayloadConcern
{
    private function extractCtripCapturedSection(array $payload, string $section): array
    {
        $rows = [];
        foreach ($this->ctripCapturedSectionAliases($section) as $key) {
            if (array_key_exists($key, $payload)) {
                $rows = array_merge($rows, $this->normalizeCtripCapturedSectionList($payload[$key], $section));
            }
        }

        if (isset($payload['responses']) && is_array($payload['responses'])) {
            foreach ($payload['responses'] as $response) {
                if (!is_array($response) || !$this->ctripCaptureResponseMatchesSection($response, $section)) {
                    continue;
                }
                if ($section === 'business' && $this->isCtripOverviewSpecialResponse($response)) {
                    continue;
                }
                $data = $response['data'] ?? $response['body'] ?? $response['json'] ?? [];
                $rows = array_merge($rows, $this->normalizeCtripCapturedSectionList($data, $section));
            }
        }

        $seen = [];
        $deduped = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $identity = (string)($row['_fingerprint'] ?? md5(json_encode($row, JSON_UNESCAPED_UNICODE)));
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $deduped[] = $row;
        }
        return $deduped;
    }

    private function isCtripOverviewSpecialResponse(array $response): bool
    {
        $url = strtolower((string)($response['url'] ?? ''));
        foreach ([
            'getcompetehotelreportv1',
            'gethotwordsv1',
            'gethothotelsv1',
            'getflowhotelsv1',
            'getuserbehaviorv1',
            'getuserbehavorv1',
            'gettrafficreportv1',
            'getlastweekreportv1',
        ] as $needle) {
            if (str_contains($url, $needle)) {
                return true;
            }
        }

        $data = $response['data'] ?? $response['body'] ?? $response['json'] ?? [];
        return is_array($data) && isset($data['data']['hotRooms']) && is_array($data['data']['hotRooms']);
    }

    private function ctripCapturedSectionAliases(string $section): array
    {
        return match ($section) {
            'business' => ['business', 'overview', 'hotelList', 'marketOverview'],
            'traffic' => ['traffic', 'businessData', 'peerTrends', 'rankList', 'categoryRankList', 'competitionRankList'],
            default => [$section],
        };
    }

    private function normalizeCtripCapturedSectionList($value, string $section): array
    {
        if (!is_array($value)) {
            return [];
        }
        if ($this->isSequentialArray($value)) {
            $rows = [];
            foreach ($value as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if ($this->looksLikeCtripCapturedSectionRow($item, $section)) {
                    $rows[] = $item;
                } else {
                    $rows = array_merge($rows, $this->normalizeCtripCapturedSectionList($item, $section));
                }
            }
            return $rows;
        }

        if ($this->looksLikeCtripCapturedSectionRow($value, $section)) {
            return [$value];
        }

        foreach ($this->ctripCapturedListPaths($section) as $path) {
            $nested = $this->readNestedMeituanValue($value, $path);
            if (is_array($nested)) {
                $rows = $this->normalizeCtripCapturedSectionList($nested, $section);
                if (!empty($rows)) {
                    return $rows;
                }
            }
        }

        $rows = [];
        foreach ($value as $nested) {
            if (is_array($nested)) {
                $rows = array_merge($rows, $this->normalizeCtripCapturedSectionList($nested, $section));
            }
        }
        return $rows;
    }

    private function ctripCapturedListPaths(string $section): array
    {
        return match ($section) {
            'traffic' => [
                ['data', 'list'],
                ['data', 'rows'],
                ['data', 'traffic'],
                ['data', 'businessData'],
                ['data', 'peerTrends'],
                ['data', 'rankList'],
                ['data', 'ranking'],
                ['data', 'rankData'],
                ['data', 'categoryRank'],
                ['data', 'categoryRankList'],
                ['data', 'competitionRank'],
                ['data', 'competitionRankList'],
                ['data', 'competeRank'],
                ['data', 'competeRankList'],
                ['data', 'scanFlowDetails'],
                ['data', 'flowData'],
                ['data', 'trafficData'],
                ['data', 'statData'],
                ['result', 'list'],
                ['result', 'rows'],
                ['result', 'rankList'],
                ['list'],
                ['rows'],
                ['rankList'],
                ['categoryRankList'],
                ['competitionRankList'],
                ['data'],
            ],
            default => [
                ['data', 'hotelList'],
                ['data', 'list'],
                ['data', 'rows'],
                ['data', 'overview'],
                ['data', 'marketOverview'],
                ['data', 'flowHotelItemVos'],
                ['data', 'hotRooms'],
                ['result', 'hotelList'],
                ['result', 'list'],
                ['hotelList'],
                ['list'],
                ['rows'],
                ['data'],
            ],
        };
    }

    private function looksLikeCtripCapturedSectionRow(array $value, string $section): bool
    {
        $keys = $section === 'traffic'
            ? $this->ctripTrafficRowKeys()
            : [
                'amount', 'totalAmount', 'saleAmount', 'orderAmount', 'bookingAmount', 'gmv', '成交收入', '成交金额',
                'quantity', 'roomNights', '成交间夜',
                'bookOrderNum', 'orderCount', '订单数',
                'closeRate', 'averagePrice',
                'ordamount', 'ordquantity', 'comhtluv',
                'lossOrderVo', 'flowHotelItemVos', 'hotRooms',
                'lastWeekCommentScore', 'lastWeekCheckoutRoomNights', 'lastWeekBookQuantity',
                'totalListExposure', 'totalDetailExposure',
                'listExposure', 'detailExposure', 'flowRate', 'orderFillingNum', 'orderSubmitNum',
                'commentScore', 'uv', 'yesterdayUv', '昨日UV',
                'psi', 'PSI', 'serviceScore', 'ctripRatingall',
                'replyRate', 'replyrate5m', '回复率',
                'favoriteCount', 'hotelCollect', '收藏数',
                'visitorRank', '访客排名',
            ];
        foreach ($keys as $key) {
            if (array_key_exists($key, $value)) {
                return true;
            }
        }
        return false;
    }

    private function ctripTrafficRowKeys(): array
    {
        return OnlineTrafficDataExtractionService::ctripTrafficRowKeys();
    }

    private function ctripCaptureResponseMatchesSection(array $response, string $section): bool
    {
        $type = strtolower((string)($response['type'] ?? $response['section'] ?? ''));
        if ($type !== '' && in_array($type, $this->ctripCapturedSectionAliases($section), true)) {
            return true;
        }

        $url = strtolower((string)($response['url'] ?? ''));
        if ($url === '') {
            return false;
        }

        $needles = $section === 'traffic'
            ? ['queryscanflowdetailsv2', 'queryflowtransfornew', 'queryhomepagerealtimedata', 'getflowdata', 'gettrafficdata', 'getstatdata']
            : ['getdayreportrealtimedate', 'fetchmarketoverviewv2', 'getdayreportflowcompete', 'getdayreportserverquantity', 'fetchcurrenthotelseqinfov1', 'fetchvisitortitlev2', 'fetchcapacityoverviewv4', 'queryflowtransfornewv1', 'getcompetehotelreportv1', 'gethotwordsv1', 'gethothotelsv1', 'getflowhotelsv1', 'gethotroomsv1', 'getuserbehaviorv1', 'gettrafficreportv1', 'getlastweekreportv1', 'getdayreportcompetehotelreport'];
        foreach ($needles as $needle) {
            if (str_contains($url, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function extractCtripCapturedComments(array $payload): array
    {
        $rows = [];
        foreach (['reviews', 'comments', 'commentList', 'commentlist'] as $key) {
            if (array_key_exists($key, $payload)) {
                $rows = array_merge($rows, $this->normalizeCtripCapturedCommentList($payload[$key]));
            }
        }

        if (isset($payload['responses']) && is_array($payload['responses'])) {
            foreach ($payload['responses'] as $response) {
                if (!is_array($response)) {
                    continue;
                }
                $url = strtolower((string)($response['url'] ?? ''));
                $section = strtolower((string)($response['section'] ?? $response['type'] ?? ''));
                if ($section !== 'reviews' && !str_contains($url, 'getcommentlist')) {
                    continue;
                }
                $data = $response['data'] ?? $response['body'] ?? $response['json'] ?? [];
                $rows = array_merge($rows, $this->normalizeCtripCapturedCommentList($data));
            }
        }

        $seen = [];
        $deduped = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $identity = $this->extractCtripCommentId($row);
            if ($identity === '') {
                $identity = md5(json_encode([
                    $row['content'] ?? $row['commentContent'] ?? $row['_dom_text'] ?? '',
                    $row['user_name'] ?? $row['userName'] ?? '',
                    $row['comment_time'] ?? $row['commentTime'] ?? '',
                ], JSON_UNESCAPED_UNICODE));
            }
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $deduped[] = $row;
        }

        return $deduped;
    }

    private function normalizeCtripCapturedCommentList($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        if ($this->isSequentialArray($value)) {
            return array_values(array_filter($value, static fn($item): bool => is_array($item)));
        }

        $paths = [
            ['data', 'commentList'],
            ['data', 'commentlist'],
            ['data', 'comments'],
            ['data', 'list'],
            ['data', 'rows'],
            ['result', 'commentList'],
            ['result', 'commentlist'],
            ['result', 'comments'],
            ['result', 'list'],
            ['commentList'],
            ['commentlist'],
            ['comments'],
            ['list'],
            ['rows'],
            ['data'],
        ];
        foreach ($paths as $path) {
            $nested = $this->readNestedMeituanValue($value, $path);
            if (is_array($nested)) {
                $rows = $this->normalizeCtripCapturedCommentList($nested);
                if (!empty($rows)) {
                    return $rows;
                }
            }
        }

        return $this->looksLikeCtripCapturedCommentRow($value) ? [$value] : [];
    }

    private function looksLikeCtripCapturedCommentRow(array $value): bool
    {
        foreach (['review_id', 'reviewId', 'comment_id', 'commentId', 'id', 'commentContent', 'reviewContent', 'content', 'comment', 'score', 'rating', 'totalScore', 'commentScore'] as $key) {
            if (array_key_exists($key, $value)) {
                return true;
            }
        }
        return false;
    }
}
