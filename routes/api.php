<?php

use App\Http\Controllers\Admin\AdminBuybackController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ContentController;
use App\Http\Controllers\Admin\MenuController;
use App\Http\Controllers\Admin\NotificationTemplateController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\TaxCategoryController;
use App\Http\Controllers\Admin\PayoutController;
use App\Http\Controllers\Admin\ReconciliationController;
use App\Http\Controllers\Admin\KycController;
use App\Http\Controllers\Admin\AttributeController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\API\AddressController;
use App\Http\Controllers\API\AuthController as APIAuthController;
use App\Http\Controllers\API\CartController;
use App\Http\Controllers\API\CheckoutController;
use App\Http\Controllers\API\ContactController;
use App\Http\Controllers\API\CouponController;
use App\Http\Controllers\API\FooterController;
use App\Http\Controllers\API\GrowthStepController;
use App\Http\Controllers\API\HeritageSiteController;
use App\Http\Controllers\API\InvoiceController;
use App\Http\Controllers\API\NotificationSettingsController;
use App\Http\Controllers\API\OrderController;
use App\Http\Controllers\API\ProductReviewController;
use App\Http\Controllers\API\ReelController;
use App\Http\Controllers\API\ReturnController;
use App\Http\Controllers\API\ReviewController;
use App\Http\Controllers\API\ShippingMethodController;
use App\Http\Controllers\API\SubscriberController;
use App\Http\Controllers\API\UserDashboardController;
use App\Http\Controllers\API\WishlistController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\API\LedgerController;
use App\Http\Controllers\API\BeneficiaryController;
use App\Http\Controllers\API\GenealogyController;
use App\Http\Controllers\API\BuybackController;
use App\Http\Controllers\API\NotificationController;
use App\Http\Controllers\API\UserNotificationController;
use App\Http\Controllers\API\CoolingOffController;
use App\Http\Controllers\API\CreditNoteController;
use App\Http\Controllers\API\Webhook\RazorpayWebhookController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\FAQController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\CancellationApprovalController;
use App\Http\Controllers\Admin\CatalogueController;
use App\Http\Controllers\Admin\ExportController;
use App\Http\Controllers\Admin\FaqSectionController;
use App\Http\Controllers\Admin\WarehouseAssignmentController;
use App\Http\Controllers\Admin\WarehouseController;
use App\Http\Controllers\Admin\WarehouseStockController;
use App\Http\Controllers\API\BrandController;
use App\Http\Controllers\API\SubcategoryController;
use App\Http\Controllers\API\TestimonialController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Fallback login route (for unauthenticated API access)
Route::get('/login', function () {
    return response()->json([
        'success' => false,
        'message' => 'Authentication token is required to access this API.'
    ], 401);
})->name('login');

// ============================
// ADMIN AUTH ROUTES
// ============================
Route::prefix('admin')->group(function () {
    // Public Routes
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/send-reset-otp', [AuthController::class, 'sendResetOtp']);
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
    Route::get('/registered-users', [AuthController::class, 'getRegisteredUsers']);
    Route::get('/registered-users/{id}', [AuthController::class, 'getUserDetails']);

    // Protected Admin Routes
    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        Route::get('/dashboard', [AdminController::class, 'dashboard']);
        Route::post('/update-user-status/{id}', [AuthController::class, 'toggleUserStatus']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('distributors/{id}/status', [AuthController::class, 'updateStatus']);
        Route::post('/update', [AuthController::class, 'update']);

        // Payout Run routes
        Route::get('/payouts', [PayoutController::class, 'index']);
        Route::post('/payouts', [PayoutController::class, 'store']);
        Route::get('/payouts/{id}', [PayoutController::class, 'show']);
        Route::post('/payouts/{id}/release', [PayoutController::class, 'release']);
        Route::post('/payouts/entries/{entryId}/hold', [PayoutController::class, 'holdEntry']);
        Route::get('/payouts/{id}/export', [PayoutController::class, 'export']);
        Route::post('/payouts/{id}/notify', [PayoutController::class, 'sendNotifications']);

        // Orders
        Route::get('/all-orders', [OrderController::class, 'allOrder']);
        Route::get('/get-order-details/{id}', [OrderController::class, 'getOrderDetails']);

        // Commission Reconciliation Report
        Route::get('/reconciliation', [ReconciliationController::class, 'index']);
        Route::get('/reconciliation/export', [ReconciliationController::class, 'export']);
        Route::get('/reconciliation/summary', [ReconciliationController::class, 'summary']);
        Route::post('/reconciliation/events/{eventId}/replay', [ReconciliationController::class, 'replayEvent']);

        // Settings Management
        Route::get('/settings', [SettingController::class, 'index']);
        Route::get('/settings/buyback_activate', [SettingController::class, 'buybackActivate']);
        Route::post('/settings', [SettingController::class, 'store']);
        Route::put('/settings/{key}', [SettingController::class, 'update']);
        Route::delete('/settings/{key}', [SettingController::class, 'destroy']);
    });
});

