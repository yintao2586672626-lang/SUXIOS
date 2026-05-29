<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueResearchService;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;

final class RevenueResearchServiceTest extends TestCase
{
    use ReflectionHelper;

    public function testResearchProductsDoNotRequireReviewCollectionFields(): void
    {
        $products = $this->invokeNonPublic(new RevenueResearchService(), 'products');

        self::assertArrayNotHasKey('review-topic', $products);

        $disabledReviewFields = [
            'comment_text',
            'review_text',
            'comment_score',
            'comment_time',
            'reply_content',
        ];

        foreach ($products as $product) {
            foreach (($product['rules'] ?? []) as $rule) {
                $fields = (array)($rule['fields'] ?? []);
                foreach ($disabledReviewFields as $field) {
                    self::assertNotContains($field, $fields, 'Research product must not require disabled review field: ' . $field);
                }
            }
        }
    }

    public function testServiceQualityResearchUsesCapturedOtaOperatingData(): void
    {
        $products = $this->invokeNonPublic(new RevenueResearchService(), 'products');

        self::assertArrayHasKey('service-quality', $products);
        self::assertSame('service-quality', $products['service-quality']['key']);

        $rules = $products['service-quality']['rules'] ?? [];
        self::assertNotEmpty($rules);

        $onlineRule = null;
        foreach ($rules as $rule) {
            if (($rule['table'] ?? '') === 'online_daily_data') {
                $onlineRule = $rule;
                break;
            }
        }

        self::assertIsArray($onlineRule);
        $fields = (array)($onlineRule['fields'] ?? []);
        foreach (['data_date', 'amount', 'quantity', 'book_order_num', 'list_exposure', 'detail_exposure', 'flow_rate', 'raw_data'] as $field) {
            self::assertContains($field, $fields);
        }
    }
}
