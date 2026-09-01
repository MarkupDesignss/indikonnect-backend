<?php

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
use App\Http\Controllers\API\Webhook\RazorpayWebhookController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\PermissionController;

Route::get('/login', function () {
    return response()->json(['success' => false, 'message' => 'Authentication token is require to access this api.'], 401);
})->name('login');
Route::get('/admin/dashboard', [AdminController::class, 'dashboard']);
// Auth
Route::prefix('admin')->group(function () {
    // Public Routes
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/send-reset-otp', [AuthController::class, 'sendResetOtp']);
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
    Route::get('/registered-users', [AuthController::class, 'getRegisteredUsers']);
    Route::get('/registered-users/{id}', [AuthController::class, 'getUserDetails']);

    // Protected Routes for admin
    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        Route::post('/update-user-status/{id}', [AuthController::class, 'toggleUserStatus']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('distributors/{id}/status', [AuthController::class, 'updateStatus']);
        Route::post('/update', [AuthController::class, 'update']);

        //Payout Run routes
        Route::get('/payouts', [PayoutController::class, 'index']);
        Route::post('/payouts', [PayoutController::class, 'store']);
        Route::get('/payouts/{id}', [PayoutController::class, 'show']);
        Route::post('/payouts/{id}/release', [PayoutController::class, 'release']);
        Route::post('/payouts/entries/{entryId}/hold', [PayoutController::class, 'holdEntry']);
        Route::get('/payouts/{id}/export', [PayoutController::class, 'export']);

        Route::get('/all-orders', [OrderController::class, 'allOrder']);
        Route::get('/get-order-details/{id}', [OrderController::class, 'getOrderDetails']);

        // Payout Notifications
        Route::post('/payouts/{id}/notify', [PayoutController::class, 'sendNotifications']);

        // Commission Reconciliation Report
        Route::get('/reconciliation', [ReconciliationController::class, 'index']);
        Route::get('/reconciliation/export', [ReconciliationController::class, 'export']);
        Route::get('/reconciliation/summary', [ReconciliationController::class, 'summary']);
        Route::post('/reconciliation/events/{eventId}/replay', [ReconciliationController::class, 'replayEvent']);

        // Settings Management
        Route::get('/settings', [SettingController::class, 'index']);
        Route::post('/settings', [SettingController::class, 'store']);
        Route::put('/settings/{key}', [SettingController::class, 'update']);
        Route::delete('/settings/{key}', [SettingController::class, 'destroy']);
    });
});

// Header menu
Route::prefix('header')->group(function () {
    // Public routes
    Route::get('/', [MenuController::class, 'index']);
    // Protected routes for admin
    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        Route::post('/add', [MenuController::class, 'store']);
        Route::delete('/delete/{id}', [MenuController::class, 'destroy']);
        Route::post('/menu/{id}/status', [MenuController::class, 'toggleStatus']);
        Route::post('/update/{id}', [MenuController::class, 'update']);
    });
});

// Contents (Pages)
Route::prefix('contents')->group(function () {
    // Public routes (No middleware)
    Route::get('/', [ContentController::class, 'index']);
    Route::get('/{slug}', [ContentController::class, 'show']);
    // Protected routes for admin
    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        Route::get('/admins/all', [ContentController::class, 'adminindex']);
        Route::get('/admin/{slug}', [ContentController::class, 'adminindex']);
        Route::post('/add', [ContentController::class, 'store']);
        Route::post('/update/{id}', [ContentController::class, 'update']);
        Route::delete('/delete/{id}', [ContentController::class, 'destroy']);
    });
});

// Categories
Route::prefix('categories')->group(function () {
    // Public routes (No middleware)
    Route::get('/', [CategoryController::class, 'index']);
    Route::get('/{id}', [CategoryController::class, 'show']);
    // Protected routes for admin
    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        Route::post('/add', [CategoryController::class, 'store']);
        Route::post('/update/{id}', [CategoryController::class, 'update']);
        Route::delete('/delete/{id}', [CategoryController::class, 'destroy']);
        Route::post('/update/{id}/status', [CategoryController::class, 'updateStatus']);
        Route::post('/bulk-delete', [CategoryController::class, 'bulkDelete']);
        Route::delete('/delete-image/{id}', [CategoryController::class, 'deleteImage']);
    });
});