// Payment Management (should be admin protected)
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/payment-management', [PayoutController::class, 'paymentManagement']);
});

// ============================
// HEADER MENU
// ============================
Route::get('/logo', [MenuController::class, 'logo']);
Route::prefix('header')->group(function () {
    Route::get('/', [MenuController::class, 'index']);

    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        Route::post('/add', [MenuController::class, 'store']);
        Route::delete('/delete/{id}', [MenuController::class, 'destroy']);
        Route::post('/menu/{id}/status', [MenuController::class, 'toggleStatus']);
        Route::post('/update/{id}', [MenuController::class, 'update']);
    });
});

// ============================
// CONTENTS (PAGES)
// ============================
Route::prefix('contents')->group(function () {
    // Public routes
    Route::get('/', [ContentController::class, 'index']);
    Route::get('/landing-page', [ContentController::class, 'landingindex']);
    Route::get('/{slug}', [ContentController::class, 'show']);

    // Admin routes
    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        Route::get('/admins/all', [ContentController::class, 'adminindex']);
        Route::get('/admin/{slug}', [ContentController::class, 'adminindex']);
        Route::post('/add', [ContentController::class, 'store']);
        Route::post('/update/{id}', [ContentController::class, 'update']);
        Route::delete('/delete/{id}', [ContentController::class, 'destroy']);
        Route::delete('/content-media/{id}', [ContentController::class, 'deleteMedia']);
    });
});

// ============================
// CATEGORIES
// ============================
Route::prefix('categories')->group(function () {
    // Public routes
    Route::get('/', [CategoryController::class, 'index']);
    Route::get('/{id}', [CategoryController::class, 'show']);

    // Admin routes
    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        Route::post('/add', [CategoryController::class, 'store']);
        Route::post('/update/{id}', [CategoryController::class, 'update']);
        Route::delete('/delete/{id}', [CategoryController::class, 'destroy']);
        Route::post('/update/{id}/status', [CategoryController::class, 'updateStatus']);
        Route::post('/bulk-delete', [CategoryController::class, 'bulkDelete']);
    });
});

// ============================
// CONTACT US
// ============================
Route::prefix('contact')->group(function () {
    Route::middleware('optional.auth:sanctum')->group(function () {
        Route::post('/send-request', [ContactController::class, 'store']);
    });

    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        Route::get('/', [ContactController::class, 'index']);
        Route::get('/{id}', [ContactController::class, 'show']);
        Route::delete('/{id}', [ContactController::class, 'destroy']);
        Route::post('/mark-read/{id}', [ContactController::class, 'markAsRead']);
        Route::post('/bulk-delete', [ContactController::class, 'bulkDelete']);
    });
});

// ============================
// NEWSLETTERS / SUBSCRIBERS
// ============================
Route::prefix('subscribers')->group(function () {
    Route::post('/', [SubscriberController::class, 'store']);

    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        Route::get('/', [SubscriberController::class, 'index']);
        Route::delete('/{email}', [SubscriberController::class, 'destroy']);
    });
});

// ============================
// PRODUCTS
// ============================
Route::post('move-product-image/{product}', [ProductController::class, 'swapImageOrder']);
Route::prefix('products')->group(function () {
    // Public routes with optional auth
    Route::middleware('optional.auth:sanctum')->group(function () {
        Route::get('/', [ProductController::class, 'index']);
        Route::get('/trending', [ProductController::class, 'trending']);
        Route::get('/slug/{slug}', [ProductController::class, 'showBySlug']);
        Route::get('/code/{code}', [ProductController::class, 'showByCode']);
        Route::get('/{product}', [ProductController::class, 'show']);
        Route::get('/category/{categoryId}', [ProductController::class, 'productsByCategory']);
        Route::get('/brands/{brandId}', [ProductController::class, 'productsByBrand']);
    });

    // Admin protected routes
    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        Route::post('/', [ProductController::class, 'store']);
        Route::post('/update/{id}', [ProductController::class, 'update']);
        Route::delete('/images/{id}', [ProductController::class, 'deleteImages']);
        Route::put('/{product}', [ProductController::class, 'update']);
        Route::delete('/{product}', [ProductController::class, 'destroy']);
        Route::delete('/{product}/images', [ProductController::class, 'deleteImages']);
        Route::post('/{product}/stock', [ProductController::class, 'updateStock']);
        Route::post('/{product}/toggle-publish', [ProductController::class, 'togglePublish']);
        Route::post('/publish/{product}/product', [ProductController::class, 'togglePublished']);
        Route::post('/stock/update', [ProductController::class, 'updateStock']);
    });
});

