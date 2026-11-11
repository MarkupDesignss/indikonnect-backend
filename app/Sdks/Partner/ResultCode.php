<?php

namespace App\Sdks\Partner;

/**
 * The `code` of every partner API response. 0 is success; anything else is a refusal.
 *
 * Each case's note says when Ignite returns it and the rule behind it.
 * `message()` is the default `msg`; a refusal carrying figures sends them in `msg` instead.
 *
 * Terms
 *   Node, tracking center (TC)  one position in the binary tree. A member registers with three:
 *                               -001 (the main account), -002 and -003. An extra TC is a further
 *                               node an order earns, numbered -004 onward.
 *   BA ID                       a node's id: the member's code plus the node number, e.g. AIA7918383-001.
 *   Sponsor                     the member who brought the new member in. Sets the unilevel tree.
 *   Binary tree                 every node has one left and one right slot. CV landing on a node
 *                               climbs to every node above it, and commission pays on the CV each
 *                               side of a node collects.
 *   CV                          the volume figure each catalog line carries. Commission is computed
 *                               on CV, not on price.
 *   Qualified                   a node holding the plan's qualify_cv (planRanks) from its owner's own
 *                               purchases. Until the -001 qualifies, the member buys at retail price.
 *   Sales week                  the week `ordered_at` falls in. The order's CV pays out in a later
 *                               settlement week, after the country's sales delay, in the Saturday settlement.
 */
enum ResultCode: int
{
    case OK = 0;

    /** The request carries no `Authorization: Bearer` header. */
    case API_KEY_MISSING = 1001;
    /** The key matches no partner. Ignite issues one key per partner. */
    case API_KEY_UNKNOWN = 1002;
    /** The call came from an IP not registered for the partner. */
    case IP_NOT_ALLOWED = 1003;

    /** A field is missing or has the wrong type or range. `data` holds the failing fields, each with its messages. */
    case VALIDATION_FAILED = 2001;

    /**
     * No member of this partner holds the BA ID. A call reads or acts on the partner's own
     * members only, and a buyer or sponsor must be a main account (-001). A sponsor or
     * placement parent may belong to anyone, and so may the member setPlacement seats when a
     * main account of this partner recruited it.
     */
    case MEMBER_NOT_FOUND = 3001;
    /** Ignite operations switched coin exchange off, platform-wide or for this member. Nothing was redeemed. */
    case CONVERT_DISABLED = 3002;
    /**
     * The member cannot redeem this quantity. Coins convert at rates Ignite sets per quantity band.
     * `msg` states the reason: balance too low, or qty outside every band.
     */
    case REDEMPTION_REFUSED = 3003;
    /** A call with the same partner_redemption_ref is still running. Retry after it finishes. */
    case REDEMPTION_IN_PROGRESS = 3004;

    /**
     * The member's placement window is closed. A sponsor may choose a new member's position until
     * Wednesday 00:00 (UTC+8) of the sales week after registration. Ignite then seats the member itself,
     * at the far end of the sponsor -001's weaker leg by lifetime CV, and only Ignite operations can move it.
     */
    case PLACEMENT_DEADLINE_PASSED = 4001;
    /**
     * The placement parent and side is not a legal position. This call placed nothing. `msg` names the rule:
     * - a member is placed once; moving a placed member is a correction by Ignite operations. A retried
     *   setPlacement or placeBankedTc whose first attempt succeeded lands here
     * - a -002 or -003 sits under its own -001 and moves only with it
     * - sponsor and placement parent both hold a binary position; on placeBankedTc, the main account does
     * - the placement parent is the sponsor or a node in the sponsor's binary downline
     * - each node has one left and one right slot; a taken slot refuses, and so does one held by an
     *   unpaid order on Ignite
     */
    case PLACEMENT_REFUSED = 4002;
    /** The main account holds no extra TC in its TC Bank. memberDetails tc_bank_available reads 0. */
    case TC_BANK_EMPTY = 4003;

