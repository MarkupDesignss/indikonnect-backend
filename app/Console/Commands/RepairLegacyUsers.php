<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\CarbonImmutable;

class RepairLegacyUsers extends Command
{
    protected $signature = 'legacy:repair-users
        {csv-directory : Private directory containing Customers_BAs.csv and Customer_Addresses.csv}
        {mapping : Path to indiekonnect_v2_id_mapping.csv}
        {--ba= : Limit to a single distributor BA ID}
        {--source-tz= : Confirmed timezone for source timestamps, e.g. Asia/Kolkata}
        {--bank : Include encrypted bank account repair after API security review}
        {--apply : Write changes; otherwise preview only}';
    protected $description = 'Repair missing legacy user/profile fields in the testing DB only';

    private function value($v): ?string
    {
        if ($v === null || is_bool($v)) return null;
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }
    private function yes($v): bool
    {
        return in_array(strtoupper(trim((string) $v)), ['1', 'TRUE', 'YES'], true);
    }
    private function missing($v): bool
    {
        return $v === null || $v === '';
    }
    private function fill(array &$updates, object $record, string $field, $value): void
    {
        if (!$this->missing($value) && $this->missing($record->{$field} ?? null)) {
            $updates[$field] = $value;
        }
    }
    private function timestamp($value, ?string $tz): ?string
    {
        if (!$tz || $value === null || $value === '') return null;
        try {
            // CSV export must preserve unambiguous ISO date and time.
            if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', (string) $value)) return null;
            $date = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', (string) $value, $tz);
            if (!$date || $date->format('Y-m-d H:i:s') !== $value) return null;
            // Target MySQL TIMESTAMP range, UTC session.
            if ($date->timestamp <= 0 || $date->timestamp > 2147483647) return null;
            return $date->utc()->format('Y-m-d H:i:s');
        } catch (\Throwable $e) { return null; }
    }
    private function rows(string $path, array $required): \Generator
    {
        if (!is_readable($path)) throw new \RuntimeException('CSV missing or unreadable');
        $fp = fopen($path, 'rb');
        if (!$fp) throw new \RuntimeException('CSV unavailable');
        try {
            $header = fgetcsv($fp, 0, ',', '"', '');
            if (!$header) throw new \RuntimeException('CSV header missing');
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            $header = array_map('trim', $header);
            if (count(array_unique($header)) !== count($header) || array_diff($required, $header)) {
                throw new \RuntimeException('Invalid CSV header');
            }
            while (($values = fgetcsv($fp, 0, ',', '"', '')) !== false) {
                if ($values === [null]) continue;
                if (count($values) !== count($header)) throw new \RuntimeException('Invalid CSV row width');
                yield array_combine($header, $values);
            }
        } finally { fclose($fp); }
    }
    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $tz = $this->option('source-tz');
        if ($tz && !in_array($tz, timezone_identifiers_list(), true) && $tz !== 'UTC') {
            $this->error('Invalid source timezone.'); return self::FAILURE;
        }
        if ($this->option('bank') && !$this->confirm('Bank CSV values checked against exact original source strings and API exposure reviewed?')) return self::FAILURE;
        $db = DB::connection();
        if ($db->getDriverName() !== 'mysql' || $db->getDatabaseName() !== 'markcawm_indiekonnect_local'
            || $db->selectOne('SELECT DATABASE() AS db')->db !== 'markcawm_indiekonnect_local') {
            $this->error('Blocked: wrong database. Only markcawm_indiekonnect_local is allowed.'); return self::FAILURE;
        }
        $db->statement("SET SESSION time_zone = '+00:00'");
        if ($apply && !$this->confirm('Testing DB backup verified, application writes paused, and API exposure reviewed?')) return self::FAILURE;
        $counts = ['source_rows'=>0,'matched'=>0,'not_mapped'=>0,'identity_conflict'=>0,'profile_missing'=>0,'registration_conflict'=>0,'bank_conflict'=>0,'users_to_update'=>0,'profiles_to_update'=>0,'users_updated'=>0,'profiles_updated'=>0];
        $fields = [];
        try {
            $fp = fopen($this->argument('mapping'), 'r');
            if (!$fp) throw new \RuntimeException('Mapping unavailable');
            $header = fgetcsv($fp, 0, ',', '"', '');
            if (!$header) throw new \RuntimeException('Empty mapping');
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            if ($header !== ['source_entity','source_id','target_table','target_id']) throw new \RuntimeException('Unexpected mapping headers');
            $map = []; $seen = [];
            while (($r = fgetcsv($fp, 0, ',', '"', '')) !== false) {
                if (count($r) !== 4 || $r[0] !== 'customer' || $r[2] !== 'users') continue;
                if (!ctype_digit($r[1]) || !ctype_digit($r[3]) || isset($map[$r[1]]) || isset($seen[$r[3]])) throw new \RuntimeException('Invalid/duplicate mapping');
                $map[$r[1]] = (int) $r[3]; $seen[$r[3]] = true;
            }
            fclose($fp);
            $directory = rtrim($this->argument('csv-directory'), '/\\');
            $customerPath = $directory.'/Customers_BAs.csv';
            $addressPath = $directory.'/Customer_Addresses.csv';
            $required = ['customer_id','ba_id','user_type','email','registration_status','ba_registration_date','bank_ifsc_decrypted','bank_name_at_bank','aadhaar_address_id'];
            if ($this->option('bank')) $required = array_merge($required, ['bank_account_validated_flag','bank_account_number_decrypted']);
            $addresses = [];
            foreach ($this->rows($addressPath, ['address_id','customer_id','city','state','pin_code']) as $a) {
                $key = $this->value($a['address_id'] ?? null);
                if ($key) {
                    if (isset($addresses[$key])) throw new \RuntimeException('Duplicate address ID');
                    $addresses[$key] = $a;
                }
            }
            // Validate customer row widths and required header before performing any writes.
            foreach ($this->rows($customerPath, $required) as $_) { }
            $sourceSeen = [];
            foreach ($this->rows($customerPath, $required) as $r) {
                $ba = $this->value($r['ba_id'] ?? null);
                if ($this->option('ba') && $ba !== $this->option('ba')) continue;
                $counts['source_rows']++;
                $sid = $this->value($r['customer_id'] ?? null);
                if (!$sid || !isset($map[$sid])) { $counts['not_mapped']++; continue; }
                if (isset($sourceSeen[$sid])) throw new \RuntimeException('Duplicate source ID');
                $sourceSeen[$sid] = true;
                $u = $db->table('users')->where('id', $map[$sid])->first();
                $type = match ($r['user_type'] ?? null) {'BA'=>'distributor','Customer'=>'customer',default=>null};
                $email = $this->value($r['email'] ?? null);
                if (!$u || !$type || $u->account_type !== $type || ($type === 'distributor' && (!$ba || $ba !== $u->distributor_id))
                    || ($type === 'customer' && (!$email || strcasecmp($email, (string) $u->email) !== 0))) {
                    $counts['identity_conflict']++; continue;
                }
                $counts['matched']++;
                $registered = ($r['registration_status'] ?? null) === 'REGISTERED';
                if ($registered !== (bool) $u->is_registered) { $counts['registration_conflict']++; continue; }
                $uu = []; $pp = [];
                if ($registered) $this->fill($uu, $u, 'registration_completed_at', $this->timestamp($r['ba_registration_date'] ?? null, $tz));
                // Do not infer active/approved/consent/registration step or overwrite existing dates.
                $profile = null;
                if ($type === 'distributor') {
                    $profiles = $db->table('distributor_profiles')->where('user_id', $u->id)->limit(2)->get();
                    if ($profiles->count() !== 1) { $counts['profile_missing']++; continue; }
                    $profile = $profiles->first();
                    $ifsc = strtoupper($this->value($r['bank_ifsc_decrypted'] ?? null) ?? '');
                    if (preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/D', $ifsc)) $this->fill($pp, $profile, 'bank_ifsc', $ifsc);
                    $name = $this->value($r['bank_name_at_bank'] ?? null);
                    if ($name && mb_strlen($name) <= 191) $this->fill($pp, $profile, 'bank_holder_name', $name);
                    // Use the exact linked Aadhaar address only, and check ownership.
                    $a = $addresses[$this->value($r['aadhaar_address_id'] ?? null) ?? ''] ?? null;
                    if ($a && $this->value($a['customer_id'] ?? null) === $sid) {
                        foreach (['city'=>'city','state'=>'state','pin_code'=>'pincode'] as $src=>$dest) {
                            $v = $this->value($a[$src] ?? null);
                            if ($dest === 'pincode' && $v && !preg_match('/^\d{6}$/D', $v)) continue;
                            if ($v && mb_strlen($v) <= ($dest === 'pincode' ? 10 : 255)) $this->fill($pp, $profile, $dest, $v);
                        }
                    }
                    if ($this->option('bank') && $this->yes($r['bank_account_validated_flag'] ?? null) && $profile->bank_verified) {
                        $raw = $r['bank_account_number_decrypted'] ?? null;
                        // Only use --bank if CSV values were verified against original text cells; CSV cannot prove source type.
                        if (is_string($raw) && preg_match('/^\d{6,34}$/D', trim($raw))) {
                            $raw = trim($raw); $last4 = substr($raw, -4);
                            $compatible = preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/D', $ifsc) && ($this->missing($u->account_last4) || $u->account_last4 === $last4)
                                && ($this->missing($profile->bank_ifsc) || $profile->bank_ifsc === $ifsc)
                                && ($this->missing($profile->complete_bank_account) || $profile->complete_bank_account === $raw);
                            if ($compatible && !$this->missing($profile->encrypted_bank_account)) {
                                try { $compatible = (string) decrypt($profile->encrypted_bank_account) === $raw; }
                                catch (\Throwable $e) { $compatible = false; }
                            }
                            if (!$compatible) { $counts['bank_conflict']++; }
                            else {
                                if ($this->missing($profile->encrypted_bank_account)) {
                                    $pp['encrypted_bank_account'] = $apply ? encrypt($raw) : '__PREVIEW__';
                                }
                                $this->fill($uu, $u, 'account_last4', $last4);
                            }
                        }
                    }
                }
                foreach (['users'=>$uu,'distributor_profiles'=>$pp] as $table=>$updates) {
                    foreach (array_keys($updates) as $field) $fields[$table.'.'.$field] = ($fields[$table.'.'.$field] ?? 0) + 1;
                }
                if ($uu) $counts['users_to_update']++;
                if ($pp) $counts['profiles_to_update']++;
                if ($apply) {
                    // MyISAM: no transaction rollback. Maintenance/backup required.
                    // Conditional updates protect against overwriting nonempty values.
                    foreach ([['users',$u,$uu,'users_updated'],['distributor_profiles',$profile,$pp,'profiles_updated']] as [$table,$record,$updates,$counter]) {
                        if (!$updates) continue;
                        $q = $db->table($table)->where('id', $record->id);
                        foreach (array_keys($updates) as $field) {
                            if ($field === 'registration_completed_at') $q->whereNull($field);
                            else $q->where(fn($sub) => $sub->whereNull($field)->orWhere($field, ''));
                        }
                        $updates['updated_at'] = gmdate('Y-m-d H:i:s');
                        $counts[$counter] += $q->update($updates);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Avoid leaking source rows or SQL bindings with personal information.
            $this->error('Repair stopped. Error type: '.get_class($e).'. Check configuration and inputs securely. Partial writes may exist if --apply was used.');
            $this->table(['Metric','Count'], collect($counts)->map(fn($n,$k)=>[$k,$n])->values()->all());
            return self::FAILURE;
        }
        $this->info($apply ? 'APPLY complete; review counts' : 'DRY RUN; no database writes');
        $this->table(['Metric','Count'], collect($counts)->map(fn($n,$k)=>[$k,$n])->values()->all());
        $this->table(['Field','Planned updates'], collect($fields)->map(fn($n,$k)=>[$k,$n])->values()->all());
        if (!$counts['source_rows']) { $this->error('No source rows matched the selected BA.'); return self::FAILURE; }
        return self::SUCCESS;
    }
}
