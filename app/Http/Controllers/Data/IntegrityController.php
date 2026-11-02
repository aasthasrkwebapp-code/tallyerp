<?php

namespace App\Http\Controllers\Data;

use App\Http\Controllers\Controller;
use App\Services\Data\IntegrityChecker;

class IntegrityController extends Controller
{
    public function __invoke(IntegrityChecker $checker)
    {
        return view('data.integrity', ['checks' => $checker->run()]);
    }
}
