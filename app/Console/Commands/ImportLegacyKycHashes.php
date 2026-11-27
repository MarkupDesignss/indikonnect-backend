<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportLegacyKycHashes extends Command
{
    protected $signature = 'legacy:import-kyc-hashes
        {csv : Private path to Legacy_KYC_Hashes.csv}
        {mapping : Existing user ID mapping CSV}
        {--ba= : Limit to one BA ID}
        {--apply : Write missing hashes; default is dry run}';
    protected $description = 'Import original legacy KYC hashes into dedicated test database fields';

    private function rows(string $path, array $expected): \Generator
    {
        if (!is_readable($path)) throw new \RuntimeException('File unreadable');
        $fp = fopen($path, 'rb');
        if (!$fp) throw new \RuntimeException('File unavailable');
        try {
            $header = fgetcsv($fp, 0, ',', '"', '');
            if (!$header) throw new \RuntimeException('Header missing');
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            if ($header !== $expected) throw new \RuntimeException('Wrong headers');
            while (($r = fgetcsv($fp, 0, ',', '"', '')) !== false) {
                if ($r === [null]) continue;
                if (count($r) !== count($header)) throw new \RuntimeException('Wrong row width');
                yield array_combine($header, $r);
            }
        } finally { fclose($fp); }
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $db = DB::connection();
        $stage = 'database guard';
        try {
            if ($db->getDriverName() !== 'mysql' || $db->getDatabaseName() !== 'markcawm_indiekonnect_local'
                || $db->selectOne('SELECT DATABASE() AS db')->db !== 'markcawm_indiekonnect_local') {
                $this->error('Blocked: only markcawm_indiekonnect_local is permitted.');
                return self::FAILURE;
            }
            $stage = 'schema check';
            if (!$db->getSchemaBuilder()->hasColumns('distributor_profiles', ['aadhaar_hash','pan_hash'])) {
                $this->error('Required hash columns missing.'); return self::FAILURE;
            }
            $stage = 'mapping validation';
            $map = []; $targets = [];
            foreach ($this->rows($this->argument('mapping'), ['source_entity','source_id','target_table','target_id']) as $r) {
                if ($r['source_entity'] !== 'customer' || $r['target_table'] !== 'users') continue;
                $sid = $r['source_id']; $tid = $r['target_id'];
                if (!ctype_digit($sid) || !ctype_digit($tid) || isset($map[$sid]) || isset($targets[$tid])) throw new \RuntimeException('Invalid mapping');
                $map[$sid] = $tid; $targets[$tid] = true;
            }
            $stage = 'source validation';
            $source = []; $bas = [];
            foreach ($this->rows($this->argument('csv'), ['customer_id','ba_id','aadhaar_hash','pan_hash']) as $r) {
                $sid = $r['customer_id']; $ba = $r['ba_id'];
                if (!ctype_digit($sid) || $ba === '' || isset($source[$sid]) || isset($bas[$ba])) throw new \RuntimeException('Duplicate or invalid identity');
                foreach (['aadhaar_hash','pan_hash'] as $f) {
                    if ($r[$f] !== '' && !preg_match('/^[0-9a-fA-F]{64}$/D', $r[$f])) throw new \RuntimeException('Invalid hash');
                }
                $source[$sid] = $r; $bas[$ba] = true;
            }
            $stage = 'identity and conflict checks';
            $counts = array_fill_keys(['source_rows','matched','not_mapped','identity_conflict','profile_missing','hash_conflict','profiles_to_update','aadhaar_hash_to_update','pan_hash_to_update','aadhaar_hash_already_present','pan_hash_already_present','profiles_updated','aadhaar_hash_updated','pan_hash_updated'], 0);
            $plan = [];
            foreach ($source as $sid => $r) {
                if ($this->option('ba') && $r['ba_id'] !== $this->option('ba')) continue;
                $counts['source_rows']++;
                if (!isset($map[$sid])) { $counts['not_mapped']++; continue; }
                $u = $db->table('users')->where('id', $map[$sid])->first(['id','distributor_id','account_type']);
                if (!$u || $u->account_type !== 'distributor' || $u->distributor_id !== $r['ba_id']) { $counts['identity_conflict']++; continue; }
                $p = $db->table('distributor_profiles')->where('user_id',$u->id)->limit(2)->get(['id','user_id','aadhaar_hash','pan_hash']);
                if ($p->count() !== 1) { $counts['profile_missing']++; continue; }
                $p = $p->first(); $counts['matched']++;
                $changes = [];
                foreach (['aadhaar_hash','pan_hash'] as $f) {
                    if ($r[$f] === '') continue;
                    if ($p->$f === null) $changes[$f] = $r[$f];
                    elseif (hash_equals((string)$p->$f, $r[$f])) $counts[$f.'_already_present']++;
                    else $counts['hash_conflict']++;
                }
                if ($changes) {
                    $plan[] = ['id'=>$p->id,'user_id'=>$u->id,'fields'=>$changes];
                    $counts['profiles_to_update']++;
                    foreach ($changes as $f => $_) $counts[$f.'_to_update']++;
                }
            }
            $report = function () use (&$counts) {
                $rows = []; foreach ($counts as $k=>$v) $rows[] = [$k,$v];
                $this->table(['Metric','Count'],$rows);
            };
            if (!$counts['source_rows']) { $this->error('No matching source rows.'); return self::FAILURE; }
            if (!$apply) { $this->info('DRY RUN; no database writes'); $report(); return self::SUCCESS; }
            if ($counts['identity_conflict'] || $counts['profile_missing'] || $counts['hash_conflict']) {
                $report(); $this->error('Apply blocked by conflicts.'); return self::FAILURE;
            }
            if (!$this->confirm('Test DB backed up, application writes paused, hash fields hidden and API/exports reviewed?')) return self::FAILURE;
            $stage = 'database writes';
            $db->transaction(function () use ($db,$plan,&$counts) {
                foreach ($plan as $row) {
                    $q = $db->table('distributor_profiles')->where('id',$row['id'])->where('user_id',$row['user_id']);
                    foreach ($row['fields'] as $f=>$_) $q->whereNull($f);
                    if ($q->update($row['fields']) !== 1) throw new \RuntimeException('Concurrent change');
                    $counts['profiles_updated']++;
                    foreach ($row['fields'] as $f=>$_) $counts[$f.'_updated']++;
                }
            });
            $this->info('APPLY completed. Unmapped rows were skipped.'); $report(); return self::SUCCESS;
        } catch (\Throwable $e) {
            // Do not print SQL bindings, hashes, or source identities.
            $this->error('Import stopped at '.$stage.'. Review privately; no sensitive values printed. If writes started, verify database state before retrying.');
            return self::FAILURE;
        }
    }
}
