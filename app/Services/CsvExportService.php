<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\StreamedResponse;

class CsvExportService
{
    /**
     * Grouped CSV: har model = ek block (multiple rows).
     * Headers per-block hote hain, isliye global headers nahi.
     */
    public function streamGrouped(
        string $filename,
        $query,
        callable $blockMapper,
        int $chunkSize = 200
    ): StreamedResponse {
        return response()->streamDownload(function () use ($query, $blockMapper, $chunkSize) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            $query->chunkById($chunkSize, function ($models) use ($out, $blockMapper) {
                foreach ($models as $model) {
                    $rows = $blockMapper($model);

                    foreach ($rows as $row) {
                        $row = array_map([$this, 'normalizeCell'], (array) $row);
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

    /**
     * Array / object / DateTime → safe scalar for fputcsv().
     * Ye "Array to string conversion" error rokta hai.
     */
    private function normalizeCell($value)
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (is_object($value)) {
            if ($value instanceof \DateTimeInterface) {
                return $value->format('Y-m-d H:i:s');
            }
            return method_exists($value, '__toString')
                ? (string) $value
                : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        return $value;
    }
}
