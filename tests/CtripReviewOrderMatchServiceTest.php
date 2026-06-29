<?php
declare(strict_types=1);

namespace Tests;

use app\service\CtripReviewOrderMatchService;
use PHPUnit\Framework\TestCase;

final class CtripReviewOrderMatchServiceTest extends TestCase
{
    public function testMaskedReviewUsernameLocksGuestFromImMemberUidHash(): void
    {
        $service = new CtripReviewOrderMatchService();
        $uid = md5('m5193520007');

        $result = $service->matchReviewIdentity([
            'commentId' => 'comment-1',
            'userName' => 'M519352****',
        ], [
            [
                'groupId' => 'group-1',
                'members' => [
                    $uid => [
                        'uid' => $uid,
                        'nickName' => '刘玉',
                        'pic' => 'https://example.test/avatar.jpg',
                        'roleType' => 'guest',
                    ],
                    md5('robot0000') => [
                        'uid' => md5('robot0000'),
                        'nickName' => '智能客服',
                        'roleType' => 'robot',
                    ],
                ],
            ],
        ]);

        self::assertSame('person_locked', $result['status']);
        self::assertSame('medium', $result['confidence']);
        self::assertSame($uid, $result['identity']['guest_uid']);
        self::assertSame('刘玉', $result['identity']['guest_name']);
        self::assertSame('m5193520007', $result['identity']['matched_candidate']);
        self::assertSame('member_uid_md5_candidate', $result['match_method']);
    }

    public function testReviewOrderMatchReturnsFoundWhenIdentityAndOrderEvidenceAlign(): void
    {
        $service = new CtripReviewOrderMatchService();
        $uid = md5('m5193520007');

        $result = $service->matchReviewToOrder([
            'commentId' => 'comment-1',
            'userName' => 'M519352****',
            'checkinTimeStr' => '入住 2026-06-28',
            'hotelRoomInfo' => '高级大床房',
        ], [
            [
                'groupId' => 'group-1',
                'members' => [
                    $uid => [
                        'uid' => $uid,
                        'nickName' => '刘玉',
                        'roleType' => 'guest',
                    ],
                ],
            ],
        ], [
            [
                'orderId' => '1128148172921068',
                'guestUid' => $uid,
                'guestName' => '刘玉',
                'arrivalDate' => '2026-06-28',
                'roomName' => '高级大床房-含早',
                'platform' => 'ctrip',
            ],
        ]);

        self::assertSame('found', $result['status']);
        self::assertSame('1128148172921068', $result['order']['order_id']);
        self::assertSame('high', $result['confidence']);
        self::assertSame('im_uid_date_room', $result['match_method']);
    }

    public function testStandardizedReviewAndOrderAliasesCanMatch(): void
    {
        $service = new CtripReviewOrderMatchService();
        $uid = md5('m5193520007');

        $result = $service->matchReviewToOrder([
            'review_id' => 'review-standard-1',
            'user_name_masked' => 'M519352****',
            'check_in_date' => '2026-06-28',
            'room_type' => 'Deluxe King',
        ], [
            [
                'groupId' => 'group-standard-1',
                'members' => [
                    $uid => [
                        'uid' => $uid,
                        'nickName' => 'Guest A',
                        'roleType' => 'guest',
                    ],
                ],
            ],
        ], [
            [
                'orderNo' => 'CTRIP-ORDER-1',
                'memberUid' => $uid,
                'contactName' => 'Guest A',
                'checkIn' => '2026-06-28',
                'room_type_name' => 'Deluxe King Breakfast',
                'platform' => 'ctrip',
            ],
        ]);

        self::assertSame('found', $result['status']);
        self::assertSame('CTRIP-ORDER-1', $result['order']['order_id']);
        self::assertSame('Guest A', $result['order']['guest_name']);
        self::assertSame($uid, $result['order']['guest_uid']);
        self::assertSame('im_uid_date_room', $result['match_method']);
    }

    public function testPartialYearCoverageDateDoesNotBlockMatching(): void
    {
        $service = new CtripReviewOrderMatchService();
        $uid = md5('m5193520007');

        $result = $service->matchReviewToOrder([
            'commentId' => 'comment-year-coverage',
            'user_name_masked' => 'M519352****',
            'check_in_date' => '2026-06-28',
            'room_type' => 'Deluxe King',
        ], [
            [
                'groupId' => 'group-year-coverage',
                'members' => [
                    $uid => [
                        'uid' => $uid,
                        'nickName' => 'Guest A',
                        'roleType' => 'guest',
                    ],
                ],
            ],
        ], [
            [
                'orderNo' => 'CTRIP-ORDER-YEAR-COVERAGE',
                'memberUid' => $uid,
                'contactName' => 'Guest A',
                'checkIn' => '2026-06-28',
                'room_type_name' => 'Deluxe King Breakfast',
            ],
        ], [
            'coverage_start_date' => '2026',
        ]);

        self::assertSame('found', $result['status']);
        self::assertSame('CTRIP-ORDER-YEAR-COVERAGE', $result['order']['order_id']);
    }

    public function testReviewOrderMatchReturnsPersonLockedWhenSameGuestHasMultipleOrders(): void
    {
        $service = new CtripReviewOrderMatchService();
        $uid = md5('m5193520007');

        $result = $service->matchReviewToOrder([
            'commentId' => 'comment-1',
            'userName' => 'M519352****',
            'checkinTimeStr' => '入住 2026-06-28',
            'hotelRoomInfo' => '高级大床房',
        ], [
            [
                'groupId' => 'group-1',
                'members' => [
                    $uid => [
                        'uid' => $uid,
                        'nickName' => '刘玉',
                        'roleType' => 'guest',
                    ],
                ],
            ],
        ], [
            [
                'orderId' => '1128148172921068',
                'guestUid' => $uid,
                'guestName' => '刘玉',
                'arrivalDate' => '2026-06-28',
                'roomName' => '高级大床房-含早',
                'platform' => 'ctrip',
            ],
            [
                'orderId' => '1128148172921069',
                'guestUid' => $uid,
                'guestName' => '刘玉',
                'arrivalDate' => '2026-06-28',
                'roomName' => '高级大床房-无早',
                'platform' => 'ctrip',
            ],
        ]);

        self::assertSame('person_locked', $result['status']);
        self::assertSame('刘玉', $result['person_name']);
        self::assertCount(2, $result['candidates']);
    }

    public function testReviewBeforeCoverageReturnsOutOfCoverage(): void
    {
        $service = new CtripReviewOrderMatchService();

        $result = $service->matchReviewToOrder([
            'commentId' => 'old-comment',
            'userName' => 'Unknown****',
            'checkinTimeStr' => '入住 2026-05-01',
        ], [], [], [
            'coverage_start_date' => '2026-06-01',
        ]);

        self::assertSame('out_of_coverage', $result['status']);
        self::assertSame('review_before_order_coverage', $result['reason']);
    }
}
