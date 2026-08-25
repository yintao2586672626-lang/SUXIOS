# AI知识库资料吸收包

来源：`D:\桌面\SUXIOS\AI知识库资料`

版本：`2026-08-14.1`

- `source-manifest.json`：全部顶层源文件的哈希、类型、解析状态与边界。
- `method-pack.json`：从来源目录中已成型的方法、专题与案例 Markdown 提取的可检索参考条目。
- `priority-pack.json`：用户点名的预订进度、单店案例和酒店小红书资料，经证据校准后的重点条目；外来 Skill 仅静态审阅，未安装、未执行。
- `integrated-model.json`：把 33 个方法条目与 3 个重点条目整合成证据、指标、诊断、动作、冲突处理和黄金样例统一模型。
- 所有条目均为 `reference_only`，不含当前酒店事实，不授权 OTA/PMS/企微写入。
- 运行 `scripts/sync_ai_knowledge_library.php --persist` 才会写入当前宿析OS数据库；不带 `--persist` 仅校验。
