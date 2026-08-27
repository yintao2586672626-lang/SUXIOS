<?php
declare(strict_types=1);

namespace app\controller;

use app\service\HotelScopeService;
use app\service\OperationManagementService;
use app\service\LocalAiRuntimeService;
use app\service\LocalMediaExtractionService;
use app\service\OperatingNetworkService;
use app\service\OperatingQuestionAiAnswerService;
use app\service\OperatingQuestionExecutionBridgeService;
use app\service\OperatingQuestionCouncilService;
use app\service\OperatingQuestionPreciseQueryService;
use app\service\OperatingQuestionService;
use app\service\OperatingSopService;
use app\service\WecomInboundService;
use app\service\WecomAibotService;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;
use think\Response;
use Throwable;

final class OperatingIntelligence extends Base
{
    private OperatingQuestionService $questionService;
    private OperatingQuestionExecutionBridgeService $questionExecutionBridge;
    private OperatingSopService $sopService;
    private OperatingNetworkService $networkService;
    private HotelScopeService $hotelScope;

    public function __construct(\think\App $app)
    {
        parent::__construct($app);
        $aiAnswerService = new OperatingQuestionAiAnswerService();
        $preciseQueryService = new OperatingQuestionPreciseQueryService();
        $this->questionService = new OperatingQuestionService(
            null,
            static fn(array $payload): array => $aiAnswerService->generate($payload),
            static fn(array $payload): array => $preciseQueryService->finalize($payload)
        );
        $this->questionExecutionBridge = new OperatingQuestionExecutionBridgeService(
            $this->questionService,
            new OperationManagementService()
        );
        $this->sopService = new OperatingSopService();
        $this->networkService = new OperatingNetworkService();
        $this->hotelScope = new HotelScopeService();
    }

    public function createQuestion(): Response
    {
        try {
            $input = $this->requestData();
            [$hotelId, $tenantId] = $this->resolveHotel((int)($input['hotel_id'] ?? 0), 'operation.view');
            return $this->success($this->questionService->create(
                $tenantId,
                $hotelId,
                (string)($input['question'] ?? ''),
                (string)($input['platform'] ?? ''),
                (string)($input['date_start'] ?? ''),
                (string)($input['date_end'] ?? ''),
                (int)($this->currentUser->id ?? 0),
                (string)($input['model_key'] ?? 'local_second_brain'),
                (string)($input['decision_object'] ?? '')
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '经营问题保存失败'), $this->status($e));
        }
    }

    public function questions(): Response
    {
        try {
            $hotelIds = $this->accessibleHotels('operation.view');
            $hotelId = (int)$this->request->param('hotel_id', 0);
            if ($hotelId > 0 && !in_array($hotelId, $hotelIds, true)) {
                throw new RuntimeException('无权查看该酒店经营问题');
            }
            $tenantId = $hotelId > 0 ? $this->tenantForHotel($hotelId) : $this->currentTenantId();
            return $this->success($this->questionService->list(
                $tenantId,
                $hotelIds,
                $hotelId > 0 ? $hotelId : null
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '经营问题查询失败'), $this->status($e));
        }
    }