// Contact us
Route::prefix('contact')->group(function () {
    // Public route
    Route::post('/send-request', [ContactController::class, 'store']);

    // Protected routes for admin
    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        Route::get('/', [ContactController::class, 'index']);
        Route::get('/{id}', [ContactController::class, 'show']);
        Route::delete('/{id}', [ContactController::class, 'destroy']);
        Route::post('/bulk-delete', [ContactController::class, 'bulkDelete']);
    });
});

// Newsletters
Route::prefix('subscribers')->group(function () {
    // Public route
    Route::post('/', [SubscriberController::class, 'store']);
    // Protected routes for admin
    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        Route::get('/', [SubscriberController::class, 'index']);
        Route::delete('/{email}', [SubscriberController::class, 'destroy']);
    });
});

Route::prefix('products')->group(function () {
    // Public routes with optional authentication
    Route::middleware('optional.auth:sanctum')->group(function () {
        Route::get('/', [ProductController::class, 'index']);
        Route::get('/trending', [ProductController::class, 'trending']);
        Route::get('/slug/{slug}', [ProductController::class, 'showBySlug']);
        Route::get('/code/{code}', [ProductController::class, 'showByCode']);
        Route::get('/{product}', [ProductController::class, 'show']);
        Route::post('/update/{id}', [ProductController::class, 'update']);
        Route::delete('/images/{id}', [ProductController::class, 'deleteImages']);
        Route::get('/category/{categoryId}', [ProductController::class, 'productsByCategory']);
    });

    // Admin protected routes
    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        Route::post('/', [ProductController::class, 'store']);
        // Route::post('/', [ProductController::class, 'store'])
        //     ->middleware('permission:product.view');
        Route::put('/{product}', [ProductController::class, 'update']);
        Route::delete('/{product}', [ProductController::class, 'destroy']);
        Route::delete('/{product}/images', [ProductController::class, 'deleteImages']);
        Route::post('/{product}/stock', [ProductController::class, 'updateStock']);
        Route::post('/{product}/toggle-publish', [ProductController::class, 'togglePublish']);
    });
});

Route::post('/products-deal-of-the-day/{id}', [ProductController::class, 'markAsDealOfTheDay']);
Route::delete('/products-deal-of-the-day/{id}', [ProductController::class, 'removeDealOfTheDay']);
Route::get('/products-deal-of-the-day', [ProductController::class, 'getDealOfTheDayProducts']);
Route::get('/products-top-discounted', [ProductController::class, 'getTopDiscountedProducts']);
Route::get('/product-sections', [ProductController::class, 'getProductSections']);


Route::prefix('tax-categories')->group(function () {
    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        // Tax Category Routes
        Route::get('/', [TaxCategoryController::class, 'index']);
        Route::get('/show/{id}', [TaxCategoryController::class, 'show']);
        // Route::get('/get-all', [TaxCategoryController::class, 'all']);
        // Route::get('/stats', [TaxCategoryController::class, 'stats']);
        Route::post('/', [TaxCategoryController::class, 'store']);
        Route::post('/update/{id}', [TaxCategoryController::class, 'update']);
        // Route::delete('/delete/{id}', [TaxCategoryController::class, 'destroy']);
        // Route::post('/bulk-delete', [TaxCategoryController::class, 'bulkDelete']);
    });
});

// Distributer and user
Route::prefix('distributor')->group(function () {
    Route::post('check-status', [APIAuthController::class, 'checkUserStatus']);

    // OTP endpoints (separate for phone and email)
    Route::post('send-otp', [APIAuthController::class, 'sendVerificationOtp']);
    Route::post('verify-phone-otp', [APIAuthController::class, 'verifyPhoneOtp']);
    Route::post('verify-email-otp', [APIAuthController::class, 'verifyEmailOtp']);
    Route::post('step1-personal', [APIAuthController::class, 'distributorStep1Personal']);
    Route::post('step2-sponsor', [APIAuthController::class, 'distributorStep2Sponsor']);
    Route::post('step3-aadhaar', [APIAuthController::class, 'distributorStep3Aadhaar']);
    Route::post('step4-pan', [APIAuthController::class, 'distributorStep4Pan']);
    Route::post('step5-bank', [APIAuthController::class, 'distributorStep5Bank']);
    Route::post('step6-location', [APIAuthController::class, 'distributorStep6Location']);
    Route::post('step7-submit', [APIAuthController::class, 'distributorStep7Submit']);
    // Route::post('step-data', [APIAuthController::class, 'getStepData']);
    Route::get('/step-data/{step}/{identifier}', [APIAuthController::class, 'getStepData']);
    Route::post('progress', [APIAuthController::class, 'getDistributorProgress']);
    Route::post('login', [APIAuthController::class, 'distributorLogin']);
});

