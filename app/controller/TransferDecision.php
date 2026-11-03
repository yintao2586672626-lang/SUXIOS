<?php
declare(strict_types=1);

namespace app\controller;

use app\middleware\RetiredFeatureReadOnly;
use app\service\TransferDecisionService;
use InvalidArgumentException;
use RuntimeException;
use think\App;
use think\Response;
use Throwable;

class TransferDecision extends Base
{
    private ?TransferDecisionService $service;

    public function __construct(App $app, ?TransferDecisionService $service = null)
    {
        parent::__construct($app);
        $this->service = $service;
    }

    public function source(): Response
    {
        try {
            [$hotelIds, $hotelId] = $this->resolveHotelScope((int)$this->request->param('hotel_id', 0));
            $date = $this->normalizeDate((string)$this->request->param('date', $this->currentBusinessDate()));

            return $this->success($this->service()->buildSourcePayload($hotelIds, $hotelId, $date));
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (RuntimeException $e) {
            $failureCode = $this->sourceFailureCode($e);
            if ($failureCode !== null) {
                return $this->error('转让测算来源数据暂时不可用', 503, [
                    'status_code' => $failureCode,
                ]);
            }
            return $this->error($this->safeErrorMessage($e, '获取转让测算来源数据失败'), 400);
        } catch (Throwable $e) {
            return $this->error('获取转让测算来源数据失败', 500);
        }
    }

    public function pricing(): Response
    {
        return $this->retiredWriteResponse();
    }

    public function timing(): Response
    {
        return $this->retiredWriteResponse();
    }

    public function dashboard(): Response
    {
        return $this->retiredWriteResponse();
    }

    public function records(): Response
    {
        try {
            [$hotelIds] = $this->resolveHotelScope((int)$this->request->param('hotel_id', 0));
            $list = $this->service()->records($hotelIds, (int)($this->currentUser->id ?? 0), $this->currentUser->isSuperAdmin());
            return $this->success(['list' => $list]);
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, '获取转让记录失败'), 400);
        }
    }

    public function detail(int $id): Response
    {
        try {
            if ($id <= 0) {
                return $this->error('转让记录ID无效', 422);
            }

            [$hotelIds] = $this->resolveHotelScope();
            return $this->success($this->service()->detail($id, $hotelIds, (int)($this->currentUser->id ?? 0), $this->currentUser->isSuperAdmin()));
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, '获取转让记录详情失败'), 400);
        }
    }

    public function createExecutionIntent(int $id): Response
    {
        return $this->retiredWriteResponse();
    }

    public function archive(int $id): Response
    {
        return $this->retiredWriteResponse();
    }

    private function retiredWriteResponse(): Response
    {
        if (!$this->currentUser) {
            return $this->error('请先登录', 401);
        }

        return RetiredFeatureReadOnly::response('转让测算');
    }

    private function service(): TransferDecisionService
    {
        return $this->service ??= new TransferDecisionService();
    }

    private function resolveHotelScope(int $inputHotelId = 0): array
    {
        if (!$this->currentUser) {
            throw new RuntimeException('未登录');
        }

        $hotelId = $inputHotelId > 0 ? $inputHotelId : (int)$this->request->param('hotel_id', 0);
        $permitted = array_values(array_map('intval', $this->currentUser->getPermittedHotelIds()));
        if (empty($permitted)) {
            throw new RuntimeException('暂无可访问酒店');
        }

        if ($hotelId > 0) {
            if (!in_array($hotelId, $permitted, true)) {
                throw new RuntimeException('无权查看该酒店数据');
            }
            return [[$hotelId], $hotelId];
        }

        return [$permitted, count($permitted) === 1 ? $permitted[0] : null];
    }

    private function normalizeDate(string $date): string
    {
        $timezone = new \DateTimeZone('Asia/Shanghai');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        $errors = \DateTimeImmutable::getLastErrors();
        if (
            $parsed === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format('Y-m-d') !== $date
        ) {
            throw new InvalidArgumentException('日期格式不正确');
        }

        return $date;
    }

    private function currentBusinessDate(?\DateTimeInterface $now = null): string
    {
        $timestamp = $now?->getTimestamp() ?? time();
        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new \DateTimeZone('Asia/Shanghai'))
            ->format('Y-m-d');
    }

    private function safeErrorMessage(Throwable $e, string $fallback): string
    {
        $message = trim($e->getMessage());
        if ($message !== '' && preg_match('/[\x{4e00}-\x{9fff}]/u', $message) === 1) {
            return $message;
        }

        return $fallback;
    }

    private function sourceFailureCode(RuntimeException $e): ?string
    {
        $message = trim($e->getMessage());
        if (preg_match('/^(transfer_source_(?:schema_check|read)_failed):(daily_reports|online_daily_data|hotels)$/D', $message) !== 1) {
            return null;
        }

        return $message;
    }


}
