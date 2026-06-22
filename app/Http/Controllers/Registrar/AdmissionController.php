<?php

namespace App\Http\Controllers\Registrar;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Admission;

class AdmissionController extends Controller
{
    public function index()
    {
        $admissions = Admission::all();
        return view('registrar.admissions.index', compact('admissions'));
    }

    public function show(Admission $admission)
    {
        return view('registrar.admissions.show', compact('admission'));
    }

    public function approve(Admission $admission)
    {
        $admission->status = 'approved';
        $admission->save();

        return redirect()->route('registrar.admissions.index')->with('success', 'Admission approved.');
    }

    public function reject(Admission $admission)
    {
        $admission->status = 'rejected';
        $admission->save();

        return redirect()->route('registrar.admissions.index')->with('error', 'Admission rejected.');
    }
}
