<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Carbon\Carbon;
use Throwable;

class ImportIndikonnectExcel extends Command
{
    protected $signature = 'indikonnect:import-excel {file}';

    protected $description = 'Import legacy customer and order Excel data';

    protected array $counts = [];
    protected array $errors = [];
    protected array $userIds = [];
    protected array $addressIds = [];
    protected array $orderIds = [];
    protected array $lineIds = [];

    public function handle()
    {
        $file = $this->argument('file');

        if (!file_exists($file)) {
            $this->error("Excel file not found: {$file}");
            return self::FAILURE;
        }

        if (!class_exists(IOFactory::class)) {
            $this->error('Run: composer require phpoffice/phpspreadsheet');
            return self::FAILURE;
        }

        try {
            $this->info('Reading Excel workbook...');

            $workbook = IOFactory::load($file);

            // Import order is important because of relationships.
            $this->importCustomers($workbook);
            $this->importAddresses($workbook);
            $this->importBeneficiaries($workbook);
            $this->importConsents($workbook);
            $this->importOrders($workbook);
            $this->importOrderItems($workbook);
            $this->importDeliveries($workbook);
            $this->importRefunds($workbook);

            $this->newLine();
            $this->info('Import process completed.');

            foreach ($this->counts as $table => $count) {
                $this->line("{$table}: {$count}");
            }

            if (!empty($this->errors)) {
                $this->warn('Some records could not be imported:');

                foreach (array_slice($this->errors, 0, 30) as $error) {
                    $this->line($error);
                }

                $this->warn('Total errors: '.count($this->errors));
            }

            return self::SUCCESS;

        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }

    private function rows($workbook, string $sheetName): array
    {
        if (!$workbook->sheetNameExists($sheetName)) {
            return [];
        }

        $sheet = $workbook->getSheetByName($sheetName);
        $data = $sheet->toArray(null, true, true, false);

        if (count($data) < 2) {
            return [];
        }

        $headers = array_map(
            fn ($value) => trim((string) $value),
            array_shift($data)
        );

        $rows = [];

        foreach ($data as $row) {
            $record = [];

            foreach ($headers as $index => $header) {
                if ($header !== '') {
                    $record[$header] = $row[$index] ?? null;
                }
            }

            if (count(array_filter($record, fn ($v) => $v !== null && $v !== ''))) {
                $rows[] = $record;
            }
        }

        return $rows;
    }

    private function value($row, string $key, $default = null)
    {
        $value = $row[$key] ?? $default;

        if ($value === '' || $value === null) {
            return $default;
        }

        return $value;
    }

    private function dateValue($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (is_numeric($value)) {
                return ExcelDate::excelToDateTimeObject((float) $value)
                    ->format('Y-m-d H:i:s');
            }

            return Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return null;
        }
    }

    private function insertRecord(string $table, array $data, string $idColumn = 'id'): void
    {
        if (!Schema::hasTable($table)) {
            throw new \RuntimeException("Table {$table} does not exist.");
        }

        $columns = Schema::getColumnListing($table);
        $data = array_intersect_key($data, array_flip($columns));

        if (empty($data)) {
            return;
        }

        if (isset($data[$idColumn])) {
            DB::table($table)->updateOrInsert(
                [$idColumn => $data[$idColumn]],
                $data
            );
        } else {
            DB::table($table)->insert($data);
        }

        $this->counts[$table] = ($this->counts[$table] ?? 0) + 1;
    }

