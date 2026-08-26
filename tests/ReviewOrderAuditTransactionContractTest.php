<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

final class ReviewOrderAuditTransactionContractTest extends TestCase
{
    #[DataProvider('transactionalAuditMethods')]
    public function testBusinessMutationAuditIsWrittenBeforeCommit(string $trait, string $method): void
    {
        $reflection = new ReflectionMethod($trait, $method);
        $lines = file($reflection->getFileName(), FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        $source = implode("\n", array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));

        $transaction = strpos($source, 'Db::startTrans()');
        $audit = strpos($source, 'OperationLog::record');
        $commit = strpos($source, 'Db::commit()');
        $rollback = strpos($source, 'Db::rollback()');

        self::assertNotFalse($transaction, $method . ' must start a transaction');
        self::assertNotFalse($audit, $method . ' must write an audit record');
        self::assertNotFalse($commit, $method . ' must commit');
        self::assertNotFalse($rollback, $method . ' must roll back injected audit failures');
        self::assertLessThan($audit, $transaction, $method . ' must write audit after transaction starts');
        self::assertLessThan($commit, $audit, $method . ' must write audit before commit');
        self::assertLessThan($rollback, $commit, $method . ' catch/rollback must follow commit attempt');
    }

    public static function transactionalAuditMethods(): array
    {
        return [
            [\app\controller\concern\MeituanReviewOrderMatchConcern::class, 'bindMeituanReviewOrderMatch'],
            [\app\controller\concern\MeituanReviewOrderMatchConcern::class, 'rejectMeituanReviewOrderMatch'],
            [\app\controller\concern\MeituanReviewOrderMatchConcern::class, 'unbindMeituanReviewOrderMatch'],
            [\app\controller\concern\CtripReviewOrderMatchConcern::class, 'rejectCtripReviewOrderMatch'],
            [\app\controller\concern\CtripReviewOrderMatchConcern::class, 'unbindCtripReviewOrderMatch'],
        ];
    }
}
