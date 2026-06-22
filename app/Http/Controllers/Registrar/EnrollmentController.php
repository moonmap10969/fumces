<?php

namespace App\Http\Controllers\Registrar;

use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\Schedule;
use Illuminate\Http\Request;
use App\Models\FeeStructure;
use App\Models\Tuition;
use App\Models\AcademicYear;
use App\Mail\EnrollmentReminder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use App\Models\User;

class EnrollmentController extends Controller
{
    public function index(Request $request)
    {
        // FIX: Null-safe check for current academic year
        $currentYear = AcademicYear::where('is_current', true)->first();

        if (!$currentYear) {
            return redirect()->back()->with('error', 'Please set an active Academic Year before managing enrollments.');
        }

        $selectedYear = $request->get('academic_year', $currentYear->id);
        $academicYears = AcademicYear::orderBy('year_range', 'desc')->get();

        // Retention: students in previous year NOT in current year
        $previousYear = AcademicYear::where('id', '<', $currentYear->id)
                                    ->orderBy('id', 'desc')
                                    ->first();

        $atRiskStudents = collect();
        if ($previousYear) {
            $previousStudentNums = Enrollment::where('academic_year_id', $previousYear->id)->pluck('studentNumber');
            $currentStudentNums  = Enrollment::where('academic_year_id', $currentYear->id)->pluck('studentNumber');
            $atRiskStudentNumbers = $previousStudentNums->diff($currentStudentNums);
            $atRiskStudents = Admission::whereIn('studentNumber', $atRiskStudentNumbers)->get();
        }

        // Stats
        $stats = (object)[
            'total_enrollments' => Enrollment::when(
                $selectedYear,
                fn($q) => $q->where('academic_year_id', $selectedYear)
            )->count(),

            // FIX: Match using studentNumber instead of admission_id
            'pending_requests' => Admission::where('status', 'approved')
                ->whereNotExists(function ($query) {
                    $query->select(DB::raw(1))
                          ->from('enrollments')
                          ->whereRaw('enrollments.studentNumber = admissions.studentNumber');
                })->count(),

            'new_this_week' => Enrollment::where('created_at', '>=', now()->startOfWeek())->count(),
        ];

        $enrollments = Enrollment::with(['admission.academicYear', 'section'])
            ->when($selectedYear, fn($q) => $q->where('academic_year_id', $selectedYear))
            ->latest()
            ->paginate(15)
            ->appends($request->all());

        return view('registrar.enrollment.index', compact(
            'enrollments', 'stats', 'academicYears', 'atRiskStudents', 'currentYear'
        ));
    }