Route::group(['prefix' => 'distributor'], function () {
    Route::post('forgot-password', [APIAuthController::class, 'forgotPassword']);
    Route::post('verify-reset-otp', [APIAuthController::class, 'verifyResetOtp']);
    Route::post('reset-password', [APIAuthController::class, 'resetPassword']);
});

Route::group(['prefix' => 'user'], function () {
    // Public routes (no authentication required)
    Route::post('send-otp', [APIAuthController::class, 'sendOtp']);
    Route::post('verify-otp', [APIAuthController::class, 'verifyOtp']);
    Route::post('confirm_registration', [APIAuthController::class, 'completeCustomerRegistration']);
    Route::post('resend-otp', [APIAuthController::class, 'resendOtp']);

    Route::post('login', [APIAuthController::class, 'login']);
    Route::post('verify-login-otp', [APIAuthController::class, 'verifyLoginOtp']);

    // Refresh token route
    Route::post('refresh-token', [APIAuthController::class, 'refreshToken'])->name('refresh-token');

    // Protected routes
    Route::group(['middleware' => 'auth:sanctum'], function () {
        Route::get('profile', [APIAuthController::class, 'profile'])->name('profile');
        Route::post('profile', [APIAuthController::class, 'updateProfile'])->name('update-profile');
        Route::delete('profile-picture', [APIAuthController::class, 'removeProfilePicture'])->name('remove-profile-picture');
        Route::post('change-password', [APIAuthController::class, 'changePassword'])->name('change-password');
        Route::get('/user/application-status', [APIAuthController::class, 'applicationStatus']);
        Route::post('logout', [APIAuthController::class, 'logout'])->name('logout');
    });
});


Route::prefix('wishlist')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [WishlistController::class, 'index']);
    Route::post('/add', [WishlistController::class, 'add']);
    Route::post('/remove', [WishlistController::class, 'remove']);
    Route::post('/toggle', [WishlistController::class, 'toggle']);
    Route::post('/move-to-cart', [WishlistController::class, 'moveToCart']);
});

Route::prefix('cart')->group(function () {
    // Public routes (for both guest and authenticated users)
    Route::get('/', [CartController::class, 'index']);
    Route::get('/count', [CartController::class, 'count']);
    Route::post('/add', [CartController::class, 'add']);
    Route::post('/update/{itemId}', [CartController::class, 'update']);
    Route::delete('/remove/{itemId}', [CartController::class, 'remove']);
    Route::delete('/clear', [CartController::class, 'clear']);

    // Merge guest cart with user cart (after login)
    Route::post('/merge', [CartController::class, 'mergeCart'])->middleware('auth:sanctum');
});
Route::middleware('auth:sanctum')->group(function () {
    // Address Management
    Route::prefix('addresses')->group(function () {
        Route::get('/', [AddressController::class, 'index']);
        Route::post('/', [AddressController::class, 'store']);
        // Route::get('/default', [AddressController::class, 'getDefault']);
        // Route::get('/billing', [AddressController::class, 'getBillingAddresses']);
        // Route::get('/delivery', [AddressController::class, 'getDeliveryAddresses']);
        Route::post('/{id}', [AddressController::class, 'update']);
        Route::post('/{id}/default', [AddressController::class, 'setDefault']);
        Route::delete('/{id}', [AddressController::class, 'destroy']);
    });
});


Route::middleware('auth:sanctum')->group(function () {
    Route::get('/checkout/summary', [CheckoutController::class, 'summary']);
    Route::post('/checkout/apply-coins', [CheckoutController::class, 'applyCoins']);
    Route::post('/checkout/place-order', [CheckoutController::class, 'placeOrder']);
    Route::get('/order/history', [OrderController::class, 'history']);
    Route::get('/order/{id}', [OrderController::class, 'show']);
    Route::post('/checkout/apply-coupon', [CheckoutController::class, 'applyCoupon']);
    Route::post('/checkout/apply-shipping', [CheckoutController::class, 'applyShipping']);
    Route::post('/checkout/remove-coupon', [CheckoutController::class, 'removeCoupon']);
});

