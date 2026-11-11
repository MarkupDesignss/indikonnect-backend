<?php

/*
 * Ignite Partner API client for PHP. Guzzle only, no framework.
 *
 * Setup
 *   composer require guzzlehttp/guzzle
 *   Copy IgniteClient.php and ResultCode.php into the project; change the namespace.
 *
 *   Laravel, in AppServiceProvider::register(), values from config/services.php:
 *     $this->app->singleton(IgniteClient::class, fn () => new IgniteClient(
 *         config('services.ignite.base_url'),   // https://<ignite host>/partner/v1
 *         config('services.ignite.api_key'),
 *     ));
 *
 * Authentication
 *   Authorization: Bearer <api_key> on every call.
 *
 * Requests
 *   GET reads, POST writes.
 *   JSON both ways. A POST sends Content-Type: application/json.
 *
 * Response envelope
 *   Every endpoint except exportDownload answers:
 *
 *     { "code": 0, "msg": "", "data": { ... } }
 *
 *   code  0 on success; any other value is a refusal listed in ResultCode, whose note says when
 *         it is returned. Branch on this field.
 *   msg   empty on success. On a refusal: text for a log, with the figures behind it where there
 *         are any. Wording can change; never branch on it.
 *   data  the result on success. null on a refusal, except a code whose ResultCode note names a
 *         `data` field. VALIDATION_FAILED (2001) holds the failing fields:
 *         { "qty": ["The qty field must be at least 1."] }
 *
 * HTTP status
 *   200  code 0
 *   400  business or validation refusal
 *   401  API_KEY_MISSING (1001), API_KEY_UNKNOWN (1002)
 *   403  IP_NOT_ALLOWED (1003)
 *   500  fault on Ignite's side; body is not the envelope
 *
 * Errors blocks list the codes beyond API_KEY_MISSING, API_KEY_UNKNOWN and IP_NOT_ALLOWED,
 * which any call can return. A method without an Errors block returns no other code.
 *
 * SDK return values
 *   Every method except exportDownload returns the envelope as an array:
 *     $res['code'] === ResultCode::ORDER_DUPLICATE->value
 *   exportDownload returns the file text and throws on a refusal.
 *   GuzzleException: Ignite unreachable. RuntimeException: reply is not the envelope.
 *
 * Amounts
 *   Returned as decimal strings with 4 places, in replies and export files: "120.0000".
 *   Sent as strings with up to 4 decimal places ("120.5", "158400.00"); CV as a whole number ("300").
 *   Money (prices, commission, the cash_bal balance, the wd file) is USD. CV and coins are counts.
 *
 * Times
 *   Every date and time sent or returned is UTC+8. Dates "Y-m-d", times "Y-m-d H:i:s".
 *   A transaction row's `created_at` adds milliseconds: "Y-m-d H:i:s.v".
 *   Local time is converted before sending: India 14:30 (UTC+5:30) is "17:00". A wrong zone moves
 *   an order across a week or month cutoff with no error.
 *   A sales week opens and closes on Saturday at the cutoff time salesCalendar shows in `start_at`
 *   and `end_at`, not at midnight. An order's sales week is the one with start_at at or before
 *   `ordered_at` and end_at after it: an order paid on Saturday before the cutoff counts in the
 *   week before.
 *
 * Members
 *   A request names a member by `ba_id`. A placement parent may be named by `member_id` instead.
 *   Every member in a response carries `member_id`, and every `*_member_id` / `*_id` naming a
 *   member comes with its `*_ba_id`. The BA ID is filled for this partner's own members only and
 *   null for anyone else (an Ignite member, another partner's); `member_id` is always filled.
 *   Exceptions: pendingPlacements returns the BA ID and full name of every referral it lists,
 *   outsiders included, for setPlacement by BA ID. validateSponsor echoes the BA ID sent.
 *   A call taking `ba_id` acts on this partner's own members only, except validateSponsor,
 *   registerMember's sponsor, a placement parent, and setPlacement on a member from
 *   pendingPlacements. Any other BA ID answers MEMBER_NOT_FOUND (3001).
 *
 * Sponsor or position correction
 *   Done by Ignite operations on request; no call changes either. No notification is sent.
 *   Once Ignite operations confirm, memberDetails gives the new sponsor and position.
 *   A member still unplaced then shows in the new sponsor's placement window and is placed with
 *   setPlacement before the deadline. A placed member's position moves with the sponsor.
 *
 * Placement
 *   A placement parent is a node in the sponsor's binary network with a free side, usually the
 *   end of a leg. Free sides start one level below a -001: its own two sides always hold its
 *   -002 (left) and -003 (right), so naming a -001 answers PLACEMENT_REFUSED.
 *   End of each leg: far_left_id (side 1) or far_right_id (side 2) from memberDetails of the
 *   upline, sent as placement_member_id. The matching *_ba_id is null when that end belongs to a
 *   member outside this partner.
 *   Applies to registerMember, setPlacement, createOrder extra_tcs and placeBankedTc.
 *
 * Sign-up with an existing BA ID
 *   A world plan member moved to this partner by Ignite already holds a BA ID.
 *   - Sign-up asks for it first; memberDetails looks it up.
 *   - Found, and not in the partner's own records: the partner's own sign-up and KYC, then the
 *     BA ID is stored. No registerMember: sponsor and position already exist.
 *   - MEMBER_NOT_FOUND: no such BA ID, or not yet moved to this partner.
 *
 * Catalog lines and order lines
 *   Catalog line: one price of a product on the partner's Ignite catalog, named by
 *   `catalog_item_id`: retail and member price, CV, coins, and the window it is on sale.
 *   A product may hold several.
 *   Order line: one row of createOrder `lines`: a product, a quantity and the catalog line it is
 *   charged off.
 *
 * Ranks
 *   Named by ladder position, `rank_no`: 1 Silver to 8 Diamond, 0 = no rank. planRanks lists the
 *   ladder.
 *
 * CV naming
 *   Field names say CV. Enum values spell it `pv` (point_type pv1_bal and pv2_bal, descriptions
 *   pv_*); pv there means CV.
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
     * Get the sales calendar for a year
     *
     * Sales months of one sales year, each with its weeks nested.
     *
     * Request
     *   GET /meta/getSalesCalendar?year=2026
     *
     * Fields
     *   year  sales year
     *
     * Response
     *   data: {
     *     "year": 2026,
     *     "months": [
     *       {
     *         "year": 2026, "month_no": 9, "label": "2026-M09", "week_count": 5,
     *         "start_date": "2026-08-22", "end_date": "2026-09-25", "is_current": true,
     *         "weeks": [
     *           {
     *             "year": 2026, "week_no": 37, "week_in_month": 4,
     *             "start_date": "2026-09-12", "end_date": "2026-09-18",
     *             "start_at": "2026-09-12 02:00:00", "end_at": "2026-09-19 02:00:00",
     *             "is_current": true, "is_settled": true
     *           }
     *         ]
     *       }
     *     ]
     *   }
     *
     *   year + month_no  names a month
     *   year + week_no   names a week
     *   start_at         the week's first moment: start_date at Ignite's Saturday cutoff time
     *   end_at           the next week's start_at; the week holds the moments before it
     *   is_settled       true once the week's Saturday settlement finishes, not when it starts
     *
     * Errors
     *   VALIDATION_FAILED
     *
     * Notes
     *   - A sales year can end days into the next calendar year. Around New Year: request the
     *     calendar year, then the year before when no week returns is_current.
     */
    public function salesCalendar(int $year): array
    {
        return $this->get('/meta/getSalesCalendar', ['year' => $year]);
    }

    /**
     * Get plan figures and the rank ladder
     *
     * The partner's plan figures and rank ladder, lowest rank first. Display only: Ignite
     * calculates every rank.
     *
     * Request
     *   GET /meta/getPlanRanks
     *
     * Response
     *   data: {
     *     "plan": {
     *       "code": "A", "name": "Elite",
     *       "qualify_cv": "300.0000", "activation_cv": "300.0000", "pairing_step_cv": "1800.0000",
     *       "base_product_cv": "600.0000", "req_direct_cv_yearly": "300.0000",
     *       "green_redeem_usd": "125.0000", "yellow_usd_small": "30.0000", "yellow_usd_large": "40.0000",
     *       "blue_usd": "12.5000"
     *     },
     *     "ranks": [
     *       { "rank_no": 1, "name": "Silver", "step_comm": "250.0000", "req_steps": 0,
     *         "req_direct_cv_monthly": "0.0000", "req_tenure_months": 1, "req_prev_rank_count": 0,
     *         "req_prev2_rank_count": 0, "max_weekly_steps": 20 },
     *       { "rank_no": 8, "name": "Diamond", "step_comm": "285.0000", "req_steps": 310,
     *         "req_direct_cv_monthly": "1800.0000", "req_tenure_months": 3, "req_prev_rank_count": 2,
     *         "req_prev2_rank_count": 10, "max_weekly_steps": 105 }
     *     ]
     *   }
     *
     *   qualify_cv             CV the -001 takes to qualify, which opens the other nodes to CV
     *   activation_cv          CV each side of the -001 needs before pairing starts
     *   pairing_step_cv        CV per side making one pairing step
     *   base_product_cv        CV buying one extra TC out of an order's surplus
     *   req_direct_cv_yearly   direct CV per year from year two, ranks above Silver
     *   green_redeem_usd       paid by the Saturday settlement per 6 Green Coins
     *   yellow_usd_small       per Yellow Coin when redeemCoin takes 1 or 2
     *   yellow_usd_large       per Yellow Coin when redeemCoin takes 3 or more
     *   blue_usd               per Blue Coin; redeemCoin takes 4 or more
     *   rank_no                ladder position, 1 lowest to 8 highest, as memberDetails and rankProgress carry it
     *   step_comm              paid per pairing step at this rank
     *   req_steps              pairing steps the rank needs
     *   req_direct_cv_monthly  direct CV inside one sales month to reach or hold the rank
     *   req_tenure_months      consecutive sales months meeting this rank or higher, the closing month included
     *   req_prev_rank_count    unilevel downlines at the rank below, or above; 0 = none
     *   req_prev2_rank_count   the same, two ranks below
     *   max_weekly_steps       pairing steps paid per week at this rank
     *
     * Notes
     *   - Set by Ignite; changes rarely.
     */
    public function planRanks(): array
    {
        return $this->get('/meta/getPlanRanks', []);
    }

    /**
     * List catalog lines on sale
     *
     * Catalog lines on sale now on the partner's Ignite catalog, one row each, with the figures
     * createOrder charges today.
     *
     * Request
     *   GET /meta/getCatalog
     *
     * Response
     *   data: {
     *     "items": [
     *       { "catalog_item_id": 40, "partner_line_ref": "CL-88", "product_id": 12, "partner_product_ref": "CF-30",
     *         "name": "Ignite Coffee 30s",
     *         "is_physical": true, "retail_price": "120.0000", "member_price": "100.0000", "cv": "100.0000",
     *         "green_coin": "1.0000", "blue_coin": "2.0000" }
     *     ]
     *   }
     *
     *   catalog_item_id      the catalog line, sent on a createOrder order line. A product with several
     *                        catalog lines on sale lists each one
     *   partner_line_ref     the partner's own id for the catalog line; null when none was set
     *   product_id           sent on a createOrder order line beside catalog_item_id
     *   partner_product_ref  the partner's own id for the product; null when none was set
     *   name                 the product title buyers see (title_en in productList)
     *   retail_price         per unit, charged while the buyer's -001 is not qualified
     *   member_price         per unit, charged once it is
     *   cv                   CV per unit
     *   green_coin           Green Coins per unit, paid to the buyer's uplines, never the buyer, when the order's week settles
     *   blue_coin            Blue Coins per unit, paid to the buyer and the buyer's uplines when the order's week settles
     *
     * Notes
     *   - Products and catalog lines are set in the Ignite admin portal, or with createProduct and
     *     saveCatalogLines.
     *   - createOrder charges a named catalog line as it stands when the order arrives: a price
     *     changed since this read applies.
     */
    public function catalog(): array
    {
        return $this->get('/meta/getCatalog', []);
    }

    /**
     * List products
     *
     * The partner's products by product_id, hidden ones included.
     *
     * Request
     *   GET /products/getList?after_id=0&limit=100
     *
     * Fields
     *   after_id  rows after this product_id; 0 for the first page
     *   limit     1 to 500
     *
     * Response
     *   data: {
     *     "rows": [
     *       { "product_id": 12, "partner_product_ref": "CF-30", "name": "Ignite Coffee 30s",
     *         "title_en": "Ignite Coffee 30s", "desc_en": "<p>30 sachets</p>",
     *         "is_physical": true, "weight": "0.500", "is_visible": true, "sort_order": 0 }
     *     ],
     *     "has_more": false
     *   }
     *
     *   partner_product_ref  the partner's own id for the product; null on a product made in the admin portal without one
     *   name                 the name shown in the Ignite admin portal
     *   title_en             English title, shown to buyers; catalog returns it as name
     *   desc_en              English description, HTML; null when none
     *   is_physical          true when the product ships
     *   weight               kg, 3 decimals; null when the product does not ship
     *   is_visible           false keeps the product off catalog and off createOrder order lines naming no
     *                        catalog_item_id. An order line naming one still prices the order
     *   sort_order           lower lists first in catalog
     *
     * Errors
     *   VALIDATION_FAILED
     *
     * Notes
     *   - Paged: the last row's product_id as after_id while has_more is true.
     */
    public function productList(int $afterId, int $limit): array
    {
        return $this->get('/products/getList', ['after_id' => $afterId, 'limit' => $limit]);
    }

    /**
     * Create a product
     *
     * Creates a product. On sale only once a catalog line prices it (saveCatalogLines).
     *
     * Request
     *   POST /products/create
     *   {
     *     "partner_product_ref": "CF-30", "name": "Ignite Coffee 30s", "title_en": "Ignite Coffee 30s",
     *     "desc_en": "<p>30 sachets</p>", "is_physical": true,
     *     "weight": "0.500", "is_visible": true, "sort_order": 0
     *   }
     *
     * Fields
     *   partner_product_ref  the partner's own id for the product; up to 100 characters, unique among the
     *                        partner's products; never changes once set
     *   name                 up to 255 characters
     *   title_en             up to 255 characters
     *   desc_en              HTML, or null; scripts and unsafe tags removed
     *   weight              kg, up to 999999.999; required when is_physical is true, ignored otherwise
     *   sort_order           0 or more
     *
     * Response
     *   data: {
     *     "product_id": 12, "partner_product_ref": "CF-30", "name": "Ignite Coffee 30s",
     *     "title_en": "Ignite Coffee 30s", "desc_en": "<p>30 sachets</p>",
     *     "is_physical": true, "weight": "0.500", "is_visible": true, "sort_order": 0, "created": true
     *   }
     *
     *   the product as a productList row, plus
     *   created  true when this call created it; false on a repeat
     *
     * Errors
     *   VALIDATION_FAILED  field by field, under the names above
     *
     * Notes
     *   - Idempotent on partner_product_ref: a repeat returns the existing product and writes nothing,
     *     whatever the other fields hold.
     *   - Images and other languages are added in the Ignite admin portal.
     *
     * @param  array{partner_product_ref: string, name: string, title_en: string, desc_en: ?string,
     *               is_physical: bool, weight: ?string, is_visible: bool, sort_order: int}  $product
     */
    public function createProduct(array $product): array
    {
        return $this->post('/products/create', $product);
    }

    /**
     * Update a product
     *
     * Replaces every field of a product: product_id plus every createProduct field.
     *
     * Request
     *   POST /products/update
     *   {
     *     "product_id": 12, "partner_product_ref": "CF-30", "name": "Ignite Coffee 30s",
     *     "title_en": "Ignite Coffee 30s", "desc_en": "<p>30 sachets</p>",
     *     "is_physical": true, "weight": "0.500", "is_visible": true, "sort_order": 0
     *   }
     *
     * Fields
     *   product_id           a product_id of this partner's
     *   partner_product_ref  the product's own ref; a different one answers VALIDATION_FAILED. On a product
     *                        made in the admin portal without one: the ref to set
     *   other fields         as createProduct
     *
     * Response
     *   data: the product, as a productList row
     *
     * Errors
     *   VALIDATION_FAILED
     *   PRODUCT_NOT_FOUND
     */
    public function updateProduct(int $productId, array $product): array
    {
        return $this->post('/products/update', ['product_id' => $productId] + $product);
    }

    /**
     * List all catalog lines
     *
     * Every catalog line on the partner's catalog by catalog_item_id: on sale now, starting later,
     * ended and switched off.
     *
     * Request
     *   GET /catalog/getLines
     *
     * Response
     *   data: {
     *     "lines": [
     *       { "catalog_item_id": 40, "partner_line_ref": "CL-88", "product_id": 12, "partner_product_ref": "CF-30",
     *         "retail_price": "120.0000", "member_price": "100.0000", "cv": "100.0000",
     *         "green_coin": "1.0000", "blue_coin": "2.0000",
     *         "visible_start_at": "2026-10-01 00:00:00", "visible_end_at": null, "is_enabled": true,
     *         "pending_request_id": 7 }
     *     ]
     *   }
     *
     *   partner_line_ref    the partner's own id for the catalog line, as saveCatalogLines set it; null when none was set
     *   visible_start_at    first moment on sale; null = no start bound
     *   visible_end_at      the moment it leaves sale; null = no end
     *   is_enabled          false keeps the catalog line off sale whatever its window; createOrder refuses it
     *   pending_request_id  a saveCatalogLines request awaiting Ignite approval that changes this catalog
     *                       line; null when none. The figures shown stay the stored ones until it is applied
     *
     * Notes
     *   - A product may hold several catalog lines, windows overlapping or not.
     */
    public function catalogLines(): array
    {
        return $this->get('/catalog/getLines', []);
    }

    /**
     * Save a catalog line batch
     *
     * Adds and changes catalog lines in one batch. A row with `catalog_item_id` replaces that
     * catalog line's figures and window; a row with `product_id` adds a catalog line.
     *
     * Request
     *   POST /catalog/saveLines
     *   { "partner_batch_ref": "PB-1", "lines": [
     *     { "catalog_item_id": 40, "retail_price": "120.00", "member_price": "100.00", "cv": "100",
     *       "green_coin": "1", "blue_coin": "2", "visible_start_at": "2026-10-01 00:00:00",
     *       "visible_end_at": "2026-12-01 00:00:00", "is_enabled": true },
     *     { "product_id": 12, "partner_line_ref": "CL-89", "retail_price": "110.00", "member_price": "90.00",
     *       "cv": "100", "green_coin": "1", "blue_coin": "2", "visible_start_at": "2026-12-01 00:00:00",
     *       "visible_end_at": null, "is_enabled": true }
     *   ] }
     *
     * Fields
     *   partner_batch_ref  the partner's own id for the batch, up to 100 characters
     *   lines              1 to 200 rows, one per catalog line
     *   every row          all eight figure and window fields; null for an open window bound
     *   partner_line_ref   optional; the partner's own id for the catalog line, up to 100 characters, unique
     *                      among the catalog's lines. Set once, never changed: sent again it must match; left
     *                      out or null it stays. A deleted catalog line frees its ref
     *   figures            per unit, 0 or more; member_price at most retail_price
     *   visible_end_at     after visible_start_at
     *
     * Response
     *   data: { "request_id": 7, "status": "pending" }
     *   data: { "request_id": null, "status": "approved", "lines": [ { "catalog_item_id": 40 }, { "catalog_item_id": 41 } ] }
     *   data: { "request_id": null, "status": "unchanged" }
     *
     *   request_id  the approval request, as catalogChangeRequests lists it; null when stored at once or
     *               nothing changed
     *   status      pending    awaiting approval
     *               approved   stored now; approval off
     *               unchanged  every row repeats its stored catalog line and none adds one; nothing stored
     *   lines       approved only: each row's catalog_item_id, in the order sent, an added catalog line's new one included
     *
     *   On CATALOG_LINES_REFUSED:
     *   data: { "lines": { "lines.1": [ "Member Price is above Retail Price." ] } }
     *
     *   lines  each refused row's reasons, keyed lines.<index> of the rows sent
     *
     * Errors
     *   VALIDATION_FAILED      lines missing, not a list of rows, or over 200 rows
     *   CATALOG_LINES_REFUSED
     *   NETWORK_BUSY           same partner_batch_ref still running; retry once it finishes
     *
     * Notes
     *   - A catalog line left out of the batch stays as it is. Off sale: is_enabled false or a
     *     visible_end_at.
     *   - Stored at once, or sent for approval when Ignite has catalog change approval on; the
     *     integration guide shows which.
     *   - Sent for approval: applied whole or not at all. Until then catalog, catalogLines and
     *     createOrder read the stored catalog lines.
     *   - Approval checks the batch again: a batch naming a catalog line or product removed since
     *     ends `failed`.
     *   - Outcome in catalogChangeRequests; no notification.
     *   - Idempotent on partner_batch_ref for 24 h: a repeat returns the first call's result and
     *     stores nothing, whatever the approval decided since. After 24 h the same ref is a new batch.
     *   - A refused call keeps no record: the corrected batch may reuse its ref.
     *   - Catalog lines of one product may cover the same moment: a promotion beside the regular price.
     *   - Price change: a new catalog line starting when the old one ends, in the same batch; the old
     *     one's visible_end_at equals the new one's visible_start_at.
     *   - An added catalog line is found again by its partner_line_ref in catalogLines and catalog.
     *     The approved reply also lists its catalog_item_id.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function saveCatalogLines(array $lines, string $partnerBatchRef): array
    {
        return $this->post('/catalog/saveLines', ['partner_batch_ref' => $partnerBatchRef, 'lines' => $lines]);
    }

    /**
     * List catalog line batches
     *
     * saveCatalogLines batches sent for approval, newest first. Catalog changes made in the Ignite
     * admin portal are not listed.
     *
     * Request
     *   GET /catalog/getChangeRequests?before_id=0&limit=50&request_id=7
     *
     * Fields
     *   before_id   rows before this request_id; 0 for the newest. Ignored with request_id
     *   limit       1 to 500
     *   request_id  optional; that one request only
     *
     * Response
     *   data: {
     *     "rows": [
     *       { "request_id": 7, "status": "rejected", "submitted_at": "2026-11-08 10:15:00",
     *         "decided_at": "2026-11-08 16:40:12", "remark": "Member price below cost",
     *         "lines": [ { "catalog_item_id": 40, "retail_price": "120.0000", "member_price": "100.0000",
     *                      "cv": "100.0000", "green_coin": "1.0000", "blue_coin": "2.0000",
     *                      "visible_start_at": "2026-10-01 00:00:00", "visible_end_at": "2026-12-01 00:00:00",
     *                      "is_enabled": true } ] }
     *     ],
     *     "has_more": false
     *   }
     *
     *   status      pending   awaiting approval, or being approved now
     *               approved  stored
     *               rejected  refused by an Ignite admin; remark says why
     *               failed    approved, but the catalog lines no longer stood; remark says why
     *               expired   not decided within Ignite's approval window, shown in the integration guide
     *   decided_at  null while pending
     *   remark      null unless rejected or failed
     *   lines       the rows the batch sent, one per catalog line, figures as catalogLines returns them
     *
     * Errors
     *   VALIDATION_FAILED
     *
     * Notes
     *   - Paged: before_id 0, then the last row's request_id while has_more is true.
     */
    public function catalogChangeRequests(int $beforeId, int $limit, ?int $requestId = null): array
    {
        return $this->get('/catalog/getChangeRequests', ['before_id' => $beforeId, 'limit' => $limit, 'request_id' => $requestId]);
    }

    /**
     * Validate a sponsor
     *
     * Checks a sponsor BA ID by the rule registerMember applies to sponsor_ba_id: a main account
     * of this partner, of another partner or of the world plan.
     *
     * Request
     *   GET /members/validateSponsor?ba_id=AIA7918383-001
     *
     * Response
     *   data: { "member_id": 4, "ba_id": "AIA7918383-001" }
     *
     * Errors
     *   VALIDATION_FAILED
     *   MEMBER_NOT_FOUND   no such BA ID, or a -002 or -003
     */
    public function validateSponsor(string $baId): array
    {
        return $this->get('/members/validateSponsor', ['ba_id' => $baId]);
    }

    /**
     * Register a member
     *
     * Creates a member with three nodes (-001, -002, -003) under a sponsor and returns the BA ID,
     * which the partner stores. The member has no Ignite sign-in.
     *
     * Request
     *   POST /members/register
     *   { "partner_member_ref": "M-10233", "sponsor_ba_id": "AIA7918383-001", "fullname": "Ravi Kumar",
     *     "placement_ba_id": "AIA7918383-003", "side": 1 }
     *
     * Fields
     *   partner_member_ref   the partner's own id for the member, up to 100 characters; never changes
     *   sponsor_ba_id        a main account's BA ID, never a -002 or -003
     *   fullname             up to 255 characters
     *   placement_ba_id      placement parent by BA ID: a node known by name, such as an outside sponsor
     *   placement_member_id  placement parent by member_id: a node picked off binaryTree, outside ones included
     *   side                 1 left, 2 right
     *   One of placement_ba_id and placement_member_id with side, or none of the three. Anything else
     *   answers VALIDATION_FAILED.
     *   $placementParent     SDK argument: string sends placement_ba_id, int sends placement_member_id.
     *                        A member_id held as a string goes as a BA ID unless cast to int
     *
     * Response
     *   data: the memberDetails shape
     *
     * Errors
     *   VALIDATION_FAILED
     *   MEMBER_NOT_FOUND   sponsor or placement parent
     *   PLACEMENT_REFUSED  position taken or against the placement rules; nothing created
     *   NETWORK_BUSY       another placement still holds the position after 4 seconds; nothing created
     *
     * Notes
     *   - Always creates a new member. Not for a person who already holds a BA ID.
     *   - Idempotent on partner_member_ref: a ref already registered returns that member and creates nothing.
     *   - With a placement parent and side: seated there, or nothing created. A refused call may send
     *     the same partner_member_ref again.
     *   - Without them: registered unplaced. Ignite auto-places the member at placement_deadline,
     *     unless setPlacement runs first.
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
     * Get a member
     *
     * One member node. Ranks, membership status and balances are the account's: a sub-account
     * (-002, -003) returns its main account's. CV and position are the node's.
     *
     * Request
     *   GET /members/getDetails?ba_id=AIA7918383-001
     *
     * Response
     *   data: {
     *     "member_id": 4, "ba_id": "AIA7918383-001",
     *     "unilevel_parent_id": 1, "unilevel_parent_ba_id": null,
     *     "binary_parent_id": 2, "binary_parent_ba_id": null, "binary_side": 1,
     *     "cv_left": "123806.0000", "cv_right": "0.0000",
     *     "floating_cv_left": "25150.0000", "floating_cv_right": "150.0000",
     *     "total_cv_left": "123806.0000", "total_cv_right": "0.0000",
     *     "far_left_id": 9, "far_left_ba_id": "AIA5531207-001", "far_right_id": 6, "far_right_ba_id": "AIA7918383-003",
     *     "partner_member_ref": null, "main_member_id": null, "main_ba_id": null,
     *     "qualified_at": "2026-08-14 10:05:31", "membership_status": "active", "allow_withdraw": true,
     *     "rank_no": 1, "base_rank_no": 0, "eligible_rank_no": 1,
     *     "total_steps": 37,
     *     "placement_deadline": null,
     *     "cash_bal": "388.0000", "green_bal": "0.0000", "yellow_bal": "0.0000", "blue_bal": "761.0000",
     *     "cv_allocated": "300.0000", "tc_bank_available": 1
     *   }
     *
     *   unilevel_parent_*        the sponsor, by member_id and BA ID. The sample sponsor is outside this
     *                            partner: BA ID null
     *   binary_parent_*          the placement parent, by member_id and BA ID; null while unplaced
     *   binary_side              1 left, 2 right; null while unplaced
     *   far_left_*, far_right_*  the last node down each leg, by member_id and BA ID; null on an empty leg
     *   main_*                   the -001, by member_id and BA ID, on a sub-account; null on the main account
     *   partner_member_ref       the partner's own id sent to registerMember; null on a -002 or -003 and on a
     *                            member moved in by Ignite
     *   qualified_at             when this node qualified; null until then. On the -001 it ends retail pricing
     *   cv_*                     matured CV, what pairing consumes
     *   floating_cv_*            floating CV, not yet settled
     *   total_cv_*               lifetime settled CV; auto-placement seats on the weaker of these
     *   rank_no                  the rank in force: the higher of eligible_rank_no and base_rank_no
     *   eligible_rank_no         earned by the member's own conditions; progress renders against this
     *   base_rank_no             the floor Ignite operations set; 0 = none
     *   cash_bal                 commission balance; the weekly withdrawal pays it out
     *   total_steps              lifetime pairing steps on this node, paid and burned alike
     *   membership_status        retail, active, grace, suspended, terminated
     *   allow_withdraw           false while the member's payout is held, by setAllowWithdraw or Ignite operations
     *   placement_deadline       set only on an unplaced main account; Ignite auto-places when it passes
     *   cv_allocated             CV the member's own orders put on this node, lifetime, cancelled orders
     *                            excluded. Not downline CV (cv_left / cv_right). Input to the createOrder
     *                            allocations rules
     *   tc_bank_available        extra TCs waiting in the member's TC Bank for placeBankedTc: earned by an
     *                            order that did not name them, or added by Ignite operations. Null on a -002 or -003
     *
     * Errors
     *   VALIDATION_FAILED
     *   MEMBER_NOT_FOUND
     */
    public function memberDetails(string $baId): array
    {
        return $this->get('/members/getDetails', ['ba_id' => $baId]);
    }

    /**
     * Place a member
     *
     * Seats an unplaced member. A main account's -002 and -003 are seated under it in the same call.
     *
     * Request
     *   POST /members/setPlacement
     *   { "ba_id": "AIA3425549-001", "placement_ba_id": "AIA7918383-003", "side": 1 }
     *
     * Fields
     *   ba_id                one of this partner's members, or a direct referral listed by pendingPlacements:
     *                        a main account sponsored by this partner's main account, wherever it registered
     *   placement_ba_id      placement parent by BA ID: a node known by name, such as an outside sponsor
     *   placement_member_id  placement parent by member_id: a node picked off binaryTree, outside ones included
     *   side                 1 left, 2 right
     *   Exactly one of placement_ba_id and placement_member_id. Both, or neither, answers VALIDATION_FAILED.
     *   $placementParent     SDK argument: string sends placement_ba_id, int sends placement_member_id.
     *                        A member_id held as a string goes as a BA ID unless cast to int
     *
     * Response
     *   data: the memberDetails shape, after placement. For a member from outside this partner, only the
     *         tree row fields: member_id, ba_id null, parents, side, CV figures
     *
     * Errors
     *   PLACEMENT_DEADLINE_PASSED
     *   PLACEMENT_REFUSED
     *   NETWORK_BUSY
     *   MEMBER_NOT_FOUND
     *   VALIDATION_FAILED
     *
     * Notes
     *   - Deadline: placement_deadline in memberDetails, set by Ignite.
     *   - A retry whose first attempt succeeded answers PLACEMENT_REFUSED; memberDetails shows the seat.
     */
    public function setPlacement(string $baId, string|int $placementParent, int $side): array
    {
        return $this->post('/members/setPlacement', [
            'ba_id' => $baId,
            'side'  => $side,
        ] + $this->placementParent($placementParent));
    }

    /**
     * Place a TC Bank extra TC
     *
     * Seats one extra TC from a main account's TC Bank (memberDetails tc_bank_available) at exactly
     * the named parent and side.
     *
     * Request
     *   POST /members/placeBankedTc
     *   { "ba_id": "AIA8976104-001", "placement_ba_id": "AIA8976104-002", "side": 2 }
     *
     * Fields
     *   ba_id                the main account
     *   placement_ba_id      placement parent by BA ID
     *   placement_member_id  placement parent by member_id, for a node picked off binaryTree
     *   side                 1 left, 2 right
     *   Exactly one of placement_ba_id and placement_member_id. Both, or neither, answers VALIDATION_FAILED.
     *   The parent is the main account or a node in its binary downline.
     *   $placementParent     SDK argument: string sends placement_ba_id, int sends placement_member_id.
     *                        A member_id held as a string goes as a BA ID unless cast to int
     *
     * Response
     *   data: { "member_id": 211, "ba_id": "AIA8976104-005", "binary_parent_id": 200,
     *           "binary_parent_ba_id": "AIA8976104-002", "binary_side": 2 }
     *
     *   the new node, as one createOrder extra_tcs row
     *
     * Errors
     *   VALIDATION_FAILED
     *   MEMBER_NOT_FOUND         ba_id not this partner's, or a -002 or -003; or no such placement parent
     *   TC_BANK_EMPTY
     *   EXTRA_TC_PARENT_INVALID
     *   PLACEMENT_REFUSED        main account not yet placed; side taken or held
     *   NETWORK_BUSY             another placement still holds the slot after 4 seconds
     *
     * Notes
     *   - No deadline: a banked TC waits until placed.
     *   - Ignite picks the TC: one added by Ignite operations first, then the one from the oldest order.
     *     That order's getOrder then lists it in extra_tcs. Cancelling that order leaves it seated but
     *     no longer qualified.
     *   - Not idempotent. A retry whose first attempt landed answers PLACEMENT_REFUSED (side taken), or
     *     TC_BANK_EMPTY after the last banked TC; memberDetails tc_bank_available shows the drop.
     */
    public function placeBankedTc(string $baId, string|int $placementParent, int $side): array
    {
        return $this->post('/members/placeBankedTc', [
            'ba_id' => $baId,
            'side'  => $side,
        ] + $this->placementParent($placementParent));
    }

    /**
     * Hold or release weekly withdrawal
     *
     * Holds or releases a member's weekly commission withdrawal.
     *
     * Request
     *   POST /members/setAllowWithdraw
     *   { "ba_id": "AIA7918383-001", "allow_withdraw": false }
     *
     * Fields
     *   allow_withdraw  false holds, true releases
     *
     * Response
     *   data: the memberDetails shape, after the change
     *
     * Errors
     *   VALIDATION_FAILED
     *   MEMBER_NOT_FOUND   not this partner's, or a -002 or -003
     *   NETWORK_BUSY       Saturday settlement running; retry every 30 seconds
     *
     * Notes
     *   - One switch, shared with Ignite operations: the latest write from either side holds.
     *   - While held, the weekly withdrawal skips the member. The balance carries to the first weekly
     *     withdrawal after release.
     */
    public function setAllowWithdraw(string $baId, bool $allowWithdraw): array
    {
        return $this->post('/members/setAllowWithdraw', ['ba_id' => $baId, 'allow_withdraw' => $allowWithdraw]);
    }

    /**
     * List pending placements
     *
     * A sponsor's direct referrals awaiting a binary seat, nearest deadline first. Includes
     * referrals registered on Ignite or through another partner with this sponsor as referrer, and
     * follows any sponsor change by Ignite operations.
     *
     * Request
     *   GET /members/getPendingPlacements?ba_id=AIA7918383-001
     *
     * Fields
     *   ba_id  the sponsor's main account BA ID (-001)
     *
     * Response
     *   data: { "rows": [
     *     { "member_id": 311, "ba_id": "AIA3425549-001", "fullname": "Ravi Kumar",
     *       "registered_at": "2026-09-28 11:02:45", "has_ordered": true, "unplaced_downline_count": 0,
     *       "placement_deadline": "2026-10-07 00:00:00", "is_overdue": false },
     *     { "member_id": 312, "ba_id": "AIA1180042-001", "fullname": null,
     *       "registered_at": "2026-09-29 09:40:12", "has_ordered": false, "unplaced_downline_count": 2,
     *       "placement_deadline": "2026-10-07 00:00:00", "is_overdue": false }
     *   ] }
     *
     *   fullname                 null when Ignite holds none
     *   has_ordered              true when the member holds a paid order whose CV still counts (not
     *                            cancelled or returned)
     *   unplaced_downline_count  the member's own unplaced downlines, any depth; none can be placed until
     *                            this member is
     *   placement_deadline       Ignite auto-places the member when it passes
     *   is_overdue               true from placement_deadline until Ignite's auto-placement run seats the
     *                            member; setPlacement then answers PLACEMENT_DEADLINE_PASSED
     *
     * Errors
     *   VALIDATION_FAILED
     *   MEMBER_NOT_FOUND   not this partner's member, or a -002 or -003
     *
     * Notes
     *   - Source for the placement window; setPlacement seats each member.
     *   - A member leaves the list once placed, by the sponsor or by Ignite at the deadline.
     *     memberDetails or binaryTree shows where.
     */
    public function pendingPlacements(string $baId): array
    {
        return $this->get('/members/getPendingPlacements', ['ba_id' => $baId]);
    }

    /**
     * Get rank progress
     *
     * A member's progress toward every rank of their plan, in the sales month open now. Computed
     * from data the partner does not hold: orders and recruits outside this partner, and past
     * months' results.
     *
     * Request
     *   GET /members/getRankProgress?ba_id=AIA7918383-001
     *
     * Fields
     *   ba_id  the member's main account BA ID (-001)
     *
     * Response
     *   data: {
     *     "sales_month": { "year": 2026, "month_no": 9, "label": "2026-M09",
     *                      "start_date": "2026-08-22", "end_date": "2026-09-25" },
     *     "current": { "rank_no": 4, "base_rank_no": 0, "eligible_rank_no": 4 },
     *     "closing": { "met_rank_no": 4, "eligible_rank_no": 4, "rank_no": 4 },
     *     "ranks": [
     *       { "rank_no": 5, "name": "Sapphire", "step_comm": "250.0000", "max_weekly_steps": 70,
     *         "met": false,
     *         "conditions": {
     *           "steps":               { "current": 46, "required": 40, "met": true },
     *           "direct_cv":           { "current": "720.0000", "required": "600.0000", "met": true },
     *           "downlines_one_below": { "rank_no": 4, "current": 3, "required": 5, "met": false },
     *           "downlines_two_below": { "rank_no": 3, "current": 6, "required": 0, "met": true },
     *           "tenure":              { "current": 0, "required": 1, "met": false }
     *         } }
     *     ]
     *   }
     *
     *   sales_month            the month being judged; null when Ignite's sales calendar has no month for
     *                          today. direct_cv current then reads 0, and tenure counts no closed month:
     *                          its current is 1 on a rank at or below closing.met_rank_no, else 0
     *   current                the ranks held now, as memberDetails carries them; 0 = none
     *   closing                the ranks a month close today would write; 0 = none
     *     met_rank_no          highest rank whose steps, direct CV and both downline counts hold now
     *     eligible_rank_no     highest rank with tenure met, no higher than met_rank_no and at most one
     *                          rank above current eligible_rank_no
     *     rank_no              the higher of eligible_rank_no and current base_rank_no
     *   ranks                  every rank of the plan, lowest first; empty for a member holding no plan
     *     met                  steps, direct CV and both downline counts all met; tenure judged apart
     *   conditions             each: what the member has now, what the rank needs, whether it is met
     *     steps                pairing steps on the member's highest node, not the sum across nodes
     *     direct_cv            CV this sales month from the member's own paid orders, plus the retail-price
     *                          orders of members they sponsored, recruits outside this partner included
     *     downlines_one_below  unilevel downlines at any depth whose earned rank is rank_no or higher,
     *                          members outside this partner included. rank_no 0 on the lowest rank
     *     downlines_two_below  the same, two ranks below. Downlines counted for the one-below requirement
     *                          are left out of current
     *     tenure               months in a row meeting this rank or higher: the closed months, newest first,
     *                          plus the open month while closing.met_rank_no is this rank or higher
     *
     * Errors
     *   VALIDATION_FAILED
     *   MEMBER_NOT_FOUND   not this partner's member, or a -002 or -003
     *
     * Notes
     *   - Read only. Ranks change only when Ignite closes a month.
     */
    public function rankProgress(string $baId): array
    {
        return $this->get('/members/getRankProgress', ['ba_id' => $baId]);
    }

    /**
     * Get the unilevel tree
     *
     * Unilevel downlines of a member, root first. Sub-accounts are left out.
     *
     * Request
     *   GET /members/getUnilevelTree?ba_id=AIA7918383-001&depth=2
     *
     * Fields
     *   depth  the root plus every level below it down to this one, 1 to 3
     *
     * Response
     *   data: { "rows": [
     *     { "member_id": 4, "ba_id": "AIA7918383-001", "unilevel_parent_id": 1, "unilevel_parent_ba_id": null,
     *       "binary_parent_id": 2, "binary_parent_ba_id": null,
     *       "binary_side": 1, "cv_left": "123806.0000", "cv_right": "0.0000", "floating_cv_left": "25150.0000",
     *       "floating_cv_right": "150.0000", "total_cv_left": "123806.0000", "total_cv_right": "0.0000", "level": 0 },
     *     { "member_id": 7, "ba_id": null, "unilevel_parent_id": 4, "unilevel_parent_ba_id": "AIA7918383-001",
     *       "binary_parent_id": 5, "binary_parent_ba_id": "AIA7918383-002",
     *       "binary_side": 2, "cv_left": "0.0000", "cv_right": "0.0000", "floating_cv_left": "0.0000",
     *       "floating_cv_right": "0.0000", "total_cv_left": "0.0000", "total_cv_right": "0.0000", "level": 1 }
     *   ] }
     *
     *   ba_id  second sample row: a recruit of the root who registered outside this partner, so null
     *   level  0 is the root; rows ordered by level
     *
     * Errors
     *   VALIDATION_FAILED
     *   MEMBER_NOT_FOUND
     */
    public function unilevelTree(string $baId, int $depth): array
    {
        return $this->get('/members/getUnilevelTree', ['ba_id' => $baId, 'depth' => $depth]);
    }

    /**
     * Get the binary tree
     *
     * Binary downlines of a node, root first, every node including sub-accounts. Row shape:
     * unilevelTree's, plus each leg's far end.
     *
     * Request
     *   GET /members/getBinaryTree?ba_id=AIA7918383-001&depth=1
     *
     * Fields
     *   depth  the root plus every level below it down to this one, 1 to 5
     *
     * Response
     *   data: { "rows": [
     *     { "member_id": 4, "ba_id": "AIA7918383-001", "unilevel_parent_id": 1, "unilevel_parent_ba_id": null,
     *       "binary_parent_id": 2, "binary_parent_ba_id": null,
     *       "binary_side": 1, "cv_left": "123806.0000", "cv_right": "0.0000", "floating_cv_left": "25150.0000",
     *       "floating_cv_right": "150.0000", "total_cv_left": "123806.0000", "total_cv_right": "0.0000", "level": 0,
     *       "far_left_id": 9, "far_left_ba_id": "AIA5531207-001", "far_right_id": 6, "far_right_ba_id": "AIA7918383-003" },
     *     { "member_id": 5, "ba_id": "AIA7918383-002", "unilevel_parent_id": 4, "unilevel_parent_ba_id": "AIA7918383-001",
     *       "binary_parent_id": 4, "binary_parent_ba_id": "AIA7918383-001",
     *       "binary_side": 1, "cv_left": "98300.0000", "cv_right": "0.0000", "floating_cv_left": "20000.0000",
     *       "floating_cv_right": "0.0000", "total_cv_left": "98300.0000", "total_cv_right": "0.0000", "level": 1,
     *       "far_left_id": 9, "far_left_ba_id": "AIA5531207-001", "far_right_id": 7, "far_right_ba_id": null },
     *     { "member_id": 6, "ba_id": "AIA7918383-003", "unilevel_parent_id": 4, "unilevel_parent_ba_id": "AIA7918383-001",
     *       "binary_parent_id": 4, "binary_parent_ba_id": "AIA7918383-001",
     *       "binary_side": 2, "cv_left": "0.0000", "cv_right": "0.0000", "floating_cv_left": "0.0000",
     *       "floating_cv_right": "0.0000", "total_cv_left": "0.0000", "total_cv_right": "0.0000", "level": 1,
     *       "far_left_id": null, "far_left_ba_id": null, "far_right_id": null, "far_right_ba_id": null }
     *   ] }
     *
     *   level  0 is the root; rows ordered by level
     *   far_*  the last node down each leg of that row's node, by member_id and BA ID; null on an empty leg
     *
     * Errors
     *   VALIDATION_FAILED
     *   MEMBER_NOT_FOUND
     *
     * Notes
     *   - binaryTree with a far_* BA ID jumps to that leg's end.
     */
    public function binaryTree(string $baId, int $depth): array
    {
        return $this->get('/members/getBinaryTree', ['ba_id' => $baId, 'depth' => $depth]);
    }

    /**
     * List floating CV transactions
     *
     * One node's floating CV rows: credited, not yet settled.
     *
     * Request
     *   GET /members/getFloatingCvTxs?ba_id=AIA7918383-001&after_id=0&limit=100
     *
     * Fields
     *   after_id  rows after this id; 0 for the first page
     *   limit     1 to 500
     *
     * Response
     *   data: {
     *     "rows": [
     *       { "id": 1, "created_at": "2026-09-17 20:44:16.524", "side": 1, "amount": "50.0000",
     *         "description": "pv_mp", "buyer_member_id": 4, "buyer_ba_id": "AIA7918383-001",
     *         "order_no": "MY-260917-4830217",
     *         "sales_week": { "year": 2026, "week_no": 37 }, "settlement_week": { "year": 2026, "week_no": 43 } }
     *     ],
     *     "has_more": true
     *   }
     *
     *   amount           signed; a return writes a negative row
     *   description      pv_mp               CV added to a leg from a downline order
     *                    pv_refund           CV taken back when a downline order is returned
     *                    pv_refund_restore   CV added back when a return is rejected
     *                    pv_replace_out      CV taken off when a member is moved in the tree
     *                    pv_replace_in       CV added when a member is moved in the tree
     *   settlement_week  the week this CV settles in
     *
     * Errors
     *   VALIDATION_FAILED
     *   MEMBER_NOT_FOUND
     *
     * Notes
     *   - Paged: the last row's id as after_id while has_more is true.
     *   - Once a week settles, its rows leave this list and arrive as settled lump rows on pv1_bal and
     *     pv2_bal in pointTxs and the ledger export file.
     */
    public function floatingCvTxs(string $baId, int $afterId, int $limit): array
    {
        return $this->get('/members/getFloatingCvTxs', ['ba_id' => $baId, 'after_id' => $afterId, 'limit' => $limit]);
    }

    /**
     * List point transactions
     *
     * One node's ledger rows, in id order.
     *
     * Request
     *   GET /members/getPointTxs?ba_id=AIA7918383-001&after_id=31&limit=100
     *
     * Fields
     *   after_id  rows after this id; 0 for the first page
     *   limit     1 to 500
     *
     * Response
     *   data: {
     *     "rows": [
     *       { "id": 32, "created_at": "2026-09-19 10:10:05.118", "point_type": "cash_bal", "tx_type": "comm",
     *         "description": "comm_pairing", "amount": "250.0000", "bal_after": "638.0000",
     *         "order_no": null, "redemption_id": null, "rel_member_id": null, "rel_ba_id": null,
     *         "member_note": null }
     *     ],
     *     "has_more": true
     *   }
     *
     *   Row fields: the exportDownload columns, less member_id and ba_id; null where the file has an
     *   empty field.
     *
     * Errors
     *   VALIDATION_FAILED
     *   MEMBER_NOT_FOUND
     *
     * Notes
     *   - Paged: the last row's id as after_id while has_more is true.
     *   - Copy kept in step: the last id taken is the next call's after_id.
     */
    public function pointTxs(string $baId, int $afterId, int $limit): array
    {
        return $this->get('/members/getPointTxs', ['ba_id' => $baId, 'after_id' => $afterId, 'limit' => $limit]);
    }

    /**
     * List pairings for a sales week
     *
     * One sales week's pairing results for this partner's nodes, ordered by member_id, in one reply.
     *
     * Request
     *   GET /members/getPairings?year=2026&week_no=38
     *
     * Fields
     *   year     sales year, as salesCalendar names it
     *   week_no  week number within that sales year
     *   ba_id    optional; one node only, any of the partner's BA IDs, -002 and -003 included
     *
     * Response
     *   data: { "rows": [
     *     { "member_id": 4, "ba_id": "AIA7918383-001", "rank_no": 2, "step_comm": "250.0000",
     *       "pairing_step_cv": "1800.0000",
     *       "open_cv_left": "9000.0000", "open_cv_right": "1200.0000",
     *       "gained_cv_left": "300.0000", "gained_cv_right": "4500.0000", "paired_cv": "5400.0000",
     *       "close_cv_left": "3900.0000", "close_cv_right": "300.0000",
     *       "steps_before": 3, "steps_paid": 3, "steps_burned": 0, "steps_yellow": 1, "steps_halved": 0,
     *       "green_taken": "0.0000", "cash_amount": "500.0000" },
     *     { "member_id": 5, "ba_id": "AIA7918383-002", "rank_no": 2, "step_comm": "250.0000",
     *       "pairing_step_cv": "1800.0000",
     *       "open_cv_left": "50000.0000", "open_cv_right": "45000.0000",
     *       "gained_cv_left": "0.0000", "gained_cv_right": "0.0000", "paired_cv": "45000.0000",
     *       "close_cv_left": "0.0000", "close_cv_right": "0.0000",
     *       "steps_before": 40, "steps_paid": 20, "steps_burned": 5, "steps_yellow": 4, "steps_halved": 0,
     *       "green_taken": "0.0000", "cash_amount": "4000.0000" }
     *   ] }
     *
     *   member_id        the node that paired
     *   ba_id            that node's BA ID
     *   rank_no          the account's rank the commission was priced at, as planRanks numbers it
     *   step_comm        per paid step at that rank, as it stood at the Saturday settlement
     *   pairing_step_cv  CV per side making one step, as it stood at the Saturday settlement
     *   open_cv_*        matured CV on each leg before this week's CV settled
     *   gained_cv_*      CV this week settled onto each leg
     *   paired_cv        CV taken off each leg: every step this week, paid and burned, times pairing_step_cv
     *   close_cv_*       CV carried to next week as cv_left / cv_right: 0 when steps_paid plus steps_burned
     *                    reach max_weekly_steps, else the CV left on each leg after pairing
     *   steps_before     lifetime steps on this node before this Saturday settlement, paid and burned
     *                    (memberDetails total_steps)
     *   steps_paid       steps paid this week, steps_yellow included; at most the rank's max_weekly_steps
     *   steps_burned     steps past max_weekly_steps: CV taken, nothing paid
     *   steps_yellow     steps paid as one Yellow Coin each in place of cash: every 6th lifetime step on the node
     *   steps_halved     steps paid at half step_comm. Only the account's first two lifetime step payouts can be:
     *                    the first once 6 of its Green Coins were redeemed or taken, the second once 12 were
     *   green_taken      Green Coins taken from the account on those first two payouts when not halved, 6 each;
     *                    the balance may go negative
     *   cash_amount      credited to cash_bal for this row
     *
     * Errors
     *   VALIDATION_FAILED  no such sales week
     *   MEMBER_NOT_FOUND
     *
     * Notes
     *   - Written once by the Saturday settlement; a row never changes after.
     *   - Only a node that paired that week has a row: a week not yet settled, or settled before
     *     Ignite went live, returns no rows.
     *   - Commission is the account's: cash, Yellow Coins and the Green Coin take land on the main
     *     account (-001), also for a -002 or -003 row.
     *   - pointTxs and the ledger export file carry the same payout as rows, not on top of it:
     *       comm_pairing on cash_bal                  cash_amount
     *       comm_pairing on green_bal                 green_taken
     *       comm_yellow on yellow_bal                 steps_yellow
     *       pv_pairing, pv_burn on the node's pv1_bal and pv2_bal   paired_cv
     *       pv_cap_zero on pv1_bal and pv2_bal        open_cv_* + gained_cv_* - paired_cv, once steps_paid
     *                                                 plus steps_burned reach max_weekly_steps
     */
    public function pairings(int $year, int $weekNo, ?string $baId = null): array
    {
        return $this->get('/members/getPairings', ['year' => $year, 'week_no' => $weekNo, 'ba_id' => $baId]);
    }

    /**
     * Redeem coins
     *
     * Exchanges a member's coins for commission balance.
     *
     * Request
     *   POST /coins/redeem
     *   { "ba_id": "AIA7918383-001", "point_type": "yellow_bal", "qty": 3, "partner_redemption_ref": "R1" }
     *
     * Fields
     *   ba_id                   the main account's BA ID (-001). Coins sit there only: a -002 or -003
     *                           answers REDEMPTION_REFUSED
     *   point_type              yellow_bal or blue_bal
     *   qty                     whole coins
     *   partner_redemption_ref  the partner's own id for this redemption, up to 100 characters
     *
     * Response
     *   data: { "redemption_id": 7, "cash_amount": "120.0000" }
     *
     *   redemption_id  the redemption's id on Ignite; pointTxs and the export files carry it on the rows
     *                  this redemption wrote
     *   cash_amount    credited to cash_bal; in pointTxs, the cash_bal row with description cr_in
     *
     * Errors
     *   VALIDATION_FAILED
     *   MEMBER_NOT_FOUND
     *   CONVERT_DISABLED
     *   REDEMPTION_REFUSED
     *   REDEMPTION_IN_PROGRESS  same partner_redemption_ref still running; retry once it finishes
     *   NETWORK_BUSY            Saturday settlement running; retry every 30 seconds
     *
     * Notes
     *   - Rate set per quantity band: the per-coin value depends on qty (planRanks yellow_usd_small,
     *     yellow_usd_large, blue_usd).
     *   - The credited commission waits for the weekly commission withdrawal, which zeroes the balance
     *     and lists the member in that week's wd export file.
     *   - Idempotent on partner_redemption_ref for 24 h: a repeat returns the first call's result and
     *     redeems nothing.
     *   - A refused call keeps no record: the corrected call may reuse its ref.
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
     * Create an order
     *
     * Records an order the partner already took payment for. Lands accepted: CV climbs the binary
     * tree; coins and retail profit follow in the week it settles.
     *
     * Request
     *   POST /orders/create
     *   {
     *     "ba_id": "AIA8976104-001", "partner_order_ref": "SO-1001", "ordered_at": "2026-09-18 14:30:00",
     *     "local_amount": "158400.00", "local_currency": "INR",
     *     "lines": [ { "product_id": 12, "qty": 15, "catalog_item_id": 40 } ],
     *     "extra_tcs": [ { "placement_ba_id": "AIA8976104-002", "side": 1 } ],
     *     "allocations": [ { "ba_id": "AIA8976104-001", "cv": "300" }, { "ba_id": "AIA8976104-002", "cv": "300" },
     *                      { "ba_id": "AIA8976104-003", "cv": "300" }, { "extra_tc": 0, "cv": "600" } ]
     *   }
     *
     * Fields
     *   ba_id              the buyer
     *   partner_order_ref  the partner's own id for the order, up to 100 characters
     *   ordered_at         when the buyer paid; not in the future. Unnamed order lines are priced at this time
     *   local_amount       what the buyer paid the partner, in local_currency; recorded, never converted
     *   local_currency     3 capital letters, e.g. INR
     *   lines              the order lines, qty 1 or more. One product may sit on several order lines, each
     *                      naming a different catalog_item_id, e.g. 1 at the promotion catalog line and the
     *                      rest at the regular one. At most one order line per product names none. A repeat
     *                      answers VALIDATION_FAILED
     *   catalog_item_id    an enabled catalog line of the partner's catalog for this product_id, as catalog
     *                      and catalogLines return it. Left out: the catalog line on sale at ordered_at, window
     *                      start included and end excluded; refused while the product has several on sale then
     *   extra_tcs          new nodes, each seated at exactly the named parent and side, a node of the buyer's
     *                      own binary network. Parent by exactly one of placement_ba_id (BA ID) or
     *                      placement_member_id (member_id, for a node picked off binaryTree, outside ones
     *                      included); both, or neither, answers VALIDATION_FAILED
     *   allocations        where the order's CV lands. Each row names one of the buyer's nodes by ba_id, or an
     *                      extra TC of this order by its index in extra_tcs. Rules:
     *                        - rows sum to the order's CV
     *                        - the -001 takes exactly qualify_cv (planRanks) minus its cv_allocated
     *                          (memberDetails), or the whole order's CV when that is smaller; none once its
     *                          qualified_at is set
     *                        - the -002 and -003 each hold qualify_cv, across all the buyer's orders, before
     *                          any CV reaches an extra TC or a later node
     *                      Sample: the buyer holds no CV yet; 300 qualifies each of the three nodes, and the
     *                      600 left earns one extra TC (base_product_cv 600)
     *
     * Response
     *   data: {
     *     "order_no": "MY-260918-0572946", "partner_order_ref": "SO-1001", "price_basis": "retail",
     *     "subtotal": "1800.0000",
     *     "total_cv": "1500.0000", "total_green_coin": "15.0000", "total_blue_coin": "30.0000",
     *     "sales_week": { "year": 2026, "week_no": 37 }, "settlement_week": { "year": 2026, "week_no": 43 },
     *     "lines": [ { "product_id": 12, "catalog_item_id": 40, "qty": 15, "unit_price": "120.0000",
     *                  "total_price": "1800.0000", "cv": "1500.0000", "green_coin": "15.0000", "blue_coin": "30.0000" } ],
     *     "extra_tcs": [ { "member_id": 210, "ba_id": "AIA8976104-004", "binary_parent_id": 200,
     *                      "binary_parent_ba_id": "AIA8976104-002", "binary_side": 1 } ]
     *   }
     *
     *   price_basis                retail or member: the price the order lines were charged at
     *   subtotal                   the order lines' total_price added up
     *   lines                      the order lines, in request order, each with the catalog line it was charged off
     *     unit_price               per unit as charged, at price_basis
     *     total_price              unit_price times qty
     *     cv, green_coin, blue_coin  the order line's totals: per-unit figures times qty
     *   sales_week                 the week holding ordered_at
     *   settlement_week            the week the CV pays out in: the week starting the country's sales delay
     *                              (35 days for India) after the sales week ends, or the next unsettled week
     *                              when that one has settled
     *   extra_tcs                  in request order, each with the node it became. Banked ones are not listed;
     *                              getOrder later also lists one placeBankedTc seats from this order
     *
     *   On PRODUCT_NOT_ON_CATALOG:
     *   data: { "lines": { "lines.0": [ "Product 12: several catalog lines on sale at ordered_at, catalog_item_id 40, 41" ] },
     *           "product_ids": [ 12 ], "catalog_item_ids": [ 40, 41 ] }
     *
     *   lines             each refused order line's reason, keyed lines.<index> of the order lines sent
     *   catalog_item_ids  every catalog line on sale of the products refused for having several
     *
     * Errors
     *   VALIDATION_FAILED
     *   MEMBER_NOT_FOUND           buyer
     *   ORDER_DUPLICATE
     *   ORDER_DISABLED
     *   ORDER_NO_CV
     *   PRODUCT_NOT_ON_CATALOG
     *   CV_ALLOCATION_MISMATCH
     *   ALLOCATION_TARGET_INVALID
     *   CV_001_MISMATCH
     *   CV_ORIGINALS_FIRST
     *   EXTRA_TC_OVER_ENTITLEMENT
     *   EXTRA_TC_PARENT_INVALID
     *   ORDER_WEEK_UNKNOWN
     *   PLACEMENT_REFUSED          an extra TC's side is taken; the whole order is refused
     *   NETWORK_BUSY               another placement still holds an extra TC's slot after 4 seconds
     *
     * Notes
     *   - Idempotent on partner_order_ref: a repeat writes nothing and answers ORDER_DUPLICATE with the
     *     order_no. Treat as success.
     *   - Prices, CV and coins come from a catalog line of the partner's Ignite catalog; an order line
     *     carries none of them.
     *   - A named catalog line prices the order whatever its window and whatever the product's
     *     is_visible: a promotion beside the regular price, or a promotion price given after it ended.
     *     Ignite does not check that window; the partner's checkout enforces promotion windows.
     *   - Send catalog_item_id on every order line: once a second catalog line of a product is on sale,
     *     every order line of that product naming none is refused.
     *   - A catalog line with is_enabled false refuses every later order naming it, one paid while it
     *     was on sale included. visible_end_at refuses none: a promotion closes with visible_end_at.
     *   - A buyer whose -001 is not yet qualified buys at retail price; the sponsor receives the retail profit.
     *   - The order counts in the sales week holding ordered_at, however late it arrives. Its CV pays out
     *     no earlier than the next Saturday settlement; settlement_week says which.
     *   - An order arriving after its sales month closed counts toward no month's rank or matching bonus.
     *   - An extra TC the order earns and does not name goes to the buyer's TC Bank; placeBankedTc
     *     places it later.
     *
     * @param  array<int, array{product_id: int, qty: int, catalog_item_id?: int}>  $lines
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
     * Get an order
     *
     * One order sent with createOrder, by the partner's own ref.
     *
     * Request
     *   GET /orders/getDetails?partner_order_ref=SO-1001
     *
     * Response
     *   data: the createOrder data, plus
     *
     *   status  accepted, or cancelled once cancelOrder has run or Ignite operations took the order back
     *           as a return
     *
     * Errors
     *   VALIDATION_FAILED
     *   ORDER_NOT_FOUND
     *
     * Notes
     *   - Full result after a createOrder whose reply was lost: ORDER_DUPLICATE carries the order_no only.
     */
    public function getOrder(string $partnerOrderRef): array
    {
        return $this->get('/orders/getDetails', ['partner_order_ref' => $partnerOrderRef]);
    }

    /**
     * Cancel an order
     *
     * Cancels a whole order sent with createOrder, also after its week has paid out. Its CV comes
     * off the binary tree; the retail profit and coins it paid are taken back from whoever received
     * them, into a negative balance where needed. Pairing commission already paid on that CV stays.
     *
     * Request
     *   POST /orders/cancel
     *   { "partner_order_ref": "SO-1001" }
     *
     * Fields
     *   partner_order_ref  the partner_order_ref the order was created with
     *
     * Response
     *   data: { "order_no": "MY-260918-0572946", "partner_order_ref": "SO-1001" }
     *
     *   getOrder then returns status cancelled.
     *
     * Errors
     *   VALIDATION_FAILED
     *   ORDER_NOT_FOUND
     *   ORDER_ALREADY_CANCELLED
     *   NETWORK_BUSY             still held after 4 seconds. The Saturday settlement holds the network for
     *                            up to an hour; retry every 30 seconds
     *
     * Notes
     *   - Whole order only: under Ignite's return policy a buyer returns the whole order or nothing.
     *   - Idempotent: a repeat writes nothing and answers ORDER_ALREADY_CANCELLED with the order_no.
     *     Treat as success.
     */
    public function cancelOrder(string $partnerOrderRef): array
    {
        return $this->post('/orders/cancel', ['partner_order_ref' => $partnerOrderRef]);
    }

    /**
     * List export files
     *
     * The export files Ignite holds for this partner, newest week first.
     *
     * Request
     *   GET /exports/getList
     *
     * Response
     *   data: {
     *     "files": [
     *       { "name": "2026_W38_wd.csv", "size": 412, "modified_at": "2026-09-19 10:14:02" }
     *     ]
     *   }
     *
     *   size  bytes
     *
     * Notes
     *   - Once a sales week's Saturday settlement finishes, Ignite writes two CSV files for it:
     *       {year}_W{nn}_ledger.csv  every balance movement on this partner's members, any balance:
     *                                commission, coins, settled CV, take-backs from a cancelled or
     *                                returned order, coin redemptions, adjustments by Ignite operations.
     *                                Everything except the weekly commission withdrawal
     *       {year}_W{nn}_wd.csv      the weekly commission withdrawal, one row per member: the payable
     *                                figure. amount is negative, a debit off cash_bal; the member is paid
     *                                its absolute value. No row for a held member (allow_withdraw false)
     *                                or a cash_bal of 0 or below
     *   - nn: the week number, 2 digits (2026_W07_ledger.csv).
     *   - A week with no rows still has its file, header row only.
     *   - Rows cover this partner's members only. A row's file follows the node that received the row,
     *     never the buyer.
     *   - A ledger row's week is set by when it was written, not by the order or week it points at:
     *     after the previous week's Saturday settlement finished, up to the moment this week's finished.
     *     A take-back written days after its order paid out sits in the week it was written. Every row
     *     sits in exactly one week's file.
     *   - Ledger sort: account (a main account with its sub-accounts), then member_id, then point_type,
     *     then id; each balance reads top to bottom.
     *   - A member's last cash_bal row in a week shows the balance the wd file pays: nothing moves a
     *     balance while the Saturday settlement runs.
     *   - The withdrawal is left out of the ledger: the next week's first cash_bal row counts on from the
     *     balance after the payout (0 for a member paid out, the held balance for a held member), not
     *     from this file's last row.
     *   - Re-running a week's export rewrites its file with the same rows: modified_at moves, the
     *     content does not.
     */
    public function exportList(): array
    {
        return $this->get('/exports/getList', []);
    }

    /**
     * Download an export file
     *
     * One export file by name, as CSV text: UTF-8, comma-separated, header row first.
     *
     * Request
     *   GET /exports/download?name=2026_W38_ledger.csv
     *
     * Fields
     *   name  a name from exportList
     *
     * Response
     *   The file, on HTTP 200. Both files carry the same columns:
     *     id, created_at, member_id, ba_id, point_type, tx_type, description, amount, bal_after,
     *     order_no, redemption_id, rel_member_id, rel_ba_id, member_note
     *
     *   id             the row's id on Ignite, the same id pointTxs returns
     *   created_at     when the row was written
     *   member_id      the node whose balance moved
     *   ba_id          that node's BA ID
     *   point_type     the balance that moved:
     *                    cash_bal    commission balance
     *                    green_bal   Green Coin balance
     *                    yellow_bal  Yellow Coin balance
     *                    blue_bal    Blue Coin balance
     *                    pv1_bal     left leg CV balance
     *                    pv2_bal     right leg CV balance
     *                    and others
     *   tx_type        the kind of movement: comm (earned), pv (CV), cr (coin redemption),
     *                  adj (adjustment by Ignite operations), wd (withdrawal), and others
     *   description    the exact reason, e.g. comm_pairing; tx_type is its first word
     *   amount         signed; a take-back is negative
     *   bal_after      that balance on that node right after this row
     *   order_no       the order's number on a row from an order, else empty
     *   redemption_id  on a coin redemption row, its id, as redeemCoin returns it; else empty
     *   rel_member_id  the other member involved, such as the buyer behind an order payout
     *   rel_ba_id      that member's BA ID; empty for a member outside this partner
     *   member_note    text written with the row; a settled CV row names the week the CV came from
     *
     *   An empty field is an empty string; the file holds no null.
     *
     * Errors
     *   VALIDATION_FAILED  name does not match the file name pattern
     *   EXPORT_NOT_FOUND
     *
     * Notes
     *   - On a refusal the SDK throws RuntimeException: getCode() is the ResultCode value, the message
     *     is the envelope's msg.
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
