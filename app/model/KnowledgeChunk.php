<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class KnowledgeChunk extends Model
{
    protected $name = 'knowledge_chunks';
    protected $pk = 'chunk_id';

    protected $autoWriteTimestamp = true;
    protected $createTime = 'created_at';
    protected $updateTime = false;

    protected $type = [
        'chunk_id' => 'integer',
        'unit_id' => 'integer',
        'content' => 'json',
    ];

    protected $json = ['content'];
    protected $jsonAssoc = true;

    public function unit()
    {
        return $this->belongsTo(KnowledgeUnit::class, 'unit_id', 'unit_id');
    }
}