    public function create(Request $request)
    {
        $approvedAdmissions = Admission::where('status', 'approved')
            ->with('tuition')
            ->get()
            ->map(function ($student) {
                $student->balance = Tuition::where('studentNumber', $student->studentNumber)->sum('balance');
                $student->student_type = Enrollment::where('studentNumber', $student->studentNumber)->exists()
                    ? 'returning'
                    : 'new';
                return $student;
            });

        $sections  = Section::withCount('enrollments')->get();
        $schedules = Schedule::all();

        return view('registrar.enrollment.create', compact('approvedAdmissions', 'sections', 'schedules'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'admission_ids'   => 'required|array|min:1',
            'admission_ids.*' => 'exists:admissions,id',
            'section_id'      => 'required|exists:sections,section_id',
            'year_level'      => 'required|string',
        ]);

        $activeYear = AcademicYear::where('is_current', true)->first();

        if (!$activeYear) {
            return redirect()->back()->with('error', 'No active academic year is set. Cannot enroll students.');
        }

        $admissions     = Admission::whereIn('id', $request->admission_ids)->get()->keyBy('id');
        $studentNumbers = $admissions->pluck('studentNumber');

        // ── Balance check: only block RETURNING students with an unpaid balance ──
        // New students will never have a Tuition record, so this is naturally safe
        // for first-time enrollees.
        $balances = Tuition::whereIn('studentNumber', $studentNumbers)
            ->selectRaw('studentNumber, SUM(balance) as total_balance')
            ->groupBy('studentNumber')
            ->pluck('total_balance', 'studentNumber');

        // Identify returning students (enrolled in any previous academic year)
        $returningStudentNumbers = Enrollment::whereIn('studentNumber', $studentNumbers)
            ->where('academic_year_id', '!=', $activeYear->id)
            ->pluck('studentNumber')
            ->unique()
            ->toArray();

        foreach ($admissions as $admission) {
            // Only block the student if they are returning AND carry an outstanding balance
            $isReturning = in_array($admission->studentNumber, $returningStudentNumbers);
            $balance     = $balances[$admission->studentNumber] ?? 0;

            if ($isReturning && $balance > 0) {
                return redirect()->back()->with(
                    'error',
                    "Cannot re-enroll {$admission->studentFirstName} {$admission->studentLastName}. "
                    . "They have an outstanding balance of ₱" . number_format($balance, 2) . " from a previous school year. "
                    . "Please settle the balance before proceeding."
                );
            }
        }

        $alreadyEnrolled = Enrollment::whereIn('studentNumber', $studentNumbers)
            ->where('academic_year_id', $activeYear->id)
            ->pluck('studentNumber')
            ->toArray();

        // Pre-fetch each student's most recent enrollment so we can auto-advance year level
        $latestEnrollments = Enrollment::whereIn('studentNumber', $studentNumbers)
            ->where('academic_year_id', '!=', $activeYear->id)
            ->orderBy('id', 'desc')
            ->get()
            ->unique('studentNumber')            // keep only the most recent per student
            ->keyBy('studentNumber');

        $enrolledCount = 0;
        $skippedCount  = 0;

        foreach ($admissions as $admission) {
            if (in_array($admission->studentNumber, $alreadyEnrolled)) {
                $skippedCount++;
                continue;
            }

            // ── Auto-advance year level for returning students ──────────────
            // If the student was enrolled before, promote them to the next level
            // instead of using whatever the form submitted.
            $previousEnrollment = $latestEnrollments->get($admission->studentNumber);

            $resolvedYearLevel = $previousEnrollment
                ? $this->getNextYearLevel($previousEnrollment->year_level)
                : $request->year_level;   // new students use the form value

            // 1. Create Enrollment
            $enrollment = Enrollment::create([
                'studentNumber'    => $admission->studentNumber,
                'academic_year_id' => $activeYear->id,
                'section_id'       => $request->section_id,
                'year_level'       => $resolvedYearLevel,
                'school_year'      => $request->school_year ?? $activeYear->year_range,
                'status'           => 'enrolled',
            ]);

            // 2. Handle Tuition Logic
            $fee = \App\Models\FeeStructure::where('year_level', $resolvedYearLevel)
                        ->where('academic_year_id', $activeYear->id)
                        ->first();

            if ($fee) {
                $tuitionData = $this->calculateFlexibleFees($admission, $enrollment, $fee);
                \App\Models\Tuition::updateOrCreate(
                    ['studentNumber' => $admission->studentNumber, 'academic_year_id' => $activeYear->id],
                    $tuitionData
                );
            }

            // 3. Update Admission Status
            $admission->update(['status' => 'enrolled']);

            // 4. Role & Account Linkage
            $targetEmail = trim($admission->email);
            $user = \App\Models\User::where('email', $targetEmail)->first();

            if ($user) {
                if (empty($admission->user_id)) {
                    $admission->update(['user_id' => $user->id]);
                }
                $user->role        = 'student';
                $user->is_approved = true;
                $user->save();
            }

            $enrolledCount++;
        }

        $message = "{$enrolledCount} student(s) enrolled successfully.";
        if ($skippedCount > 0) {
            $message .= " {$skippedCount} student(s) were skipped (already enrolled this year).";
        }

        return redirect()->route('registrar.enrollment.index')->with('success', $message);
    }

    private function calculateFlexibleFees($admission, $enrollment, $preset)
    {
        $baseTuition = (float) $preset->base_tuition;
        $totalMisc   = (float) $preset->total_misc;

        return [
            'studentNumber'    => $admission->studentNumber,
            'academic_year_id' => $preset->academic_year_id,
            'name'             => "{$admission->studentFirstName} {$admission->studentLastName}",
            'year_level'       => $enrollment->year_level,
            'tuition_fee'      => $baseTuition,
            'misc_fees'        => $totalMisc,
            'amount'           => $baseTuition + $totalMisc,
            'balance'          => $baseTuition + $totalMisc,
            'payment_schedule' => 'monthly',
            'status'           => 'pending',
        ];
    }

    public function retentionAnalytics()
    {
        $currentYear = AcademicYear::where('is_current', true)->first();

        if (!$currentYear) {
            return redirect()->back()->with('error', 'Please set a current Academic Year first.');
        }

        $previousYear = AcademicYear::where('id', '!=', $currentYear->id)
                                    ->orderBy('year_range', 'desc')
                                    ->first();

        if (!$previousYear) {
            $atRiskStudents = collect();
            return view('registrar.enrollment.retention', compact('atRiskStudents', 'currentYear'))
                ->with('info', 'First year of operation — no previous data available for retention analysis.');
        }

        $previousStudentNums  = Enrollment::where('academic_year_id', $previousYear->id)->pluck('studentNumber');
        $currentStudentNums   = Enrollment::where('academic_year_id', $currentYear->id)->pluck('studentNumber');
        $atRiskStudentNumbers = $previousStudentNums->diff($currentStudentNums);

        $atRiskStudents = Admission::whereIn('studentNumber', $atRiskStudentNumbers)
            ->where('academic_year_id', $previousYear->id)
            ->get();

        return view('registrar.enrollment.retention', compact('atRiskStudents', 'currentYear'));
    }

