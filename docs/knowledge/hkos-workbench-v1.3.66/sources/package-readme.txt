# 裕生老师·酒店经营 Knowledge OS

面向酒店经营、教学、内容生产与个人知识治理的本地优先 Obsidian 工作台。`1.3.66` 使用独立插件 ID `yusheng-hotel-knowledge-os`，品牌区显示“裕生老师／酒店经营知识工作台”，并保留上游 Knowledge OS 的本地知识管理能力。

> 本插件仅支持桌面版 Obsidian，清单已声明 `isDesktopOnly: true`。建议先在一次性或专用测试 Vault 中验收，再由用户决定是否安装到正式知识库。

## 发布前必跑（不可跳过）

`npm test` + `python3 scripts/scan-workspace-credentials.py`（对即将打包的工作区产物扫凭据/本机痕迹，命中即非 0 退出）已挂在 `npm run package:self-install` 与 `npm run verify` 上；手工发版也必须先跑这两条。

## 主要能力

- 固定一级导航与可扩展的二级酒店知识领域。
- 以现有中文 Vault 目录为事实来源，不要求建立新的总根目录。
- Skills 工作台支持清单加载、搜索、分类、来源与状态筛选、评分排序和分页。
- 只为当前有效清单登记的本机目录提供 Finder 打开操作。
- 经二次确认后，不经 shell 包装启动受信任仓库中固定命名的 `scripts/build-catalog.mjs`；成功时重新载入清单并展示新增、减少与变化项。
- 扫描或清单验证失败时保留最近一次有效列表。

## 基础版与商业版

- **基础版**：用户自行安装 ZIP 后即可打开工作台演示、安装帮助和自己的本地 Vault；不会创建账号，也不会上传、删除、加密或锁定笔记。
- **商业版默认路线（实际发布包）**：发布方仅向用户发送短 HKOS 激活码。用户在“设置 → 裕生老师·酒店经营 Knowledge OS → 商业版授权”粘贴它；首次联网时由服务端自动绑定当前电脑，之后本地离线校验。用户无需查看、复制或发送安装码。
- 首次联网仅传短 HKOS 激活码、`hkos-device` 本机摘要和插件版本；不传 Vault、路径、笔记或附件。本插件不是账号服务或 SaaS，商业功能仍在本地 Vault 中运行。
- 同机重装或清除插件数据后，需联网再次粘贴同一码恢复；其他电脑会固定提示“已绑定其他设备”，同一短码不能用于两台电脑。换机请走支持流程，不要反复尝试激活。
- `.txt` 文件或完整 `HKOS1.` 长序列是历史或明确完全离线的特殊路径，可能由发布方预先按设备签发；它不替代默认在线激活路线。无效、过期或不匹配的操作不会覆盖当前有效本地授权。
- **本安装包已内置固定的 HKOS 联网激活服务。** 用户不需要配置服务器地址；插件只保存服务端返回、可在本机离线验证的授权证书，不保存短 HKOS 激活码。

面向客户的图文说明源文件位于 [`commercial/docs/Obsidian插件_用户安装与激活说明.md`](commercial/docs/INSTALL_AND_ACTIVATE.md)；ZIP 内使用兼容不同解压工具的 `commercial/docs/INSTALL_AND_ACTIVATE.md` 文件名，内容相同。
- 客户文档：[HKOS 小白操作手册](commercial/docs/CUSTOMER_MANUAL.html)、[手册打印版 PDF](commercial/docs/CUSTOMER_MANUAL_PRINT.pdf)；功能与治理说明：[HKOS 小白使用指南](commercial/docs/CUSTOMER_GUIDE.html)、[指南打印版 PDF](commercial/docs/CUSTOMER_GUIDE_PRINT.pdf)

测试版试点、稳定版晋升与回退边界见 [`commercial/docs/插件更新_测试版试点与稳定版晋升.md`](commercial/docs/UPDATE_PILOT_GUIDE.md)。

## 环境要求

- 桌面版 Obsidian；插件最低支持版本为 `1.8.0`。
- 本地安装包必须包含 `manifest.json`、`main.js`、`styles.css`、`README.md`、`commercial/docs/INSTALL_AND_ACTIVATE.md`、基础版与商业版快速上手、内容来源规则、`LICENSE` 和 `DEFUDDLE-LICENSE.txt`。来源权利未核定的旧头像不进入客户包。
- 开发与构建需要 Node.js 20 或更高版本；重新扫描需要 Node.js 20+ 运行时。
- 如需真实执行 Agent，需另行安装并启用兼容的 Claudian；没有对应插件时不会伪装执行成功。