// Product extra routes
Route::post('/global-search', [ProductController::class, 'globalSearch']);
Route::get('/products-deal-of-the-day', [ProductController::class, 'getDealOfTheDayProducts']);
Route::get('/products-top-discounted', [ProductController::class, 'getTopDiscountedProducts']);
Route::get('/product-sections', [ProductController::class, 'getProductSections']);
Route::get('/product-link/{id}', [ProductController::class, 'generateProductLink']);
Route::get('/product/{slug}', [ProductController::class, 'getProductBySlug']);

Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::post('/products-deal-of-the-day/{id}', [ProductController::class, 'markAsDealOfTheDay']);
    Route::delete('/products-deal-of-the-day/{id}', [ProductController::class, 'removeDealOfTheDay']);
    Route::post('/trending-products/{id}', [ProductController::class, 'updateTrendingStatus']);
});

// ============================
// TAX CATEGORIES
// ============================
Route::prefix('tax-categories')->middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/', [TaxCategoryController::class, 'index']);
    Route::get('/show/{id}', [TaxCategoryController::class, 'show']);
    Route::post('/', [TaxCategoryController::class, 'store']);
    Route::post('/update/{id}', [TaxCategoryController::class, 'update']);
});

// ============================
// DISTRIBUTOR & USER AUTH
// ============================
Route::prefix('distributor')->group(function () {
    Route::post('check-status', [APIAuthController::class, 'checkUserStatus']);
    Route::post('send-otp', [APIAuthController::class, 'sendVerificationOtp']);
    Route::post('verify-phone-otp', [APIAuthController::class, 'verifyPhoneOtp']);
    Route::post('verify-email-otp', [APIAuthController::class, 'verifyEmailOtp']);
    Route::post('step1-personal', [APIAuthController::class, 'distributorStep1Personal']);
    Route::post('/check-distributor', [APIAuthController::class, 'checkDistributorAndGenerateSponsor']);
    Route::post('step2-sponsor', [APIAuthController::class, 'distributorStep2Sponsor']);
    Route::post('step3-aadhaar', [APIAuthController::class, 'distributorStep3Aadhaar']);
    Route::post('step4-pan', [APIAuthController::class, 'distributorStep4Pan']);
    Route::post('step5-bank', [APIAuthController::class, 'distributorStep5Bank']);
    Route::post('step6-location', [APIAuthController::class, 'distributorStep6Location']);
    Route::post('step7-submit', [APIAuthController::class, 'distributorStep7Submit']);
    Route::get('/step-data/{step}/{identifier}', [APIAuthController::class, 'getStepData']);
    Route::post('progress', [APIAuthController::class, 'getDistributorProgress']);
    Route::post('login', [APIAuthController::class, 'distributorLogin'])->middleware('throttle:distributor-login');
    Route::get('location-by-pincode', [APIAuthController::class, 'getLocationByPincode']);

    // Password reset
    Route::post('forgot-password', [APIAuthController::class, 'forgotPassword']);
    Route::post('verify-reset-otp', [APIAuthController::class, 'verifyResetOtp']);
    Route::post('reset-password', [APIAuthController::class, 'resetPassword']);
});

Route::prefix('user')->group(function () {
    // Public routes
    Route::post('send-otp', [APIAuthController::class, 'sendOtp']);
    Route::post('verify-otp', [APIAuthController::class, 'verifyOtp']);
    Route::post('confirm_registration', [APIAuthController::class, 'completeCustomerRegistration']);
    Route::post('resend-otp', [APIAuthController::class, 'resendOtp']);
    Route::post('login', [APIAuthController::class, 'login']);
    Route::post('verify-login-otp', [APIAuthController::class, 'verifyLoginOtp']);
    Route::post('refresh-token', [APIAuthController::class, 'refreshToken'])->name('refresh-token');

    // Protected routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('profile', [APIAuthController::class, 'profile'])->name('profile');
        Route::post('profile', [APIAuthController::class, 'updateProfile'])->name('update-profile');
        Route::delete('profile-picture', [APIAuthController::class, 'removeProfilePicture'])->name('remove-profile-picture');
        Route::post('change-password', [APIAuthController::class, 'changePassword'])->name('change-password');
        Route::get('/user/application-status', [APIAuthController::class, 'applicationStatus']);
        Route::post('logout', [APIAuthController::class, 'logout'])->name('logout');
    });
});

// ============================
// WISHLIST
// ============================
Route::prefix('wishlist')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [WishlistController::class, 'index']);
    Route::post('/add', [WishlistController::class, 'add']);
    Route::post('/remove', [WishlistController::class, 'remove']);
    Route::post('/toggle', [WishlistController::class, 'toggle']);
    Route::post('/move-to-cart', [WishlistController::class, 'moveToCart']);
});

// ============================
// CART
// ============================
Route::prefix('cart')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [CartController::class, 'index']);
    Route::get('/count', [CartController::class, 'count']);
    Route::post('/add', [CartController::class, 'add']);
    Route::post('/update/{itemId}', [CartController::class, 'update']);
    Route::delete('/remove/{itemId}', [CartController::class, 'remove']);
    Route::delete('/clear', [CartController::class, 'clear']);
    Route::post('/merge', [CartController::class, 'mergeCart'])->middleware('auth:sanctum');
});

