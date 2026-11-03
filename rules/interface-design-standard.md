# 宿析OS界面设计规范

适用：登录后的应用外壳、经营首页及逐步修改到的业务页面。登录页继续作为深绿与香槟金的品牌基准。2026-09-08 建立；以实际业务操作与可读性验收。

## 设计目标

- 第一眼能找到门店、业务日期、可信经营事实和今日待办；一个任务只在主列表出现一次。
- 主内容明亮、导航沉稳、信息层级明确。深绿用于品牌和主操作，香槟金用于小面积强调。
- 页面美化必须保留来源、范围、缺失、失败和未验证状态。空状态解释下一步，不能制造数字、图表或完成率。
- 复用 `--color-*`、`--sx-dashboard-*` 等现有语义变量及组件；不另起一套 UI 框架或主题依赖。

## 视觉执行

| 对象 | 默认要求 |
| --- | --- |
| 背景与表面 | 页面 `#f4f6f4`，工作面白色，导航 `#102c25`；减少大面积米黄、深色主内容和渐变 |
| 文本 | 正文 `#1c3028`，辅助文字 `#5b6d63`；不能通过整体降低透明度表现次要内容 |
| 字体 | 正文 14px/22px，必要辅助信息 12px/20px，区块标题 20px/28px，页面标题 28–34px；数字用等宽数字排版 |
| 间距 | 控件内 8/12/16px，区块间 16/24px，桌面页面边距 32px，手机 16px；同一层级使用同一间距 |
| 容器 | 普通工作卡 12–16px 圆角、1px 浅边框、极轻阴影；不在卡片中重复套边框和阴影；浮层才使用明显投影 |
| 操作 | 一个区域最多一个视觉主操作，其余用描边或文本按钮；同类动作保持同色同形；真实危险动作保留危险语义 |
| 导航 | 当前页用背景、文字与指示线共同标识；导航标签简短；低频入口留在有意义的分组内 |
| 表格 | 对齐表头与数据，金额右对齐，行高 40–48px，允许表格容器独立横滚，避免整页被撑宽 |
| 动效 | 150–220ms 的颜色与轻微状态过渡；不做悬浮卡片跳动或持续装饰动画；尊重 reduced motion |

这些是产品设计选择，参考 [Fluent 字体](https://fluent2.microsoft.design/typography)、[布局](https://fluent2.microsoft.design/layout)、[导航](https://fluent2.microsoft.design/components/web/react/core/nav/usage)、[GitHub 中的 Fluent 语义变量设计](https://github.com/microsoft/fluentui/blob/master/docs/architecture/design-tokens.md)、[Atlassian 层级](https://atlassian.design/foundations/elevation/) 和 [Carbon 表格](https://carbondesignsystem.com/components/data-table/usage/)，并非上述系统对宿析OS的认证。

## 必须检查的可访问性

- 常规文字对比度至少 4.5:1，大字至少 3:1；必要控件边界及状态图形至少 3:1。装饰性分隔线不等同于必要控件边界。
- 状态不能只靠颜色表达；鼠标悬停不能是发现操作的唯一方式。
- 键盘焦点清晰可见且不被固定区域遮挡；表单有名称，展开收起保留语义。
- 320 CSS px 宽度下内容可重排、操作可到达。复杂数据表可独立滚动，不把整页做成横向画布。
- WCAG 2.2 AA 的目标尺寸要求为 24×24 CSS px（有例外）；本系统主要按钮与手机控件优先 44px，不能把 44px 写成普遍的 AA 强制条款。

依据：[WCAG 2.2 对比度](https://www.w3.org/TR/WCAG22/#contrast-minimum)、[非文字对比度](https://www.w3.org/WAI/WCAG22/understanding/non-text-contrast.html)、[颜色使用](https://www.w3.org/TR/WCAG22/#use-of-color)、[焦点可见](https://www.w3.org/TR/WCAG22/#focus-visible)、[重排](https://www.w3.org/TR/WCAG22/#reflow)、[目标尺寸](https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html)。局部检查通过不代表整站 WCAG 认证。

## 修改与验收

1. 先定位实际页面和直接依赖；保存改动前的证据，不以截图中的占位数据替换经营事实。
2. 修改源模板、公共语义样式和组件；不直接修改压缩产物或手写版本哈希。
3. 使用现有构建命令同步模板、入口、样式和 helper。`build:authenticated-style` 同步首页独立样式哈希，`verify:authenticated-style` 拒绝过期版本。
4. 检查桌面、窄屏、空状态、错误状态、长内容、键盘焦点及实际收到的资源；涉及行为变化时运行对应回归测试。
5. 交付写清实际浏览器、模拟夹具与未验证路径。不得把本地外观验证写成上线、真实采集成功或经营效果。