网页正文采集可能访问用户指定的网址；Skills 扫描脚本同样可能按脚本自身代码访问网络或本机文件。

## Vault 目录映射

插件默认直接使用现有中文目录，不会把整个知识库搬进 `AI Knowledge OS` 或其他新根目录：

| 用途 | 默认相对目录 |
| --- | --- |
| 原始资料 Inbox | `00_AI Inbox` |
| 选题 | `01_选题库` |
| 案例 | `02_案例库` |
| 方法 | `03_方法库` |
| 工具 | `04_工具库` |
| 用户问题 | `05_用户问题库` |
| 商业观察 | `06_商业观察库` |
| 生产资产 | `07_生产资产` |
| 复盘与分析 | `08_复盘数据` |
| 金句 | `09_金句库` |
| 模板与规则 | `99_模板与规则` |
| 附件 | `_附件` |

项目、分析报告和 Agent 记录使用上述目录的受控子目录。可在设置页调整映射；修改映射只影响后续读取和新建位置，不自动迁移、移动、改名或删除现有文件。

## Skills 仓库与清单

默认 Skills 仓库由当前系统主目录推导为 `~/HKOS技能仓库`，不会硬编码用户名。也可以在设置中填写另一个绝对路径；输入只作为本地草稿，点击“应用 Skills 仓库”后才会验证、保存并重新加载清单。

“验证配置”会检查以下本地文件：

```text
<skillsRepo>/catalog/skills-catalog.json
<skillsRepo>/config/scan-roots.json
<skillsRepo>/scripts/build-catalog.mjs
```

清单还会验证名称、分类、用途、价值、1—5 星评分、状态、来源、本机目录及仓库地址等字段。无效 JSON 或无效字段不会替换最近一次有效清单。

### 在 Finder 打开目录

“打开主目录”和副本目录操作只接受当前已加载 Skills 清单中的登记路径。插件会先使用真实路径和目录类型进行校验，再调用 Electron Finder 打开；路径缺失、不是目录、未在清单中或 Finder 返回错误时均显示失败，不报告成功。设置页的“打开 Skills 仓库”只打开当前配置的本地仓库根目录。

### 重新扫描本机 Skills

重新扫描需要用户再次确认，也需要独立的 Node.js 20+ 运行时。设置中的“Node.js 可执行文件”留空时，插件会从当前 PATH 的绝对目录和安全的系统标准位置自动发现；也可以保存一个绝对路径，并通过单独的“验证 Node.js”按钮检查，不会在逐字输入路径时强制重置。

插件会把候选路径解析为规范绝对路径，确认它是名为 `node` 或 `node.exe` 的普通可执行文件，再以空环境、无 shell 和 5 秒超时运行 `--version`，只接受 Node.js 20 或更高版本。它不会使用 Obsidian 或 Electron 的运行程序代替 Node.js；“验证配置”和实际扫描都会再次执行这项验证。

验证通过后，插件只会在用户配置且已信任的 Skills 仓库中，选择固定命名的 `<skillsRepo>/scripts/build-catalog.mjs`，并以参数数组、不经 shell 包装的方式启动它。扫描子进程使用空环境，不会继承父进程环境变量；操作系统仍可能注入自身的进程变量。

**重要信任边界：脚本不受沙箱限制。** 它仍会以当前用户权限访问本机文件与网络，也可以按脚本代码调用 npm、Git、其他进程或修改任意有权限的位置。固定文件名和无 shell 包装不能证明脚本安全；运行前必须检查并信任用户配置仓库中的实际脚本。不要把未知或未审计仓库配置为扫描来源。

经批准的 `HKOS技能仓库` 脚本预期生成以下内容；这是该受信任脚本当前约定的行为，不是插件对任意脚本强制实施的写入沙箱：

```text
catalog/skills-catalog.json
SKILLS_CATALOG.md
skills/                         # 安全镜像
reports/duplicates.json
reports/exclusions.json
```

插件自身负责验证仓库真实路径、固定脚本与扫描配置是否存在，建立单实例锁，限制输出长度并在失败时保留旧清单；插件自身不自动 commit 或 push，受信任脚本内部行为不受插件限制。扫描失败或新清单校验失败时，页面继续保留旧清单。

扫描超时或插件卸载时，插件会先请求子进程正常终止，经过短暂宽限后强制终止；若最终仍无法确认子进程关闭，本次调用会有界失败并禁用后续扫描，用户需要重新加载插件。该机制避免界面无限等待，但不能替代对脚本本身的信任审查。

