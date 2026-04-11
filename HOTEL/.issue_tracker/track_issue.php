<?php
/**
 * 问题追踪系统 - 自动记录问题并归档到解决方案库
 * 
 * 使用方法:
 * php track_issue.php "问题标题" "问题类别" ["错误信息"]
 * 
 * 示例:
 * php track_issue.php "数据库连接超时" "数据库" "Connection timed out"
 */

declare(strict_types=1);

class IssueTracker
{
    private string $trackerDir;
    private string $issuesFile;
    private string $solutionFile;
    private int $threshold;

    public function __construct()
    {
        $this->trackerDir = __DIR__;
        $this->issuesFile = $this->trackerDir . '/issues.json';
        $this->solutionFile = dirname($this->trackerDir) . '/项目问题解决方案库.md';
        $this->threshold = 4;
        
        // 确保目录存在
        if (!is_dir($this->trackerDir)) {
            mkdir($this->trackerDir, 0755, true);
        }
    }

    /**
     * 记录问题
     */
    public function trackIssue(string $title, string $category, ?string $errorMsg = null): array
    {
        $issues = $this->loadIssues();
        
        // 查找是否已存在该问题
        $existingIndex = $this->findIssueIndex($issues['issues'], $title);
        
        if ($existingIndex !== null) {
            // 更新现有问题
            $issues['issues'][$existingIndex]['count']++;
            $issues['issues'][$existingIndex]['lastSeen'] = date('Y-m-d');
            
            $currentCount = $issues['issues'][$existingIndex]['count'];
            $issueId = $issues['issues'][$existingIndex]['id'];
            $isNew = false;
        } else {
            // 创建新问题
            $issueId = 'ISSUE-' . str_pad((string)(count($issues['issues']) + 1), 3, '0', STR_PAD_LEFT);
            $newIssue = [
                'id' => $issueId,
                'title' => $title,
                'category' => $category,
                'count' => 1,
                'firstSeen' => date('Y-m-d'),
                'lastSeen' => date('Y-m-d'),
                'status' => 'active',
                'autoAdded' => false,
                'errorMessage' => $errorMsg,
                'notes' => ''
            ];
            $issues['issues'][] = $newIssue;
            $currentCount = 1;
            $isNew = true;
        }
        
        // 检查是否达到阈值
        $shouldArchive = $currentCount >= $this->threshold;
        
        if ($shouldArchive && !$issues['issues'][$existingIndex ?? count($issues['issues']) - 1]['autoAdded']) {
            $this->archiveToSolutionLibrary($issues['issues'][$existingIndex ?? count($issues['issues']) - 1]);
            $issues['issues'][$existingIndex ?? count($issues['issues']) - 1]['autoAdded'] = true;
            $issues['issues'][$existingIndex ?? count($issues['issues']) - 1]['notes'] = '已达到4次阈值，已自动归档到解决方案库';
        }
        
        // 更新统计
        $issues['stats'] = $this->calculateStats($issues['issues']);
        $issues['lastUpdated'] = date('Y-m-d');
        
        // 保存
        $this->saveIssues($issues);
        
        return [
            'id' => $issueId,
            'count' => $currentCount,
            'isNew' => $isNew,
            'thresholdReached' => $shouldArchive,
            'message' => $this->generateMessage($title, $currentCount, $shouldArchive)
        ];
    }

    /**
     * 查找问题索引
     */
    private function findIssueIndex(array $issues, string $title): ?int
    {
        foreach ($issues as $index => $issue) {
            if ($issue['title'] === $title) {
                return $index;
            }
        }
        return null;
    }

