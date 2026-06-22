<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\{
    Schedule,
    Attendance,
    Section,
    GradingComponent,
    Enrollment,
    ClassRecord,
    GradingItem,
    StudentGrade,
    AcademicYear,
    QuarterActivation
};


class GradeController extends Controller
{
    /**
     * Display summarized class record and attendance
     */
    public function index(Request $request)
    {
        $teacherName = Auth::user()->name;
        $date = $request->query('date', date('Y-m-d'));

        $selectedQuarter = $request->query('quarter', 'q1_grade');

        $viewMode = $request->query('view', 'subject');
        if ($selectedQuarter === 'overall_master' && $viewMode !== 'overall') {
            $selectedQuarter = 'q1_grade';
        }

        $quarterActive = $selectedQuarter === 'overall_master'
            ? true
            : $this->isQuarterActive((string) $selectedQuarter);

        $selectedSubjectId = null;
        $subjects = collect();
        $activeSchedule = null;
        $analyticsResults = [];
        $analyticsDashboard = [];

        // 1️⃣ Get Teacher Sections
        $assignedSchedules = Schedule::where('teacher', $teacherName)
            ->select('section', 'year_level')
            ->distinct()
            ->get();

        $sections = $assignedSchedules->map(function ($item) {
            return Section::where('name', trim($item->section))
                ->where('year_level', trim($item->year_level))
                ->first();
        })->filter()->whereNotNull('section_id');

        $selectedSectionId = $request->query(
            'section',
            $sections->first()->section_id ?? null
        );

        // Safe defaults
        $components        = collect();
        $gradingItems      = collect();
        $enrollments       = collect();
        $attendanceRecords = [];
        $attendanceDates   = collect();
        $classAverage      = 0;
        $passingRate       = 0;
        $highestGrade      = 0;
        $quarterlyGrades   = [];

        if (!$selectedSectionId) {
            return view('teacher.grades.index', compact(
                'sections', 'selectedSectionId', 'date',
                'components', 'gradingItems', 'enrollments',
                'attendanceRecords', 'attendanceDates',
                'classAverage', 'passingRate', 'highestGrade',
                'quarterActive', 'quarterlyGrades'
            ));
        }

        // 2️⃣ Build subjects for this teacher & section
        $activeSection = $sections->where('section_id', $selectedSectionId)->first();

        $subjectSchedules = Schedule::where('teacher', $teacherName)
            ->when($activeSection, function ($q) use ($activeSection) {
                return $q->where('section', trim($activeSection->name))
                    ->where('year_level', trim($activeSection->year_level));
            })
            ->whereNotIn(DB::raw('LOWER(subject)'), ['recess', 'lunch', 'lunch break', 'homeroom', 'break'])
            ->orderBy('subject')
            ->get();

        $uniqueSubjectSchedules = $subjectSchedules
            ->unique(fn ($s) => strtolower(trim((string) $s->subject)))
            ->values();

        $subjects = $uniqueSubjectSchedules->map(function ($s) {
            return (object)[
                'subject_id'   => $s->id,
                'subject_name' => $s->subject,
            ];
        });

        $selectedSubjectId = $request->query('subject');
        if (!$selectedSubjectId || !$subjects->firstWhere('subject_id', $selectedSubjectId)) {
            $selectedSubjectId = $subjects->first()->subject_id ?? null;
        }

        $activeSchedule = $uniqueSubjectSchedules->firstWhere('id', $selectedSubjectId)
            ?? $uniqueSubjectSchedules->first();

        // Resolved subject name used as the scope for all grade logic
        $activeSubjectName = $activeSchedule?->subject ?? null;

        // 3️⃣ Run Auto Grade Computation (strictly scoped to current subject)
        if ($activeSubjectName) {
            $this->runGradeComputation(
                $selectedSectionId,
                $selectedQuarter === 'overall_master' ? null : $selectedQuarter,
                $activeSubjectName
            );
        }

        // 4️⃣ Fetch Components & Items strictly scoped to section + subject
        $components = GradingComponent::where('section_id', $selectedSectionId)
            ->when($activeSubjectName, fn ($q) => $q->where('subject', $activeSubjectName))
            ->get();

        $gradingItems = GradingItem::where('section_id', $selectedSectionId)
            ->when($activeSubjectName, fn ($q) => $q->where('subject', $activeSubjectName))
            ->when($selectedQuarter !== 'overall_master', fn ($q) => $q->where('quarter', $selectedQuarter))
            ->with('component')
            ->get();

        // 5️⃣ Load Enrollments — attach the per-subject final grade
        $enrollments = Enrollment::where('section_id', $selectedSectionId)
            ->with(['admission', 'classRecord'])
            ->get()
            ->map(function ($enrollment) use ($selectedSectionId, $activeSubjectName) {
                $subjectRecord = null;
                if ($activeSubjectName) {
                    $subjectRecord = ClassRecord::where('studentNumber', $enrollment->id)
                        ->where('section_id', $selectedSectionId)
                        ->where('subject', $activeSubjectName)
                        ->first();
                }

                $enrollment->final_percentage = $subjectRecord?->final_average ?? 0;

                return $enrollment;
            });

        // 5.0️⃣ Compute per-quarter grades (transmuted, per subject per student)
        $allSubjectNames = $uniqueSubjectSchedules->pluck('subject')->filter()->unique()->values()->all();
        $quarterlyGrades = $this->computeQuarterlyGrades(
            $selectedSectionId,
            $enrollments,
            $allSubjectNames
        );

        // 5.1️⃣ Build analytics (scoped to active subject)
        [$analyticsResults, $analyticsDashboard, $enrollments] = $this->buildAnalytics(
            $selectedSectionId,
            $selectedQuarter,
            $components,
            $enrollments,
            $activeSubjectName
        );

        // 6️⃣ Dashboard Statistics
        $totalStudents = $enrollments->count();
        $classAverage  = $totalStudents > 0 ? $enrollments->avg('final_percentage') : 0;
        $passingRate   = $totalStudents > 0
            ? ($enrollments->where('final_percentage', '>=', 50)->count() / $totalStudents) * 100
            : 0;
        $highestGrade  = $enrollments->max('final_percentage') ?? 0;

        // 7️⃣ Attendance Data
        $attendanceRecords = Attendance::where('section_id', $selectedSectionId)
            ->whereDate('date', $date)
            ->pluck('status', 'studentNumber')
            ->toArray();

        $attendanceDates = Attendance::where('section_id', $selectedSectionId)
            ->select('date')
            ->distinct()
            ->orderBy('date', 'desc')
            ->pluck('date');

        return view('teacher.grades.index', compact(
            'sections', 'components', 'gradingItems', 'enrollments',
            'selectedSectionId', 'subjects', 'selectedSubjectId',
            'selectedQuarter', 'activeSchedule', 'viewMode',
            'analyticsResults', 'analyticsDashboard', 'quarterlyGrades',
            'attendanceRecords', 'attendanceDates', 'date',
            'classAverage', 'passingRate', 'highestGrade', 'quarterActive'
        ));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DEPED TRANSMUTATION TABLE
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Convert an Initial Grade (weighted percentage 0–100) to a
     * Quarterly Grade using the official DepEd transmutation table.
     *
     * Anything below 20.00 maps to 60 (the floor).
     */
    private function transmute(float $ig): int
    {
        $table = [
            [100.00, 100], [98.40, 99], [96.80, 98], [95.20, 97], [93.60, 96],
            [92.00,  95],  [90.40, 94], [88.80, 93], [87.20, 92], [85.60, 91],
            [84.00,  90],  [82.40, 89], [80.80, 88], [79.20, 87], [77.60, 86],
            [76.00,  85],  [74.40, 84], [72.80, 83], [71.20, 82], [69.60, 81],
            [68.00,  80],  [66.40, 79], [64.80, 78], [63.20, 77], [61.60, 76],
            [60.00,  75],  [56.00, 74], [52.00, 73], [48.00, 72], [44.00, 71],
            [40.00,  70],  [36.00, 69], [32.00, 68], [28.00, 67], [24.00, 66],
            [20.00,  65],
        ];

        foreach ($table as [$min, $grade]) {
            if ($ig >= $min) {
                return $grade;
            }
        }

        return 60; // absolute floor
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GRADE COMPUTATION
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * CORE GRADE COMPUTATION — strictly scoped to a single subject.
     */
    private function runGradeComputation(int $sectionId, ?string $quarter, ?string $subject): void
    {
        if (!$subject) {
            $students = Enrollment::where('section_id', $sectionId)->get();
            foreach ($students as $student) {
                $this->recomputeOverallAverage($student->id, $sectionId);
            }
            return;
        }

        $categories = GradingComponent::where('section_id', $sectionId)
            ->where('subject', $subject)
            ->get();

        $students = Enrollment::where('section_id', $sectionId)->get();

        foreach ($students as $student) {
            $subjectGrade = $this->computeSubjectGrade(
                $student,
                $sectionId,
                $categories,
                $quarter,
                $subject
            );

            ClassRecord::updateOrCreate(
                [
                    'studentNumber' => $student->id,
                    'section_id'    => $sectionId,
                    'subject'       => $subject,
                ],
                [
                    'final_average' => min(max(round($subjectGrade, 2), 0), 100),
                ]
            );

            $this->recomputeOverallAverage($student->id, $sectionId);
        }
    }

    /**
     * Recompute and persist the overall ClassRecord (subject = null) for one student.
     */
    private function recomputeOverallAverage(int $enrollmentId, int $sectionId): void
    {
        $subjectAverages = ClassRecord::where('studentNumber', $enrollmentId)
            ->where('section_id', $sectionId)
            ->whereNotNull('subject')
            ->pluck('final_average');

        $overallAverage = $subjectAverages->isNotEmpty()
            ? round($subjectAverages->avg(), 2)
            : 0.0;

        ClassRecord::updateOrCreate(
            [
                'studentNumber' => $enrollmentId,
                'section_id'    => $sectionId,
                'subject'       => null,
            ],
            [
                'final_average' => min(max($overallAverage, 0), 100),
            ]
        );
    }

    /**
     * Compute weighted percentage (0–100) for one student in one subject.
     * This is the Initial Grade before transmutation.
     */
    private function computeSubjectGrade($student, int $sectionId, $categories, ?string $quarter, ?string $subject): float
    {
        $weightedSum  = 0.0;
        $weightUsed   = 0.0;

        foreach ($categories as $category) {
            $weight = (float) $category->percentage;

            // ── Attendance component ─────────────────────────────────────────
            if (stripos($category->category, 'attendance') !== false) {
                $totalDays = Attendance::where('section_id', $sectionId)
                    ->distinct('date')
                    ->count('date');

                $presentDays = Attendance::where('studentNumber', $student->studentNumber)
                    ->where('section_id', $sectionId)
                    ->where('status', 'Present')
                    ->count();

                $ratio = $totalDays > 0 ? min($presentDays / $totalDays, 1.0) : 0.0;

                $weightedSum += $ratio * $weight;
                $weightUsed  += $weight;
                continue;
            }

            // ── Normal graded component ──────────────────────────────────────
            $itemIds = GradingItem::where('component_id', $category->id)
                ->where('subject', $subject)
                ->when($quarter, fn ($q) => $q->where('quarter', $quarter))
                ->pluck('id');

            if ($itemIds->isEmpty()) {
                continue;
            }

            $studentGrades = StudentGrade::where('enrollment_id', $student->id)
                ->whereIn('grading_item_id', $itemIds)
                ->get();

            $totalEarned = (float) $studentGrades->sum('raw_score');
            $totalMax    = (float) GradingItem::whereIn('id', $itemIds)->sum('max_score');

            $ratio = $totalMax > 0 ? min($totalEarned / $totalMax, 1.0) : 0.0;

            $weightedSum += $ratio * $weight;
            $weightUsed  += $weight;
        }

        if ($weightUsed <= 0) {
            return 0.0;
        }

        $normalised = ($weightedSum / $weightUsed) * 100;

        return min(max(round($normalised, 4), 0.0), 100.0);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // QUARTERLY GRADES  — transmuted per DepEd guidelines
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Compute per-quarter grades for every enrollment, per subject.
     *
     * Returns transmuted quarterly grades (integers 60–100) so that the
     * reports blade and any downstream consumers already have DepEd-correct values.
     *
     * Structure: [enrollment_id => [subject_name => [quarter_key => int]]]
     */
    private function computeQuarterlyGrades(int $sectionId, $enrollments, array $subjectNames): array
    {
        $quarters      = ['q1_grade', 'q2_grade', 'q3_grade', 'q4_grade'];
        $enrollmentIds = $enrollments->pluck('id')->all();

        $allComponents = GradingComponent::where('section_id', $sectionId)->get();
        $allItems      = GradingItem::where('section_id', $sectionId)
            ->whereIn('quarter', $quarters)
            ->get();
        $allGrades     = StudentGrade::whereIn('enrollment_id', $enrollmentIds)
            ->whereIn('grading_item_id', $allItems->pluck('id')->all())
            ->get()
            ->groupBy('enrollment_id');

        $out = [];

        foreach ($enrollments as $enrollment) {
            $out[$enrollment->id] = [];

            foreach ($quarters as $qtr) {
                foreach ($subjectNames as $subjectName) {
                    $subjectComponents = $allComponents->where('subject', $subjectName);

                    if ($subjectComponents->isEmpty()) {
                        $out[$enrollment->id][$subjectName][$qtr] = 0;
                        continue;
                    }

                    $weightedSum = 0.0;
                    $weightUsed  = 0.0;

                    foreach ($subjectComponents as $component) {
                        $weight    = (float) $component->percentage;
                        $compItems = $allItems
                            ->where('component_id', $component->id)
                            ->where('subject', $subjectName)
                            ->where('quarter', $qtr);

                        if ($compItems->isEmpty()) {
                            continue;
                        }

                        $compItemIds   = $compItems->pluck('id')->all();
                        $studentGrades = $allGrades
                            ->get($enrollment->id, collect())
                            ->whereIn('grading_item_id', $compItemIds);

                        $earned = (float) $studentGrades->sum('raw_score');
                        $max    = (float) $compItems->sum('max_score');
                        $ratio  = $max > 0 ? min($earned / $max, 1.0) : 0.0;

                        $weightedSum += $ratio * $weight;
                        $weightUsed  += $weight;
                    }

                    // Compute Initial Grade (0–100)
                    $initialGrade = ($weightUsed > 0)
                        ? min(($weightedSum / $weightUsed) * 100, 100.0)
                        : 0.0;

                    // Apply DepEd transmutation → Quarterly Grade
                    $out[$enrollment->id][$subjectName][$qtr] = $initialGrade > 0
                        ? $this->transmute(round($initialGrade, 2))
                        : 0;
                }
            }
        }

        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ANALYTICS — kept fully intact, scoped to active subject
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build analytics insights for the "Analytics" tab.
     * Scoped to the active subject so component scores reflect the right items.
     */
    private function buildAnalytics(
        int $sectionId,
        string $selectedQuarter,
        $components,
        $enrollments,
        ?string $subject = null
    ): array {
        $quarterFilter = $selectedQuarter !== 'overall_master' ? $selectedQuarter : null;

        $totalDays = Attendance::where('section_id', $sectionId)
            ->distinct('date')
            ->count('date');

        $gradingItems = GradingItem::where('section_id', $sectionId)
            ->when($subject, fn ($q) => $q->where('subject', $subject))
            ->when($quarterFilter, fn ($q) => $q->where('quarter', $quarterFilter))
            ->get()
            ->keyBy('id');

        $gradingItemIds = $gradingItems->keys()->all();

        $enrollmentIds = $enrollments->pluck('id')->all();
        $allGrades = StudentGrade::whereIn('enrollment_id', $enrollmentIds)
            ->when(!empty($gradingItemIds), fn ($q) => $q->whereIn('grading_item_id', $gradingItemIds))
            ->get()
            ->groupBy('enrollment_id');

        $sigmoid = function (float $x): float {
            $x = max(min($x, 15.0), -15.0);
            return 1.0 / (1.0 + exp(-$x));
        };

        $analyticsResults = [];
        $scatter          = [];
        $probBuckets      = array_fill(0, 10, 0);
        $riskTiers        = ['high' => 0, 'medium' => 0, 'low' => 0];
        $anomalyCount     = 0;

        $componentAverages = [];
        foreach ($components as $c) {
            $componentAverages[$c->id] = ['name' => $c->category, 'avg' => 0.0];
        }
        $componentTotals = array_fill_keys(array_keys($componentAverages), 0.0);
        $componentCounts = array_fill_keys(array_keys($componentAverages), 0);

        $featureRows = [];

        foreach ($enrollments as $enrollment) {
            $presentDays = Attendance::where('section_id', $sectionId)
                ->where('studentNumber', $enrollment->studentNumber)
                ->where('status', 'Present')
                ->count();
            $attendancePct = $totalDays > 0 ? ($presentDays / $totalDays) * 100 : 0;

            $componentScores = [];
            $missingScores   = 0;
            $activityLike    = 0.0; $activityLikeN = 0;
            $examLike        = 0.0; $examLikeN     = 0;

            foreach ($components as $component) {
                $itemIds = $gradingItems->where('component_id', $component->id)->keys()->all();

                if (empty($itemIds)) {
                    $componentScores[$component->id] = 0.0;
                    continue;
                }

                $grades = $allGrades->get($enrollment->id, collect())
                    ->whereIn('grading_item_id', $itemIds);

                $earned = (float) $grades->sum('raw_score');
                $max    = (float) $gradingItems->only($itemIds)->sum('max_score');

                $scoredItemIds = $grades->pluck('grading_item_id')->unique()->all();
                $missingScores += max(count($itemIds) - count($scoredItemIds), 0);

                $pct = $max > 0 ? min(($earned / $max) * 100, 100.0) : 0.0;
                $componentScores[$component->id] = $pct;

                $cat = strtolower((string) $component->category);
                if (str_contains($cat, 'activity') || str_contains($cat, 'performance')) {
                    $activityLike += $pct; $activityLikeN++;
                }
                if (str_contains($cat, 'exam') || str_contains($cat, 'assessment') || str_contains($cat, 'quarterly')) {
                    $examLike += $pct; $examLikeN++;
                }
            }

            $activitiesAvg = $activityLikeN > 0 ? ($activityLike / $activityLikeN) : 0.0;
            $examsAvg      = $examLikeN > 0 ? ($examLike / $examLikeN) : 0.0;
            $overall       = (float) ($enrollment->final_percentage ?? 0);

            $z = -2.2
                + 0.018 * $attendancePct
                + 0.020 * $activitiesAvg
                + 0.024 * $examsAvg
                - 0.25  * min($missingScores, 10);
            $passProb = $sigmoid($z) * 100;

            $riskFactors = [];
            if ($attendancePct < 80)  $riskFactors[] = 'Low attendance rate';
            if ($activitiesAvg < 60)  $riskFactors[] = 'Low activities/performance average';
            if ($examsAvg < 60)       $riskFactors[] = 'Low exam/assessment performance';
            if ($missingScores > 0)   $riskFactors[] = "Missing scores in {$missingScores} activity(ies)";

            $featureRows[$enrollment->id] = [
                'attendance'  => $attendancePct,
                'overall'     => $overall,
                'activities'  => $activitiesAvg,
                'exams'       => $examsAvg,
                'missing'     => $missingScores,
                'passProb'    => $passProb,
                'riskFactors' => $riskFactors,
            ];

            $enrollment->attendance_percentage = $attendancePct;
            $enrollment->component_scores      = $componentScores;

            foreach ($componentScores as $cid => $pct) {
                if (isset($componentTotals[$cid])) {
                    $componentTotals[$cid] += (float) $pct;
                    $componentCounts[$cid] += 1;
                }
            }

            $scatter[] = ['x' => round($attendancePct, 1), 'y' => round($overall, 1)];
            $bucket    = (int) floor(min(max($passProb, 0), 99.999) / 10);
            $probBuckets[$bucket] += 1;
        }

        // Robust anomaly detection
        $overallVals = collect($featureRows)->pluck('overall')->values();
        $median      = $overallVals->median() ?? 0;
        $mad         = $overallVals->map(fn ($v) => abs($v - $median))->median() ?? 0.0;
        $mad         = $mad > 0 ? $mad : 1.0;

        foreach ($enrollments as $enrollment) {
            $f = $featureRows[$enrollment->id] ?? null;
            if (!$f) continue;

            $rz      = 0.6745 * (($f['overall'] - $median) / $mad);
            $anomaly = false;
            $reason  = '';

            if (abs($rz) >= 2.8) {
                $anomaly = true;
                $reason  = 'Overall grade is an outlier compared to the class distribution.';
            }
            if (!$anomaly && $f['attendance'] < 60 && $f['overall'] > 85) {
                $anomaly = true;
                $reason  = 'Very high grade despite very low attendance (check data consistency).';
            }
            if (!$anomaly && $f['missing'] >= 5 && $f['overall'] > 80) {
                $anomaly = true;
                $reason  = 'High grade despite many missing activity scores (check encoding completeness).';
            }

            if ($anomaly) $anomalyCount++;

            $tier = 'low';
            if ($f['passProb'] < 45)      $tier = 'high';
            elseif ($f['passProb'] < 65)  $tier = 'medium';
            $riskTiers[$tier] += 1;

            $analyticsResults[$enrollment->id] = [
                'pass_probability' => round($f['passProb'], 2),
                'at_risk'          => (bool) ($f['passProb'] < 55),
                'risk_factors'     => $f['riskFactors'],
                'anomaly_alert'    => $anomaly,
                'anomaly_reason'   => $reason,
                'risk_tier'        => $tier,
                'features'         => [
                    'attendance'     => round($f['attendance'], 1),
                    'activities'     => round($f['activities'], 1),
                    'exams'          => round($f['exams'], 1),
                    'missing_scores' => (int) $f['missing'],
                    'overall'        => round($f['overall'], 1),
                ],
            ];
        }

        foreach ($componentAverages as $cid => &$row) {
            $row['avg'] = $componentCounts[$cid] > 0
                ? round($componentTotals[$cid] / $componentCounts[$cid], 1)
                : 0.0;
        }
        unset($row);

        $analyticsDashboard = [
            'risk_tiers'         => $riskTiers,
            'anomaly_count'      => $anomalyCount,
            'prob_buckets'       => $probBuckets,
            'scatter'            => $scatter,
            'component_averages' => array_values($componentAverages),
        ];

        return [$analyticsResults, $analyticsDashboard, $enrollments];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPER
    // ─────────────────────────────────────────────────────────────────────────

    private function isQuarterActive(string $quarter): bool
    {
        $activeYear = AcademicYear::where('is_current', true)->first();
        if (!$activeYear) {
            return false;
        }

        return (bool) (QuarterActivation::where('academic_year_id', $activeYear->id)
            ->where('quarter', $quarter)
            ->value('is_active') ?? false);
    }

    /**
     * Resolve all subject names taught by any teacher in a given section.
     */
    private function getSectionSubjectNames(int $sectionId): \Illuminate\Support\Collection
    {
        $section = Section::find($sectionId);
        if (!$section) {
            return collect();
        }

        return Schedule::where('section', trim($section->name))
            ->where('year_level', trim($section->year_level))
            ->whereNotIn(DB::raw('LOWER(subject)'), ['recess', 'lunch', 'lunch break', 'homeroom', 'break'])
            ->pluck('subject')
            ->map(fn ($s) => trim($s))
            ->filter()
            ->unique()
            ->values();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WRITE ACTIONS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Store Attendance
     */
    public function storeAttendance(Request $request)
    {
        $request->validate([
            'section_id' => 'required|exists:sections,section_id',
            'date'       => 'required|date',
            'attendance' => 'required|array',
        ]);

        $attendanceDate = Carbon::parse($request->date);
        $dayName        = $attendanceDate->format('l');

        $schedule = Schedule::where('section_id', $request->section_id)
            ->where('day_of_week', $dayName)
            ->orderBy('start_time')
            ->first();

        if ($attendanceDate->isFuture()) {
            return redirect()->back()->with('error', 'You cannot mark attendance for a future date.');
        }

        if ($schedule && $attendanceDate->isToday()) {
            $scheduledStart = Carbon::parse($request->date . ' ' . $schedule->start_time);
            if (now()->lt($scheduledStart)) {
                return redirect()->back()->with(
                    'error',
                    'You can only mark attendance at or after the scheduled start time (' . $scheduledStart->format('M d, Y h:i A') . ').'
                );
            }
        }

        DB::transaction(function () use ($request) {
            foreach ($request->attendance as $studentNumber => $status) {
                Attendance::updateOrCreate(
                    [
                        'section_id'    => $request->section_id,
                        'studentNumber' => $studentNumber,
                        'date'          => $request->date,
                    ],
                    ['status' => $status]
                );
            }
        });

        return redirect()->to(
            route('teacher.grades.index', ['section' => $request->section_id, 'date' => $request->date]) . '#attendance'
        )->with('success', 'Attendance updated!');
    }

    /**
     * Store Scores
     */
   /**
     * Store Scores
     *
     * After saving, redirects back to the grade-encoding tab (#scores) while
     * preserving the active section, subject, quarter, and grading item so the
     * teacher lands exactly where they left off instead of being dropped on the
     * default grading-schema tab.
     */
    public function storeScores(Request $request)
    {
        $request->validate([
            'grading_item_id' => 'required|exists:grading_items,id',
            'scores'          => 'required|array',
            // Hidden fields added to the blade form for redirect context
            'section_id'      => 'nullable|exists:sections,section_id',
            'subject_id'      => 'nullable',
            'quarter'         => 'nullable|string',
        ]);

        $gradingItem = GradingItem::findOrFail((int) $request->grading_item_id);
        if (!$this->isQuarterActive((string) $gradingItem->quarter)) {
            return redirect()->back()->with('error', 'Quarter is not active. Encoding is locked by the admin.');
        }

        foreach ($request->scores as $enrollmentId => $rawScore) {
            if ($rawScore !== null && $rawScore !== '') {
                StudentGrade::updateOrCreate(
                    [
                        'enrollment_id'   => $enrollmentId,
                        'grading_item_id' => $request->grading_item_id,
                    ],
                    ['raw_score' => $rawScore]
                );
            }
        }

        // Build the redirect URL back to the exact state the teacher was on.
        // '#encoding' matches the tab key defined in the index blade's $tabs array,
        // which Alpine reads via window.location.hash on load.
        $redirectUrl = route('teacher.grades.index', array_filter([
            'section'         => $request->section_id,
            'subject'         => $request->subject_id,
            'quarter'         => $request->quarter,
            'grading_item_id' => $request->grading_item_id,
        ])) . '#encoding';

        return redirect()->to($redirectUrl)->with('success', 'Scores recorded!');
    }
    /**
     * Store Grading Category (Component / Schema)
     * Auto-applied to ALL subjects in the section.
     */
    public function store(Request $request)
    {
        $request->validate([
            'category'           => 'required|string',
            'percentage'         => 'required|numeric|min:1|max:100',
            'section_id'         => 'required|exists:sections,section_id',
            'subject'            => 'required|string',
            'calculation_method' => 'nullable|in:average',
        ]);

        $words = explode(' ', $request->category);
        $code  = count($words) > 1
            ? strtoupper(substr($words[0], 0, 1) . substr($words[1], 0, 1))
            : strtoupper(substr($request->category, 0, 2));

        $currentTotal = GradingComponent::where('section_id', $request->section_id)
            ->where('subject', $request->subject)
            ->where('category', '!=', $request->category)
            ->sum('percentage');

        if (($currentTotal + $request->percentage) > 100) {
            return redirect()->back()
                ->with('error', 'Limit exceeded! Total weight for this subject cannot surpass 100%.');
        }

        $allSubjects = $this->getSectionSubjectNames($request->section_id);

        $skipped = 0;

        foreach ($allSubjects as $subjectName) {
            $cleanSubject  = trim($subjectName);
            $cleanCategory = trim($request->category);

            $subjectTotal = GradingComponent::where('section_id', $request->section_id)
                ->where('subject', $cleanSubject)
                ->where('category', '!=', $cleanCategory)
                ->sum('percentage');

            if (($subjectTotal + $request->percentage) > 100) {
                $skipped++;
                continue;
            }

            GradingComponent::updateOrCreate(
                [
                    'section_id' => (int) $request->section_id,
                    'subject'    => $cleanSubject,
                    'category'   => $cleanCategory,
                ],
                [
                    'code'               => $code,
                    'percentage'         => $request->percentage,
                    'calculation_method' => 'average',
                ]
            );
        }

        $message = 'Grading category saved for all subjects!';
        if ($skipped > 0) {
            $message .= " ({$skipped} subject(s) skipped — adding this category would exceed 100% weight for those subjects.)";
        }

        return redirect()->back()->with('success', $message);
    }

    /**
     * Store Grading Item (Activity / Assessment) — strictly per-subject.
     */
    public function storeItem(Request $request)
    {
        $request->validate([
            'section_id'   => 'required|exists:sections,section_id',
            'component_id' => 'required|exists:grading_components,id',
            'subject'      => 'required|string',
            'item_name'    => 'required|string',
            'max_score'    => 'required|numeric|min:1',
            'quarter'      => 'required|string|in:q1_grade,q2_grade,q3_grade,q4_grade',
        ]);

        if (!$this->isQuarterActive((string) $request->quarter)) {
            return redirect()->back()->with('error', 'Quarter is not active. Activity creation is locked by the admin.');
        }

        $component = GradingComponent::where('id', $request->component_id)
            ->where('section_id', $request->section_id)
            ->where('subject', $request->subject)
            ->first();

        if (!$component) {
            return redirect()->back()->with(
                'error',
                'The selected grading category does not belong to the chosen subject. Please refresh and try again.'
            );
        }

        GradingItem::create($request->only([
            'section_id', 'component_id', 'subject', 'item_name', 'max_score', 'quarter',
        ]));

        return redirect()->back()->with('success', 'Activity added!');
    }

    public function destroy($id)
    {
        GradingComponent::findOrFail($id)->delete();
        return redirect()->back()->with('success', 'Category deleted!');
    }

    public function destroyItem($id)
    {
        GradingItem::findOrFail($id)->delete();
        return redirect()->back()->with('success', 'Activity removed!');
    }

    /**
     * Release activities for a specific subject + quarter.
     * Kept for programmatic/API use; the UI button has been removed.
     */
    public function releaseActivities(Request $request)
    {
        $request->validate([
            'section_id' => 'required|exists:sections,section_id',
            'subject'    => 'required|string',
            'quarter'    => 'required|string|in:q1_grade,q2_grade,q3_grade,q4_grade',
        ]);

        if (!$this->isQuarterActive((string) $request->quarter)) {
            return redirect()->back()->with('error', 'Quarter is not active. Releasing activities is locked by the admin.');
        }

        GradingItem::where('section_id', $request->section_id)
            ->where('subject', $request->subject)
            ->where('quarter', $request->quarter)
            ->update(['is_released' => true]);

        return redirect()->back()->with('success', 'Activities released successfully!');
    }

    /**
     * Release final grades for a section.
     *
     * FIX: Also marks ALL grading items for this section + quarter as released
     * so that students can see their individual scores and computed grades.
     * The "Release Activities" UI has been removed — this is the single release action.
     */
    public function releaseFinalGrades(Request $request)
    {
        $request->validate([
            'section_id' => 'required|exists:sections,section_id',
            'quarter'    => 'required|string|in:q1_grade,q2_grade,q3_grade,q4_grade',
        ]);

        if (!$this->isQuarterActive((string) $request->quarter)) {
            return redirect()->back()->with('error', 'Quarter is not active. Releasing final grades is locked by the admin.');
        }

        // Mark every grading item for this section + quarter as released
        // so student-side visibility and grade computation both work correctly.
        GradingItem::where('section_id', $request->section_id)
            ->where('quarter', $request->quarter)
            ->update(['is_released' => true]);

        // Mark all ClassRecord rows (per-subject and overall) as released
        ClassRecord::where('section_id', $request->section_id)
            ->update(['is_released_final' => true]);

        return redirect()->back()->with('success', 'Final grades released to students!');
    }
}