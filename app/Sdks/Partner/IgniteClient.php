<?php

/*
 * Ignite partner API client. Guzzle only, no framework.
 *
 * Setup
 *   composer require guzzlehttp/guzzle
 *   Copy IgniteClient.php and ResultCode.php; change the namespace.
 *
 *   Laravel, in AppServiceProvider::register(), values from config/services.php:
 *     $this->app->singleton(IgniteClient::class, fn () => new IgniteClient(
 *         config('services.ignite.base_url'),   // https://<ignite host>/partner/v1
 *         config('services.ignite.api_key'),
 *     ));
 *
 * Transport
 *   Auth     Authorization: Bearer <api_key>, on every call
 *   Methods  GET reads, POST writes
 *   Bodies   JSON both ways; a POST sends Content-Type: application/json
 *
 * Members
 *   A request names a member by `ba_id`, except a placement parent, which may be named by
 *   `member_id` instead. Every member a response names carries `member_id`.
 *   Every `*_member_id` / `*_id` naming a member comes with its `*_ba_id`. The BA ID is filled for
 *   this partner's own members only, and null for anyone else (an Ignite member, or another
 *   partner's), whose `member_id` still comes.
 *   Two exceptions: pendingPlacements sends the BA ID and full name of every referral it lists,
 *   outsiders included, since the sponsor seats them with setPlacement by BA ID; validateSponsor
 *   echoes the BA ID it was sent, whoever holds it.
 *   Every call taking `ba_id` acts on this partner's own members only, except validateSponsor,
 *   registerMember's sponsor, a placement parent, and setPlacement on a member from
 *   pendingPlacements; any other BA ID answers MEMBER_NOT_FOUND (3001).
 *   A wrong sponsor or position is corrected by Ignite operations on request; no call changes
 *   either. Ignite does not notify the change: once Ignite operations confirm it, re-read memberDetails
 *   and update the stored sponsor and position. A member still unplaced then shows in the new
 *   sponsor's placement window and is placed with setPlacement before the deadline; a placed
 *   member's position moves with the sponsor.
 *
 * Placement
 *   A placement parent is a node in the sponsor's binary network with a free side, usually the
 *   end of a leg. Free sides start one level below a -001: its own two sides always hold its
 *   -002 (left) and -003 (right), so naming a -001 answers PLACEMENT_REFUSED. The end of each leg is far_left_id (send side 1) or far_right_id
 *   (send side 2) from memberDetails of the upline, sent as placement_member_id; the matching
 *   *_ba_id is null when that end belongs to a member outside this partner. Applies to
 *   registerMember, setPlacement, createOrder extra_tcs and placeBankedTc.
 *
 * Sign-up of a person who already holds a BA ID
 *   A world plan member moved to this partner by Ignite already holds one. Ask for it at sign-up,
 *   before anything else, and look it up with memberDetails. Found, and not in the partner's own
 *   records: run the partner's own sign-up and KYC, then store the BA ID. Never registerMember:
 *   sponsor and position already exist. MEMBER_NOT_FOUND means no such BA ID, or not yet moved
 *   to this partner.
 *
 * Amounts are decimal strings with 4 places, e.g. "120.0000". Money (prices, commission, the
 * cash_bal balance, the wd file) is USD; CV and coins are counts, not money.
 *
 * Ranks are named by ladder position, `rank_no`: 1 Silver to 8 Diamond, 0 = no rank. planRanks
 * lists the ladder.
 *
 * Times: every date and time sent or returned is UTC+8. Dates "Y-m-d", times "Y-m-d H:i:s".
 * A transaction row's `created_at` adds milliseconds: "Y-m-d H:i:s.v".
 * Convert local time before sending: India 14:30 (UTC+5:30) is "17:00" here. A wrong zone moves an
 * order across a week or month cutoff without any error.
 *
 * Field names say CV. Enum values spell it `pv` (point_type pv1_bal and pv2_bal, descriptions
 * pv_*); pv there means CV.
 *
 * Response format
 *   Every endpoint answers in one envelope, except the file exportDownload returns:
 *
 *     { "code": 0, "msg": "", "data": { ... } }
 *
 *   code  0 on success. Anything else is a refusal, listed in ResultCode. Branch on this.
 *         Each code's note in ResultCode says when it is returned.
 *   msg   empty on success. On a refusal, readable text for a log, with the figures behind it
 *         where there are any.
 *         Wording can change; never branch on it.
 *   data  the result on success. null on a refusal, except the codes whose ResultCode note
 *         names a `data` field. VALIDATION_FAILED (2001) holds the failing fields:
 *         { "qty": ["The qty field must be at least 1."] }
 *
 * HTTP status
 *   200  code 0
 *   400  a business or validation refusal
 *   401  API_KEY_MISSING (1001) or API_KEY_UNKNOWN (1002)
 *   403  IP_NOT_ALLOWED (1003)
 *   500  fault on Ignite's side; the body is not the envelope
 *
 * Every method except exportDownload returns the envelope as an array. Branch on `code` against ResultCode:
 *   $res['code'] === ResultCode::ORDER_DUPLICATE->value
 * exportDownload returns the file's text and throws on a refusal (see its docblock).
 * A method throws GuzzleException when Ignite cannot be reached, and RuntimeException
 * when the answer is not the envelope.
 */

namespace App\Sdks\Partner;

