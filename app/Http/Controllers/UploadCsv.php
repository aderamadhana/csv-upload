<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Jobs\ProcessCsvUpload;

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
            'file' => 'required|mimes:csv,txt|max:51200',
        ]);

        $uploadStartedAt = $request->input('upload_started_at') ?: now();

        $file = $request->file('file');

        $storedPath = $file->storeAs(
            'uploads',
            time() . '_' . $file->getClientOriginalName()
        );

        // buat log: pending, dengan waktu upload selesai
        $backgroundId = DB::table('csv_upload_background_process')->insertGetId([
            'file_name'          => $file->getClientOriginalName(),
            'status'             => 'pending',
            'message'            => null,
            'created_at'         => now(),
            'updated_at'         => now(),
            'upload_started_at'  => $uploadStartedAt,
            'upload_finished_at' => now(),
            'processing_started_at' => null,
            'finished_at'        => null,
        ]);

        ProcessCsvUpload::dispatch($storedPath, $backgroundId);

        // karena kita pakai fetch (AJAX), balas JSON, bukan redirect
        return response()->json([
            'success' => true,
            'background_id' => $backgroundId,
        ]);
    }
}
