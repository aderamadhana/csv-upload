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
        try {
            // tandai mulai processing
            DB::table('csv_upload_background_process')
                ->where('id', $this->backgroundId)
                ->update([
                    'status'                => 'processing',
                    'processing_started_at' => now(),
                    'updated_at'            => now(),
                ]);

            // ... [isi proses upload CSV sama seperti sebelumnya]

            // hitung durasi upload total (upload + processing)
            $record = DB::table('csv_upload_background_process')->find($this->backgroundId);
            $uploadStart = $record?->upload_started_at ? strtotime($record->upload_started_at) : null;
            $end = time();
            $duration = $uploadStart ? max($end - $uploadStart, 0) : null;

            DB::table('csv_upload_background_process')
                ->where('id', $this->backgroundId)
                ->update([
                    'status'               => 'completed',
                    'message'              => "Proses selesai, {$inserted} baris diproses.",
                    'finished_at'          => now(),
                    'updated_at'           => now(),
                    'last_duration_seconds'=> $duration,
                ]);

        } catch (\Throwable $e) {
            $record = DB::table('csv_upload_background_process')->find($this->backgroundId);
            $uploadStart = $record?->upload_started_at ? strtotime($record->upload_started_at) : null;
            $end = time();
            $duration = $uploadStart ? max($end - $uploadStart, 0) : null;

            DB::table('csv_upload_background_process')
                ->where('id', $this->backgroundId)
                ->update([
                    'status'               => 'failed',
                    'message'              => $e->getMessage(),
                    'finished_at'          => now(),
                    'updated_at'           => now(),
                    'last_duration_seconds'=> $duration,
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
                'finished_at' => now(),
                'updated_at'  => now(),
            ]);
    }
}