use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class IgniteClient
{
    private Client $http;
    private string $baseUrl;

    public function __construct(string $baseUrl, string $apiKey, int $timeoutSec = 30)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->http = new Client([
            'timeout'     => $timeoutSec,
            'http_errors' => false,
            'headers'     => [
                'Authorization' => "Bearer {$apiKey}",
                'Accept'        => 'application/json',
                'User-Agent'    => 'IgniteClient-PHP/1.0',
            ],
        ]);
    }

    /**
     * Sales months for one sales year, each with its weeks nested.
     *
     * `is_settled` turns true once the Saturday settlement on a week finishes, not when it starts.
     * A month is named by `year` + `month_no`, a week by `year` + `week_no`.
     *
     * GET /meta/getSalesCalendar?year=2026
     *
     * data: {
     *   "year": 2026,
     *   "months": [
     *     {
     *       "year": 2026, "month_no": 9, "label": "2026-M09", "week_count": 5,
     *       "start_date": "2026-08-22", "end_date": "2026-09-25", "is_current": true,
     *       "weeks": [
     *         {
     *           "year": 2026, "week_no": 37, "week_in_month": 4,
     *           "start_date": "2026-09-12", "end_date": "2026-09-18", "is_current": true, "is_settled": true
     *         }
     *       ]
     *     }
     *   ]
     * }
     *
     * Refusal codes: VALIDATION_FAILED
     *
     * @param  int  $year  sales year. It can end days into the next calendar year, so around New Year
     *                     ask for the calendar year and, when no week comes back `is_current`, the one before
     */
    public function salesCalendar(int $year): array
    {
        return $this->get('/meta/getSalesCalendar', ['year' => $year]);
    }

    /**
     * The partner's plan figures and the rank ladder, lowest rank first. Set by Ignite; they change
     * rarely. For display only: Ignite calculates every rank.
     *
     * GET /meta/getPlanRanks
     *
     * data: {
     *   "plan": {
     *     "code": "A", "name": "Elite",
     *     "qualify_cv": "300.0000", "activation_cv": "300.0000", "pairing_step_cv": "1800.0000",
     *     "base_product_cv": "600.0000", "req_direct_cv_yearly": "300.0000",
     *     "green_redeem_usd": "125.0000", "yellow_usd_small": "30.0000", "yellow_usd_large": "40.0000",
     *     "blue_usd": "12.5000"
     *   },
     *   "ranks": [
     *     { "rank_no": 1, "name": "Silver", "step_comm": "250.0000", "req_steps": 0,
     *       "req_direct_cv_monthly": "0.0000", "req_tenure_months": 1, "req_prev_rank_count": 0,
     *       "req_prev2_rank_count": 0, "max_weekly_steps": 20 },
     *     { "rank_no": 8, "name": "Diamond", "step_comm": "285.0000", "req_steps": 310,
     *       "req_direct_cv_monthly": "1800.0000", "req_tenure_months": 3, "req_prev_rank_count": 2,
     *       "req_prev2_rank_count": 10, "max_weekly_steps": 105 }
     *   ]
     * }
     *
     *   qualify_cv             CV the -001 takes to qualify, which opens the other nodes to CV
     *   activation_cv          CV each side of the -001 needs before pairing starts
     *   pairing_step_cv        CV per side making one pairing step
     *   base_product_cv        CV buying one extra TC out of an order's surplus
     *   req_direct_cv_yearly   direct CV per year from year two, ranks above Silver
     *   green_redeem_usd       USD the Saturday run pays for every 6 Green Coins
     *   yellow_usd_small       USD per Yellow Coin when redeemCoin takes 1 or 2
     *   yellow_usd_large       USD per Yellow Coin when redeemCoin takes 3 or more
     *   blue_usd               USD per Blue Coin; redeemCoin takes 4 or more
     *   rank_no                ladder position, 1 lowest to 8 highest; what memberDetails and rankProgress carry
     *   step_comm              USD paid per pairing step at this rank
     *   req_steps              pairing steps the rank needs
     *   req_direct_cv_monthly  direct CV inside one sales month to reach or hold the rank
     *   req_tenure_months      consecutive sales months meeting this rank or higher, the closing month included
     *   req_prev_rank_count    unilevel downlines at the rank below, or above; 0 = none
     *   req_prev2_rank_count   the same, two ranks below
     *   max_weekly_steps       pairing steps paid per week at this rank
     *
     * Refusal codes: none
     */
    public function planRanks(): array
    {
        return $this->get('/meta/getPlanRanks', []);
    }

    /**
     * The partner's products on sale now on its Ignite catalog, with the figures createOrder
     * charges today. Products and prices are set in the Ignite admin portal.
     *
     * GET /meta/getCatalog
     *
     * data: {
     *   "items": [
     *     { "product_id": 12, "name": "Ignite Coffee 30s", "is_physical": true,
     *       "retail_price": "120.0000", "member_price": "100.0000", "cv": "100.0000",
     *       "green_coin": "1.0000", "blue_coin": "2.0000" }
     *   ]
     * }
     *
     *   product_id    what a createOrder line sends
     *   retail_price  USD per unit, charged while the buyer's -001 is not qualified
     *   member_price  USD per unit, charged once it is
     *   cv            CV per unit
     *   green_coin    Green Coins per unit, paid to the buyer's uplines, never the buyer, when the order's week settles
     *   blue_coin     Blue Coins per unit, paid to the buyer and the buyer's uplines when the order's week settles
     *
     * createOrder prices at its `ordered_at`, so a price changed since this read applies to it.
     *
     * Refusal codes: none
     */
    public function catalog(): array
    {
        return $this->get('/meta/getCatalog', []);
    }

    /**
     * Check a sponsor BA ID before registerMember: a main account of this partner, another partner
     * or the world plan. The same rule registerMember applies to sponsor_ba_id.
     *
     * GET /members/validateSponsor?ba_id=AIA7918383-001
     *
     * data: { "member_id": 4, "ba_id": "AIA7918383-001" }
     *
     * Refusal codes: VALIDATION_FAILED, MEMBER_NOT_FOUND (no such BA ID, or a -002 or -003)
     */
    public function validateSponsor(string $baId): array
    {
        return $this->get('/members/validateSponsor', ['ba_id' => $baId]);
    }

    /**
     * Create a member with its three nodes (-001, -002, -003) under a sponsor, and store the
     * BA ID it returns. The member cannot sign in to Ignite.
     *
     * Always creates a new member. Never call it for a person who already holds a BA ID.
     *
     * Safe to retry: a `partner_member_ref` already registered returns that member and creates nothing.
     * With a placement parent and `side` the member is also seated there, or nothing is created:
     * a position that is taken or breaks the rules answers PLACEMENT_REFUSED, one another placement
     * still holds after 4 seconds answers NETWORK_BUSY, and the same `partner_member_ref` can be sent again.
     * Without them the member registers unplaced and Ignite auto-places it at `placement_deadline`,
     * unless setPlacement runs first.
     *
     * POST /members/register
     * body: { "partner_member_ref": "M-10233", "sponsor_ba_id": "AIA7918383-001", "fullname": "Ravi Kumar",
     *         "placement_ba_id": "AIA7918383-003", "side": 1 }
     *
     *   placement_ba_id      the placement parent by BA ID: a node known by name, such as an outside sponsor
     *   placement_member_id  the placement parent by member_id: a node picked off binaryTree, outside ones included
     *                        Send one of the two with `side`, or neither and no `side`. Anything else
     *                        answers VALIDATION_FAILED
     *
     * data: the memberDetails shape
     *
     * Refusal codes: VALIDATION_FAILED, MEMBER_NOT_FOUND (sponsor or placement parent), PLACEMENT_REFUSED,
     * NETWORK_BUSY
     *
     * @param  string  $partnerMemberRef  the partner's own id for the member, up to 100 characters; never changes
     * @param  string  $sponsorBaId  a main account's BA ID, never a -002 or -003
     * @param  string  $fullname  up to 255 characters
     * @param  string|int|null  $placementParent  string = BA ID, int = member_id. A member_id held as a
     *                                            string must be cast to int, or it is sent as a BA ID
     * @param  int|null  $side  1 left, 2 right
     */
    public function registerMember(
        string $partnerMemberRef,
        string $sponsorBaId,
        string $fullname,
        string|int|null $placementParent = null,
        ?int $side = null,
    ): array {
        return $this->post('/members/register', [
            'partner_member_ref' => $partnerMemberRef,
            'sponsor_ba_id'      => $sponsorBaId,
            'fullname'           => $fullname,
            'side'               => $side,
        ] + $this->placementParent($placementParent));
    }

    /**
     * One member node. Ranks, membership status and balances are the account's; a
     * sub-account (-002, -003) answers with its main account's. CV and position are the node's.
     *
     * GET /members/getDetails?ba_id=AIA7918383-001
     *
     * data: {
     *   "member_id": 4, "ba_id": "AIA7918383-001",
     *   "unilevel_parent_id": 1, "unilevel_parent_ba_id": null,
     *   "binary_parent_id": 2, "binary_parent_ba_id": null, "binary_side": 1,
     *   "cv_left": "123806.0000", "cv_right": "0.0000",
     *   "floating_cv_left": "25150.0000", "floating_cv_right": "150.0000",
     *   "total_cv_left": "123806.0000", "total_cv_right": "0.0000",
     *   "far_left_id": 9, "far_left_ba_id": "AIA5531207-001", "far_right_id": 6, "far_right_ba_id": "AIA7918383-003",
     *   "partner_member_ref": null, "main_member_id": null, "main_ba_id": null,
     *   "qualified_at": "2026-08-14 10:05:31", "membership_status": "active", "allow_withdraw": true,
     *   "rank_no": 1, "base_rank_no": 0, "eligible_rank_no": 1,
     *   "total_steps": 37,
     *   "placement_deadline": null,
     *   "cash_bal": "388.0000", "green_bal": "0.0000", "yellow_bal": "0.0000", "blue_bal": "761.0000",
     *   "cv_allocated": "300.0000", "tc_bank_available": 1
     * }
     *
     *   unilevel_parent_*    the sponsor, by member_id and BA ID. The sample sponsor is outside this
     *                        partner, so its BA ID is null
     *   binary_parent_*      the placement parent, by member_id and BA ID; null while unplaced
     *   binary_side          1 left, 2 right; null while unplaced
     *   far_left_*, far_right_*  the last node down each leg, by member_id and BA ID; null on an empty leg
     *   main_*               the -001, by member_id and BA ID, on a sub-account; null on the main account
     *   partner_member_ref   the partner's own id sent to registerMember; null on a -002 or -003 and on a
     *                        member moved in by Ignite
     *   qualified_at         UTC+8, when this node qualified; null until then. On the -001 it ends retail pricing
     *   cv_*                 matured CV, what pairing consumes
     *   floating_cv_*        floating CV, not yet settled
     *   total_cv_*           lifetime settled CV; auto-placement seats on the weaker of these
     *   rank_no              the rank in force: the higher of eligible_rank_no and base_rank_no
     *   eligible_rank_no     earned by the member's own conditions; render progress against this
     *   base_rank_no         the floor Ignite operations set; 0 = none
     *   cash_bal             commission balance; the weekly withdrawal pays it out
     *   total_steps          lifetime pairing steps on this node, paid and burned alike
     *   membership_status    retail, active, grace, suspended, terminated
     *   allow_withdraw       false while the member's payout is held (setAllowWithdraw, or Ignite
     *                        operations): the weekly withdrawal skips them
     *                        and the balance carries, so the wd file has no line for them
     *   placement_deadline   UTC+8. Set only on an unplaced main account; Ignite auto-places when it passes
     *   cv_allocated         CV the member's own orders put on this node, lifetime, cancelled orders excluded.
     *                        Not downline CV (cv_left / cv_right). The -001 takes qualify_cv minus this until
     *                        qualified_at is set, then nothing; extra TCs need -002 and -003 each at
     *                        qualify_cv or more
     *   tc_bank_available    extra TCs waiting in the member's TC Bank for placeBankedTc: earned by an order
     *                        that did not name them, or added by Ignite operations. Null on a -002 or -003
     *
     * Refusal codes: VALIDATION_FAILED, MEMBER_NOT_FOUND
     */
    public function memberDetails(string $baId): array
    {
        return $this->get('/members/getDetails', ['ba_id' => $baId]);
    }

    /**
     * Seat an unplaced member. A main account's -002 and -003 are seated under it by the same call.
     * The deadline is Ignite's (placement_deadline in memberDetails).
     * The member is one of this partner's own, or a direct referral listed by pendingPlacements: a
     * main account sponsored by this partner's main account, wherever it registered.
     *
     * POST /members/setPlacement
     * body: { "ba_id": "AIA3425549-001", "placement_ba_id": "AIA7918383-003", "side": 1 }
     *
     *   placement_ba_id      the placement parent by BA ID: a node known by name, such as an outside sponsor
     *   placement_member_id  the placement parent by member_id: a node picked off binaryTree, outside ones included
     *                        Send exactly one of the two. Both, or neither, answers VALIDATION_FAILED
     *
     * data: the memberDetails shape, after placement. For a member from outside this partner, only
     *       the tree row fields (member_id, ba_id null, parents, side, CV figures): its balances and
     *       ranks are not this partner's
     *
     * Refusal codes: PLACEMENT_DEADLINE_PASSED, PLACEMENT_REFUSED, NETWORK_BUSY, MEMBER_NOT_FOUND,
     * VALIDATION_FAILED
     *
     * @param  string|int  $placementParent  string = BA ID, int = member_id. A member_id held as a
     *                                       string must be cast to int, or it is sent as a BA ID
     * @param  int  $side  1 left, 2 right
     */
    public function setPlacement(string $baId, string|int $placementParent, int $side): array
    {
        return $this->post('/members/setPlacement', [
            'ba_id' => $baId,
            'side'  => $side,
        ] + $this->placementParent($placementParent));
    }

    /**
     * Seat one extra TC from a main account's TC Bank (memberDetails tc_bank_available) at exactly
     * the named parent and side. No deadline: a banked TC waits until placed.
     * Ignite picks the TC: one Ignite operations added first, then the one from the oldest order.
     * That order's getOrder then lists it in extra_tcs, and cancelling that order leaves it seated
     * but no longer qualified.
     *
     * POST /members/placeBankedTc
     * body: { "ba_id": "AIA8976104-001", "placement_ba_id": "AIA8976104-002", "side": 1 }
     *
     *   placement_ba_id      the placement parent by BA ID
     *   placement_member_id  the placement parent by member_id, for a node picked off binaryTree
     *                        Send exactly one of the two. Both, or neither, answers VALIDATION_FAILED.
     *                        The parent is the main account or a node in its binary downline
     *
     * data: { "member_id": 211, "ba_id": "AIA8976104-005", "binary_parent_id": 200,
     *         "binary_parent_ba_id": "AIA8976104-002", "binary_side": 1 }
     *
     *   the new node, as one createOrder extra_tcs row
     *
     * Not safe to retry blindly: a retry whose first attempt landed answers PLACEMENT_REFUSED (side
     * taken), or TC_BANK_EMPTY when that was the last banked TC; memberDetails tc_bank_available
     * shows whether it dropped.
     *
     * Refusal codes: VALIDATION_FAILED, MEMBER_NOT_FOUND (ba_id not this partner's, or a -002 or -003;
     * or no such placement parent), TC_BANK_EMPTY, EXTRA_TC_PARENT_INVALID, PLACEMENT_REFUSED (main account not yet placed, side taken
     * or held), NETWORK_BUSY (another placement still holds the slot after 4 seconds)
     *
     * @param  string|int  $placementParent  string = BA ID, int = member_id. A member_id held as a
     *                                       string must be cast to int, or it is sent as a BA ID
     * @param  int  $side  1 left, 2 right
     */
    public function placeBankedTc(string $baId, string|int $placementParent, int $side): array
    {
        return $this->post('/members/placeBankedTc', [
            'ba_id' => $baId,
            'side'  => $side,
        ] + $this->placementParent($placementParent));
    }

    /**
     * Hold or release a member's weekly commission withdrawal. One switch, shared with Ignite
     * operations: the latest write from either side holds. While off, the weekly withdrawal
     * skips the member and the balance carries to the first weekly withdrawal after it turns back on.
     *
     * POST /members/setAllowWithdraw
     * body: { "ba_id": "AIA7918383-001", "allow_withdraw": false }
     *
     * data: the memberDetails shape, after the change
     *
     * Refusal codes: VALIDATION_FAILED, MEMBER_NOT_FOUND (not this partner's, or a -002 or -003),
     * NETWORK_BUSY (the Saturday settlement is running; retry every 30 seconds)
     */
    public function setAllowWithdraw(string $baId, bool $allowWithdraw): array
    {
        return $this->post('/members/setAllowWithdraw', ['ba_id' => $baId, 'allow_withdraw' => $allowWithdraw]);
    }

    /**
     * A sponsor's direct referrals who still need a binary seat, nearest deadline first. It
     * includes referrals who registered on Ignite or through another partner with this sponsor as
     * referrer, and reflects any sponsor change Ignite operations made. Build the placement window
     * from this list, then seat each member with setPlacement.
     *
     * A member leaves the list once placed, by the sponsor or by Ignite at the deadline. Read where
     * it landed from memberDetails or binaryTree.
     *
     * GET /members/getPendingPlacements?ba_id=AIA7918383-001
     *
     * data: { "rows": [
     *   { "member_id": 311, "ba_id": "AIA3425549-001", "fullname": "Ravi Kumar",
     *     "registered_at": "2026-09-28 11:02:45", "has_ordered": true, "unplaced_downline_count": 0,
     *     "placement_deadline": "2026-10-07 00:00:00", "is_overdue": false },
     *   { "member_id": 312, "ba_id": "AIA1180042-001", "fullname": null,
     *     "registered_at": "2026-09-29 09:40:12", "has_ordered": false, "unplaced_downline_count": 2,
     *     "placement_deadline": "2026-10-07 00:00:00", "is_overdue": false }
     * ] }
     *
     *   ba_id                    the member's BA ID, filled on every row, also for a member outside this partner
     *   fullname                 null when Ignite holds none
     *   registered_at            UTC+8
     *   has_ordered              true when the member holds a paid order whose CV still counts (not cancelled
     *                            or returned)
     *   unplaced_downline_count  the member's own unplaced downlines, any depth; none of them can be placed
     *                            until this member is
     *   placement_deadline       UTC+8. Ignite auto-places the member when it passes
     *   is_overdue               true once placement_deadline has passed and before Ignite's auto-placement run
     *                            seats the member; setPlacement then answers PLACEMENT_DEADLINE_PASSED
     *
     * Refusal codes: VALIDATION_FAILED, MEMBER_NOT_FOUND (not this partner's member, or a -002 / -003)
     *
     * @param  string  $baId  the sponsor's main account BA ID (-001)
     */
    public function pendingPlacements(string $baId): array
    {
        return $this->get('/members/getPendingPlacements', ['ba_id' => $baId]);
    }

    /**
     * A member's progress toward every rank on their plan, in the sales month open now. Computed
     * from data the partner does not hold: orders and recruits outside this partner, and past
     * months' results. Reading changes nothing; ranks change only when Ignite closes a month.
     *
     * GET /members/getRankProgress?ba_id=AIA7918383-001
     *
     * data: {
     *   "sales_month": { "year": 2026, "month_no": 9, "label": "2026-M09",
     *                    "start_date": "2026-08-22", "end_date": "2026-09-25" },
     *   "current": { "rank_no": 4, "base_rank_no": 0, "eligible_rank_no": 4 },
     *   "closing": { "met_rank_no": 4, "eligible_rank_no": 4, "rank_no": 4 },
     *   "ranks": [
     *     { "rank_no": 5, "name": "Sapphire", "step_comm": "250.0000", "max_weekly_steps": 70,
     *       "met": false,
     *       "conditions": {
     *         "steps":               { "current": 46, "required": 40, "met": true },
     *         "direct_cv":           { "current": "720.0000", "required": "600.0000", "met": true },
     *         "downlines_one_below": { "rank_no": 4, "current": 3, "required": 5, "met": false },
     *         "downlines_two_below": { "rank_no": 3, "current": 6, "required": 0, "met": true },
     *         "tenure":              { "current": 0, "required": 1, "met": false }
     *       } }
     *   ]
     * }
     *
     *   sales_month          the month being judged; null when Ignite's sales calendar has no month
     *                        for today. direct_cv current then reads 0, and tenure counts no closed
     *                        month: its current is 1 on a rank at or below closing.met_rank_no, else 0
     *   current              the ranks held now, as memberDetails carries them. 0 = none
     *   closing              the ranks a month close today would write. 0 = none
     *     met_rank_no        highest rank whose steps, direct CV and both downline counts hold now
     *     eligible_rank_no   highest rank with tenure met, no higher than met_rank_no and at most one
     *                        rank above current eligible_rank_no
     *     rank_no            the higher of eligible_rank_no and current base_rank_no
     *   ranks                every rank on the plan, lowest first; empty for a member holding no plan
     *     met                steps, direct CV and both downline counts all met. Tenure is judged apart
     *   conditions           each holds what the member has now, what the rank needs, and whether it is met
     *     steps              pairing steps on the member's highest node, not the sum across nodes
     *     direct_cv          CV this sales month from the member's own paid orders, plus the retail-price
     *                        orders of members they sponsored, including recruits outside this partner
     *     downlines_one_below  unilevel downlines at any depth whose earned rank is rank_no or higher,
     *                        members outside this partner included. rank_no 0 on the lowest rank
     *     downlines_two_below  the same, two ranks below. Downlines already used for the one-below
     *                        requirement are left out of current
     *     tenure             months in a row that met this rank or higher: the closed months, newest first,
     *                        plus the open month while closing.met_rank_no is this rank or higher
     *
     * Refusal codes: VALIDATION_FAILED, MEMBER_NOT_FOUND (not this partner's member, or a -002 / -003)
     *
     * @param  string  $baId  the member's main account BA ID (-001)
     */
    public function rankProgress(string $baId): array
    {
        return $this->get('/members/getRankProgress', ['ba_id' => $baId]);
    }

    /**
     * Unilevel downlines of a member, root first. Sub-accounts are left out.
     *
     * GET /members/getUnilevelTree?ba_id=AIA7918383-001&depth=2
     *
     * data: { "rows": [
     *   { "member_id": 4, "ba_id": "AIA7918383-001", "unilevel_parent_id": 1, "unilevel_parent_ba_id": null,
     *     "binary_parent_id": 2, "binary_parent_ba_id": null,
     *     "binary_side": 1, "cv_left": "123806.0000", "cv_right": "0.0000", "floating_cv_left": "25150.0000",
     *     "floating_cv_right": "150.0000", "total_cv_left": "123806.0000", "total_cv_right": "0.0000", "level": 0 },
     *   { "member_id": 7, "ba_id": null, "unilevel_parent_id": 4, "unilevel_parent_ba_id": "AIA7918383-001",
     *     "binary_parent_id": 5, "binary_parent_ba_id": "AIA7918383-002",
     *     "binary_side": 2, "cv_left": "0.0000", "cv_right": "0.0000", "floating_cv_left": "0.0000",
     *     "floating_cv_right": "0.0000", "total_cv_left": "0.0000", "total_cv_right": "0.0000", "level": 1 }
     * ] }
     *
     *   ba_id  null for a member outside this partner, here a recruit of the root who registered elsewhere
     *   level  0 is the root. Rows are ordered by level.
     *
     * Refusal codes: VALIDATION_FAILED, MEMBER_NOT_FOUND
     *
     * @param  int  $depth  1 to 3 levels below the root
     */
    public function unilevelTree(string $baId, int $depth): array
    {
        return $this->get('/members/getUnilevelTree', ['ba_id' => $baId, 'depth' => $depth]);
    }

    /**
     * Binary downlines of a node, root first, every node including sub-accounts.
     * Same row shape as unilevelTree, plus each leg's far end.
     *
     * GET /members/getBinaryTree?ba_id=AIA7918383-001&depth=1
     *
     * data: { "rows": [
     *   { "member_id": 4, "ba_id": "AIA7918383-001", "unilevel_parent_id": 1, "unilevel_parent_ba_id": null,
     *     "binary_parent_id": 2, "binary_parent_ba_id": null,
     *     "binary_side": 1, "cv_left": "123806.0000", "cv_right": "0.0000", "floating_cv_left": "25150.0000",
     *     "floating_cv_right": "150.0000", "total_cv_left": "123806.0000", "total_cv_right": "0.0000", "level": 0,
     *     "far_left_id": 9, "far_left_ba_id": "AIA5531207-001", "far_right_id": 6, "far_right_ba_id": "AIA7918383-003" },
     *   { "member_id": 5, "ba_id": "AIA7918383-002", "unilevel_parent_id": 4, "unilevel_parent_ba_id": "AIA7918383-001",
     *     "binary_parent_id": 4, "binary_parent_ba_id": "AIA7918383-001",
     *     "binary_side": 1, "cv_left": "98300.0000", "cv_right": "0.0000", "floating_cv_left": "20000.0000",
     *     "floating_cv_right": "0.0000", "total_cv_left": "98300.0000", "total_cv_right": "0.0000", "level": 1,
     *     "far_left_id": 9, "far_left_ba_id": "AIA5531207-001", "far_right_id": 7, "far_right_ba_id": null },
     *   { "member_id": 6, "ba_id": "AIA7918383-003", "unilevel_parent_id": 4, "unilevel_parent_ba_id": "AIA7918383-001",
     *     "binary_parent_id": 4, "binary_parent_ba_id": "AIA7918383-001",
     *     "binary_side": 2, "cv_left": "0.0000", "cv_right": "0.0000", "floating_cv_left": "0.0000",
     *     "floating_cv_right": "0.0000", "total_cv_left": "0.0000", "total_cv_right": "0.0000", "level": 1,
     *     "far_left_id": null, "far_left_ba_id": null, "far_right_id": null, "far_right_ba_id": null }
     * ] }
     *
     *   level        0 is the root. Rows are ordered by level.
     *   far_*        the last node down each leg of that row's node, by member_id and BA ID; call
     *                binaryTree with the BA ID to jump there; null on an empty leg
     *
     * Refusal codes: VALIDATION_FAILED, MEMBER_NOT_FOUND
     *
     * @param  int  $depth  1 to 5 levels below the root
     */
    public function binaryTree(string $baId, int $depth): array
    {
        return $this->get('/members/getBinaryTree', ['ba_id' => $baId, 'depth' => $depth]);
    }

    /**
     * One node's floating CV rows: credited, not yet settled. Once a week settles, its rows
     * leave this list and arrive as settled lump rows (pv1_bal, pv2_bal) in pointTxs and the
     * `ledger` export file.
     *
     * GET /members/getFloatingCvTxs?ba_id=AIA7918383-001&after_id=0&limit=100
     *
     * data: {
     *   "rows": [
     *     { "id": 1, "created_at": "2026-09-17 20:44:16.524", "side": 1, "amount": "50.0000",
     *       "description": "pv_mp", "buyer_member_id": 4, "buyer_ba_id": "AIA7918383-001",
     *       "order_no": "MY-260917-4830217",
     *       "sales_week": { "year": 2026, "week_no": 37 }, "settlement_week": { "year": 2026, "week_no": 43 } }
     *   ],
     *   "has_more": true
     * }
     *
     *   amount           signed; a return writes a negative row
     *   description      pv_mp, pv_refund, pv_refund_restore, pv_replace_out, pv_replace_in
     *                    pv_mp               CV added to a leg from a downline order
     *                    pv_refund           CV taken back when a downline order is returned
     *                    pv_refund_restore   CV added back when a return is rejected
     *                    pv_replace_out      CV taken off when a member is moved in the tree
     *                    pv_replace_in       CV added when a member is moved in the tree
     *   settlement_week  the week this CV settles in
     *
     * Page by passing the last row's `id` as `after_id` while `has_more` is true.
     *
     * Refusal codes: VALIDATION_FAILED, MEMBER_NOT_FOUND
     *
     * @param  int  $afterId  rows after this id; 0 for the first page
     * @param  int  $limit  1 to 500
     */
    public function floatingCvTxs(string $baId, int $afterId, int $limit): array
    {
        return $this->get('/members/getFloatingCvTxs', ['ba_id' => $baId, 'after_id' => $afterId, 'limit' => $limit]);
    }

    /**
     * One node's ledger rows in id order, for a partner keeping its own copy: store the
     * last `id` taken and pass it as `after_id` next time.
     *
     * GET /members/getPointTxs?ba_id=AIA7918383-001&after_id=31&limit=100
     *
     * data: {
     *   "rows": [
     *     { "id": 32, "created_at": "2026-09-19 02:10:05.118", "point_type": "cash_bal", "tx_type": "comm",
     *       "description": "comm_pairing", "amount": "250.0000", "bal_after": "638.0000",
     *       "order_no": null, "redemption_id": null, "rel_member_id": null, "rel_ba_id": null,
     *       "member_note": null }
     *   ],
     *   "has_more": true
     * }
     *
     *   point_type     cash_bal (commission), green_bal, yellow_bal, blue_bal, pv1_bal, pv2_bal, and others
     *                    cash_bal    commission balance
     *                    green_bal   Green Coin balance
     *                    yellow_bal  Yellow Coin balance
     *                    blue_bal    Blue Coin balance
     *                    pv1_bal     left leg CV balance
     *                    pv2_bal     right leg CV balance
     *   order_no       the order's number on a row from an order, else null
     *   redemption_id  on a coin redemption row, its id, as redeemCoin returns it; else null
     *
     * Refusal codes: VALIDATION_FAILED, MEMBER_NOT_FOUND
     *
     * @param  int  $afterId  rows after this id; 0 for the first page
     * @param  int  $limit  1 to 500
     */
    public function pointTxs(string $baId, int $afterId, int $limit): array
    {
        return $this->get('/members/getPointTxs', ['ba_id' => $baId, 'after_id' => $afterId, 'limit' => $limit]);
    }

    /**
     * One sales week's pairing results for this partner's nodes, ordered by member_id, all in one
     * reply. The Saturday settlement writes each row once; a row never changes
     * after. Only a node that paired that week has a row, so a week not yet settled answers no rows.
     * A week settled before Ignite went live answers no rows either.
     *
     * The commission is the account's: cash, Yellow Coins and the Green Coin take land on the main
     * account (-001), also for a -002 or -003 row. pointTxs and the `ledger` export file show the same
     * payout as rows pointing at it, not on top of it: comm_pairing on cash_bal (cash_amount) and on
     * green_bal (green_taken), comm_yellow on yellow_bal (steps_yellow), and pv_pairing and pv_burn on
     * the node's pv1_bal and pv2_bal (paired_cv).
     *
     * GET /members/getPairings?year=2026&week_no=38
     *
     * data: { "rows": [
     *   { "member_id": 4, "ba_id": "AIA7918383-001", "rank_no": 2, "step_comm": "250.0000",
     *     "pairing_step_cv": "1800.0000",
     *     "open_cv_left": "9000.0000", "open_cv_right": "1200.0000",
     *     "gained_cv_left": "300.0000", "gained_cv_right": "4500.0000", "paired_cv": "5400.0000",
     *     "close_cv_left": "3900.0000", "close_cv_right": "300.0000",
     *     "steps_before": 3, "steps_paid": 3, "steps_burned": 0, "steps_yellow": 1, "steps_halved": 0,
     *     "green_taken": "0.0000", "cash_amount": "500.0000" },
     *   { "member_id": 5, "ba_id": "AIA7918383-002", "rank_no": 2, "step_comm": "250.0000",
     *     "pairing_step_cv": "1800.0000",
     *     "open_cv_left": "50000.0000", "open_cv_right": "45000.0000",
     *     "gained_cv_left": "0.0000", "gained_cv_right": "0.0000", "paired_cv": "45000.0000",
     *     "close_cv_left": "5000.0000", "close_cv_right": "0.0000",
     *     "steps_before": 40, "steps_paid": 20, "steps_burned": 5, "steps_yellow": 4, "steps_halved": 0,
     *     "green_taken": "0.0000", "cash_amount": "4000.0000" }
     * ] }
     *
     *   member_id        the node that paired
     *   ba_id            that node's BA ID
     *   rank_no          the account's rank the Saturday settlement priced the commission at, as planRanks numbers it
     *   step_comm        USD per paid step at that rank, as it stood at the Saturday settlement
     *   pairing_step_cv  CV per side making one step, as it stood at the Saturday settlement
     *   open_cv_*        matured CV on each leg before this week's CV settled
     *   gained_cv_*      CV this week settled onto each leg
     *   paired_cv        CV taken off each leg: every step this week, paid and burned, times pairing_step_cv
     *   close_cv_*       CV left on each leg after pairing; it carries to next week as cv_left / cv_right
     *   steps_before     lifetime steps on this node before this Saturday settlement, paid and burned (memberDetails total_steps)
     *   steps_paid       steps paid this week, steps_yellow included; at most the rank's max_weekly_steps
     *   steps_burned     steps past max_weekly_steps: their CV is taken, nothing is paid
     *   steps_yellow     steps paid as one Yellow Coin each in place of cash: every 6th lifetime step on the node
     *   steps_halved     steps paid at half step_comm. Only the account's first two lifetime step payouts can be:
     *                    the first once 6 of its Green Coins were redeemed or taken, the second once 12 were
     *   green_taken      Green Coins taken from the account on those first two payouts when not halved, 6 each;
     *                    the balance may go negative
     *   cash_amount      USD credited to cash_bal for this row
     *
     * Refusal codes: VALIDATION_FAILED (no such sales week), MEMBER_NOT_FOUND
     *
     * @param  int  $year  sales year, as salesCalendar names it
     * @param  int  $weekNo  week number within that sales year
     * @param  string|null  $baId  one node only; any of the partner's BA IDs, -002 and -003 included. Null for all
     */
    public function pairings(int $year, int $weekNo, ?string $baId = null): array
    {
        return $this->get('/members/getPairings', ['year' => $year, 'week_no' => $weekNo, 'ba_id' => $baId]);
    }

    /**
     * Exchange a member's coins for commission balance.
     *
     * The commission credited waits for the weekly commission withdrawal, which zeroes
     * the balance and lists the member in that week's `wd` export file. The rate is
     * banded by quantity, so the same qty is not always worth the same per coin.
     *
     * Safe to retry: a repeated `partner_redemption_ref` within 24 hours returns the first call's
     * result and redeems nothing. A refused call keeps no record, so the same ref may be sent
     * again once corrected.
     *
     * POST /coins/redeem
     * body: { "ba_id": "AIA7918383-001", "point_type": "yellow_bal", "qty": 3, "partner_redemption_ref": "R1" }
     *
     * data: { "redemption_id": 7, "cash_amount": "120.0000" }
     *   redemption_id  the redemption's id on Ignite, to store beside the partner's own record;
     *                  pointTxs and the export files carry it on the rows this redemption wrote
     *   cash_amount    credited to cash_bal; pointTxs shows it as the cash_bal row with
     *                  description cr_in
     *
     * Refusal codes: VALIDATION_FAILED, MEMBER_NOT_FOUND, CONVERT_DISABLED, REDEMPTION_REFUSED,
     * REDEMPTION_IN_PROGRESS (same ref still running; retry after it finishes),
     * NETWORK_BUSY (the Saturday settlement is running; retry every 30 seconds)
     *
     * @param  string  $baId                  the main account's BA ID (-001). Coins sit there only, so a -002 or
     *                                        -003 answers REDEMPTION_REFUSED
     * @param  string  $pointType             yellow_bal or blue_bal
     * @param  int     $qty                   whole coins
     * @param  string  $partnerRedemptionRef  the partner's own id for this redemption, up to 100 characters
     */
    public function redeemCoin(string $baId, string $pointType, int $qty, string $partnerRedemptionRef): array
    {
        return $this->post('/coins/redeem', [
            'ba_id'                  => $baId,
            'point_type'             => $pointType,
            'qty'                    => $qty,
            'partner_redemption_ref' => $partnerRedemptionRef,
        ]);
    }

    /**
     * Record an order the partner already took payment for. It lands accepted: CV climbs the
     * binary tree, and coins and retail profit follow in the week it settles. Blue Coins pay the
     * buyer and the buyer's uplines; Green Coins pay the uplines only, never the buyer.
     *
     * Prices, CV and coins come off the partner's catalog on Ignite, read at `ordered_at`;
     * a line names only the product and quantity. Prices there are USD, and a buyer whose -001
     * is not yet qualified buys at retail price, paying the sponsor the retail profit.
     * `local_amount` is what the buyer paid the partner. Ignite records it and never converts it.
     *
     * An order whose lines carry no CV between them is refused with ORDER_NO_CV; keep it on the
     * partner's side only.
     *
     * Safe to retry with the same `partner_order_ref`: a repeat writes nothing and answers ORDER_DUPLICATE
     * with the order_no. Treat that as success.
     *
     * POST /orders/create
     * body: {
     *   "ba_id": "AIA8976104-001", "partner_order_ref": "SO-1001", "ordered_at": "2026-09-18 14:30:00",
     *   "local_amount": "158400.00", "local_currency": "INR",
     *   "lines": [ { "product_id": 12, "qty": 15 } ],
     *   "extra_tcs": [ { "placement_ba_id": "AIA8976104-002", "side": 1 } ],
     *   "allocations": [ { "ba_id": "AIA8976104-001", "cv": "300" }, { "ba_id": "AIA8976104-002", "cv": "300" },
     *                    { "ba_id": "AIA8976104-003", "cv": "300" }, { "extra_tc": 0, "cv": "600" } ]
     * }
     *
     *   partner_order_ref  the partner's own id for the order, up to 100 characters
     *   local_amount       up to 4 decimal places
     *   local_currency     3 capital letters, e.g. INR
     *   lines              each product_id once per order; qty 1 or more
     *   ordered_at         UTC+8, when the buyer paid. Not in the future. Prices are read at this time, and
     *                      the order counts in the sales week holding it, however late it arrives. Its CV
     *                      pays out no earlier than the next Saturday settlement; `settlement_week` in the
     *                      reply says which. An order arriving after its sales month closed counts toward
     *                      no month's rank or matching bonus
     *   extra_tcs          new nodes, each seated at exactly the named parent and side, a node of the
     *                      buyer's own binary network. A side taken by then refuses the whole order.
     *                      Each names its parent by exactly one of `placement_ba_id` (BA ID) or
     *                      `placement_member_id` (member_id, for a node picked off binaryTree, outside
     *                      ones included); both, or neither, answers VALIDATION_FAILED. An extra TC the
     *                      order earns and does not name goes to the buyer's TC Bank; placeBankedTc
     *                      places it later
     *   allocations        where the order's CV lands. Each row names one of the buyer's nodes by `ba_id`,
     *                      or an extra TC of this order by its index in `extra_tcs`. They sum to the
     *                      order's CV; the -001 takes exactly qualify_cv (planRanks) minus its
     *                      cv_allocated (memberDetails), zero once its qualified_at is set, or the whole
     *                      order's CV when that is smaller; and the -002 and -003 each hold qualify_cv,
     *                      across all the buyer's orders, before any CV reaches an extra TC or a later
     *                      node. The sample buyer holds no CV yet: 300 qualifies each of the three,
     *                      and the 600 left earns one extra TC (base_product_cv 600)
     *
     * data: {
     *   "order_no": "MY-260918-0572946", "partner_order_ref": "SO-1001", "price_basis": "retail",
     *   "total_cv": "1500.0000", "total_green_coin": "15.0000", "total_blue_coin": "30.0000",
     *   "sales_week": { "year": 2026, "week_no": 37 }, "settlement_week": { "year": 2026, "week_no": 43 },
     *   "extra_tcs": [ { "member_id": 210, "ba_id": "AIA8976104-004", "binary_parent_id": 200,
     *                    "binary_parent_ba_id": "AIA8976104-002", "binary_side": 1 } ]
     * }
     *
     *   price_basis      retail or member: the price the lines were charged at
     *   sales_week       the week holding ordered_at
     *   settlement_week  the week the CV pays out in: the week starting the country's sales delay (35 days
     *                    for India) after the sales week ends, or the next unsettled week when that one
     *                    has settled
     *   extra_tcs        in request order, each with the node it became. Banked ones are not listed;
     *                    getOrder later also lists one placeBankedTc seats from this order
     *
     * Refusal codes: VALIDATION_FAILED, MEMBER_NOT_FOUND (buyer), ORDER_DUPLICATE, ORDER_DISABLED, ORDER_NO_CV, PRODUCT_NOT_ON_CATALOG,
     * CV_ALLOCATION_MISMATCH, ALLOCATION_TARGET_INVALID, CV_001_MISMATCH, CV_ORIGINALS_FIRST, EXTRA_TC_OVER_ENTITLEMENT,
     * EXTRA_TC_PARENT_INVALID, ORDER_WEEK_UNKNOWN, PLACEMENT_REFUSED (an extra TC's
     * side is taken), NETWORK_BUSY (another placement still holds an extra TC's slot after 4 seconds)
     *
     * @param  array<int, array{product_id: int, qty: int}>  $lines
     * @param  array<int, array{placement_ba_id?: string, placement_member_id?: int, side: int}>  $extraTcs
     * @param  array<int, array{ba_id?: string, extra_tc?: int, cv: string}>  $allocations
     */
    public function createOrder(
        string $baId,
        string $partnerOrderRef,
        string $orderedAt,
        string $localAmount,
        string $localCurrency,
        array $lines,
        array $allocations,
        array $extraTcs = [],
    ): array {
        return $this->post('/orders/create', [
            'ba_id'             => $baId,
            'partner_order_ref' => $partnerOrderRef,
            'ordered_at'        => $orderedAt,
            'local_amount'      => $localAmount,
            'local_currency'    => $localCurrency,
            'lines'             => $lines,
            'extra_tcs'         => $extraTcs,
            'allocations'       => $allocations,
        ]);
    }

    /**
     * One order sent with createOrder, by the partner's own ref. Read it after a createOrder whose
     * answer was lost: ORDER_DUPLICATE carries the order_no only, this carries the full result.
     *
     * GET /orders/getDetails?partner_order_ref=SO-1001
     *
     * data: the createOrder data, plus
     *   status           accepted, or cancelled once cancelOrder has run or Ignite operations took the
     *                    order back as a return
     *
     * Refusal codes: VALIDATION_FAILED, ORDER_NOT_FOUND
     */
    public function getOrder(string $partnerOrderRef): array
    {
        return $this->get('/orders/getDetails', ['partner_order_ref' => $partnerOrderRef]);
    }

    /**
     * Cancel an order sent with createOrder, also after its week has paid out. Its CV comes back off
     * the binary tree, and the retail profit and coins it paid are taken back from whoever received
     * them, into a negative balance where needed. Pairing commission already paid on that CV stays.
     *
     * Whole order only: under Ignite's return policy a buyer returns the whole order or nothing.
     *
     * Safe to retry: a repeat writes nothing and answers ORDER_ALREADY_CANCELLED with the order_no.
     * Treat that as success.
     *
     * POST /orders/cancel
     * body: { "partner_order_ref": "SO-1001" }
     *
     * data: { "order_no": "MY-260918-0572946", "partner_order_ref": "SO-1001" }
     *
     * The order then reads status cancelled in getOrder.
     *
     * Refusal codes: VALIDATION_FAILED, ORDER_NOT_FOUND, ORDER_ALREADY_CANCELLED,
     * NETWORK_BUSY (still held after 4 seconds; the Saturday settlement holds the network for up to an
     * hour, so retry every 30 seconds)
     *
     * @param  string  $partnerOrderRef  the partner_order_ref the order was created with
     */
    public function cancelOrder(string $partnerOrderRef): array
    {
        return $this->post('/orders/cancel', ['partner_order_ref' => $partnerOrderRef]);
    }

    /**
     * The export files Ignite holds for this partner, newest week first.
     *
     * Once the Saturday settlement of a sales week finishes, Ignite writes two CSV files for it:
     *
     *   {year}_W{nn}_ledger.csv  every balance movement on this partner's members, any balance:
     *                            commission, coins, settled CV, take-backs from a cancelled or returned
     *                            order, coin redemptions, adjustments by Ignite operations. Everything except
     *                            the weekly commission withdrawal, which is in the wd file only
     *   {year}_W{nn}_wd.csv      one row per member: the weekly commission withdrawal, the payable figure.
     *                            `amount` is negative, a debit off cash_bal; pay the member its absolute value.
     *                            No row for a held member (allow_withdraw off): the balance stays and pays out whole
     *                            in the first week after the hold lifts
     *
     * Which week a ledger row belongs to is decided by when it was written, not by the order or week
     * it points at: after the previous week's Saturday settlement finished, up to the moment this
     * week's finished.
     * A take-back written days after its order paid out is in the week it was written. Every row is
     * in exactly one week's file.
     *
     * Ledger rows are sorted by account (a main account with its sub-accounts), then member_id,
     * then point_type, then id, so each balance reads top to bottom. A member's last cash_bal row
     * in a week shows the balance the wd file pays: nothing moves a balance while the Saturday
     * settlement is running.
     * The withdrawal itself is left out of the ledger, so the next week's first cash_bal row counts
     * on from the balance after the payout (0 for a member paid out, the held balance for a member
     * held), not from this file's last row.
     *
     * `nn` is the week number, 2 digits (2026_W07_ledger.csv). A week with no rows still has its
     * file, header row only. The rows are this partner's members only; a node's file is decided by
     * the node that received the row, never by the buyer.
     *
     * Re-running a week's export rewrites its file with the same rows: `modified_at` moves, the content does not.
     *
     * GET /exports/getList
     *
     * data: {
     *   "files": [
     *     { "name": "2026_W38_wd.csv", "size": 412, "modified_at": "2026-09-19 10:14:02" }
     *   ]
     * }
     *
     *   size         bytes
     *   modified_at  UTC+8
     *
     * Refusal codes: none
     */
    public function exportList(): array
    {
        return $this->get('/exports/getList', []);
    }

    /**
     * One export file by name, as the CSV text itself: UTF-8, comma-separated, a header row first.
     *
     * Both files carry the same columns, the same names as pointTxs fields:
     *   id, created_at, member_id, ba_id, point_type, tx_type, description, amount, bal_after,
     *   order_no, redemption_id, rel_member_id, rel_ba_id, member_note
     *
     *   id             the row's id on Ignite, the same id pointTxs returns
     *   created_at     when the row was written; UTC+8, milliseconds
     *   member_id      the node whose balance moved
     *   ba_id          that node's BA ID
     *   point_type     the balance that moved: cash_bal (commission), green_bal, yellow_bal, blue_bal,
     *                  pv1_bal, pv2_bal, and others
     *                    cash_bal    commission balance
     *                    green_bal   Green Coin balance
     *                    yellow_bal  Yellow Coin balance
     *                    blue_bal    Blue Coin balance
     *                    pv1_bal     left leg CV balance
     *                    pv2_bal     right leg CV balance
     *   tx_type        the kind of movement: comm (earned), pv (CV), cr (coin redemption),
     *                  adj (adjustment by Ignite operations), wd (withdrawal), and others
     *   description    the exact reason, e.g. comm_pairing; tx_type is its first word
     *   amount         signed, 4 decimal places; a take-back is negative
     *   bal_after      that balance on that node right after this row, 4 decimal places
     *   order_no       the order's number on a row from an order, else empty
     *   redemption_id  on a coin redemption row, its id, as redeemCoin returns it; else empty
     *   rel_member_id  the other member involved, such as the buyer behind an order payout
     *   rel_ba_id      that member's BA ID; empty for a member outside this partner, as in pointTxs
     *   member_note    text written with the row; a settled CV row names the week the CV came from
     *
     * An empty field is an empty string; there is no null in the file.
     *
     * GET /exports/download?name=2026_W38_ledger.csv
     *
     * Returns the file on HTTP 200. On a refusal, throws RuntimeException whose getCode() is the
     * ResultCode value and whose message is the envelope's `msg`.
     *
     * Refusal codes: VALIDATION_FAILED (name does not match the pattern), EXPORT_NOT_FOUND
     *
     * @param  string  $name  a name from exportList
     */
    public function exportDownload(string $name): string
    {
        $response = $this->http->request('GET', $this->baseUrl . '/exports/download', ['query' => ['name' => $name]]);
        if ($response->getStatusCode() === 200) {
            return (string) $response->getBody();
        }

        $body = $this->envelope($response);
        throw new RuntimeException($body['msg'], $body['code']);
    }

    private function get(string $path, array $query): array
    {
        return $this->send('GET', $path, ['query' => $query]);
    }

    private function placementParent(string|int|null $parent): array
    {
        return match (true) {
            is_int($parent)    => ['placement_member_id' => $parent],
            is_string($parent) => ['placement_ba_id' => $parent],
            default            => [],
        };
    }

    private function post(string $path, array $body): array
    {
        return $this->send('POST', $path, ['json' => $body]);
    }

    private function send(string $method, string $path, array $options): array
    {
        return $this->envelope($this->http->request($method, $this->baseUrl . $path, $options));
    }

    private function envelope(ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true);

        if (!is_int($body['code'] ?? null)) {
            throw new RuntimeException("Ignite answered HTTP {$response->getStatusCode()} without the envelope");
        }

        return $body;
    }
}