    private function importCustomers($workbook): void
    {
        $this->info('Importing customers...');

        foreach ($this->rows($workbook, 'Customers_BAs') as $row) {
            try {
                $id = (int) $this->value($row, 'customer_id');

                if (!$id) {
                    continue;
                }

                $userType = strtolower((string) $this->value($row, 'user_type', 'customer'));

                $accountType = in_array($userType, ['ba', 'distributor'])
                    ? 'distributor'
                    : 'customer';

                $status = strtolower((string) $this->value($row, 'registration_status', 'pending'));

                $distributorStatus = in_array($status, [
                    'active', 'suspended', 'withdrawn', 'closed'
                ]) ? $status : 'pending';

                $createdAt = $this->dateValue(
                    $this->value($row, 'ba_registration_date')
                ) ?? now()->toDateTimeString();

                $this->insertRecord('users', [
                    'id' => $id,
                    'distributor_id' => $this->value($row, 'ba_id'),
                    'full_name' => $this->value($row, 'full_name'),
                    'email' => $this->value($row, 'email'),
                    'phone' => $this->value($row, 'contact_number'),
                    'password' => Hash::make(Str::random(40)),
                    'country' => 'India',
                    'date_of_birth' => $this->dateValue(
                        $this->value($row, 'dob')
                    ),
                    'account_type' => $accountType,
                    'phone_verified' => (int) $this->value($row, 'mobile_verified_flag', 0),
                    'email_verified_at' => (int) $this->value($row, 'email_verified_flag', 0)
                        ? $this->dateValue($this->value($row, 'email_verified_timestamp'))
                        : null,
                    'phone_verified_at' => (int) $this->value($row, 'mobile_verified_flag', 0)
                        ? $this->dateValue($this->value($row, 'mobile_verified_timestamp'))
                        : null,
                    'is_registered' => 1,
                    'is_active' => 1,
                    'sponsor_id' => $this->value($row, 'referral_code'),
                    'distributor_status' => $distributorStatus,
                    'created_at' => $createdAt,
                    'updated_at' => now(),
                ]);

                $this->userIds[$id] = $id;

            } catch (Throwable $e) {
                $customerId = $this->value($row, 'customer_id');

                $this->errors[] = "Customer {$customerId}: ".$e->getMessage();

                $this->warn("Failed Customer ID: {$customerId}");
                $this->warn($e->getMessage());
            }
        }
    }

