<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Admission;
use App\Models\User;
use App\Models\Document;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use App\Mail\ApplicationSubmitted;

class AdmissionController extends Controller
{
    public function index()
    {
        // Guest users — just show the admissions info page
        if (Auth::guest()) {
            return view('admissions');
        }

        // FIX: Check by user_id only — most reliable, no academic_year dependency
        $alreadyApplied = Admission::where('user_id', Auth::id())->exists();

        if ($alreadyApplied) {
            return view('success-card');
        }

        return view('admissions');
    }

    public function create()
    {
        return view('admissions.create');
    }

    public function store(Request $request)
    {
        // 1. Check active academic year
        $currentYear = \App\Models\AcademicYear::where('is_current', true)->first();

        if (!$currentYear) {
            return redirect()->back()
                ->with('error', 'Enrollment is currently closed. No active academic year is set.');
        }

        // 2. FIX: Block duplicate — check by user_id only
        // Simple and reliable — if this user has ANY admission record, block it
        $existing = Admission::where('user_id', Auth::id())->exists();

        if ($existing) {
            return redirect()->back()
                ->with('error', 'You have already submitted an application. Each account can only have one application. To apply for another child, please register a new account.');
        }

        // 3. Validate input
        $validatedData = $request->validate([
            'studentFirstName'  => 'required|string|max:255',
            'studentLastName'   => 'required|string|max:255',
            'dateOfBirth' => 'required|date|before:' . now()->subYears(3)->format('Y-m-d'),
            'gender'            => 'required|in:Male,Female',
            'year_level'        => 'required|in:kinder1,kinder2,kinder3,grade1,grade2,grade3,grade4,grade5,grade6,grade7,grade8,grade9,grade10',
            'parentFirstName'   => 'required|string|max:255',
            'parentLastName'    => 'required|string|max:255',
            'email'             => 'required|email',
            'phone'             => 'required|string|max:20',
            'address'           => 'required|string|max:500',
            'city'              => 'required|string|max:255',
            'state'             => 'required|string|max:255',
            'zipCode'           => 'required|string|max:10',
            'household_income'  => 'required|string',
            'household_size'    => 'required|integer|min:1|max:30',
            'employment_status' => 'required|string',
            'report_card'       => 'required|mimes:jpg,jpeg,png,pdf|max:5120',
            'birth_certificate' => 'required|mimes:jpg,jpeg,png,pdf|max:5120',
            'applicant_photo'   => 'required|mimes:jpg,jpeg,png|max:5120',
            'father_photo'      => 'nullable|mimes:jpg,jpeg,png|max:5120',
            'mother_photo'      => 'nullable|mimes:jpg,jpeg,png|max:5120',
            'guardian_photo'    => 'nullable|mimes:jpg,jpeg,png|max:5120',
            'transferee_docs'   => 'nullable|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        // 4. Handle file uploads
        $fileFields = [
            'report_card', 'birth_certificate', 'applicant_photo',
            'father_photo', 'mother_photo', 'guardian_photo', 'transferee_docs'
        ];

        foreach ($fileFields as $field) {
            if ($request->hasFile($field)) {
                $validatedData[$field] = $request->file($field)
                    ->store('admissions/documents', 'public');
            }
        }

        // 5. Create admission record
        $admission = Admission::create(array_merge($validatedData, [
            'user_id'          => Auth::id(),
            'academic_year_id' => $currentYear->id,
            'status'           => 'pending',
            'studentNumber'    => 'PENDING-' . str_pad(
                Admission::whereYear('created_at', date('Y'))->count() + 1,
                4, '0', STR_PAD_LEFT
            ),
            'street' => $request->address,
            'zip'    => $request->zipCode,
        ]));

        // 6. Log documents
        foreach ($fileFields as $field) {
            if (isset($validatedData[$field])) {
                Document::create([
                    'user_id'   => Auth::id(),
                    'file_name' => ucwords(str_replace('_', ' ', $field)),
                    'file_path' => $validatedData[$field],
                    'type'      => 'admission_record',
                    'status'    => 'Pending',
                ]);
            }
        }

        // 7. Send confirmation email
        try {
            Mail::to($request->email)->send(new ApplicationSubmitted($admission));
        } catch (\Exception $e) {
            \Log::warning('Failed to send admission confirmation email: ' . $e->getMessage());
        }

        return view('success-card');
    }
}