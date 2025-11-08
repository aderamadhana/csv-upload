<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Jobs\ProcessCsvUpload;
use Carbon\Carbon;

class UploadCsv extends Controller
{
    public function index()
    {
        $backgrounds = DB::table('csv_upload_background_process')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return view('upload', compact('backgrounds'));
    }

    public function backgroundStatus()
    {
        $rows = DB::table('csv_upload_background_process')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(function ($row) {
                // total = dari mulai upload sampai selesai proses (atau sekarang)
                if ($row->upload_started_at) {
                    $start = strtotime($row->upload_started_at);
                    $end = $row->finished_at
                        ? strtotime($row->finished_at)
                        : time();

                    $row->total_seconds = max($end - $start, 0);
                } else {
                    $row->total_seconds = 0;
                }

                return $row;
            });

        return response()->json($rows);
    }

    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:csv,txt|max:512000',
        ]);

        // ambil dari client (ISO) lalu konversi ke timezone app
        $clientStarted = $request->input('upload_started_at');

        if ($clientStarted) {
            $uploadStartedAt = Carbon::parse($clientStarted)
                ->setTimezone(config('app.timezone')); // contoh: Asia/Jakarta
        } else {
            $uploadStartedAt = now()->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z');
        }

        $file = $request->file('file');

        $storedPath = $file->storeAs(
            'uploads',
            time() . '_' . $file->getClientOriginalName()
        );

        $backgroundId = DB::table('csv_upload_background_process')->insertGetId([
            'file_name'             => $file->getClientOriginalName(),
            'status'                => 'pending',
            'message'               => null,
            'created_at'            => now()->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z'),
            'updated_at'            => now()->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z'),
            'upload_started_at'     => $uploadStartedAt,   // ✅ sudah sama formatnya
            'upload_finished_at'    => now()->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z'),              // ✅ sama format
            'processing_started_at' => null,
            'finished_at'           => null,
        ]);

        ProcessCsvUpload::dispatch($storedPath, $backgroundId);

        return response()->json([
            'success'       => true,
            'background_id' => $backgroundId,
            'file_name'     => $file->getClientOriginalName(),
        ]);
    }

}