    public function sendBurstAlerts(Request $request)
    {
        $request->validate([
            'custom_message' => 'required|string|max:2000',
        ]);

        $currentYear = AcademicYear::where('is_current', true)->first();

        if (!$currentYear) {
            return redirect()->back()->with('error', 'No active academic year set.');
        }

        $previousYear = AcademicYear::where('id', '!=', $currentYear->id)
                                    ->orderBy('year_range', 'desc')
                                    ->first();

        if (!$previousYear) {
            return redirect()->back()->with('error', 'No previous academic year found.');
        }

        $previousNums = Enrollment::where('academic_year_id', $previousYear->id)->pluck('studentNumber');
        $currentNums  = Enrollment::where('academic_year_id', $currentYear->id)->pluck('studentNumber');
        $atRiskNums   = $previousNums->diff($currentNums);

        $students = Admission::whereIn('studentNumber', $atRiskNums)
            ->where('academic_year_id', $previousYear->id)
            ->get();

        $sentCount = 0;
        foreach ($students as $student) {
            if (!$student->email) continue;

            $message = str_replace('[Student Name]', $student->studentFirstName, $request->custom_message);

            try {
                Mail::to($student->email)->send(new EnrollmentReminder($student->studentFirstName, $message));

                DB::table('email_logs')->insert([
                    'studentNumber'   => $student->studentNumber,
                    'recipient_email' => $student->email,
                    'subject'         => 'Action Required: Enrollment for SY ' . $currentYear->year_range,
                    'message'         => $message,
                    'status'          => 'sent',
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);

                $sentCount++;
            } catch (\Exception $e) {
                \Log::warning("Failed to send reminder to {$student->email}: " . $e->getMessage());
            }
        }

        return redirect()->back()->with('success', "{$sentCount} email reminder(s) sent successfully.");
    }

    /**
     * Normalise a year-level string to the canonical slug used as array keys
     * and stored in the enrollments table (e.g. "Kinder 1" → "kinder1").
     *
     * Strips spaces, hyphens, and underscores so that values coming from the
     * UI dropdown ("Kinder 1", "Grade 1") and values already in the DB
     * ("kinder1", "grade1") all resolve to the same key.
     */
    private function normalizeYearLevel(string $yearLevel): string
    {
        return strtolower(preg_replace('/[\s\-_]+/', '', trim($yearLevel)));
    }

    private function mapYearLevelToNumeric($yearLevel): int
    {
        $levels = [
            'kinder1' => 1,  'kinder2' => 2,
            'grade1'  => 4,  'grade2'  => 5,  'grade3'  => 6,
            'grade4'  => 7,  'grade5'  => 8,  'grade6'  => 9,
            'grade7'  => 10, 'grade8'  => 11, 'grade9'  => 12,
            'grade10' => 13,
        ];

        return $levels[$this->normalizeYearLevel($yearLevel)] ?? 0;
    }

    /**
     * Return the next year level in the standard DepEd progression.
     * Used to auto-advance returning students during re-enrollment.
     * Always returns the canonical slug format ("kinder2", "grade1", ...).
     * If the student is already at Grade 10, the same level is returned.
     */
    private function getNextYearLevel(string $yearLevel): string
    {
        $progression = [
            'kinder1' => 'kinder2',
            'kinder2' => 'grade1',
            'grade1'  => 'grade2',
            'grade2'  => 'grade3',
            'grade3'  => 'grade4',
            'grade4'  => 'grade5',
            'grade5'  => 'grade6',
            'grade6'  => 'grade7',
            'grade7'  => 'grade8',
            'grade8'  => 'grade9',
            'grade9'  => 'grade10',
        ];

        $normalized = $this->normalizeYearLevel($yearLevel);

        // Return the next level slug, or the normalized current level if
        // no progression exists (e.g. grade10 is the ceiling).
        return $progression[$normalized] ?? $normalized;
    }

    /**
     * NOTE: getPredictiveAtRiskQueue() has been removed from direct web request handling.
     * Running Python via Process::run() blocks the HTTP request and will time out.
     * This should be dispatched as a queued job:
     *
     *   dispatch(new \App\Jobs\ComputeAtRiskStudents());
     *
     * And results stored in a DB table for the view to read asynchronously.
     */
}