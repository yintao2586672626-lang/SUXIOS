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

    public function testMaskedReviewUsernameLocksGuestFromRawImMemberUid(): void
    {
        $service = new CtripReviewOrderMatchService();

        $result = $service->matchReviewIdentity([
            'commentId' => 'comment-raw-uid',
            'userName' => 'M773721****',
        ], [
            [
                'groupId' => 'group-raw',
                'members' => [
                    [
                        'guestUid' => 'm7737213206',
                        'nickName' => '王佳虹',
                        'roleType' => 'guest',
                    ],
                ],
            ],
        ]);

        self::assertSame('person_locked', $result['status']);
        self::assertSame('m7737213206', $result['identity']['guest_uid']);
        self::assertSame('王佳虹', $result['identity']['guest_name']);
        self::assertSame('m7737213206', $result['identity']['matched_candidate']);
    }

    public function testSequentialMemberWithoutUidIsNotIndexedAsNumericArrayKey(): void
    {
        $service = new CtripReviewOrderMatchService();

        $result = $service->matchReviewIdentity([
            'commentId' => 'comment-no-uid',
            'userName' => 'M773721****',
        ], [
            [
                'groupId' => 'group-no-uid',
                'members' => [
                    [
                        'nickName' => '王佳虹',
                        'roleType' => 'guest',
                    ],
                ],
            ],
        ]);

        self::assertSame('unmatched', $result['status']);
        self::assertSame('im_member_cache_empty', $result['reason']);
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

    public function testMd5IdentityCanMatchRawOrderGuestUidFromMatchedCandidate(): void
    {
        $service = new CtripReviewOrderMatchService();
        $uid = md5('m7737213206');

        $result = $service->matchReviewToOrder([
            'commentId' => 'comment-raw-order-uid',
            'userName' => 'M773721****',
            'checkinTimeStr' => '2026-06-22',
            'hotelRoomInfo' => '精选双床房',
        ], [
            [
                'groupId' => 'group-md5',
                'members' => [
                    $uid => [
                        'uid' => $uid,
                        'nickName' => '王佳虹',
                        'roleType' => 'guest',
                    ],
                ],
            ],
        ], [
            [
                'orderId' => '1128148740643954',
                'guestUid' => 'm7737213206',
                'guestName' => '',
                'arrivalDate' => '2026-06-22',
                'roomName' => '精选双床房<双早>',
                'platform' => 'ctrip',
            ],
        ]);

        self::assertSame('found', $result['status']);
        self::assertSame('1128148740643954', $result['order']['order_id']);
        self::assertSame('王佳虹', $result['identity']['guest_name']);
        self::assertSame('m7737213206', $result['identity']['matched_candidate']);
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

    public function testMonthOnlyReviewDateOnlyProducesCandidateOrder(): void
    {
        $service = new CtripReviewOrderMatchService();
        $uid = md5('3207250779');

        $result = $service->matchReviewToOrder([
            'commentId' => '1972322725',
            'userName' => '320725****',
            'checkinTimeStr' => '2026-06',
            'hotelRoomInfo' => 'Room A',
        ], [
            [
                'groupId' => 'group-zhang',
                'members' => [
                    $uid => [
                        'uid' => $uid,
                        'nickName' => 'Guest Zhang',
                        'roleType' => 'guest',
                    ],
                ],
            ],
        ], [
            [
                'orderId' => '1128148273218642',
                'guestName' => 'Guest Zhang',
                'arrivalDate' => '2026-06-02',
                'roomName' => 'Room A No Breakfast',
                'platform' => 'ctrip',
            ],
        ]);

        self::assertSame('person_locked', $result['status']);
        self::assertSame('person_locked_order_evidence_insufficient', $result['reason']);
        self::assertSame('1128148273218642', $result['candidates'][0]['order_id']);
        self::assertSame('medium', $result['confidence']);
        self::assertSame('im_uid_month_room_candidate', $result['match_method']);
        self::assertSame('2026-06', $result['evidence']['order_filter']['review_month']);
        self::assertSame('month', $result['evidence']['order_filter']['review_date_precision']);
        self::assertSame('review_date_is_not_day_precision', $result['evidence']['order_filter']['auto_confirm_blocked_reason']);
    }

    public function testPreciseReviewDateMismatchDoesNotFallbackToUniqueRoomOrder(): void
    {
        $service = new CtripReviewOrderMatchService();
        $uid = md5('3207250779');

        $result = $service->matchReviewToOrder([
            'commentId' => 'date-mismatch',
            'userName' => '320725****',
            'checkinTimeStr' => '2026-06-01',
            'hotelRoomInfo' => 'Room A',
        ], [
            [
                'groupId' => 'group-zhang',
                'members' => [
                    $uid => [
                        'uid' => $uid,
                        'nickName' => 'Guest Zhang',
                        'roleType' => 'guest',
                    ],
                ],
            ],
        ], [
            [
                'orderId' => '1128148273218642',
                'guestName' => 'Guest Zhang',
                'arrivalDate' => '2026-06-02',
                'roomName' => 'Room A No Breakfast',
                'platform' => 'ctrip',
            ],
        ]);

        self::assertSame('person_locked', $result['status']);
        self::assertSame('person_locked_date_mismatch', $result['reason']);
        self::assertSame('im_uid_date_mismatch', $result['match_method']);
        self::assertCount(1, $result['candidates']);
    }

    public function testReviewPublishedBeforeCheckoutEligibilityDoesNotMatchOrder(): void
    {
        $service = new CtripReviewOrderMatchService();
        $uid = md5('3207250779');

        $result = $service->matchReviewToOrder([
            'commentId' => 'publish-too-early',
            'userName' => '320725****',
            'checkinTimeStr' => '2026-06-02',
            'addtime' => '2026年6月1日06:37:06',
            'hotelRoomInfo' => 'Room A',
        ], [
            [
                'groupId' => 'group-zhang',
                'members' => [
                    $uid => [
                        'uid' => $uid,
                        'nickName' => 'Guest Zhang',
                        'roleType' => 'guest',
                    ],
                ],
            ],
        ], [
            [
                'orderId' => '1128148273218642',
                'guestName' => 'Guest Zhang',
                'arrivalDate' => '2026-06-02',
                'departureDate' => '2026-06-03',
                'roomName' => 'Room A No Breakfast',
                'platform' => 'ctrip',
            ],
        ]);

        self::assertSame('person_locked', $result['status']);
        self::assertSame('person_locked_publish_time_mismatch', $result['reason']);
        self::assertSame('im_uid_publish_time_mismatch', $result['match_method']);
        self::assertSame('2026-06-01 06:37:06', $result['evidence']['review_published_at']);
        self::assertSame('1128148273218642', $result['candidates'][0]['order_id']);
        self::assertSame('2026-06-03', $result['candidates'][0]['departure_date']);
    }

    public function testReviewPublishedBeforeCheckoutDayTwoPmDoesNotMatchOrder(): void
    {
        $service = new CtripReviewOrderMatchService();
        $uid = md5('3207250779');

        $result = $service->matchReviewToOrder([
            'commentId' => 'publish-before-two-pm',
            'userName' => '320725****',
            'checkinTimeStr' => '2026-06-02',
            'addtime' => '2026-06-03 13:59:00',
            'hotelRoomInfo' => 'Room A',
        ], [
            [
                'groupId' => 'group-zhang',
                'members' => [
                    $uid => [
                        'uid' => $uid,
                        'nickName' => 'Guest Zhang',
                        'roleType' => 'guest',
                    ],
                ],
            ],
        ], [
            [
                'orderId' => '1128148273218642',
                'guestName' => 'Guest Zhang',
                'arrivalDate' => '2026-06-02',
                'departureDate' => '2026-06-03',
                'roomName' => 'Room A No Breakfast',
                'platform' => 'ctrip',
            ],
        ]);

        self::assertSame('person_locked', $result['status']);
        self::assertSame('person_locked_publish_time_mismatch', $result['reason']);
        self::assertSame('im_uid_publish_time_mismatch', $result['match_method']);
    }

    public function testReviewPublishedAfterCheckoutDayTwoPmCanMatchOrder(): void
    {
        $service = new CtripReviewOrderMatchService();
        $uid = md5('3207250779');

        $result = $service->matchReviewToOrder([
            'commentId' => 'publish-eligible',
            'userName' => '320725****',
            'checkinTimeStr' => '2026-06-02',
            'addtime' => '2026-06-03 14:01:00',
            'hotelRoomInfo' => 'Room A',
        ], [
            [
                'groupId' => 'group-zhang',
                'members' => [
                    $uid => [
                        'uid' => $uid,
                        'nickName' => 'Guest Zhang',
                        'roleType' => 'guest',
                    ],
                ],
            ],
        ], [
            [
                'orderId' => '1128148273218642',
                'guestName' => 'Guest Zhang',
                'arrivalDate' => '2026-06-02',
                'departureDate' => '2026-06-03',
                'roomName' => 'Room A No Breakfast',
                'platform' => 'ctrip',
            ],
        ]);

        self::assertSame('found', $result['status']);
        self::assertSame('1128148273218642', $result['order']['order_id']);
        self::assertSame('im_uid_date_room', $result['match_method']);
        self::assertSame('checkout_day_after_14_00', $result['evidence']['order_filter']['review_rule']);
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
