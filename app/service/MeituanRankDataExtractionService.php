<?php
declare(strict_types=1);

namespace app\service;

final class MeituanRankDataExtractionService
{
    /**
     * @return array{rows: array<int, array<string, mixed>>, source: string}
     */
    public static function extractForPersistenceWithSource($responseData): array
    {
        if (!is_array($responseData)) {
            return ['rows' => [], 'source' => ''];
        }

        $peerRows = self::extractPeerRankRows($responseData['data']['peerRankData'] ?? null);
        if ($peerRows !== []) {
            return ['rows' => $peerRows, 'source' => 'data.peerRankData[].roundRanks'];
        }

        foreach ([
            'data.roundrank' => $responseData['data']['roundrank'] ?? null,
            'data.rankList' => $responseData['data']['rankList'] ?? null,
            'data.list' => $responseData['data']['list'] ?? null,
        ] as $source => $rows) {
            if (self::isRowList($rows)) {
                return ['rows' => $rows, 'source' => $source];
            }
        }

        if (isset($responseData['data']) && is_array($responseData['data'])) {
            if (self::isRowList($responseData['data'])) {
                return ['rows' => $responseData['data'], 'source' => 'data'];
            }

            $expandedRows = [];
            foreach ($responseData['data'] as $value) {
                if (is_array($value)) {
                    $expandedRows = array_merge($expandedRows, $value);
                }
            }
            if ($expandedRows !== []) {
                return ['rows' => $expandedRows, 'source' => 'data.*'];
            }
        }

        foreach ([
            'list' => $responseData['list'] ?? null,
            'roundrank' => $responseData['roundrank'] ?? null,
        ] as $source => $rows) {
            if (self::isRowList($rows)) {
                return ['rows' => $rows, 'source' => $source];
            }
        }

        return ['rows' => [], 'source' => ''];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function extractForPersistence($responseData): array
    {
        return self::extractForPersistenceWithSource($responseData)['rows'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function extractForDisplay($responseData): array
    {
        if (!is_array($responseData)) {
            return [];
        }

        foreach ([
            $responseData['data']['peerRankData'] ?? null,
            $responseData['data']['data']['peerRankData'] ?? null,
            $responseData['peerRankData'] ?? null,
        ] as $peerRankData) {
            $rows = self::extractPeerRankRows($peerRankData);
            if ($rows !== []) {
                return $rows;
            }
        }

        foreach ([
            $responseData['data']['roundrank'] ?? null,
            $responseData['data']['rankList'] ?? null,
            $responseData['data']['list'] ?? null,
            $responseData['data'] ?? null,
            $responseData['list'] ?? null,
            $responseData['roundrank'] ?? null,
        ] as $rows) {
            if (self::isRowList($rows)) {
                return $rows;
            }
        }

        return [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function extractPeerRankRows($peerRankData): array
    {
        if (!is_array($peerRankData)) {
            return [];
        }

        $rows = [];
        foreach ($peerRankData as $rankData) {
            if (!is_array($rankData) || !isset($rankData['roundRanks']) || !is_array($rankData['roundRanks'])) {
                continue;
            }

            foreach ($rankData['roundRanks'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $item['_dimName'] = $rankData['dimName'] ?? '';
                $item['_aiMetricName'] = $rankData['aiMetricName'] ?? '';
                $rows[] = $item;
            }
        }

        return $rows;
    }

    private static function isRowList($rows): bool
    {
        return is_array($rows) && isset($rows[0]) && is_array($rows[0]);
    }
}
