# OTA 批量数据校验

## 汇总

- 文件数: 2
- 失败文件数: 1
- 校验记录数: 2
- 异常记录数: 1
- 错误数: 1
- 告警数: 1

## 文件结果

| 文件 | 状态 | 记录数 | 异常记录 | 错误 | 告警 |
|---|---|---:|---:|---:|---:|
| C:\Users\ADMINI~1\AppData\Local\Temp\suxi-ota-data-batch-contract\hotel-a\ota_rows.json | pass | 1 | 0 | 0 | 0 |
| C:\Users\ADMINI~1\AppData\Local\Temp\suxi-ota-data-batch-contract\hotel-b\rows.json | fail | 1 | 1 | 1 | 1 |

## 异常记录

| 文件 | 行 | 等级 | 酒店ID | 酒店名称 | 原因 |
|---|---:|---|---|---|---|
| C:\Users\ADMINI~1\AppData\Local\Temp\suxi-ota-data-batch-contract\hotel-b\rows.json | 1 | error | 1002 | Invalid Hotel | ADR: ADR mismatch; book_order_num: Ctrip business report raw_data misses book_order_num aliases |
