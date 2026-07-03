<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Jobs\ProcessVideoClipper;

class ClipperController extends Controller
{
    public function index() {
        return view('dashboard');
    }

    public function store(Request $request) {
        $request->validate([
            'youtube_url' => 'required|url'
        ]);

        // Lempar tugas berat ke background job
        ProcessVideoClipper::dispatch($request->youtube_url);

        return back()->with('status', 'Permintaan masuk antrean, Bos! Mesin sedang bekerja di latar belakang. Silakan cek folder storage secara berkala.');
    }
}