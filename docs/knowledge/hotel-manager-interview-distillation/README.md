# 酒店店长访谈与资料蒸馏吸收包

本知识包来自用户提供的两份 Markdown：

- `02_HOTEL_MANAGER_INTERVIEW_QUESTIONS.md`：42 个店长访谈问题，SHA-256 `8ef04ba708bdb18ef54ba8f70744afe80fafe63412dbbe4c49cbf339f1aa30af`。
- `提示词_酒店行业资料深度蒸馏总控_v1.0.md`：资料蒸馏方法模板，SHA-256 `6d2ba0f0ec83389f62c37878c03494ed6cbf9c80cf47fd60b6001624883b452d`。

`knowledge-pack.json` 保留文件名、指纹、问题编号、来源章节和结构化内容，不保存临时绝对路径。第一份资料按 14 个访谈主题拆分，第二份仅吸收来源契约、证据原子化、事实/判断/建议分离、方法合同和质量验证等方法；其中的路径、外部链接、Skill、自动连续和写库命令均不作为用户授权。

所有内容均为 `reference_only`：

- 问题不是门店现行 SOP、品牌政策或法规；
- 回答未知、没有岗位或没有标准都必须如实保留；
- 不得收集或保存客人、员工、账号、密码、联系方式、证件、Cookie、验证码或私人号码；
- 不授权调价、库存、赔付、消防、OTA修改、消息发送或任何外部写入。

校验或同步：

```powershell
C:\xampp\php\php.exe scripts\sync_hotel_manager_interview_knowledge.php
C:\xampp\php\php.exe scripts\sync_hotel_manager_interview_knowledge.php `
  --persist `
  --source-interview "<02_HOTEL_MANAGER_INTERVIEW_QUESTIONS.md 的绝对路径>" `
  --source-distillation "<提示词_酒店行业资料深度蒸馏总控_v1.0.md 的绝对路径>"
```

带 `--persist` 时两份原文件必须同时存在，字节数和 SHA-256 完全匹配；保存使用现有 `knowledge_units` 与 `knowledge_chunks`，并在同一事务中完成幂等写入和精确回读。
