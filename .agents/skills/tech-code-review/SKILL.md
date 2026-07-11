---
name: tech-code-review
description: Coordinate project code reviews across PR intake, critical review, coding standards, security, clean-code analysis, and report synthesis.
scene: "tech"
sub_scene: "code-review"
skills:
  - "pr-reviewer"
  - "critical-code-reviewer"
  - "project-code-standard"
  - "security-audit"
  - "clean-code-review"
  - "cody"
---

# 代码审查工作流

你现在要完成一次全面的代码审查任务。你已安装以下 Skill，请按步骤串联使用：

## 步骤 1：PR 接入与差异分析（获取层）
使用 **pr-reviewer** 完成：
- 自动获取 GitHub Pull Request 的代码差异（diff）
- 集成 lint 工具进行初步静态检查
- 分析变更文件范围、影响面和风险等级
- 标记新增/修改/删除的代码段

输出 PR 差异分析和初步 lint 结果。

## 步骤 2：严格代码质量审查（分析层）
使用 **Critical Code Reviewer** 完成：
- 以对抗性视角严格审查代码，绝不容忍平庸
- 检测潜在 Bug、逻辑错误和边界条件问题
- 识别安全漏洞（注入、XSS、敏感信息泄露等）
- 分析性能瓶颈和资源泄漏风险
- 支持 Python、JavaScript/TypeScript、SQL 及前端代码

记录所有质量问题和严重等级。

## 步骤 3：编码规范检查与自动修复（分析层）
使用 **Project Code Standard** 完成：
- 检查代码是否符合项目/团队编码规范
- 验证命名规范、缩进风格、导入顺序等格式要求
- 对格式问题执行自动修复
- 生成代码规范合规报告

输出规范检查结果和自动修复建议。

## 步骤 4：安全审计（分析层）
使用 **Security Audit** 完成：
- 扫描暴露的凭证、密钥和敏感配置
- 检测已知 CVE 漏洞和不安全依赖
- 审查认证授权逻辑、输入校验和加密实现
- 评估安全风险等级并提供修复方案

输出安全审计报告。

## 步骤 5：整洁代码原则验证（分析层）
使用 **Clean Code** 完成：
- 基于 KISS/DRY/YAGNI 原则审查代码设计
- 识别反模式（God Object、Long Method、Feature Envy 等）
- 评估函数/类的职责是否单一
- 检查代码可读性和可维护性
- 提出重构建议

输出整洁代码评估和重构建议。

## 步骤 6：生成结构化中文审查报告（输出层）
使用 **code-review-assistant** 完成：
- 汇总前五步的审查结果
- 生成结构化中文 Review 报告
- 报告覆盖：Bug、安全漏洞、性能问题、可读性、最佳实践、类型安全、错误处理、测试覆盖
- 按严重程度分级（致命/严重/警告/建议）

## 最终输出
将以上步骤的结果整合为完整的代码审查包，交付以下文件：
1. **代码审查报告**：按严重等级分类的问题清单和修复建议
2. **安全审计报告**：漏洞扫描结果和安全风险评估
3. **规范合规报告**：编码规范检查结果和自动修复记录
4. **重构建议清单**：基于整洁代码原则的优化方向
