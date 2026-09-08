<?php
declare(strict_types=1);

namespace app\service;

/** Deterministic evidence selection evaluation; does not call or grade an LLM. */
final class KnowledgeRetrievalEvaluationService
{
    public function questions(): array
    {
        return json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/resources/knowledge/representative-questions.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function evaluate(array $units, array $chunks, array $context, ?array $questions = null): array
    {
        $retrieval = new OperatingQuestionKnowledgeRetrievalService();
        $results = [];
        foreach ($questions ?? $this->questions() as $question) {
            $scope = array_replace($context, $question['context'] ?? [], ['question' => $question['question']]);
            $result = $retrieval->buildFromRows($units, $chunks, $scope);
            $results[] = [
                'id' => $question['id'], 'question' => $question['question'], 'category' => $question['category'],
                'status' => $result['status'],
                'refs' => array_column($result['items'], 'ref'),
                'decision_refs' => array_column(array_filter($result['items'], static fn($item) => $item['decision_safe']), 'ref'),
                'citations' => array_map(static fn($item) => ['ref' => $item['ref'], 'digest' => $item['content_digest'], 'version' => $item['version'], 'usage_policy' => $item['usage_policy']], $result['items']),
                'conflicts' => $result['conflicts'] ?? [],
                'excluded_reasons' => array_values(array_unique(array_merge([], ...array_column($result['exclusions'] ?? [], 'reason_codes')))),
            ];
        }
        return ['status' => 'evaluated', 'method' => OperatingQuestionKnowledgeRetrievalService::METHOD, 'evidence_boundary' => 'deterministic_retrieval_and_citations_not_llm_quality', 'question_count' => count($results), 'questions' => $results];
    }

    public function compare(array $units, array $before, array $after, array $context): array
    {
        $old = $this->evaluate($units, $before, $context);
        $new = $this->evaluate($units, $after, $context);
        $affected = [];
        foreach ($new['questions'] as $index => $result) {
            if ((new KnowledgeContentDigestService())->digest($old['questions'][$index]) !== (new KnowledgeContentDigestService())->digest($result)) {
                $affected[] = ['id' => $result['id'], 'question' => $result['question'], 'before' => $old['questions'][$index], 'after' => $result];
            }
        }
        return ['status' => 'reevaluated', 'scope' => 'selected_unit_only', 'question_count' => $new['question_count'], 'affected_count' => count($affected), 'affected_questions' => $affected, 'evidence_boundary' => $new['evidence_boundary']];
    }
}