// ============================
// ADDRESSES
// ============================
Route::middleware('auth:sanctum')->prefix('addresses')->group(function () {
    Route::get('/', [AddressController::class, 'index']);
    Route::post('/', [AddressController::class, 'store']);
    Route::post('/{id}', [AddressController::class, 'update']);
    Route::post('/{id}/default', [AddressController::class, 'setDefault']);
    Route::delete('/{id}', [AddressController::class, 'destroy']);
});

// ============================
// CHECKOUT & ORDERS
// ============================
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/checkout/summary', [CheckoutController::class, 'summary']);
    Route::post('/checkout/apply-coins', [CheckoutController::class, 'applyCoins']);
    Route::post('/checkout/place-order', [CheckoutController::class, 'placeOrder']);
    Route::get('/order/history', [OrderController::class, 'history']);
    Route::get('/order/{id}', [OrderController::class, 'show']);
    Route::post('/checkout/apply-coupon', [CheckoutController::class, 'applyCoupon']);
    Route::post('/checkout/apply-shipping', [CheckoutController::class, 'applyShipping']);
    Route::post('/checkout/remove-coupon', [CheckoutController::class, 'removeCoupon']);

    // Credit Notes (User)
    Route::get('/orders/{orderReference}/credit-notes', [CreditNoteController::class, 'getByOrder']);
    Route::get('/credit-notes/{id}', [CreditNoteController::class, 'userShow']);
    Route::get('/credit-notes/{id}/download-data', [CreditNoteController::class, 'downloadData']);

    // Order actions
    Route::get('/orders/confirmed/{order_reference}', [OrderController::class, 'getConfirmedOrder']);
    Route::get('/my-orders', [OrderController::class, 'getOrder']);
    Route::post('/orders/{orderReference}/cancel/{id}', [OrderController::class, 'requestCancellation']);
    Route::post('/orders/{orderReference}/withdrawCancel/{id}', [OrderController::class, 'withdrawCancel']);
});

Route::get('/orders/statuses', [OrderController::class, 'statuses']);

// ============================
// REVIEWS
// ============================
Route::get('/products/{product}/reviews', [ReviewController::class, 'index']);
Route::get('/products/{product}/reviews/average', [ReviewController::class, 'getAverageRating']);

Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::post('/admin/product-reviews/{id}/action', [ReviewController::class, 'updateReviewAction']);
    Route::get('/admin/product-reviews', [ReviewController::class, 'getAllReviews']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user/reviews/{product}', [ReviewController::class, 'showUserReview']);
    Route::post('/reviews', [ReviewController::class, 'store']);
    Route::put('/reviews/{review}', [ReviewController::class, 'update']);
    Route::delete('/reviews/{review}', [ReviewController::class, 'destroy']);
    Route::get('/user/reviews', [ReviewController::class, 'userIndex']);
});

// ============================
// WEBHOOKS
// ============================
Route::post('/webhook/razorpay', [RazorpayWebhookController::class, 'handle']);

// ============================
// SHIPPING METHODS
// ============================
Route::prefix('shipping-methods')->group(function () {
    Route::get('/', [ShippingMethodController::class, 'index']);
    Route::post('/', [ShippingMethodController::class, 'store']);
    Route::get('/{id}', [ShippingMethodController::class, 'show']);
    Route::post('/{id}', [ShippingMethodController::class, 'update']);
    Route::delete('/{id}', [ShippingMethodController::class, 'destroy']);
});

// ============================
// COUPONS
// ============================
Route::middleware('optional.auth:sanctum')->prefix('coupons')->group(function () {
    Route::get('/', [CouponController::class, 'index']);
    Route::post('/', [CouponController::class, 'store']);
    Route::get('/{id}', [CouponController::class, 'show']);
    Route::post('/{id}', [CouponController::class, 'update']);
    Route::delete('/{id}', [CouponController::class, 'destroy']);
});

// ============================
// USER DASHBOARD & LEDGER
// ============================
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user/dashboard', [UserDashboardController::class, 'dashboard']);
    Route::get('/distributor-stats', [UserDashboardController::class, 'getDistributorStats']);
    Route::get('/invoice/order/{orderId}/{lineId?}', [InvoiceController::class, 'getInvoiceByOrder']);
    Route::get('/distributor/ledger', [LedgerController::class, 'index']);
    Route::get('/distributor/ledger/summary', [LedgerController::class, 'summary']);
    Route::get('/distributor/ledger/tax-summary', [LedgerController::class, 'taxSummary']);
});

Route::get('/stats', [UserDashboardController::class, 'getStats']);

// ============================
// REELS
// ============================
Route::prefix('reels')->group(function () {
    Route::get('/', [ReelController::class, 'index']);
    Route::get('/{id}', [ReelController::class, 'show']);
    Route::get('/product/{productId}', [ReelController::class, 'getByProduct']);
});

