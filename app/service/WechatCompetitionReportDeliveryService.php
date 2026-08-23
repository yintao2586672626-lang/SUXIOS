<?php
declare(strict_types=1);

namespace app\service;

/**
 * Delivers one saved competition report as a compact text message followed by
 * a table-like PNG. Both parts share the same bundle and durable delivery gate.
 */
final class WechatCompetitionReportDeliveryService
{
    public function __construct(
        private readonly ?WechatCompetitionReportRendererService $renderer = null,
        private readonly ?WechatCompetitionVisualCardService $visualCard = null,
        private readonly ?CloudAutomationService $automation = null
    ) {
    }

    /**
     * @param array<string, mixed> $report
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function deliver(
        array $report,
        int $hotelId,
        string $hotelName,
        string $edition,
        array $context = []
    ): array {
        $reportId = (int)($report['id'] ?? 0);
        $reportDate = trim((string)($report['report_date'] ?? ''));
        if ($hotelId <= 0 || $reportId <= 0 || $reportDate === '') {
            throw new \InvalidArgumentException('saved_competition_report_identity_invalid');
        }
        if (($report['competition_bundle_readback']['exact_readback_verified'] ?? false) !== true) {
            throw new \RuntimeException('competition_bundle_exact_readback_required');
        }

        $renderer = $this->renderer ?? new WechatCompetitionReportRendererService();
        $visualCard = $this->visualCard ?? new WechatCompetitionVisualCardService();
        $automation = $this->automation ?? new CloudAutomationService();
        $rendered = $renderer->renderCompact($report, $hotelName, $edition);
        $baseContext = array_merge($context, [
            'report_edition' => (string)$rendered['report_edition'],
            'status_only' => (bool)$rendered['status_only'],
            'source_fingerprint' => (string)$rendered['source_fingerprint'],
            'bundle_id' => (string)$rendered['bundle_id'],
        ]);

        $textDelivery = $automation->deliverSavedDailyReport(
            $hotelId,
            $reportId,
            $reportDate,
            $rendered['payload'],
            array_merge($baseContext, ['artifact_kind' => 'summary_text'])
        );
        $textStatus = (string)($textDelivery['delivery_status'] ?? 'failed');
        if ($textStatus !== 'sent') {
            return $this->aggregate($hotelId, $rendered, $textDelivery, null, null);
        }

        $visualMeta = null;
        try {
            $model = $visualCard->buildModel($report, $hotelName, $edition);
            $image = $visualCard->renderImagePayload($model);
            $visualMeta = [
                'generated' => true,
                'image_bytes' => (int)$image['image_bytes'],
                'image_md5' => (string)$image['image_md5'],
                'schema' => (string)($model['schema'] ?? ''),
            ];
            $visualDelivery = $automation->deliverSavedDailyReport(
                $hotelId,
                $reportId,
                $reportDate,
                $image['payload'],
                array_merge($baseContext, ['artifact_kind' => 'visual_card'])
            );
        } catch (\Throwable $exception) {
            $visualDelivery = [
                'status' => 'render_failed',
                'delivery_status' => 'render_failed',
                'hotel_id' => $hotelId,
                'robot_count' => 0,
                'sent_count' => 0,
                'failed_count' => 0,
                'failures' => [],
                'reason' => 'competition_visual_card_render_failed',
                'error_code' => mb_substr($exception->getMessage(), 0, 180, 'UTF-8'),
            ];
            $visualMeta = ['generated' => false];
        }

        return $this->aggregate($hotelId, $rendered, $textDelivery, $visualDelivery, $visualMeta);
    }

    /**
     * @param array<string, mixed> $rendered
     * @param array<string, mixed> $textDelivery
     * @param array<string, mixed>|null $visualDelivery
     * @param array<string, mixed>|null $visualMeta
     * @return array<string, mixed>
     */
    private function aggregate(
        int $hotelId,
        array $rendered,
        array $textDelivery,
        ?array $visualDelivery,
        ?array $visualMeta
    ): array {
        $textStatus = (string)($textDelivery['delivery_status'] ?? 'failed');
        $visualStatus = $visualDelivery === null
            ? 'not_attempted'
            : (string)($visualDelivery['delivery_status'] ?? 'failed');
        $status = $textStatus;
        if ($textStatus === 'sent' && $visualStatus === 'sent') {
            $status = 'sent';
        } elseif ($textStatus === 'sent') {
            $status = 'partial';
        }

        $parts = [
            'summary_text' => $this->partSummary($textDelivery),
            'visual_card' => $visualDelivery === null
                ? ['delivery_status' => 'not_attempted']
                : $this->partSummary($visualDelivery),
        ];
        $deliveries = array_values(array_filter([$textDelivery, $visualDelivery], 'is_array'));
        $failures = [];
        foreach ($deliveries as $delivery) {
            foreach ((array)($delivery['failures'] ?? []) as $failure) {
                if (is_array($failure)) {
                    $failures[] = $failure;
                }
            }
        }

        return [
            'status' => $status,
            'delivery_status' => $status,
            'hotel_id' => $hotelId,
            'robot_count' => max(
                (int)($textDelivery['robot_count'] ?? 0),
                (int)($visualDelivery['robot_count'] ?? 0)
            ),
            'sent_count' => array_sum(array_map(
                static fn(array $delivery): int => (int)($delivery['sent_count'] ?? 0),
                $deliveries
            )),
            'failed_count' => array_sum(array_map(
                static fn(array $delivery): int => (int)($delivery['failed_count'] ?? 0),
                $deliveries
            )),
            'failures' => $failures,
            'delivery_parts' => $parts,
            'visual_card' => $visualMeta ?? ['generated' => false],
            'report_edition' => (string)$rendered['report_edition'],
            'status_only' => (bool)$rendered['status_only'],
            'source_fingerprint' => (string)$rendered['source_fingerprint'],
            'bundle_id' => (string)$rendered['bundle_id'],
            'single_calculation' => true,
            'collection_triggered' => false,
            'report_generation_triggered' => false,
        ];
    }

    /** @param array<string, mixed> $delivery @return array<string, mixed> */
    private function partSummary(array $delivery): array
    {
        return [
            'delivery_status' => (string)($delivery['delivery_status'] ?? 'failed'),
            'robot_count' => (int)($delivery['robot_count'] ?? 0),
            'sent_count' => (int)($delivery['sent_count'] ?? 0),
            'failed_count' => (int)($delivery['failed_count'] ?? 0),
            'reason' => (string)($delivery['reason'] ?? ''),
            'error_code' => (string)($delivery['error_code'] ?? ''),
            'idempotent_replay' => ($delivery['idempotent_replay'] ?? false) === true,
        ];
    }
}
