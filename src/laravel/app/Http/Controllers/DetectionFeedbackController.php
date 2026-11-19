<?php

namespace App\Http\Controllers;

use App\Services\DetectionFeedbackService;
use Illuminate\Http\Request;

/** What the detection did here, as a JSON download without names and addresses (see DetectionFeedbackService). */
class DetectionFeedbackController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = DetectionFeedbackService::build();

        return response()->streamDownload(
            fn () => print json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'detection-feedback-'.now()->format('Y-m-d').'.json',
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }
}
