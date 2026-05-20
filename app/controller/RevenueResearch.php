<?php
declare(strict_types=1);

namespace app\controller;

use app\service\RevenueResearchService;
use RuntimeException;
use think\Response;

class RevenueResearch extends Base
{
    public function run(): Response
    {
        $data = $this->requestData();
        $productKey = trim((string)($data['product_key'] ?? ''));
        $modelKey = trim((string)($data['model_key'] ?? 'deepseek_chat'));
        $hotelIdRaw = trim((string)($data['hotel_id'] ?? ''));
        $hotelId = $hotelIdRaw !== '' ? (int)$hotelIdRaw : null;

        if ($productKey === '') {
            return $this->error('product_key 不能为空', 422);
        }

        try {
            $result = (new RevenueResearchService())->run($productKey, $modelKey, $this->currentUser, $hotelId);
            return $this->success($result, '经营预测已生成');
        } catch (RuntimeException $e) {
            $code = $e->getCode();
            if ($code < 400 || $code > 599) {
                $code = 500;
            }
            return $this->error($e->getMessage(), $code);
        } catch (\Throwable $e) {
            return $this->error('经营预测失败：' . $e->getMessage(), 500);
        }
    }
}