Route::middleware(['auth:sanctum', 'admin'])->prefix('reels')->group(function () {
    Route::post('/', [ReelController::class, 'store']);
    Route::post('/{id}', [ReelController::class, 'update']);
    Route::delete('/{id}', [ReelController::class, 'destroy']);
});

// ============================
// HERITAGE SITES
// ============================
Route::prefix('heritage')->group(function () {
    Route::get('/', [HeritageSiteController::class, 'index']);
    Route::get('/{id}', [HeritageSiteController::class, 'show']);
});

Route::middleware(['auth:sanctum', 'admin'])->prefix('heritage')->group(function () {
    Route::post('/', [HeritageSiteController::class, 'store']);
    Route::put('/{id}', [HeritageSiteController::class, 'update']);
    Route::delete('/{id}', [HeritageSiteController::class, 'destroy']);
});

// ============================
// FOOTER
// ============================
Route::prefix('footer')->group(function () {
    Route::get('/', [FooterController::class, 'index']);
});

Route::middleware(['auth:sanctum', 'admin'])->prefix('footer')->group(function () {
    Route::post('/', [FooterController::class, 'store']);
    Route::post('/update', [FooterController::class, 'update']);
});

// ============================
// RETURNS
// ============================
Route::middleware('auth:sanctum')->group(function () {
    // User routes
    Route::get('/returns/eligibility', [ReturnController::class, 'eligibility']);
    Route::post('/returns/initiate', [ReturnController::class, 'initiate']);
    Route::get('/returns/my-returns', [ReturnController::class, 'myReturns']);
    Route::get('/returns/{id}', [ReturnController::class, 'show']);
    Route::post('/returns/{returnId}/cancel', [ReturnController::class, 'cancel']);

    // Admin routes
    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::post('/cooling-off/{returnId}/approve', [ReturnController::class, 'approveCoolingOff']);
        Route::post('/cooling-off/{returnId}/reject', [ReturnController::class, 'rejectCoolingOff']);
        Route::get('/returns', [ReturnController::class, 'adminIndex']);
        Route::get('/returns/{id}', [ReturnController::class, 'adminShow']);
        Route::post('/returns/{id}/approve', [ReturnController::class, 'adminApprove']);
        Route::post('/returns/{id}/reject', [ReturnController::class, 'adminReject']);
        Route::post('/returns/{id}/received', [ReturnController::class, 'adminMarkReceived']);
        Route::post('/returns/{id}/complete', [ReturnController::class, 'adminComplete']);
        Route::post('/returns/{id}/refund', [ReturnController::class, 'adminRefund']);
    });
});

// ============================
// GROWTH STEPS
// ============================
Route::prefix('growth-steps')->group(function () {
    Route::get('/', [GrowthStepController::class, 'index']);
    Route::get('/{id}', [GrowthStepController::class, 'show']);
});

Route::middleware(['auth:sanctum', 'admin'])->prefix('growth-steps')->group(function () {
    Route::post('/', [GrowthStepController::class, 'store']);
    Route::post('/{id}', [GrowthStepController::class, 'update']);
    Route::delete('/{id}', [GrowthStepController::class, 'destroy']);
});

// ============================
// NOTIFICATION SETTINGS
// ============================
Route::middleware('auth:sanctum')->prefix('notification-settings')->group(function () {
    Route::get('/', [NotificationSettingsController::class, 'index']);
    Route::put('/', [NotificationSettingsController::class, 'update']);
    Route::post('/toggle', [NotificationSettingsController::class, 'toggle']);
    Route::post('/activate-all', [NotificationSettingsController::class, 'activateAll']);
    Route::post('/deactivate-all', [NotificationSettingsController::class, 'deactivateAll']);
});

Route::middleware('auth:sanctum')->prefix('user-notifications')->group(function () {
    Route::get('/', [NotificationSettingsController::class, 'index']);
    Route::put('/', [NotificationSettingsController::class, 'update']);
    Route::post('/toggle', [NotificationSettingsController::class, 'toggle']);
    Route::post('/activate-all', [NotificationSettingsController::class, 'activateAll']);
    Route::post('/deactivate-all', [NotificationSettingsController::class, 'deactivateAll']);
});

// ============================
// BENEFICIARIES
// ============================
Route::middleware('auth:sanctum')->prefix('distributor/beneficiaries')->group(function () {
    Route::get('/', [BeneficiaryController::class, 'index']);
    Route::post('/', [BeneficiaryController::class, 'store']);
    Route::put('/{id}', [BeneficiaryController::class, 'update']);
    Route::delete('/{id}', [BeneficiaryController::class, 'destroy']);
    Route::post('/{id}/confirm', [BeneficiaryController::class, 'confirm']);
    Route::get('/summary', [BeneficiaryController::class, 'summary']);
});