    /**
     * The partner_order_ref was already received, so the order exists on Ignite. `data.order_no` names it. Treat as success.
     * The full first result, extra TC BA IDs included, comes from getOrder.
     */
    case ORDER_DUPLICATE = 5001;
    /**
     * An order line has no catalog line to price it. Price, CV and coins come only from the partner's
     * Ignite catalog. An order line naming `catalog_item_id` needs an enabled catalog line of the
     * partner's catalog for that product, its window and the product's is_visible not read. An order
     * line naming none needs exactly one catalog line of a visible product on sale at `ordered_at`:
     * none, or several, refuses. `data.lines` holds each refused order line's reason keyed
     * `lines.<index>` of the order lines sent, naming the catalog_item_ids on sale where several were.
     * `data.product_ids` lists the products, `data.catalog_item_ids` every catalog line on sale where
     * several were.
     */
    case PRODUCT_NOT_ON_CATALOG = 5002;
    /**
     * The allocations do not add up to the order's CV. The buyer picks the node each part of the CV
     * lands on, and all of it is allocated. `msg` gives both totals.
     */
    case CV_ALLOCATION_MISMATCH = 5003;
    /** An allocation names a `ba_id` that is not one of the buyer's nodes, or an `extra_tc` index with no extra TC in the order. */
    case ALLOCATION_TARGET_INVALID = 5004;
    /**
     * The -001 got the wrong amount. A member's first qualify_cv of own-purchase CV goes to the -001,
     * across all their orders, and qualifies it. So the -001 takes exactly qualify_cv minus its cv_allocated
     * (memberDetails), or the whole order's CV when that is smaller; a qualified -001 takes none.
     * qualify_cv 300, 180 already on the -001, order CV 400: the -001 takes exactly 120.
     * `msg` gives the figure.
     */
    case CV_001_MISMATCH = 5005;
    /**
     * The order asks for more extra TCs than it earns. An order earns them only when it leaves the -001,
     * -002 and -003 all qualified. The CV left after qualifying them buys one extra TC per base_product_cv
     * (planRanks), rounded down. An earned extra TC not requested goes to the member's TC Bank, and
     * placeBankedTc places it. `msg` gives the count earned.
     */
    case EXTRA_TC_OVER_ENTITLEMENT = 5006;
    /**
     * An extra TC's parent is outside the buyer's own network, on createOrder and placeBankedTc. The parent
     * must be a placed node that is the buyer or sits in the buyer's binary downline.
     */
    case EXTRA_TC_PARENT_INVALID = 5007;
    /** No week in Ignite's sales calendar holds `ordered_at`, or the later week its CV pays out in. Ignite extends the calendar. */
    case ORDER_WEEK_UNKNOWN = 5009;
    /** No order of this partner carries the partner_order_ref. */
    case ORDER_NOT_FOUND = 5010;
    /** The order is cancelled already. `data.order_no` names it. Treat as success. */
    case ORDER_ALREADY_CANCELLED = 5011;
    /**
     * Another write holds the network. Nothing was written, and the same call can be sent again.
     * On cancelOrder, redeemCoin and setAllowWithdraw: the Saturday settlement, up to an hour; retry every 30 seconds.
     * On saveCatalogLines: a call with the same partner_batch_ref is still running; retry after it finishes.
     * On a placement: another placement on the same member or slot; a retry after it finishes can succeed.
     */
    case NETWORK_BUSY = 5013;
    /**
     * Every order line's catalog CV is zero, so the order carries none. Ignite accepts only orders
     * carrying CV; this one stays on the partner's side only.
     */
    case ORDER_NO_CV = 5014;
    /**
     * CV went past the -003 too early. After the -001, the -002 and -003 each fill to qualify_cv, counting
     * accepted orders and this one, before any CV reaches an extra TC or a node from -004 on.
     * `msg` names the node and its shortfall.
     */
    case CV_ORIGINALS_FIRST = 5015;
    /**
     * Ignite operations switched ordering off on the buyer's account. Nothing was written, and the
     * payment stays on the partner's side. A retry succeeds only once Ignite confirms the switch is back on.
     */
    case ORDER_DISABLED = 5017;

    /**
     * No export file by this name. Ignite writes a week's files once its Saturday settlement
     * finishes, so a week not yet settled has none. exportList names every file there is.
     */
    case EXPORT_NOT_FOUND = 6001;

    /** No product of this partner holds the product_id. A call reads or writes the partner's own products only. */
    case PRODUCT_NOT_FOUND = 7001;
    /**
     * The batch broke a catalog line rule, so no catalog line of it was stored. `msg` names every
     * refused row as `lines.<index>`, and `data.lines` holds each one's reasons under that key: a field
     * missing or out of range, a member price above the retail price, a visible_end_at not after
     * visible_start_at, a catalog line or product not the partner's, or a partner_line_ref that
     * differs from the catalog line's own, repeats in the batch or names another catalog line.
     */
    case CATALOG_LINES_REFUSED = 7002;

    public function message(): string
    {
        return match ($this) {
            self::OK                 => '',
            self::API_KEY_MISSING    => 'API key missing',
            self::API_KEY_UNKNOWN    => 'API key not recognised',
            self::IP_NOT_ALLOWED     => 'IP address not allowed',
            self::VALIDATION_FAILED  => 'Validation failed',
            self::MEMBER_NOT_FOUND   => 'Member not found',
            self::CONVERT_DISABLED   => 'Coin exchange disabled',
            self::REDEMPTION_REFUSED => 'Redemption refused',
            self::REDEMPTION_IN_PROGRESS => 'Redemption in progress',
            self::PLACEMENT_DEADLINE_PASSED => 'Placement deadline passed',
            self::PLACEMENT_REFUSED => 'Placement refused',
            self::TC_BANK_EMPTY => 'TC Bank empty',
            self::ORDER_DUPLICATE => 'Order already received',
            self::PRODUCT_NOT_ON_CATALOG => 'Product not on sale on the partner catalog',
            self::CV_ALLOCATION_MISMATCH => 'CV allocation does not equal order CV',
            self::ALLOCATION_TARGET_INVALID => 'Allocation target is not a node of the buyer',
            self::CV_001_MISMATCH => 'Wrong CV allocation to the -001',
            self::EXTRA_TC_OVER_ENTITLEMENT => 'More extra TCs than the order earns',
            self::EXTRA_TC_PARENT_INVALID => 'Extra TC parent outside the buyer network',
            self::ORDER_WEEK_UNKNOWN => 'Order date outside the sales calendar',
            self::ORDER_NOT_FOUND => 'Order not found',
            self::ORDER_ALREADY_CANCELLED => 'Order already cancelled',
            self::NETWORK_BUSY => 'Network update in progress',
            self::ORDER_NO_CV => 'Order carries no CV',
            self::CV_ORIGINALS_FIRST => 'The -002 and -003 take CV first',
            self::ORDER_DISABLED => 'Ordering disabled on this account',
            self::EXPORT_NOT_FOUND => 'Export file not found',
            self::PRODUCT_NOT_FOUND => 'Product not found',
            self::CATALOG_LINES_REFUSED => 'Catalog lines refused',
        };
    }

    public function httpStatus(): int
    {
        return match ($this) {
            self::OK                                    => 200,
            self::API_KEY_MISSING, self::API_KEY_UNKNOWN => 401,
            self::IP_NOT_ALLOWED                        => 403,
            default                                     => 400,
        };
    }
}
