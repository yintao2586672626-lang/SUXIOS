# 酒店BD与新店运营培训吸收包

来源为用户提供的 `酒店行业BD与新店运营实战培训分享.docx`，文件 SHA-256：
`e6a07ed97562862e06d9a58b228d658e2f8eec85299e948b121821dc9b5191e7`。

本目录只保留来源文件名、哈希、结构化方法、未知项和使用边界，不复制原始文档，不保留作者元数据、签名图片地址、个人待办或身份证件类材料。

`knowledge-pack.json` 将培训经验拆成 BD 诊断、投资假设登记、OTA 信息真实性检查、AI 问答复用、经验主张审核和新店上线受控闭环。全部内容为 `reference_only`：

- 不代表当前酒店、平台门店或业务日事实；
- 不授权投资、定价、库存、OTA 资料、推广、评价、内容发布或消息发送；
- 培训中的案例数字、平台阈值、材料清单和审核时长需要当前证据再次验证；
- 新店闭环仅可生成内部人工清单，真实平台操作仍由授权人员审批和回读。

校验或同步：

```powershell
C:\xampp\php\php.exe scripts\sync_hotel_bd_new_store_training.php
C:\xampp\php\php.exe scripts\sync_hotel_bd_new_store_training.php `
  --persist `
  --source "<酒店行业BD与新店运营实战培训分享.docx 的绝对路径>"
```

不带 `--persist` 只校验代码内固定指纹的知识包；带 `--persist` 必须同时提供原始 DOCX，文件大小和 SHA-256 完全一致后才会幂等写入现有权威链路 `knowledge_units` 与 `knowledge_chunks`。数据库回读逐条比较完整内容哈希、精确片段键集合和知识单元身份；任何不一致都会在事务内回滚。
