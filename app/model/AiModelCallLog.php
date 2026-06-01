<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class AiModelCallLog extends Model
{
    protected $name = 'ai_model_call_logs';

    protected $autoWriteTimestamp = true;
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    protected $json = ['knowledge_sources_json', 'governance_json'];
    protected $jsonAssoc = true;

    protected $type = [
        'id' => 'integer',
        'hotel_id' => 'integer',
        'user_id' => 'integer',
        'prompt_length' => 'integer',
        'request_payload_size' => 'integer',
        'http_status' => 'integer',
        'latency_ms' => 'integer',
        'response_length' => 'integer',
        'confidence_score' => 'float',
        'low_confidence' => 'integer',
        'human_confirmation_required' => 'integer',
        'human_confirmed_by' => 'integer',
        'knowledge_sources_json' => 'array',
        'governance_json' => 'array',
    ];
}
