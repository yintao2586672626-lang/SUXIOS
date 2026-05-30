<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;

class OtaTrafficUrlNormalizer
{
    private const CTRIP_TRAFFIC_URL = 'https://ebooking.ctrip.com/datacenter/api/inland/marketanalysis/flowanalysis/queryFlowTransforNewV1?hostType=Ebooking';
    private const CTRIP_TRAFFIC_ENDPOINTS = [
        'queryFlowTransforNewV1',
        'queryScanFlowDetailsV2',
        'queryFlowTransforNew',
        'queryHomePageRealTimeData',
        'getFlowData',
        'getTrafficData',
        'getStatData',
    ];

    public static function normalizeCtripTrafficUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            $url = self::CTRIP_TRAFFIC_URL;
        }

        $url = preg_replace('/\s+/', '', $url) ?? $url;
        $random = rtrim(rtrim(sprintf('%.12F', mt_rand() / mt_getrandmax()), '0'), '.');

        $hasAllowedEndpoint = false;
        foreach (self::CTRIP_TRAFFIC_ENDPOINTS as $endpoint) {
            if (stripos($url, $endpoint) !== false) {
                $hasAllowedEndpoint = true;
                break;
            }
        }

        if (!$hasAllowedEndpoint) {
            throw new InvalidArgumentException('Request URL 必须是携程流量数据接口');
        }

        if (stripos($url, 'hostType=') === false) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . 'hostType=Ebooking';
        }

        if (preg_match('/([?&])v=[^&]*/i', $url)) {
            return preg_replace('/([?&])v=[^&]*/i', '$1v=' . $random, $url) ?? $url;
        }

        return $url . (strpos($url, '?') === false ? '?' : '&') . 'v=' . $random;
    }
}