    public function questionScopeOptions(): Response
    {
        try {
            [$hotelId, $tenantId] = $this->resolveHotel(
                (int)$this->request->param('hotel_id', 0),
                'operation.view'
            );
            return $this->success($this->questionService->scopeOptions($tenantId, $hotelId));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '经营问题可用数据范围查询失败'), $this->status($e));
        }
    }

    public function readQuestion(int $id): Response
    {
        try {
            $hotelIds = $this->accessibleHotels('operation.view');
            $question = $this->questionService->read(
                $id,
                $this->currentTenantId(),
                $hotelIds
            );
            $question['action_intent_readback'] = $this->questionExecutionBridge->readExistingIntents(
                $id,
                $this->currentTenantId(),
                $hotelIds
            );
            return $this->success($question);
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '经营问题回读失败'), $this->status($e));
        }
    }

    public function createQuestionExecutionIntent(int $id, int $actionIndex): Response
    {
        try {
            return $this->success($this->questionExecutionBridge->createIntent(
                $id,
                $actionIndex,
                $this->currentTenantId(),
                $this->accessibleHotels('operation.execute'),
                (int)($this->currentUser->id ?? 0)
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '经营问答行动草案提交失败'), $this->status($e));
        }
    }

    public function localAiCapabilities(): Response
    {
        try {
            $this->accessibleHotels('operation.view');
            return $this->success((new LocalAiRuntimeService())->capabilities());
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '本地第二大脑状态读取失败'), $this->status($e));
        }
    }

    public function extractLocalMedia(): Response
    {
        try {
            $input = $this->requestData();
            [$hotelId, $tenantId] = $this->resolveHotel((int)($input['hotel_id'] ?? 0), 'operation.execute');
            $file = $this->request->file('file');
            if (!$file) {
                throw new InvalidArgumentException('请选择图片、音频或视频文件');
            }
            $path = method_exists($file, 'getPathname') ? (string)$file->getPathname() : '';
            $name = method_exists($file, 'getOriginalName') ? (string)$file->getOriginalName() : '';
            $mime = method_exists($file, 'getOriginalMime') ? (string)$file->getOriginalMime() : '';
            try {
                $result = (new LocalMediaExtractionService())->extract(
                    $tenantId,
                    $hotelId,
                    (int)($this->currentUser->id ?? 0),
                    $path,
                    $name,
                    $mime
                );
            } finally {
                if ($path !== '' && is_uploaded_file($path)) {
                    @unlink($path);
                }
            }
            return $this->success($result);
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '本地媒体提取失败'), $this->status($e));
        }
    }

    public function localMediaExtractions(): Response
    {
        try {
            $hotelIds = $this->accessibleHotels('operation.view');
            $hotelId = (int)$this->request->param('hotel_id', 0);
            if ($hotelId > 0 && !in_array($hotelId, $hotelIds, true)) {
                throw new RuntimeException('无权查看该酒店本地媒体提取记录');
            }
            $tenantId = $hotelId > 0 ? $this->tenantForHotel($hotelId) : $this->currentTenantId();
            return $this->success((new LocalMediaExtractionService())->list(
                $tenantId,
                $hotelIds,
                $hotelId > 0 ? $hotelId : null,
                (int)$this->request->param('limit', 20)
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '本地媒体提取记录查询失败'), $this->status($e));
        }
    }

    public function readLocalMediaExtraction(int $id): Response
    {
        try {
            return $this->success((new LocalMediaExtractionService())->read(
                $id,
                $this->currentTenantId(),
                $this->accessibleHotels('operation.view')
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '本地媒体提取结果回读失败'), $this->status($e));
        }
    }

    public function wecomInboundCapabilities(): Response
    {
        try {
            $hotelIds = $this->accessibleHotels('operation.view');
            $tenantId = $this->currentTenantId();
            return $this->success([
                'preferred_transport' => 'wecom_aibot_websocket',
                'aibot_websocket' => (new WecomAibotService())->capability($tenantId, $hotelIds),
                'custom_app_callback_adapter' => (new WecomInboundService())->capability($tenantId, $hotelIds),
                'robot_webhook_inbound' => 'unsupported',
            ]);
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '企业微信入站能力读取失败'), $this->status($e));
        }
    }

    public function saveWecomInboundBinding(): Response
    {
        try {
            $input = $this->requestData();
            [$hotelId, $tenantId] = $this->resolveHotel((int)($input['hotel_id'] ?? 0), 'operation.execute');
            return $this->success((new WecomInboundService())->saveBinding(
                $tenantId,
                $hotelId,
                (int)($this->currentUser->id ?? 0),
                (string)($input['conversation_id'] ?? ''),
                (string)($input['label'] ?? '宿析经营追问'),
                (string)($input['binding_key'] ?? '')
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '企业微信入站绑定保存失败'), $this->status($e));
        }
    }

    public function createWecomAibotBindingCode(): Response
    {
        try {
            $input = $this->requestData();
            [$hotelId, $tenantId] = $this->resolveHotel((int)($input['hotel_id'] ?? 0), 'operation.execute');
            return $this->success((new WecomAibotService())->createBindingCode(
                $tenantId,
                $hotelId,
                (int)($this->currentUser->id ?? 0),
                (string)($input['label'] ?? '宿析经营追问')
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '企业微信智能机器人绑定码创建失败'), $this->status($e));
        }
    }

    public function setWecomAibotReplyEnabled(int $id): Response
    {
        try {
            $input = $this->requestData();
            if (!array_key_exists('enabled', $input)) {
                throw new InvalidArgumentException('enabled 必填');
            }
            $enabled = filter_var($input['enabled'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($enabled === null) {
                throw new InvalidArgumentException('enabled 必须是布尔值');
            }
            return $this->success((new WecomAibotService())->setReplyEnabled(
                $id,
                $this->currentTenantId(),
                $this->accessibleHotels('operation.execute'),
                $enabled
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '企业微信智能机器人回复开关保存失败'), $this->status($e));
        }
    }

    public function disableWecomAibotBinding(int $id): Response
    {
        try {
            return $this->success((new WecomAibotService())->disableBinding(
                $id,
                $this->currentTenantId(),
                $this->accessibleHotels('operation.execute')
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '企业微信智能机器人解绑失败'), $this->status($e));
        }
    }

    public function wecomInboundBindings(): Response
    {
        try {
            return $this->success((new WecomInboundService())->bindings(
                $this->currentTenantId(),
                $this->accessibleHotels('operation.view')
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '企业微信入站绑定查询失败'), $this->status($e));
        }
    }

    public function wecomInboundEvents(): Response
    {
        try {
            $hotelIds = $this->accessibleHotels('operation.view');
            $hotelId = (int)$this->request->param('hotel_id', 0);
            if ($hotelId > 0 && !in_array($hotelId, $hotelIds, true)) {
                throw new RuntimeException('无权查看该酒店企业微信入站事件');
            }
            $tenantId = $hotelId > 0 ? $this->tenantForHotel($hotelId) : $this->currentTenantId();
            return $this->success((new WecomInboundService())->events(
                $tenantId,
                $hotelIds,
                $hotelId > 0 ? $hotelId : null,
                (int)$this->request->param('limit', 50)
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '企业微信入站事件查询失败'), $this->status($e));
        }
    }

    public function readWecomInboundEvent(int $id): Response
    {
        try {
            return $this->success((new WecomInboundService())->readEvent(
                $id,
                $this->currentTenantId(),
                $this->accessibleHotels('operation.view')
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '企业微信入站事件回读失败'), $this->status($e));
        }
    }

    public function runQuestionCouncil(int $id): Response
    {
        try {
            $input = $this->requestData();
            return $this->success((new OperatingQuestionCouncilService())->reserveShadow(
                $id,
                $this->currentTenantId(),
                $this->accessibleHotels('operation.view'),
                (int)($this->currentUser->id ?? 0),
                (string)($input['client_run_key'] ?? ''),
                true
            ), '经营顾问会诊已保留，后台逐视角处理中');
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '经营顾问会诊保留失败'), $this->status($e));
        }
    }

    public function latestQuestionCouncil(int $id): Response
    {
        try {
            return $this->success((new OperatingQuestionCouncilService())->latest(
                $id,
                $this->currentTenantId(),
                $this->accessibleHotels('operation.view')
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '经营顾问会诊回读失败'), $this->status($e));
        }
    }

    public function resumeQuestionCouncil(int $id, int $runId): Response
    {
        try {
            return $this->success((new OperatingQuestionCouncilService())->resumeRun(
                $runId,
                $id,
                $this->currentTenantId(),
                $this->accessibleHotels('operation.view')
            ), '经营顾问会诊已按原 run 恢复');
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '经营顾问会诊恢复失败'), $this->status($e));
        }
    }

    public function readQuestionCouncil(int $id, int $runId): Response
    {
        try {
            $run = (new OperatingQuestionCouncilService())->read(
                $runId,
                $this->currentTenantId(),
                $this->accessibleHotels('operation.view')
            );
            if ((int)($run['question_id'] ?? 0) !== $id) {
                throw new RuntimeException('经营顾问会诊记录不属于当前经营问题', 404);
            }
            return $this->success($run);
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '经营顾问会诊精确回读失败'), $this->status($e));
        }
    }

    public function createSop(): Response
    {
        try {
            $input = $this->requestData();
            [$hotelId, $tenantId] = $this->resolveHotel((int)($input['hotel_id'] ?? 0), 'operation.execute');
            $memoryIds = is_array($input['source_memory_ids'] ?? null) ? $input['source_memory_ids'] : [];
            return $this->success($this->sopService->createCandidate(
                $tenantId,
                $hotelId,
                $memoryIds,
                $input,
                (int)($this->currentUser->id ?? 0)
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '候选SOP保存失败'), $this->status($e));
        }
    }

    public function sops(): Response
    {
        try {
            $hotelIds = $this->accessibleHotels('operation.view');
            $hotelId = (int)$this->request->param('hotel_id', 0);
            if ($hotelId > 0 && !in_array($hotelId, $hotelIds, true)) {
                throw new RuntimeException('无权查看该酒店SOP');
            }
            return $this->success($this->sopService->listVersions(
                $hotelId > 0 ? $this->tenantForHotel($hotelId) : $this->currentTenantId(),
                $hotelIds,
                $hotelId > 0 ? $hotelId : null
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '经营SOP查询失败'), $this->status($e));
        }
    }

    public function readSop(int $id): Response
    {
        try {
            return $this->success($this->sopService->readVersion(
                $id,
                $this->currentTenantId(),
                $this->accessibleHotels('operation.view')
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '经营SOP回读失败'), $this->status($e));
        }
    }

    public function validateSop(int $id): Response
    {
        try {
            $hotelIds = $this->accessibleHotels('operation.execute');
            $current = $this->sopService->readVersion($id, 0, $hotelIds);
            return $this->success($this->sopService->validateVersion(
                $id,
                (int)$current['tenant_id'],
                $hotelIds,
                $this->requestData(),
                (int)($this->currentUser->id ?? 0)
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '经营SOP验证失败'), $this->status($e));
        }
    }

    public function replicateSop(int $id): Response
    {
        try {
            $input = $this->requestData();
            $hotelIds = $this->accessibleHotels('operation.execute');
            $source = $this->sopService->readVersion($id, 0, $hotelIds);
            return $this->success($this->sopService->replicate(
                $id,
                (int)$source['tenant_id'],
                $hotelIds,
                (int)($input['target_hotel_id'] ?? 0),
                (int)($this->currentUser->id ?? 0),
                $input
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '跨店复制草稿保存失败'), $this->status($e));
        }
    }

    public function readReplication(int $id): Response
    {
        try {
            return $this->success($this->sopService->readReplication(
                $id,
                $this->currentTenantId(),
                $this->accessibleHotels('operation.view')
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '跨店复制草稿回读失败'), $this->status($e));
        }
    }

    public function operatingNetwork(): Response
    {
        try {
            $hotelIds = $this->accessibleHotels('operation.view');
            $hotelId = (int)$this->request->param('hotel_id', 0);
            if ($hotelId <= 0 || !in_array($hotelId, $hotelIds, true)) {
                throw new RuntimeException('请选择可访问的单个酒店');
            }
            return $this->success($this->networkService->overview(
                $this->tenantForHotel($hotelId),
                $hotelId,
                $hotelIds
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '经营复制网络读取失败'), $this->status($e));
        }
    }

    public function previewOperatingProfile(): Response
    {
        try {
            $hotelIds = $this->accessibleHotels('operation.view');
            $hotelId = (int)$this->request->param('hotel_id', 0);
            if ($hotelId <= 0 || !in_array($hotelId, $hotelIds, true)) {
                throw new RuntimeException('请选择可访问的单个酒店');
            }
            return $this->success($this->networkService->previewProfileDraft(
                $this->tenantForHotel($hotelId),
                $hotelId,
                $hotelIds
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '经营画像待核验草稿预览生成失败'), $this->status($e));
        }
    }

    public function saveOperatingProfile(): Response
    {
        try {
            $input = $this->requestData();
            [$hotelId, $tenantId] = $this->resolveHotel((int)($input['hotel_id'] ?? 0), 'operation.execute');
            return $this->success($this->networkService->saveProfile(
                $tenantId,
                $hotelId,
                $input,
                (int)($this->currentUser->id ?? 0)
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '酒店经营画像保存失败'), $this->status($e));
        }
    }

    public function reviewReplication(int $id): Response
    {
        try {
            $hotelIds = $this->accessibleHotels('operation.execute');
            $replication = $this->sopService->readReplication($id, 0, $hotelIds);
            return $this->success($this->networkService->recordReplicationReview(
                $id,
                (int)$replication['tenant_id'],
                $hotelIds,
                $this->requestData(),
                (int)($this->currentUser->id ?? 0)
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '复制复盘保存失败'), $this->status($e));
        }
    }

    public function createReplicationExecutionIntent(int $id): Response
    {
        try {
            $hotelIds = $this->accessibleHotels('operation.execute');
            $replication = $this->sopService->readReplication($id, 0, $hotelIds);
            return $this->success($this->networkService->createReplicationExecutionIntent(
                $id,
                (int)$replication['tenant_id'],
                $hotelIds,
                $this->requestData(),
                (int)($this->currentUser->id ?? 0)
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '复制验证待审批任务创建失败'), $this->status($e));
        }
    }

    public function replicationReviews(int $id): Response
    {
        try {
            $hotelIds = $this->accessibleHotels('operation.view');
            $replication = $this->sopService->readReplication($id, 0, $hotelIds);
            return $this->success($this->networkService->listReplicationReviews(
                $id,
                (int)$replication['tenant_id'],
                $hotelIds
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '复制复盘读取失败'), $this->status($e));
        }
    }

    /** @return array{0:int,1:int} */
    private function resolveHotel(int $hotelId, string $capability): array
    {
        if ($hotelId <= 0) {
            throw new InvalidArgumentException('请选择单个酒店');
        }
        $hotelIds = $this->accessibleHotels($capability);
        if (!in_array($hotelId, $hotelIds, true)) {
            throw new RuntimeException('无权访问或操作该酒店');
        }
        return [$hotelId, $this->tenantForHotel($hotelId)];
    }

    /** @return list<int> */
    private function accessibleHotels(string $capability): array
    {
        if (!$this->currentUser) {
            throw new RuntimeException('未登录');
        }
        $ids = array_values(array_unique(array_filter(array_map(
            'intval',
            $this->hotelScope->accessibleHotelIds($this->currentUser, $capability)
        ), static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            throw new RuntimeException('暂无可访问酒店');
        }
        return $ids;
    }

    private function tenantForHotel(int $hotelId): int
    {
        $tenantId = (int)Db::name('hotels')->where('id', $hotelId)->where('status', 1)->value('tenant_id');
        if ($tenantId <= 0) {
            throw new RuntimeException('酒店租户身份缺失');
        }
        return $tenantId;
    }

    private function currentTenantId(): int
    {
        if (!$this->currentUser || $this->currentUser->isSuperAdmin()) {
            return 0;
        }
        return max(0, (int)($this->currentUser->tenant_id ?? 0));
    }

    private function status(Throwable $e): int
    {
        if ($e->getMessage() === '未登录') {
            return 401;
        }
        if (str_contains($e->getMessage(), '无权') || str_contains($e->getMessage(), '租户身份不一致')) {
            return 403;
        }
        if (str_contains(strtolower($e->getMessage()), 'not found')) {
            return 404;
        }
        if (in_array((int)$e->getCode(), [409, 413, 422, 503], true)) {
            return (int)$e->getCode();
        }
        return $e instanceof InvalidArgumentException ? 422 : 500;
    }

    private function safeMessage(Throwable $e, string $fallback): string
    {
        $message = trim($e->getMessage());
        if ($message !== '' && (
            $e instanceof InvalidArgumentException
            || $e instanceof RuntimeException
            || preg_match('/[\x{4e00}-\x{9fff}]/u', $message) === 1
        )) {
            return $message;
        }
        return $fallback;
    }
}
