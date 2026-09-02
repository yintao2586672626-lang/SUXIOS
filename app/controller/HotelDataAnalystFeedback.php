<?php
declare(strict_types=1);

namespace app\controller;

use app\model\OperationLog;
use app\service\HotelDataAnalystFeedbackService;
use app\service\HotelScopeService;
use app\service\OperatingQuestionService;
use RuntimeException;
use think\Response;
use Throwable;

final class HotelDataAnalystFeedback extends Base
{
    private OperatingQuestionService $questionService;
    private HotelDataAnalystFeedbackService $feedbackService;
    private HotelScopeService $hotelScope;

    public function __construct(\think\App $app)
    {
        parent::__construct($app);
        $this->questionService = new OperatingQuestionService();
        $this->feedbackService = new HotelDataAnalystFeedbackService($this->questionService);
        $this->hotelScope = new HotelScopeService();
    }

    public function create(int $questionId): Response
    {
        try {
            [$question, $tenantId, $hotelIds] = $this->questionScope($questionId);
            $feedback = $this->feedbackService->save(
                $tenantId,
                $hotelIds,
                $questionId,
                (int)($this->currentUser->id ?? 0),
                $this->requestData()
            );
            OperationLog::record(
                'hotel_data_analyst',
                'feedback_recorded',
                'Record append-only hotel data analyst feedback',
                (int)($this->currentUser->id ?? 0),
                (int)$question['hotel_id'],
                null,
                [
                    'outcome' => 'success',
                    'tenant_id' => $tenantId,
                    'question_id' => $questionId,
                    'feedback_id' => (int)$feedback['id'],
                    'feedback_kind' => (string)$feedback['feedback_kind'],
                    'source_content_digest' => (string)$feedback['source_content_digest'],
                    'quality_receipt_digest' => (string)$feedback['quality_receipt_digest'],
                    'content_digest' => (string)$feedback['content_digest'],
                    'formal_evaluation_case_created' => false,
                    'model_training_triggered' => false,
                ]
            );
            return $this->success($feedback, '分析反馈已保存并精确回读');
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '分析反馈保存失败'), $this->status($e));
        }
    }

    public function mine(int $questionId): Response
    {
        try {
            [, $tenantId, $hotelIds] = $this->questionScope($questionId);
            return $this->success($this->feedbackService->listMine(
                $tenantId,
                $hotelIds,
                $questionId,
                (int)($this->currentUser->id ?? 0),
                (int)$this->request->param('limit', 20)
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '分析反馈查询失败'), $this->status($e));
        }
    }

    public function read(int $questionId, int $feedbackId): Response
    {
        try {
            [, $tenantId, $hotelIds] = $this->questionScope($questionId);
            return $this->success($this->feedbackService->read(
                $feedbackId,
                $tenantId,
                $hotelIds,
                $questionId,
                (int)($this->currentUser->id ?? 0)
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '分析反馈回读失败'), $this->status($e));
        }
    }

    /** @return array{array<string,mixed>,int,list<int>} */
    private function questionScope(int $questionId): array
    {
        $hotelIds = $this->accessibleHotels('operation.view');
        $question = $this->questionService->read($questionId, $this->currentTenantId(), $hotelIds);
        $tenantId = (int)($question['tenant_id'] ?? 0);
        if ($tenantId <= 0 || (int)($question['hotel_id'] ?? 0) <= 0) {
            throw new RuntimeException('feedback_question_not_found', 404);
        }
        return [$question, $tenantId, $hotelIds];
    }

    /** @return list<int> */
    private function accessibleHotels(string $capability): array
    {
        if (!$this->currentUser) throw new RuntimeException('未登录', 401);
        $ids = array_values(array_unique(array_filter(array_map(
            'intval',
            $this->hotelScope->accessibleHotelIds($this->currentUser, $capability)
        ), static fn(int $id): bool => $id > 0)));
        if ($ids === []) throw new RuntimeException('feedback_question_not_found', 404);
        return $ids;
    }

    private function currentTenantId(): int
    {
        if (!$this->currentUser || $this->currentUser->isSuperAdmin()) return 0;
        return max(0, (int)($this->currentUser->tenant_id ?? 0));
    }

    private function status(Throwable $e): int
    {
        $code = (int)$e->getCode();
        if (in_array($code, [401, 403, 404, 409, 422, 503], true)) return $code;
        if ($e instanceof \InvalidArgumentException) return 422;
        if (str_contains($e->getMessage(), 'not_found')) return 404;
        if (str_contains($e->getMessage(), 'drift') || str_contains($e->getMessage(), 'conflict')) return 409;
        return 500;
    }
}