// ============================
// GENEALOGY
// ============================
Route::middleware('auth:sanctum')->prefix('distributor/genealogy')->group(function () {
    Route::get('/tree', [GenealogyController::class, 'tree']);
    Route::get('/children/{userId}', [GenealogyController::class, 'children']);
    Route::get('/search', [GenealogyController::class, 'search']);
    Route::get('/downline', [GenealogyController::class, 'downlineList']);
});

// ============================
// NOTIFICATION TEMPLATES
// ============================
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/notification-templates', [NotificationTemplateController::class, 'index']);
    Route::post('/notification-templates', [NotificationTemplateController::class, 'store']);
    Route::get('/notification-templates/{id}', [NotificationTemplateController::class, 'show']);
    Route::post('/notification-templates/{id}', [NotificationTemplateController::class, 'update']);
    Route::delete('/notification-templates/{id}', [NotificationTemplateController::class, 'destroy']);
    Route::post('/notification-templates/{id}/activate', [NotificationTemplateController::class, 'activate']);
    Route::post('/notification-templates/{id}/preview', [NotificationTemplateController::class, 'preview']);
    Route::get('/notification-template/event-types', [NotificationTemplateController::class, 'eventTypes']);
    Route::get('/notification-template/channels', [NotificationTemplateController::class, 'channels']);
});

Route::get('/notification-templates/active/{eventType}/{channel}', [NotificationTemplateController::class, 'getActiveTemplate']);

// ============================
// USER NOTIFICATIONS
// ============================
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/notifications', [UserNotificationController::class, 'index']);
    Route::get('/notifications/unread', [UserNotificationController::class, 'unreadNotifications']);
    Route::post('/notifications/{id}/read', [UserNotificationController::class, 'markAsRead']);
    Route::post('/notifications/read-all', [UserNotificationController::class, 'markAllAsRead']);
    Route::delete('/notifications/delete-all', [UserNotificationController::class, 'deleteAll']);
    Route::delete('/notifications/{id}', [UserNotificationController::class, 'destroy']);
});

// ============================
// OUTBOUND API (EXTERNAL)
// ============================
Route::prefix('external')->middleware(['outbound.api'])->group(function () {
    Route::get('/products', [ProductController::class, 'externalIndex']);
    Route::get('/products/{identifier}', [ProductController::class, 'externalShow']);
    Route::get('/orders', [OrderController::class, 'externalIndex']);
    Route::get('/orders/{orderReference}', [OrderController::class, 'externalShow']);
});

// ============================
// DISTRIBUTOR COOLING-OFF
// ============================
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/orders/{orderReference}/cooling-off-eligibility', [CoolingOffController::class, 'eligibility']);
    Route::post('/orders/{orderReference}/cooling-off-withdraw', [CoolingOffController::class, 'withdraw']);
    Route::get('/cooling-off/eligibility', [CoolingOffController::class, 'distributorshipEligibility']);
    Route::post('/cooling-off/withdraw-distributorship', [CoolingOffController::class, 'withdrawDistributorship']);
    Route::get('/cooling-off/history', [CoolingOffController::class, 'history']);
});

// ============================
// BUYBACK (DISTRIBUTOR)
// ============================
Route::middleware('auth:sanctum')->prefix('distributor/buyback')->group(function () {
    Route::get('/eligible', [BuybackController::class, 'eligibleStock']);
    Route::post('/initiate', [BuybackController::class, 'initiate']);
    Route::get('/history', [BuybackController::class, 'history']);
    Route::get('/summary', [BuybackController::class, 'summary']);
});

// ============================
// ADMIN BUYBACK MANAGEMENT
// ============================
Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::prefix('buyback')->group(function () {
        Route::get('/requests', [AdminBuybackController::class, 'index']);
        Route::get('/requests/{id}', [AdminBuybackController::class, 'show']);
        Route::post('/requests/{id}/approve', [AdminBuybackController::class, 'approve']);
        Route::post('/requests/{id}/reject', [AdminBuybackController::class, 'reject']);
        Route::post('/requests/{id}/mark-received', [AdminBuybackController::class, 'markReceived']);
        Route::get('/summary', [AdminBuybackController::class, 'summary']);
    });
});

// ============================
// ADMIN NOTIFICATIONS
// ============================
Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/{id}', [NotificationController::class, 'show']);
    Route::put('notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::put('notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::delete('notifications/{id}', [NotificationController::class, 'destroy']);
    Route::delete('notifications', [NotificationController::class, 'destroyAll']);
});

// ============================
// ADMIN KYC MANAGEMENT
// ============================
Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/kyc/applications', [KycController::class, 'pendingApplications']);
    Route::get('/kyc/applications/{userId}', [KycController::class, 'show']);
    Route::post('/kyc/applications/{userId}/approve', [KycController::class, 'approve']);
    Route::post('/kyc/applications/{userId}/reject', [KycController::class, 'reject']);
    Route::post('/kyc/applications/{userId}/return', [KycController::class, 'returnForCorrection']);
});

