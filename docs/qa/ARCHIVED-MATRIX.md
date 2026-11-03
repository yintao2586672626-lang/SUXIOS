# 2026-07-15 诊断用例压缩存档

`hotel_system_4000_diagnostic_cases_2026-07-15.jsonl.gz` 是原 4,000 条 JSONL 用例的无损 GZIP 存档。这里只保存历史用例定义及当时的证据状态，不代表这些组合在当前版本全部执行通过。活动测试、运行代码及发布流程不读取该矩阵。

2026-10-02 压缩验证：原始 11,292,402 字节，压缩后 371,396 字节；解压内容逐字节一致。原文件和压缩文件的 SHA-256、行数及修改前引用备份保存在 `output/slimming/20261002-whole/root/source-ledger.json`。

在仓库根目录运行以下命令可恢复到临时文件，供查询或离线历史复核：

```powershell
node -e "const fs=require('fs'),z=require('zlib');fs.writeFileSync('output/diagnostic-cases-20260715.jsonl',z.gunzipSync(fs.readFileSync('docs/qa/hotel_system_4000_diagnostic_cases_2026-07-15.jsonl.gz')));"
```

恢复后保持原来的 `variant_execution_status` 和证据边界，不能把历史基线或未执行的组合算成当前验收。
