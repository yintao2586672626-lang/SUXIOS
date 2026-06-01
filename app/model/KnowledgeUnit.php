<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class KnowledgeUnit extends Model
{
    protected $name = 'knowledge_units';
    protected $pk = 'unit_id';

    protected $autoWriteTimestamp = true;
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    protected $type = [
        'unit_id' => 'integer',
        'hotel_id' => 'integer',
        'created_by' => 'integer',
        'tags' => 'json',
    ];

    protected $json = ['tags'];
    protected $jsonAssoc = true;

    public function chunks()
    {
        return $this->hasMany(KnowledgeChunk::class, 'unit_id', 'unit_id');
    }
}