    private function importAddresses($workbook): void
    {
        $this->info('Importing addresses...');

        foreach ($this->rows($workbook, 'Customer_Addresses') as $row) {
            try {
                $id = (int) $this->value($row, 'address_id');
                $userId = (int) $this->value($row, 'customer_id');

                if (!$id || !DB::table('users')->where('id', $userId)->exists()) {
                    continue;
                }

                $this->insertRecord('addresses', [
                    'id' => $id,
                    'user_id' => $userId,
                    'type' => 'Home',
                    'status' => 'active',
                    'recipient_name' => $this->value($row, 'contact_person', 'Customer'),
                    'contact_number' => $this->value($row, 'contact_phone_number', 'NA'),
                    'address_line_1' => $this->value($row, 'address_line1', 'NA'),
                    'address_line_2' => $this->value($row, 'address_line2'),
                    'city' => $this->value($row, 'city', 'NA'),
                    'state' => $this->value($row, 'state', 'NA'),
                    'postcode' => $this->value($row, 'pin_code', '000000'),
                    'country' => $this->value($row, 'country', 'India'),
                    'is_default' => (int) $this->value($row, 'default_delivery_address', 0),
                    'is_billing' => 1,
                    'is_delivery' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->addressIds[$userId][] = [
                    'id' => $id,
                    'is_default' => (int) $this->value($row, 'default_delivery_address', 0),
                ];

            } catch (Throwable $e) {
                $this->errors[] = "Address {$this->value($row, 'address_id')}: ".$e->getMessage();
            }
        }
    }

    private function importBeneficiaries($workbook): void
    {
        $this->info('Importing beneficiaries...');

        foreach ($this->rows($workbook, 'Beneficiaries') as $row) {
            try {
                $userId = (int) $this->value($row, 'customer_id');

                if (!DB::table('users')->where('id', $userId)->exists()) {
                    continue;
                }

                $this->insertRecord('beneficiaries', [
                    'id' => (int) $this->value($row, 'beneficiary_id'),
                    'user_id' => $userId,
                    'full_name' => $this->value($row, 'beneficiary_name', 'NA'),
                    'relationship' => $this->value($row, 'relationship', 'Other'),
                    'contact_number' => $this->value($row, 'mobile_number'),
                    'email' => $this->value($row, 'email_id'),
                    'created_at' => $this->dateValue($this->value($row, 'created_date')) ?? now(),
                    'updated_at' => now(),
                ]);

            } catch (Throwable $e) {
                $this->errors[] = "Beneficiary: ".$e->getMessage();
            }
        }
    }

    private function importConsents($workbook): void
    {
        $this->info('Importing consent records...');

        foreach ($this->rows($workbook, 'Consent_Records') as $row) {
            try {
                $userId = (int) $this->value($row, 'customer_id');

                if (!DB::table('users')->where('id', $userId)->exists()) {
                    continue;
                }

                $accepted = (int) $this->value($row, 'is_accepted', 0);

                $this->insertRecord('consent_records', [
                    'user_id' => $userId,
                    'consent_type' => $this->value($row, 'consent_type', 'legacy'),
                    'is_agreed' => $accepted,
                    'status' => $accepted ? 'active' : 'revoked',
                    'ip_address' => $this->value($row, 'ip_address'),
                    'agreed_at' => $this->dateValue($this->value($row, 'accepted_time')),
                    'created_at' => $this->dateValue($this->value($row, 'accepted_time')) ?? now(),
                    'updated_at' => now(),
                ]);

            } catch (Throwable $e) {
                $this->errors[] = "Consent: ".$e->getMessage();
            }
        }
    }

    private function getAddressId(int $userId): ?int
    {
        $addresses = $this->addressIds[$userId] ?? [];

        if (empty($addresses)) {
            return null;
        }

        foreach ($addresses as $address) {
            if ($address['is_default']) {
                return $address['id'];
            }
        }

        return $addresses[0]['id'];
    }

    private function importOrders($workbook): void
    {
        $this->info('Importing orders...');

        foreach ($this->rows($workbook, 'Orders') as $row) {
            try {
                $id = (int) $this->value($row, 'order_id');
                $userId = (int) $this->value($row, 'customer_id');
                $addressId = $this->getAddressId($userId);

                if (!$id || !$addressId) {
                    $this->errors[] = "Order {$id}: Customer/address not found.";
                    continue;
                }

                $sourceStatus = strtolower((string) $this->value($row, 'order_status', 'pending'));

                $allowedStatuses = [
                    'pending', 'confirmed', 'processing', 'dispatched',
                    'shipped', 'delivered', 'cancelled', 'refunded'
                ];

                $status = in_array($sourceStatus, $allowedStatuses)
                    ? $sourceStatus
                    : 'pending';

                $this->insertRecord('orders', [
                    'id' => $id,
                    'order_reference' => (string) $this->value($row, 'bill_id', $id),
                    'user_id' => $userId,
                    'billing_address_id' => $addressId,
                    'delivery_address_id' => $addressId,
                    'subtotal' => (float) $this->value($row, 'net_total', 0),
                    'total_gst' => max(
                        0,
                        (float) $this->value($row, 'gross_total', 0)
                        - (float) $this->value($row, 'net_total', 0)
                    ),
                    'total_payable' => (float) $this->value($row, 'gross_total', 0),
                    'shipping_charge' => (float) $this->value($row, 'shipping_charges', 0),
                    'cv' => (float) $this->value($row, 'cv', 0),
                    'status' => $status,
                    'created_at' => $this->dateValue($this->value($row, 'created_datetime')) ?? now(),
                    'updated_at' => now(),
                ]);

                $this->orderIds[$id] = $id;

            } catch (Throwable $e) {
                $this->errors[] = "Order {$this->value($row, 'order_id')}: ".$e->getMessage();
            }
        }
    }

    private function importOrderItems($workbook): void
    {
        $this->info('Importing order items...');

        foreach ($this->rows($workbook, 'Order_Items_Taxes') as $row) {
            try {
                $orderId = (int) $this->value($row, 'order_id');

                if (!DB::table('orders')->where('id', $orderId)->exists()) {
                    continue;
                }

                $sku = trim((string) $this->value($row, 'product_code_sku'));

                $variant = DB::table('product_variants')
                    ->where('sku', $sku)
                    ->first();

                $product = $variant
                    ? DB::table('products')->where('id', $variant->product_id)->first()
                    : DB::table('products')->where('product_code', $sku)->first();

                if (!$product) {
                    $this->errors[] = "Order item SKU not matched: {$sku}";
                    continue;
                }

                $quantity = max(1, (int) $this->value($row, 'quantity', 1));
                $unitPrice = (float) $this->value($row, 'item_price', 0);

                $this->insertRecord('order_lines', [
                    'id' => (int) $this->value($row, 'item_id'),
                    'order_id' => $orderId,
                    'product_id' => $product->id,
                    'variant_id' => $variant->id ?? null,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => $unitPrice * $quantity,
                    'shipping_charge' => (float) $this->value($row, 'shipping_charges', 0),
                    'commissionable_volume' => (float) $this->value($row, 'cv', 0),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->lineIds[(int) $this->value($row, 'item_id')] =
                    (int) $this->value($row, 'item_id');

            } catch (Throwable $e) {
                $this->errors[] = "Order item {$this->value($row, 'item_id')}: ".$e->getMessage();
            }
        }
    }

    private function importDeliveries($workbook): void
    {
        $this->info('Importing deliveries...');

        foreach ($this->rows($workbook, 'Deliveries') as $row) {
            try {
                $orderId = (int) $this->value($row, 'order_id');

                if (!DB::table('orders')->where('id', $orderId)->exists()) {
                    continue;
                }

                $status = strtolower((string) $this->value($row, 'shipment_status', 'pending'));

                if (!in_array($status, ['pending', 'shipped', 'dispatched', 'delivered'])) {
                    $status = 'pending';
                }

                $this->insertRecord('order_shipping_details', [
                    'id' => (int) $this->value($row, 'delivery_id'),
                    'order_id' => $orderId,
                    'courier_tracking_number' => $this->value($row, 'shipment_id'),
                    'courier_company' => $this->value($row, 'carrier_name'),
                    'courier_delivery_date' => $this->dateValue($this->value($row, 'shipment_date')),
                    'status' => $status,
                    'delivery_notes' => $this->value($row, 'delivery_address'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            } catch (Throwable $e) {
                $this->errors[] = "Delivery {$this->value($row, 'delivery_id')}: ".$e->getMessage();
            }
        }
    }

    private function importRefunds($workbook): void
    {
        $this->info('Importing refunds...');

        foreach ($this->rows($workbook, 'Refunds') as $row) {
            try {
                $orderId = (int) $this->value($row, 'original_order_id');

                if (!DB::table('orders')->where('id', $orderId)->exists()) {
                    $this->errors[] = "Refund: Original order {$orderId} not found.";
                    continue;
                }

                $sourceStatus = strtolower((string) $this->value($row, 'refund_status', 'initiated'));

                $status = match ($sourceStatus) {
                    'success', 'successful', 'completed' => 'completed',
                    'failed', 'failure' => 'failed',
                    default => 'initiated',
                };

                $this->insertRecord('refunds', [
                    'id' => (int) $this->value($row, 'refund_id'),
                    'order_id' => $orderId,
                    'amount' => (float) $this->value($row, 'refund_amount', 0),
                    'gateway_reference' => $this->value($row, 'refund_bill_id'),
                    'status' => $status,
                    'refund_method' => $this->value($row, 'refund_type'),
                    'notes' => $this->value($row, 'refund_reason'),
                    'created_at' => $this->dateValue($this->value($row, 'refund_datetime')) ?? now(),
                    'updated_at' => now(),
                ]);

            } catch (Throwable $e) {
                $this->errors[] = "Refund {$this->value($row, 'refund_id')}: ".$e->getMessage();
            }
        }
    }
}