    /**
     * 归档到解决方案库
     */
    private function archiveToSolutionLibrary(array $issue): void
    {
        if (!file_exists($this->solutionFile)) {
            echo "⚠️  警告: 解决方案库文件不存在: {$this->solutionFile}\n";
            return;
        }

        $newEntry = $this->generateSolutionEntry($issue);
        
        // 读取现有内容
        $content = file_get_contents($this->solutionFile);
        
        // 在 "## 🔧 快速修复命令集" 之前插入新问题
        $insertMarker = "## 🔧 快速修复命令集";
        $position = strpos($content, $insertMarker);
        
        if ($position !== false) {
            $newContent = substr($content, 0, $position) . $newEntry . "\n" . substr($content, $position);
            file_put_contents($this->solutionFile, $newContent);
            
            // 同时记录到待处理列表
            $this->addToPending($issue);
            
            echo "\n✅ 已自动归档到解决方案库!\n";
            echo "📁 请完善详细信息后提交到版本控制\n";
        }
    }

    /**
     * 生成解决方案条目
     */
    private function generateSolutionEntry(array $issue): string
    {
        $date = date('Y-m-d');
        $issueNum = $this->getNextIssueNumber();
        $errorMsg = $issue['errorMessage'] ?? '未记录';
        $threshold = $this->threshold;
        
        return <<<MD

### 问题 #{$issueNum}：{$issue['title']} 【自动归档】

| 项目 | 内容 |
|------|------|
| **问题描述** | {$issue['title']} |
| **问题类型** | {$issue['category']} |
| **发生次数** | {$issue['count']} 次（达到归档阈值） |
| **首次出现** | {$issue['firstSeen']} |
| **最后出现** | {$issue['lastSeen']} |
| **错误信息** | {$errorMsg} |
| **根本原因** | ⚠️ 待补充 - 请分析并填写 |
| **解决方案** | ⚠️ 待补充 - 请填写详细解决步骤 |
| **预防措施** | ⚠️ 待补充 - 请填写如何避免再次发生 |
| **自动归档时间** | {$date} |

> 📝 **注意**: 本问题已达到 {$threshold} 次发生阈值，系统自动归档。
> 请开发者补充完整的问题分析和解决方案。

---

MD;
    }

    /**
     * 获取下一个问题编号
     */
    private function getNextIssueNumber(): int
    {
        $content = file_get_contents($this->solutionFile);
        preg_match_all('/### 问题 #(\d+)/', $content, $matches);
        $nums = isset($matches[1]) ? $matches[1] : [0];
        $maxNum = max(array_map('intval', $nums));
        return $maxNum + 1;
    }

    /**
     * 添加到待处理列表
     */
    private function addToPending(array $issue): void
    {
        $issues = $this->loadIssues();
        $issues['pendingIssues'][] = [
            'id' => $issue['id'],
            'title' => $issue['title'],
            'category' => $issue['category'],
            'addedAt' => date('Y-m-d H:i:s'),
            'status' => '待完善'
        ];
        $this->saveIssues($issues);
    }

    /**
     * 生成消息
     */
    private function generateMessage(string $title, int $count, bool $thresholdReached): string
    {
        if ($thresholdReached) {
            return "🔴 [{$title}] 已达到 {$count} 次! 已自动归档到解决方案库，请补充完整信息。";
        }
        
        $remaining = $this->threshold - $count;
        return "🟡 [{$title}] 当前 {$count} 次，再发生 {$remaining} 次将自动归档。";
    }

    /**
     * 加载问题列表
     */
    private function loadIssues(): array
    {
        if (!file_exists($this->issuesFile)) {
            return [
                'version' => '1.0',
                'lastUpdated' => date('Y-m-d'),
                'issues' => [],
                'pendingIssues' => [],
                'stats' => ['totalIssues' => 0, 'pendingThreshold' => 0]
            ];
        }
        
        return json_decode(file_get_contents($this->issuesFile), true);
    }