// Public routes
Route::get('/products/{product}/reviews', [ReviewController::class, 'index']);
Route::get('/products/{product}/reviews/average', [ReviewController::class, 'getAverageRating']);
Route::post('/admin/product-reviews/{id}/action', [ReviewController::class, 'updateReviewAction']);
Route::get('/admin/product-reviews', [ReviewController::class, 'getAllReviews']);

// Authenticated user routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user/reviews/{product}', [ReviewController::class, 'showUserReview']);
    Route::post('/reviews', [ReviewController::class, 'store']);
    Route::put('/reviews/{review}', [ReviewController::class, 'update']);
    Route::delete('/reviews/{review}', [ReviewController::class, 'destroy']);

    // Get user's all reviews
    Route::get('/user/reviews', [ReviewController::class, 'userIndex']);
});
Route::post('/webhook/razorpay', [RazorpayWebhookController::class, 'handle']);
Route::get('/product-link/{id}', [ProductController::class, 'generateProductLink']);
Route::get('/product/{slug}', [ProductController::class, 'getProductBySlug']);


Route::prefix('shipping-methods')->group(function () {
    Route::get('/', [ShippingMethodController::class, 'index']);
    Route::post('/', [ShippingMethodController::class, 'store']);
    Route::get('/{id}', [ShippingMethodController::class, 'show']);
    Route::post('/{id}', [ShippingMethodController::class, 'update']);
    Route::delete('/{id}', [ShippingMethodController::class, 'destroy']);
});


Route::prefix('coupons')->group(function () {
    Route::get('/', [CouponController::class, 'index']);
    Route::post('/', [CouponController::class, 'store']);
    Route::get('/{id}', [CouponController::class, 'show']);
    Route::post('/{id}', [CouponController::class, 'update']);
    Route::delete('/{id}', [CouponController::class, 'destroy']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/orders/confirmed/{order_reference}', [OrderController::class, 'getConfirmedOrder']);
    // Or use a more generic route
    Route::get('/my-orders', [OrderController::class, 'getOrder']);
    Route::post('/orders/{orderReference}/cancel', [OrderController::class, 'cancel']);
});

Route::get('/orders/statuses', [OrderController::class, 'statuses']);
Route::get('admin/orders/statuses', [OrderController::class, 'orderstatuses']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user/dashboard', [UserDashboardController::class, 'dashboard']);
    Route::get('/distributor-stats', [UserDashboardController::class, 'getDistributorStats']);
    Route::get(
        '/invoice/order/{orderId}/{lineId?}',
        [InvoiceController::class, 'getInvoiceByOrder']
    );
    // Distributor Ledger & Tax SummaFry
    Route::get('/distributor/ledger', [LedgerController::class, 'index']);
    Route::get('/distributor/ledger/summary', [LedgerController::class, 'summary']);
    Route::get('/distributor/ledger/tax-summary', [LedgerController::class, 'taxSummary']);
});

Route::prefix('reels')->group(function () {
    Route::get('/', [ReelController::class, 'index']);
    Route::post('/', [ReelController::class, 'store']);
    Route::get('/{id}', [ReelController::class, 'show']);
    Route::post('/{id}', [ReelController::class, 'update']);
    Route::delete('/{id}', [ReelController::class, 'destroy']);
    Route::get('/product/{productId}', [ReelController::class, 'getByProduct']);
});

// Heritage Sites Routes
Route::prefix('heritage')->group(function () {
    // Get all sites with optional filters
    Route::get('/', [HeritageSiteController::class, 'index']);
    Route::get('/{id}', [HeritageSiteController::class, 'show']);
    Route::post('/', [HeritageSiteController::class, 'store']);
    Route::put('/{id}', [HeritageSiteController::class, 'update']);
    Route::delete('/{id}', [HeritageSiteController::class, 'destroy']);
});

Route::prefix('footer')->group(function () {
    Route::get('/', [FooterController::class, 'index']);
    Route::post('/', [FooterController::class, 'store']);
    Route::put('/update', [FooterController::class, 'update']);
});

