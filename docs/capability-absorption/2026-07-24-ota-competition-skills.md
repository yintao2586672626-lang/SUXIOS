# 携程/美团竞争商圈 Skill 吸收记录

日期：2026-07-24
状态：`integrated`（模拟/离线路径已通过守卫；真实线上 OTA 报告内容
仍为 `unverified`）

## 来源与指纹

来源为用户提供的本地压缩包，未联网下载，未执行包内安装脚本。

| 包 | SHA-256 | 结论 |
|---|---|---|
| `0724meituan-hotel-competition-suite-codex-v1.0.0(1).zip` | `3a3816f10608db86e9dc6141da2794216d001d8bf4341bf13ab2d48c6018099e` | 美团 Codex 组合套件 |
| `0724meituan-hotel-competition-router-v1.0.0(1).zip` | `c2e40a5fbc27f23c253c0f1d3cd5bf8e92f9d14f361de2fe2376752e311066aa` | 与商业套件内嵌包一致 |
| `0724meituan-hotel-competition-lite-v1.0.0(1).zip` | `ea6831c22eaa923f025e4fe1f9d70bacf9d2b1a86148605e34a72c16f26cdffb` | 与商业套件内嵌包一致 |
| `0724meituan-hotel-competition-flagship-v1.0.0.zip` | `6234ac4afcd1f774278a2c47a861da555220ac6cfec748053c075920a2241bc8` | 与商业套件内嵌包一致 |
| `0724美团酒店竞争商圈分析Skill商业套件_v1.0.0.zip` | `b54ebbe7e55705a7199410db1e5d3de55c43334d1f5a27c537fd4b3b8ad6f796` | 美团商业交付总包 |
| `携程酒店竞争商圈报告_Skill商业化套件_v1.0.0(1).zip` | `6638e108bad0e45aa48fcb487af89f69a5f1c9599d61750b5e27fca856fa263b` | 携程商业交付总包 |

美团商业总包校验清单 271/271 通过；携程总包 85/85 通过。未发现路径
穿越、可执行二进制、Office 宏或外部链接部件。

## 权限、依赖与分发边界

- 美团核心要求 Python 3.10+，核心计算无第三方依赖；可选 Word/HTML/
  图表渲染声明了 `python-docx`、`matplotlib`、`pandas`、`openpyxl`、
  `jinja2`、`bs4`、`lxml`、`Pillow`、`Playwright`。
- 携程核心使用 Python 标准库。
- 美团安装脚本具有覆盖/删除同名 Skill 目录的能力；本次没有运行。
- 所有脚本均已静态检查；没有继承包内任何 shell 或 PowerShell 预授权。
- 未发现与当前项目完全同名的 Skill；存在与 `suxi-ai-report`、
  `suxi-ota-ops`、`suxi-ota-revenue-semantic-layer` 的功能重叠，因此
  选择吸收到现有入口而不是平行安装六套 Skill。
- 美团许可证是尚未填写权利人/年份的专有商业模板；携程包没有许可证
  文件。当前只按用户提供的内部材料吸收，权利边界澄清前不对外分发
  原包、源码或私有材料。
- 包内标注“内部原始参考/勿外发”和“private source material”的真实
  报告只检查了文件类型、哈希和宏风险，没有读取或复制进宿析OS。

## 已吸收能力

1. 默认简版；明确旗舰/深度/HTML 时走旗舰；双版必须一算两渲染。
2. 统一产出 `analysis_bundle.json`，携程历史名
   `analysis_context.json` 只作为同一结果的兼容名。
3. 美团保留曝光、浏览、订单、销售、入住两套窗口和平台转化率；
   携程保留访客、订单、ADR、ARI、SCI 和平台转化率。
4. 直接竞品、进攻标杆、流量标杆、转化标杆分开。
5. 价格建议只输出单房型、小步、可回滚的场景与保本间夜倍率。
6. 接入宿析既有原则：平台/酒店绑定、目标日期、来源、采集时间、
   持久化回查状态和质量门槛先于角色、价格和行动。
7. 接入宿析既有运营链：
   `昨日OTA数据 -> 异常判断 -> 竞品对比 -> AI建议 -> 今日运营动作`。

## 主动修正的外部缺口

- 不用逐店求和补缺失的来源汇总；
- 不静默删除重复酒店；
- 不用第一个子串命中识别本店；
- 酒店ID始终按字符串处理，保留前导零；
- 缺失分母返回 `null`，不返回 0；
- 裸值 `1` 的百分比要求澄清；
- 合成、未验证、部分、过期、绑定缺失、无权限和采集失败均不得输出
  可执行角色、价格和运营动作；
- 平台转化率与可复算转化率并列保留，不伪造平台私有算法；
- OTA 商圈结论不得提升为全酒店经营事实。

## 项目落点

- 统一计算入口：`scripts/build_ota_competition_bundle.py`
- 现有报告 Skill：`.agents/skills/suxi-ai-report/SKILL.md`
- 详细调用契约：
  `.agents/skills/suxi-ai-report/references/competition-circle.md`
- 回归测试：`tests/python/ota_competition_bundle_test.py`

停止条件：本次只完成“已落库 OTA 数据 -> 可信共享 bundle -> AI 日报保存/
回读与页面展示”的最小闭环；不连接真实 OTA 账号，不执行平台或生产数据
写入，不扩展为全酒店经营结论。
