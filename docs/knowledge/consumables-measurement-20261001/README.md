# 易耗品计量方法参考包

4条中文方法：包装稀释量纲、用量标定样本、服务事件分母、损耗原因证据。来源为NIST、EPA及酒店可持续发展联盟第一手资料；并非Joydo/JHIRA网站观察。

状态：reference_only / store_only / absorption_candidate / understood。酒店及OTA事实=false，决策/任务起草安全=false，未定义系数不默认。法规、医疗/儿童场景操作频次、财税政策与本店实耗均不从来源自动继承。

保留 sources/method-review.md、knowledge-pack.json 和本文件的SHA256；source-manifest.json登记URL、抽取日期及自有摘要定位。未下载原文PDF，哈希只覆盖自有资料。

入口：`C:/xampp/php/php.exe scripts/sync_joydo_jhira_knowledge.php --profile=measurement`；授权本地知识保存使用`--persist`，验证使用`--verify`。保存只写knowledge_units与knowledge_chunks，复用本地hotelx安全检查、事务、稳定键去重、冲突拒绝、exact回读、运行检索与跨店fixture。

verification.json记录预览、首次保存、验证、重复保存和原overview/cost/other兼容检查。知识储存成功不能证明业务页面、实耗、部署或经营效果。停止条件为4条保存回读、检索与幂等验收完成。
