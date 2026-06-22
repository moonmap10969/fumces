<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use App\Models\Admission;

class DocumentController extends Controller
{
    public function index()
    {
        $admission = Admission::where('user_id', Auth::id())->firstOrFail();
        
        // Map your actual database columns here
        $documents = [
            'form_138' => [
                'title' => 'Form 138 / Report Card', 
                'path' => $admission->form_138
            ],
            'good_moral' => [
                'title' => 'Good Moral Certificate', 
                'path' => $admission->good_moral
            ],
            'psa_birth_cert' => [
                'title' => 'PSA Birth Certificate', 
                'path' => $admission->psa_birth_cert
            ],
            'id_picture' => [
                'title' => '2x2 ID Picture', 
                'path' => $admission->id_picture
            ],
        ];

        return view('student.documents.index', compact('documents'));
    }

    public function download($column)
    {
        $admission = Admission::where('user_id', Auth::id())->firstOrFail();
        
        // Security check to prevent unauthorized column access
        $validColumns = ['form_138', 'good_moral', 'psa_birth_cert', 'id_picture'];

        if (in_array($column, $validColumns) && !empty($admission->$column)) {
            return Storage::download($admission->$column);
        }

        return redirect()->back()->with('error', 'Requested document is unavailable or was not submitted.');
    }
}