Route::middleware('auth:sanctum')->group(function () {
    // User routes
    Route::get('/returns/eligibility', [ReturnController::class, 'eligibility']);
    Route::post('/returns/initiate', [ReturnController::class, 'initiate']);
    Route::get('/returns/my-returns', [ReturnController::class, 'myReturns']);
    Route::get('/returns/{id}', [ReturnController::class, 'show']);

    // Admin routes
    Route::middleware('admin')->prefix('admin')->group(function () {

        // ========== COOLING-OFF ADMIN ROUTES ==========
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
Route::get('/stats', [UserDashboardController::class, 'getStats']);

Route::prefix('growth-steps')->group(function () {
    Route::get('/', [GrowthStepController::class, 'index']);
    Route::post('/', [GrowthStepController::class, 'store']);
    Route::get('/{id}', [GrowthStepController::class, 'show']);
    Route::post('/{id}', [GrowthStepController::class, 'update']);
    Route::delete('/{id}', [GrowthStepController::class, 'destroy']);
});

Route::middleware('auth:sanctum')->prefix('notification-settings')->group(function () {
    // Get notification settings
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

Route::middleware('auth:sanctum')->group(function () {
    // Beneficiary Management
    Route::prefix('distributor/beneficiaries')->group(function () {
        Route::get('/', [BeneficiaryController::class, 'index']);
        Route::post('/', [BeneficiaryController::class, 'store']);
        Route::put('/{id}', [BeneficiaryController::class, 'update']);
        Route::delete('/{id}', [BeneficiaryController::class, 'destroy']);
        Route::post('/{id}/confirm', [BeneficiaryController::class, 'confirm']);
        Route::get('/summary', [BeneficiaryController::class, 'summary']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    // Genealogy Tree
    Route::prefix('distributor/genealogy')->group(function () {
        Route::get('/tree', [GenealogyController::class, 'tree']);
        Route::get('/children/{userId}', [GenealogyController::class, 'children']);
        Route::get('/search', [GenealogyController::class, 'search']);
        Route::get('/downline', [GenealogyController::class, 'downlineList']);
    });
});

// Admin routes with authentication
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    // CRUD routes
    Route::get('/notification-templates', [NotificationTemplateController::class, 'index']);
    Route::post('/notification-templates', [NotificationTemplateController::class, 'store']);
    Route::get('/notification-templates/{id}', [NotificationTemplateController::class, 'show']);
    Route::post('/notification-templates/{id}', [NotificationTemplateController::class, 'update']);
    Route::delete('/notification-templates/{id}', [NotificationTemplateController::class, 'destroy']);

    // Additional routes
    Route::post('/notification-templates/{id}/activate', [NotificationTemplateController::class, 'activate']);
    Route::post('/notification-templates/{id}/preview', [NotificationTemplateController::class, 'preview']);
    Route::get('/notification-template/event-types', [NotificationTemplateController::class, 'eventTypes']);
    Route::get('/notification-template/channels', [NotificationTemplateController::class, 'channels']);
});

// Public route for getting active template
Route::get('/notification-templates/active/{eventType}/{channel}', [NotificationTemplateController::class, 'getActiveTemplate']);

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/notifications', [UserNotificationController::class, 'index']);
    Route::get('/notifications/unread', [UserNotificationController::class, 'unreadNotifications']);
    Route::post('/notifications/{id}/read', [UserNotificationController::class, 'markAsRead']);
    Route::post('/notifications/read-all', [UserNotificationController::class, 'markAllAsRead']);
    Route::delete('/notifications/delete-all', [UserNotificationController::class, 'deleteAll']);
    Route::delete('/notifications/{id}', [UserNotificationController::class, 'destroy']);
});

// ============================
// OUTBOUND API
// ============================
Route::prefix('external')->middleware(['outbound.api'])->group(function () {
    // Products
    Route::get('/products', [ProductController::class, 'externalIndex']);
    Route::get('/products/{identifier}', [ProductController::class, 'externalShow']);

    // Orders
    Route::get('/orders', [OrderController::class, 'externalIndex']);
    Route::get('/orders/{orderReference}', [OrderController::class, 'externalShow']);
});

// Cooling-Off
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/orders/{orderReference}/cooling-off-eligibility', [CoolingOffController::class, 'eligibility']);
    Route::post('/orders/{orderReference}/cooling-off-withdraw', [CoolingOffController::class, 'withdraw']);
    Route::get('/cooling-off/history', [CoolingOffController::class, 'history']);
});

// Buyback
Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('distributor/buyback')->group(function () {
        Route::get('/eligible', [BuybackController::class, 'eligibleStock']);
        Route::post('/initiate', [BuybackController::class, 'initiate']);
        Route::get('/history', [BuybackController::class, 'history']);
        Route::get('/summary', [BuybackController::class, 'summary']);
    });
});

Route::prefix('admin')->middleware('auth:sanctum')->group(function () {
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/{id}', [NotificationController::class, 'show']);
    Route::put('notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::put('notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::delete('notifications/{id}', [NotificationController::class, 'destroy']);
    Route::delete('notifications', [NotificationController::class, 'destroyAll']);
});

Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function () {
    // ========== KYC Application Management ==========
    Route::get('/kyc/applications', [KycController::class, 'pendingApplications']);
    Route::get('/kyc/applications/{userId}', [KycController::class, 'show']);
    Route::post('/kyc/applications/{userId}/approve', [KycController::class, 'approve']);
    Route::post('/kyc/applications/{userId}/reject', [KycController::class, 'reject']);
    Route::post('/kyc/applications/{userId}/return', [KycController::class, 'returnForCorrection']);
});

Route::prefix('admin')->group(function () {
    Route::middleware(['auth:sanctum'])->group(function () {

        // Admin Management
        Route::get('/get', [AdminController::class, 'index']);
        Route::get('/admins/{id}', [AdminController::class, 'show']);
        Route::post('/create', [AdminController::class, 'store']);
        Route::post('/update/{id}', [AdminController::class, 'update']);
        Route::delete('/delete/{id}', [AdminController::class, 'destroy']);

        // Role Management
        Route::get('/roles', [RoleController::class, 'index']);
        Route::get('/roles/{id}', [RoleController::class, 'show']);
        Route::post('/roles', [RoleController::class, 'store']);
        Route::post('/roles/{id}', [RoleController::class, 'update']);
        Route::delete('/roles/{id}', [RoleController::class, 'destroy']);

        // Permission Management
        Route::get('/permissions', [PermissionController::class, 'index']);
        Route::get('/permissions/modules', [PermissionController::class, 'getModules']);
        Route::post('/permissions', [PermissionController::class, 'store']);
        Route::post('/permissions/{id}', [PermissionController::class, 'update']);
        Route::delete('/permissions/{id}', [PermissionController::class, 'destroy']);
    });
});

Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function () {

    // Individual routes (NO resource)
    Route::get('attributes', [AttributeController::class, 'index']);
    Route::post('attributes', [AttributeController::class, 'store']);
    Route::get('attributes/{id}', [AttributeController::class, 'show']);
    Route::put('attributes/{id}', [AttributeController::class, 'update']);
    Route::delete('attributes/{id}', [AttributeController::class, 'destroy']);

    // Custom routes for values
    Route::get('attributes/{attributeId}/values', [AttributeController::class, 'getValues']);
    Route::post('attributes/{attributeId}/values', [AttributeController::class, 'storeValue']);
    Route::put('attributes/{attributeId}/values/{valueId}', [AttributeController::class, 'updateValue']);
    Route::delete('attributes/{attributeId}/values/{valueId}', [AttributeController::class, 'destroyValue']);
    Route::post('attributes/{attributeId}/values/bulk', [AttributeController::class, 'bulkStoreValues']);

    // Helper routes
    Route::get('attributes-dropdown', [AttributeController::class, 'getForDropdown']);
});

Route::get('admin/orders/statuses', [OrderController::class, 'orderstatuses']);

Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::prefix('orders')->group(function () {
        Route::post('/dispatch', [OrderController::class, 'dispatch']);
        Route::post('/ship', [OrderController::class, 'ship']);
        Route::post('/deliver', [OrderController::class, 'deliver']);
        Route::get('/{orderReference}/shipping-details', [OrderController::class, 'getShippingDetails']);
    });
});

Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function () {
    // Audit Log
    Route::get('/audit-log', [AuditLogController::class, 'index']);
    Route::get('/audit-log/export', [AuditLogController::class, 'export']);
});

Route::post('/stock/update', [ProductController::class, 'updateStock']);