// ============================
// ADMIN MANAGEMENT
// ============================
Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/get', [AdminController::class, 'index']);
    Route::get('/admins/{id}', [AdminController::class, 'show']);
    Route::post('/create', [AdminController::class, 'store']);
    Route::post('/update/{id}', [AdminController::class, 'update']);
    Route::delete('/delete/{id}', [AdminController::class, 'destroy']);

    // Roles
    Route::get('/roles', [RoleController::class, 'index']);
    Route::get('/roles/{id}', [RoleController::class, 'show']);
    Route::post('/roles', [RoleController::class, 'store']);
    Route::post('/roles/{id}', [RoleController::class, 'update']);
    Route::delete('/roles/{id}', [RoleController::class, 'destroy']);

    // Permissions
    Route::get('/permissions', [PermissionController::class, 'index']);
    Route::get('/permissions/modules', [PermissionController::class, 'getModules']);
    Route::post('/permissions', [PermissionController::class, 'store']);
    Route::post('/permissions/{id}', [PermissionController::class, 'update']);
    Route::delete('/permissions/{id}', [PermissionController::class, 'destroy']);
});

// ============================
// ATTRIBUTES
// ============================
Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('attributes', [AttributeController::class, 'index']);
    Route::post('attributes', [AttributeController::class, 'store']);
    Route::get('attributes/{id}', [AttributeController::class, 'show']);
    Route::put('attributes/{id}', [AttributeController::class, 'update']);
    Route::delete('attributes/{id}', [AttributeController::class, 'destroy']);
    Route::get('attributes/{attributeId}/values', [AttributeController::class, 'getValues']);
    Route::post('attributes/{attributeId}/values', [AttributeController::class, 'storeValue']);
    Route::put('attributes/{attributeId}/values/{valueId}', [AttributeController::class, 'updateValue']);
    Route::delete('attributes/{attributeId}/values/{valueId}', [AttributeController::class, 'destroyValue']);
    Route::post('attributes/{attributeId}/values/bulk', [AttributeController::class, 'bulkStoreValues']);
    Route::get('attributes-dropdown', [AttributeController::class, 'getForDropdown']);

    // Credit Notes (Admin)
    Route::get('/credit-notes', [CreditNoteController::class, 'index']);
    Route::get('/credit-notes/{id}', [CreditNoteController::class, 'show']);
    Route::get('/credit-notes/{id}/download-data', [CreditNoteController::class, 'downloadAdminData']);
    Route::get('/credit-notes/export', [CreditNoteController::class, 'export']);

    // Admin User Management
    Route::post('/users', [AdminUserController::class, 'store']);
});

// ============================
// ORDER STATUSES (ADMIN)
// ============================
Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/orders/statuses', [OrderController::class, 'orderstatuses']);
});

// ============================
// ORDER DISPATCH/SHIP/DELIVER (ADMIN)
// ============================
Route::middleware(['auth:sanctum', 'admin'])->prefix('orders')->group(function () {
    Route::post('/dispatch', [OrderController::class, 'dispatch']);
    Route::post('/ship', [OrderController::class, 'ship']);
    Route::post('/deliver', [OrderController::class, 'deliver']);
    Route::get('/{orderReference}/shipping-details', [OrderController::class, 'getShippingDetails']);
});

// ============================
// AUDIT LOG (ADMIN)
// ============================
Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/audit-log', [AuditLogController::class, 'index']);
    Route::get('/audit-log/export', [AuditLogController::class, 'export']);
});

// ============================
// BRANDS
// ============================
Route::prefix('brands')->group(function () {
    Route::get('/', [BrandController::class, 'index']);
    Route::get('/{id}', [BrandController::class, 'show']);
});

Route::middleware(['auth:sanctum', 'admin'])->prefix('brands')->group(function () {
    Route::post('/', [BrandController::class, 'store']);
    Route::post('/{id}', [BrandController::class, 'update']);
    Route::delete('/{id}', [BrandController::class, 'destroy']);
});

// ============================
// FAQS
// ============================
Route::prefix('faqs')->group(function () {
    Route::get('/', [FAQController::class, 'index']);
    Route::get('/{id}', [FAQController::class, 'show']);
});

Route::middleware(['auth:sanctum', 'admin'])->prefix('faqs')->group(function () {
    Route::post('/', [FAQController::class, 'store']);
    Route::post('/{id}', [FAQController::class, 'update']);
    Route::delete('/{id}', [FAQController::class, 'destroy']);
    Route::post('/bulk-delete', [FAQController::class, 'bulkDestroy']);
});

// FAQ Sections
Route::get('faq-sections/dropdown', [FaqSectionController::class, 'dropdown']);
Route::get('faq-sections', [FaqSectionController::class, 'index']);
Route::get('faq-sections/{id}', [FaqSectionController::class, 'show']);

Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::post('faq-sections', [FaqSectionController::class, 'store']);
    Route::post('faq-sections/{id}', [FaqSectionController::class, 'update']);
    Route::delete('faq-sections/{id}', [FaqSectionController::class, 'destroy']);
});