    /**
     * 保存问题列表
     */
    private function saveIssues(array $issues): void
    {
        file_put_contents($this->issuesFile, json_encode($issues, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 计算统计
     */
    private function calculateStats(array $issues): array
    {
        $total = count($issues);
        $resolved = count(array_filter($issues, fn($i) => $i['status'] === 'resolved'));
        $autoAdded = count(array_filter($issues, fn($i) => isset($i['autoAdded']) ? $i['autoAdded'] : false));
        $pendingThreshold = count(array_filter($issues, fn($i) => $i['count'] >= $this->threshold && !(isset($i['autoAdded']) ? $i['autoAdded'] : false)));
        
        return [
            'totalIssues' => $total,
            'resolvedIssues' => $resolved,
            'autoAddedIssues' => $autoAdded,
            'pendingThreshold' => $pendingThreshold
        ];
    }

    /**
     * 显示统计信息
     */
    public function showStats(): void
    {
        $issues = $this->loadIssues();
        $stats = $issues['stats'];
        
        echo "\n";
        echo "╔════════════════════════════════════════╗\n";
        echo "║         📊 问题追踪统计                ║\n";
        echo "╠════════════════════════════════════════╣\n";
        echo "║ 总问题数:        {$this->pad($stats['totalIssues'])}                ║\n";
        echo "║ 已解决:          {$this->pad($stats['resolvedIssues'])}                ║\n";
        echo "║ 自动归档:        {$this->pad($stats['autoAddedIssues'])}                ║\n";
        echo "║ 待处理阈值:      {$this->pad($stats['pendingThreshold'])}                ║\n";
        echo "╚════════════════════════════════════════╝\n";
        echo "\n阈值设置: {$this->threshold} 次自动归档\n";
        
        if (!empty($issues['pendingIssues'])) {
            echo "\n📋 待完善的问题:\n";
            foreach ($issues['pendingIssues'] as $pending) {
                echo "   - [{$pending['id']}] {$pending['title']}\n";
            }
        }
    }

    private function pad($value): string
    {
        return str_pad((string)$value, 4, ' ', STR_PAD_LEFT);
    }

    /**
     * 列出所有问题
     */
    public function listIssues(): void
    {
        $issues = $this->loadIssues();
        echo "\n📋 所有记录的问题:\n\n";
        foreach ($issues['issues'] as $issue) {
            $autoAdded = isset($issue['autoAdded']) ? $issue['autoAdded'] : false;
            $status = $autoAdded ? '📁' : ($issue['status'] === 'resolved' ? '✅' : '🟡');
            echo "{$status} [{$issue['id']}] {$issue['title']} ({$issue['count']}次)\n";
        }
        echo "\n";
    }
}

// 命令行入口
if (PHP_SAPI === 'cli') {
    $tracker = new IssueTracker();
    
    if ($argc < 2) {
        echo "\n📋 问题追踪系统\n";
        echo "用法: php track_issue.php [命令] [参数...]\n\n";
        echo "命令:\n";
        echo "  track \"问题标题\" \"类别\" [\"错误信息\"]  - 记录问题\n";
        echo "  stats                                      - 查看统计\n";
        echo "  list                                       - 列出所有问题\n\n";
        echo "示例:\n";
        echo "  php track_issue.php track \"数据库连接超时\" \"数据库\" \"Connection timed out\"\n\n";
        exit(0);
    }
    
    $command = $argv[1];
    
    switch ($command) {
        case 'track':
            if ($argc < 4) {
                echo "❌ 错误: 缺少参数\n";
                echo "用法: php track_issue.php track \"问题标题\" \"类别\" [\"错误信息\"]\n";
                exit(1);
            }
            $title = $argv[2];
            $category = $argv[3];
            $errorMsg = isset($argv[4]) ? $argv[4] : null;
            
            $result = $tracker->trackIssue($title, $category, $errorMsg);
            echo "\n" . $result['message'] . "\n";
            echo "问题ID: {$result['id']}, 当前次数: {$result['count']}\n\n";
            break;
            
        case 'stats':
            $tracker->showStats();
            break;
            
        case 'list':
            $tracker->listIssues();
            break;
            
        default:
            echo "❌ 未知命令: {$command}\n";
            exit(1);
    }
} else {
    echo "❌ 此脚本只能通过命令行运行\n";
    exit(1);
}
