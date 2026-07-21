<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;

class AiGuardController extends Controller
{
    public function detectDriver()
    {
        $pythonPath = base_path('ai_engine/venv/Scripts/python.exe');
        $scriptPath = base_path('ai_engine/api_detect.py');

        if (!file_exists($scriptPath)) {
            return response()->json([
                'status' => 'error',
                'message' => 'หาไฟล์ api_detect.py ไม่เจอครับ'
            ], 500);
        }

        $command = '"' . $pythonPath . '" "' . $scriptPath . '" 2>&1';

        $output = shell_exec($command);

        $result = json_decode($output, true);

        if (!$result) {
            return response()->json([
                'status' => 'error',
                'message' => 'สั่งรัน Python แล้วพังกลางทางครับ!',
                'raw_output' => $output,
                'executed_command' => $command
            ], 500);
        }

        return response()->json($result);
    }
}