## 测试 Vault 优先安装

先在备份过的专用测试 Vault 验证，再考虑正式安装：

```text
测试 Vault/
└── .obsidian/
    └── plugins/
        └── yusheng-hotel-knowledge-os/
            ├── manifest.json
            ├── main.js
            ├── styles.css
            ├── README.md
            ├── LICENSE
            └── DEFUDDLE-LICENSE.txt
```

随后在 Obsidian 的“第三方插件”中启用“裕生老师·酒店经营 Knowledge OS”，通过左侧 Ribbon 或命令面板打开工作台。插件安装目录的完整识别路径为 `.obsidian/plugins/yusheng-hotel-knowledge-os`。

不要把整个个人 Vault、`.obsidian/workspace.json`、其他插件的 `data.json`、Cookie、Token、Secret、客户资料或本地缓存加入安装包。

### 自行安装 ZIP

下载并解压发布方提供的 ZIP 后，确认 `yusheng-hotel-knowledge-os` 文件夹第一层直接包含 `manifest.json`、`main.js` 和 `styles.css`。将整个文件夹复制到目标 Vault 的 `.obsidian/plugins/` 下，重启 Obsidian 后在“第三方插件”中启用插件。随后点击 Ribbon 脑图标，或通过命令面板打开“知识驾驶舱”。

发布方构建可自装 ZIP：

```bash
npm run package:self-install
```

该命令会先运行源码语法与发布元数据检查，再生成一个新版本 ZIP，并拒绝覆盖同名旧包。完整回归测试仍应在发布前单独执行。

## 回滚与卸载

1. 在 Obsidian 设置中禁用“裕生老师·酒店经营 Knowledge OS”。
2. 如需彻底移除运行代码，删除 `.obsidian/plugins/yusheng-hotel-knowledge-os` 文件夹。
3. 如曾修改目录映射，可保留设置以便重装，或在删除插件前记录映射值。

禁用或删除插件不会删除 Vault 笔记和 Skills 数据。插件不会在卸载时删除 Markdown、附件、中文知识目录或 `~/HKOS技能仓库`；因此回滚插件代码不会回滚或清理用户内容。

## 本地数据与安全边界

- 知识笔记、标签、任务、报告与附件保存在用户本地 Vault。
- Dashboard、Knowledge、Graph 与 Analytics 依据实际 Vault 内容计算，不复制原作者私人数据。
- AI 建议与本地规则分析不等同于真实客户事实；发布、删除和外部发送仍由用户确认。
- Skills 扫描输出会限制长度并清理常见 Token、Cookie、授权头和私钥片段。
- Skills 扫描入口固定且不经 shell，但脚本不受沙箱限制；必须审查用户配置仓库中的实际 `build-catalog.mjs`。
- Skills 重扫只接受经过规范路径、可执行文件和 Node.js 20+ 版本验证的独立 Node 运行时，不会把 Obsidian/Electron 当作 Node。
- 插件不会因安装而自动创建 GitHub Fork、远端仓库或 Git 提交。

## 开发与验证

```bash
npm ci
npm test
npm run lint
npm run check
npm run build
npm run verify
```

主要工程文件：

- `source.js`：Obsidian 插件源代码。
- `main.js`：通过 `npm run build` 生成的运行产物。
- `styles.css`：酒店知识工作台样式。
- `manifest.json`、`package.json`、`versions.json`：版本与兼容性元数据。
- `src/`：Vault 映射、Skills 清单、Finder、扫描及工作台模型。
- `tests/`：Node 单元、契约与发布回归测试。

修改源代码后必须重新构建 `main.js`。`npm run verify` 会检查单元测试、语法、发布文件、版本一致性以及构建产物是否同步。

## 上游与第三方许可

本定制版基于 SnowMontain 的开源项目 [Knowledge OS](https://github.com/SnowMontain/Knowledge-OS) 演进。上游及本仓库代码遵循安装包中保留的 [MIT License](./LICENSE)，其版权与许可声明必须随源码及重要分发内容保留。

商业序列号控制本发行版提供的功能解锁与约定服务，不改变收件人依 MIT 对代码享有的复制、修改、再分发等权利；请勿把设备绑定表述为法律上的防复制或独家分发保证。客户笔记及素材另按其自身权利和授权处理。

网页正文提取能力包含 Steph Ango 的 Defuddle 代码；对应 MIT 许可单独保存在 [`DEFUDDLE-LICENSE.txt`](./DEFUDDLE-LICENSE.txt)。分发插件时必须同时保留该文件。
