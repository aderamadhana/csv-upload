<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProcessCsvUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $path;
    protected int $backgroundId;

    public function __construct(string $path, int $backgroundId)
    {
        $this->path = $path;
        $this->backgroundId = $backgroundId;
    }

    public function handle(): void
    {
        // always defined
        $inserted = 0;

        try {
            // mark processing
            DB::table('csv_upload_background_process')
                ->where('id', $this->backgroundId)
                ->update([
                    'status'                => 'processing',
                    'processing_started_at' => now()->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z'),
                    'updated_at'            => now()->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z'),
                ]);

            if (!Storage::exists($this->path)) {
                throw new \RuntimeException("File not found: {$this->path}");
            }

            $raw = Storage::get($this->path);

            // remove BOM
            $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);

            // clean non-UTF-8
            $clean = iconv('UTF-8', 'UTF-8//IGNORE', $raw);
            if ($clean === false || trim($clean) === '') {
                throw new \RuntimeException('File kosong atau encoding tidak valid.');
            }

            $lines = preg_split("/\r\n|\n|\r/", trim($clean));
            if (!$lines || count($lines) < 2) {
                throw new \RuntimeException('CSV kosong atau header tidak ditemukan.');
            }

            $delimiter = str_contains($lines[0], ';') ? ';' : ',';

            // header
            $rawHeader = str_getcsv(array_shift($lines), $delimiter);
            if (!$rawHeader || !count($rawHeader)) {
                throw new \RuntimeException('Header CSV tidak bisa dibaca.');
            }

            $headers = [];
            foreach ($rawHeader as $i => $col) {
                if ($i === 0) {
                    $col = preg_replace('/^\xEF\xBB\xBF/', '', $col);
                }
                $headers[$i] = strtoupper(trim($col));
            }

            // rows
            foreach ($lines as $line) {
                if (trim($line) === '') {
                    continue;
                }

                $row = str_getcsv($line, $delimiter);
                if (!$row) {
                    continue;
                }

                if (count($row) < count($headers)) {
                    $row = array_pad($row, count($headers), null);
                } elseif (count($row) > count($headers)) {
                    $row = array_slice($row, 0, count($headers));
                }

                $data = @array_combine($headers, $row);
                if ($data === false || $data === null) {
                    continue;
                }

                $uniqueKey = $data['UNIQUE_KEY'] ?? null;
                if (!$uniqueKey || trim($uniqueKey) === '') {
                    continue;
                }

                DB::table('csv_upload')->updateOrInsert(
                    ['UNIQUE_KEY' => $uniqueKey],
                    [
                        'PRODUCT_TITLE'          => $data['PRODUCT_TITLE']          ?? null,
                        'PRODUCT_DESCRIPTION'    => $data['PRODUCT_DESCRIPTION']    ?? null,
                        'STYLE'                  => $data['STYLE#']                 ?? null,
                        'SANMAR_MAINFRAME_COLOR' => $data['SANMAR_MAINFRAME_COLOR'] ?? null,
                        'SIZE'                   => $data['SIZE']                   ?? null,
                        'COLOR_NAME'             => $data['COLOR_NAME']             ?? null,
                        'PIECE_PRICE'            => (isset($data['PIECE_PRICE']) && $data['PIECE_PRICE'] !== '')
                                                    ? (float) $data['PIECE_PRICE']
                                                    : null,
                    ]
                );

                $inserted++;
            }

            // duration (upload_started_at -> now)
            $record = DB::table('csv_upload_background_process')
                ->where('id', $this->backgroundId)
                ->first();

            $uploadStart = ($record && $record->upload_started_at)
                ? strtotime($record->upload_started_at)
                : null;

            $end = time();
            $duration = $uploadStart ? max($end - $uploadStart, 0) : null;

            DB::table('csv_upload_background_process')
                ->where('id', $this->backgroundId)
                ->update([
                    'status'                => 'completed',
                    'message'               => "Proses selesai, {$inserted} baris diproses.",
                    'finished_at'           => now()->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z'),
                    'updated_at'            => now()->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z'),
                    'last_duration_seconds' => $duration,
                ]);

        } catch (\Throwable $e) {
            // on error: mark failed + duration
            $record = DB::table('csv_upload_background_process')
                ->where('id', $this->backgroundId)
                ->first();

            $uploadStart = ($record && $record->upload_started_at)
                ? strtotime($record->upload_started_at)
                : null;

            $end = time();
            $duration = $uploadStart ? max($end - $uploadStart, 0) : null;

            DB::table('csv_upload_background_process')
                ->where('id', $this->backgroundId)
                ->update([
                    'status'                => 'failed',
                    'message'               => $e->getMessage(),
                    'finished_at'           => now()->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z'),
                    'updated_at'            => now()->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z'),
                    'last_duration_seconds' => $duration,
                ]);

            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        DB::table('csv_upload_background_process')
            ->where('id', $this->backgroundId)
            ->update([
                'status'      => 'failed',
                'message'     => $e->getMessage(),
                'finished_at' => now()->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z'),
                'updated_at'  => now()->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z'),
            ]);
    }
}
