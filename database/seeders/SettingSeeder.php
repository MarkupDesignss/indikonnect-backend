<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Setting;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            // --- Legal & Compliance ---
            ['group' => 'legal', 'key' => 'cooling_off_days', 'value' => '30', 'data_type' => 'integer', 'description' => 'Cooling-off period in days for new distributors/purchases'],
            ['group' => 'legal', 'key' => 'return_window_days', 'value' => '30', 'data_type' => 'integer', 'description' => 'Return window in days after delivery'],
            ['group' => 'legal', 'key' => 'buyback_window_days', 'value' => '30', 'data_type' => 'integer', 'description' => 'Buy-back window for distributors leaving'],
            ['group' => 'legal', 'key' => 'buyback_deduction_percent', 'value' => '0', 'data_type' => 'integer', 'description' => 'Percentage deduction on buy-back settlement'],
            ['group' => 'legal', 'key' => 'location_retention_days', 'value' => '90', 'data_type' => 'integer', 'description' => 'How many days to keep GPS location data'],

            // --- Tax & Finance ---
            ['group' => 'tax', 'key' => 'tds_rate_percent', 'value' => '2.00', 'data_type' => 'string', 'description' => 'TDS rate on commission (in %)'],
            ['group' => 'tax', 'key' => 'tax_rounding_precision', 'value' => '2', 'data_type' => 'integer', 'description' => 'Decimal places for tax rounding'],
            ['group' => 'tax', 'key' => 'default_tax_category_id', 'value' => '1', 'data_type' => 'integer', 'description' => 'Default GST tax category ID'],

            // --- Auth & Security ---
            ['group' => 'auth', 'key' => 'otp_expiry_minutes', 'value' => '5', 'data_type' => 'integer', 'description' => 'OTP expiry time in minutes'],
            ['group' => 'auth', 'key' => 'otp_retry_limit', 'value' => '3', 'data_type' => 'integer', 'description' => 'Max failed OTP attempts before lock'],
            ['group' => 'auth', 'key' => 'account_lockout_minutes', 'value' => '15', 'data_type' => 'integer', 'description' => 'Lockout duration after too many failures'],
            ['group' => 'auth', 'key' => 'admin_session_timeout_minutes', 'value' => '30', 'data_type' => 'integer', 'description' => 'Admin session timeout'],
            ['group' => 'auth', 'key' => 'customer_session_timeout_minutes', 'value' => '120', 'data_type' => 'integer', 'description' => 'Customer/Distributor session timeout'],

            // --- Notification ---
            ['group' => 'notification', 'key' => 'support_email', 'value' => 'customercare@indiekonnect.com', 'data_type' => 'email', 'description' => 'Primary support email address'],
            ['group' => 'notification', 'key' => 'grievance_email', 'value' => 'grievance@indiekonnect.com', 'data_type' => 'email', 'description' => 'Grievance redressal email address'],
            ['group' => 'notification', 'key' => 'order_confirm_sms_enabled', 'value' => 'true', 'data_type' => 'boolean', 'description' => 'Send SMS on order confirmation?'],
            ['group' => 'notification', 'key' => 'payout_release_email_enabled', 'value' => 'true', 'data_type' => 'boolean', 'description' => 'Send email on payout release?'],
            ['group' => 'notification', 'key' => 'notification_retry_attempts', 'value' => '3', 'data_type' => 'integer', 'description' => 'Number of retries for failed notifications'],

            // --- Checkout ---
            ['group' => 'checkout', 'key' => 'default_payment_gateway', 'value' => 'razorpay', 'data_type' => 'string', 'description' => 'Default payment gateway'],
            ['group' => 'checkout', 'key' => 'invoice_prefix', 'value' => 'INV-', 'data_type' => 'string', 'description' => 'Invoice number prefix'],
            ['group' => 'checkout', 'key' => 'max_cart_items', 'value' => '50', 'data_type' => 'integer', 'description' => 'Maximum items per cart'],
            ['group' => 'checkout', 'key' => 'free_shipping_min_amount', 'value' => '500', 'data_type' => 'integer', 'description' => 'Minimum order amount for free shipping'],

            // --- Integration & API ---
            ['group' => 'integration', 'key' => 'commission_api_timeout_seconds', 'value' => '30', 'data_type' => 'integer', 'description' => 'Commission API timeout'],
            ['group' => 'integration', 'key' => 'commission_api_retry_backoff', 'value' => '60', 'data_type' => 'integer', 'description' => 'Retry backoff in seconds'],
            ['group' => 'integration', 'key' => 'max_api_retry_count', 'value' => '5', 'data_type' => 'integer', 'description' => 'Max retry attempts for Commission API'],
            ['group' => 'integration', 'key' => 'genealogy_cache_minutes', 'value' => '15', 'data_type' => 'integer', 'description' => 'Cache TTL for genealogy tree'],
            ['group' => 'integration', 'key' => 'outbound_api_rate_limit', 'value' => '1000', 'data_type' => 'integer', 'description' => 'Rate limit for external API calls per minute'],

            // --- Inventory ---
            ['group' => 'inventory', 'key' => 'global_low_stock_threshold', 'value' => '10', 'data_type' => 'integer', 'description' => 'Global low stock threshold'],

            // --- Maintenance ---
            ['group' => 'maintenance', 'key' => 'commission_reconciliation_cron', 'value' => '0 2 * * *', 'data_type' => 'string', 'description' => 'Cron schedule for reconciliation report'],
            ['group' => 'maintenance', 'key' => 'payout_period_close_day', 'value' => '25', 'data_type' => 'integer', 'description' => 'Day of month to close payout period'],
            ['group' => 'maintenance', 'key' => 'site_maintenance_mode', 'value' => 'false', 'data_type' => 'boolean', 'description' => 'Put site in maintenance mode', 'is_editable' => false],
        ];

        foreach ($defaults as $setting) {
            Setting::updateOrCreate(
                ['key' => $setting['key']],
                $setting
            );
        }
    }
}