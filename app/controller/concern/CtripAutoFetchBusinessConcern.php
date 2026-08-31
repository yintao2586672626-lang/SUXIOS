<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\service\CtripManualFetchRequestService;

trait CtripAutoFetchBusinessConcern
{
    private function executeCtripBusinessAutoFetchTask(string $label, array $body, int $hotelId): array
    {
        return $this->withAutoFetchCredential('ctrip', $body, $hotelId, function (array $credentialPayload) use ($label, $body, $hotelId): array {
            $cookieHeader = $this->autoFetchCredentialCookieHeader($credentialPayload);
            if ($cookieHeader === '') {
                return ['module' => $label, 'saved_count' => 0, 'success' => false, 'message' => 'credential_payload_missing_cookie'];
            }

            $startDate = (string)($body['start_date'] ?? '');
            $endDate = (string)($body['end_date'] ?? $startDate);
            $result = $this->sendHttpRequest(
                (string)($body['url'] ?? 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportCompeteHotelReport'),
                ['nodeId' => (string)($body['node_id'] ?? '24588'), 'startDate' => $startDate, 'endDate' => $endDate],
                $cookieHeader
            );
            if (empty($result['success']) || !is_array($result['data'] ?? null)) {
                return ['module' => $label, 'saved_count' => 0, 'success' => false, 'message' => 'ctrip_request_failed'];
            }

            $responseData = $result['data'];
            $responseStatus = $responseData['responseStatus'] ?? $responseData['status'] ?? $responseData['code'] ?? null;
            if ($responseStatus !== null && !in_array($responseStatus, [0, '0', 200, '200'], true)) {
                return ['module' => $label, 'saved_count' => 0, 'success' => false, 'message' => 'ctrip_api_rejected'];
            }
            $responseDateEvidence = CtripManualFetchRequestService::extractResponseDateEvidence($responseData);
            $responseDates = array_values(array_unique(array_column($responseDateEvidence, 'date')));
            $dateVerification = $startDate === $endDate
                ? CtripManualFetchRequestService::verifyResponseBusinessDate($startDate, $responseDates)
                : [
                    'status' => 'target_date_unverified',
                    'verified' => false,
                    'requested_date' => $startDate . ' 至 ' . $endDate,
                    'source_business_date' => null,
                    'response_dates' => $responseDates,
                    'reason' => 'multi_day_response_business_date_unverified',
                ];
            if (($dateVerification['verified'] ?? false) !== true) {
                return [
                    'module' => $label,
                    'saved_count' => 0,
                    'success' => false,
                    'status' => 'target_date_unverified',
                    'message' => (string)($dateVerification['reason'] ?? 'response_business_date_unverified'),
                    'response_dates' => (array)($dateVerification['response_dates'] ?? []),
                ];
            }

            $expectedPlatformHotelId = trim((string)(
                $credentialPayload['platform_hotel_id']
                ?? $credentialPayload['ctrip_hotel_id']
                ?? $credentialPayload['ota_hotel_id']
                ?? $credentialPayload['hotel_id']
                ?? ''
            ));
            $persistenceContext = [
                'ingestion_method' => 'manual_cookie_api',
                'config_id' => trim((string)($body['config_id'] ?? '')),
                'requested_business_date' => $startDate,
                'source_business_date' => (string)($dateVerification['source_business_date'] ?? ''),
                'response_dates' => (array)($dateVerification['response_dates'] ?? []),
                'response_date_evidence' => $responseDateEvidence,
                'date_verification_status' => (string)($dateVerification['status'] ?? 'target_date_unverified'),
            ];
            if ($this->isMeaningfulCtripPlatformHotelId($expectedPlatformHotelId, $hotelId)) {
                $persistenceContext['self_hotel_ids'] = [$expectedPlatformHotelId];
            }
            $savedCount = $this->parseAndSaveData($responseData, $startDate, $endDate, $hotelId, $persistenceContext);
            $taskResult = [
                'module' => $label,
                'saved_count' => $savedCount,
                'success' => $savedCount > 0,
                'message' => $savedCount > 0 ? 'ok' : 'no_rows',
                'credential_source' => 'vault',
            ];
            $runReadback = method_exists($this, 'lastCtripStructuredRunReadback')
                ? $this->lastCtripStructuredRunReadback()
                : [];
            if ($savedCount > 0 && !empty($runReadback['write_success'])) {
                $taskResult['run_readback'] = $runReadback;
            }
            return $taskResult;
        });
    }
}