// ============================
// NOTIFY ME
// ============================
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/notify-me', [ProductController::class, 'notifyMe']);
});

// ============================
// SUBCATEGORIES
// ============================
Route::prefix('subcategories')->group(function () {
    Route::get('/', [SubcategoryController::class, 'index']);
    Route::get('/category/{categoryId}', [SubcategoryController::class, 'getByCategory']);
});

Route::middleware(['auth:sanctum', 'admin'])->prefix('subcategories')->group(function () {
    Route::post('/', [SubcategoryController::class, 'store']);
    Route::post('/{id}', [SubcategoryController::class, 'update']);
    Route::post('/{id}/toggle-status', [SubcategoryController::class, 'toggleStatus']);
});

// ============================
// TESTIMONIALS
// ============================
Route::prefix('testimonials')->group(function () {
    Route::get('/', [TestimonialController::class, 'index']);
});

Route::middleware(['auth:sanctum', 'admin'])->prefix('testimonials')->group(function () {
    Route::post('/', [TestimonialController::class, 'store']);
    Route::post('/{testimonial}', [TestimonialController::class, 'update']);
    Route::delete('/{testimonial}', [TestimonialController::class, 'destroy']);
    Route::patch('/{testimonial}/toggle-active', [TestimonialController::class, 'toggleActive']);
});

// ============================
// CANCELLATION REQUESTS (ADMIN)
// ============================
Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/cancellation-requests', [CancellationApprovalController::class, 'getPendingRequests']);
    Route::get('/cancellation-requests/{orderLineId}', [CancellationApprovalController::class, 'getRequestDetails']);
    Route::post('/cancellation-requests/{orderLineId}/approve', [CancellationApprovalController::class, 'approve']);
    Route::post('/cancellation-requests/{orderLineId}/reject', [CancellationApprovalController::class, 'reject']);
});

// ============================
// CATALOGUES
// ============================
Route::get('/catalogues', [CatalogueController::class, 'index']);

Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::post('/catalogues', [CatalogueController::class, 'store']);
    Route::post('/catalogues/{id}', [CatalogueController::class, 'replace']);
});

// ============================
// COMBO PRODUCTS
// ============================
Route::middleware('optional.auth:sanctum')->group(function () {
    Route::get('/combo/products', [ProductController::class, 'availableProducts']);
    Route::get('/combos', [ProductController::class, 'listCombos']);
});

Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::post('/combo/create', [ProductController::class, 'createCombo']);
    Route::delete('/combo/{parentComboId}', [ProductController::class, 'deleteCombo']);
});

// ============================
// ORDER LINES ACTIONS
// ============================
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::post('/order-lines/{orderLine}/undelivered', [OrderController::class, 'markUndelivered']);
    Route::post('/order-lines/{orderLine}/cancel-return', [AdminUserController::class, 'updateCancelReturnAllowed']);
});

// ============================
// CSV EXPORT
// ============================
Route::get('/csv-data', [ExportController::class, 'csvData'])->name('orders-full');

// ============================
// WAREHOUSES
// ============================
Route::prefix('warehouses')->name('warehouses.')->group(function () {
    Route::get('/', [WarehouseController::class, 'index'])->name('index');
    Route::post('/', [WarehouseController::class, 'store'])->name('store');
    Route::get('/{warehouse}', [WarehouseController::class, 'show'])->name('show');
    Route::post('/{warehouse}', [WarehouseController::class, 'update'])->name('update');
});

// ============================
// WAREHOUSE ASSIGNMENTS (ADMIN)
// ============================
Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    Route::prefix('warehouse-assignments')->group(function () {
        Route::get('/', [WarehouseAssignmentController::class, 'index']);
        Route::post('/', [WarehouseAssignmentController::class, 'store']);
        Route::get('/{id}', [WarehouseAssignmentController::class, 'show']);
        Route::post('/{id}', [WarehouseAssignmentController::class, 'update']);
        Route::delete('/{id}', [WarehouseAssignmentController::class, 'destroy']);
    });

    Route::get('warehouses/{id}/admins', [WarehouseAssignmentController::class, 'warehouseAdmins']);
    Route::get('admins/{id}/warehouses', [WarehouseAssignmentController::class, 'adminWarehouses']);
});

// ============================
// WAREHOUSE STOCKS
// ============================
Route::prefix('warehouse-stocks')->group(function () {
    Route::get('/{warehouseId}', [WarehouseStockController::class, 'index']);
    Route::post('/', [WarehouseStockController::class, 'store']);
    Route::post('/{id}', [WarehouseStockController::class, 'update']);
});

Route::post(
    '/warehouses/{warehouseId}/update-stock',
    [WarehouseStockController::class, 'updateStock']
);

Route::post('/upload-products/csv', [ProductController::class, 'upload']);
Route::get('/reports/sales', [ProductController::class, 'salesReport']);
