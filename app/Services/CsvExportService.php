<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CsvExportService
{
    /**
     * Ek query se single-row-per-model CSV.
     */
    public function stream(
        string $filename,
        array $headers,
        $query,
        callable $rowMapper,
        int $chunkSize = 1000
    ): StreamedResponse {
        return $this->streamMulti($filename, $headers, $query, function ($model) use ($rowMapper) {
            return [$rowMapper($model)];
        }, $chunkSize);
    }

    /**
     * Ek query se multi-row-per-model CSV (e.g. order + its lines).
     * $rowMapper must return an array of arrays (each inner array = one CSV row).
     */
    public function streamMulti(
        string $filename,
        array $headers,
        $query,
        callable $rowMapper,
        int $chunkSize = 500
    ): StreamedResponse {
        return response()->streamDownload(function () use ($headers, $query, $rowMapper, $chunkSize) {
            $out = fopen('php://output', 'w');

            // UTF-8 BOM (Excel friendly)
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, $headers);

            $query->chunkById($chunkSize, function ($models) use ($out, $rowMapper) {
                foreach ($models as $model) {
                    $rows = $rowMapper($model);

                    foreach ($rows as $row) {
                        fputcsv($out, $row);
                    }
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control'       => 'no-store, no-cache',
            'Pragma'              => 'no-cache',
        ]);
    }
}
