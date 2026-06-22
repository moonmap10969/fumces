<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\ClassRecord;
use App\Models\Enrollment;
use App\Models\GradingComponent;
use App\Models\GradingItem;
use App\Models\StudentGrade;
use App\Models\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class GradeController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // QUARTERS MAP: internal key => display label
    // ─────────────────────────────────────────────────────────────────────────
    private const QUARTERS = [
        'q1_grade' => '1st Quarter',
        'q2_grade' => '2nd Quarter',
        'q3_grade' => '3rd Quarter',
        'q4_grade' => '4th Quarter',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // DEPED TRANSMUTATION TABLE
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Convert an Initial Grade (weighted percentage 0–100) to a
     * Quarterly Grade using the official DepEd transmutation table.
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
    // INDEX
    // ─────────────────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $selectedQuarter = $request->query('quarter', 'all');
        if (!in_array($selectedQuarter, array_merge(['all'], array_keys(self::QUARTERS)))) {
            $selectedQuarter = 'all';
        }

        $user       = Auth::user();
        $admission  = Admission::where('user_id', $user->id)->first();
        $enrollment = $admission
            ? Enrollment::where('studentNumber', $admission->studentNumber)->latest('id')->first()
            : null;

        if (!$enrollment) {
            return view('student.grades.index', [
                'overallAverage'    => null,
                'subjectsTaken'     => 0,
                'completedUnits'    => 0,
                'awardsCount'       => 0,
                'subjectRows'       => collect(),
                'gradeDistribution' => collect(),
                'selectedQuarter'   => $selectedQuarter,
                'quarters'          => self::QUARTERS,
            ]);
        }

        $sectionId = $enrollment->section_id;

        // ── Resolve grade level ───────────────────────────────────────────
        $rawYearLevel      = $user->year_level ?: $enrollment->year_level;
        $normalizedLevel   = strtolower(str_replace(' ', '', (string) $rawYearLevel));
        $subjectGradeLevel = preg_match('/^grade(\d+)$/', $normalizedLevel, $m)
            ? 'Grade ' . $m[1]
            : ucwords(trim((string) $rawYearLevel));

        $subjects = \App\Models\Subject::whereRaw(
            'LOWER(REPLACE(grade_level, " ", "")) = ?',
            [strtolower(str_replace(' ', '', $subjectGradeLevel))]
        )->where('is_active', true)->get();

        $subjectNames = $subjects->pluck('name')->all();
        $quarterKeys  = array_keys(self::QUARTERS);

        // ── Pre-fetch: which subjects have final grades released ──────────
        // FIX: A released final grade means the student can see all quarter data
        // for that subject, regardless of individual item release status.
        $releasedFinalSubjects = ClassRecord::where('section_id', $sectionId)
            ->where('studentNumber', $enrollment->id)
            ->where('is_released_final', true)
            ->whereNotNull('subject')
            ->pluck('subject')
            ->flip(); // O(1) lookup: subject => index

        // ── Pre-fetch: which quarter+subject combos have released items ───
        $releasedItemsBySubject = GradingItem::where('section_id', $sectionId)
            ->whereIn('subject', $subjectNames)
            ->whereIn('quarter', $quarterKeys)
            ->where('is_released', true)
            ->get()
            ->groupBy('subject');

        // ── Pre-fetch: grading components ─────────────────────────────────
        $componentsBySubject = GradingComponent::where('section_id', $sectionId)
            ->whereIn('subject', $subjectNames)
            ->get()
            ->groupBy('subject');

        // ── Pre-fetch: ALL grading items that the student is entitled to see.
        // FIX: When a subject has is_released_final = true, include ALL items
        // for that subject so the grade computation has real data to work with,
        // even if individual items were never separately marked as released.
        $releasedFinalSubjectNames = $releasedFinalSubjects->keys()->all();

        $allReleasedItems = GradingItem::where('section_id', $sectionId)
            ->whereIn('subject', $subjectNames)
            ->whereIn('quarter', $quarterKeys)
            ->where(function ($query) use ($releasedFinalSubjectNames) {
                $query->where('is_released', true)
                      ->orWhereIn('subject', $releasedFinalSubjectNames);
            })
            ->get();

        // ── Pre-fetch: student's raw scores for those items ───────────────
        $studentGradesByItemId = StudentGrade::where('enrollment_id', $enrollment->id)
            ->whereIn('grading_item_id', $allReleasedItems->pluck('id')->all())
            ->get()
            ->keyBy('grading_item_id');

        // ── Pre-fetch: teacher names ───────────────────────────────────────
        $teacherBySubject = Schedule::where('section_id', $sectionId)
            ->whereIn('subject', $subjectNames)
            ->pluck('teacher', 'subject');

        // ─────────────────────────────────────────────────────────────────
        // Build per-subject rows
        // ─────────────────────────────────────────────────────────────────
        $subjectRows = $subjects->map(function ($subject) use (
            $enrollment, $sectionId, $quarterKeys,
            $releasedFinalSubjects, $releasedItemsBySubject,
            $componentsBySubject, $allReleasedItems,
            $studentGradesByItemId, $teacherBySubject
        ) {
            $name              = $subject->name;
            $isFinalReleased   = isset($releasedFinalSubjects[$name]);
            $subjectComponents = $componentsBySubject->get($name, collect());
            $subjectRelItems   = $releasedItemsBySubject->get($name, collect());

            $quarterInitialGrades   = [];
            $quarterTransmutedGrades = [];

            foreach ($quarterKeys as $qtr) {
                // Visible if: teacher released items for this qtr+subject
                // OR teacher released final grades for this section+subject.
                $qtrItemsReleased = $subjectRelItems->where('quarter', $qtr);
                $visible = $qtrItemsReleased->isNotEmpty() || $isFinalReleased;

                if (!$visible) {
                    $quarterInitialGrades[$qtr]    = null;
                    $quarterTransmutedGrades[$qtr] = null;
                    continue;
                }

                // Use all items the student is entitled to see for this quarter.
                $qtrItems = $allReleasedItems
                    ->where('subject', $name)
                    ->where('quarter', $qtr);

                // Compute Initial Grade (0–100 weighted percentage)
                $initialGrade = $this->computeQuarterGrade(
                    $subjectComponents,
                    $qtrItems,
                    $studentGradesByItemId
                );

                $quarterInitialGrades[$qtr] = $initialGrade;

                // Apply DepEd transmutation → Quarterly Grade (integer 60–100)
                $quarterTransmutedGrades[$qtr] = ($initialGrade > 0)
                    ? $this->transmute($initialGrade)
                    : null;
            }

            // DepEd final grade = average of available transmuted quarterly grades
            $availableTransmuted = collect($quarterTransmutedGrades)
                ->filter(fn ($v) => $v !== null && $v > 0);

            $final = $availableTransmuted->isNotEmpty()
                ? (int) round($availableTransmuted->avg())
                : null;

            return [
                'subject'    => $name,
                'teacher'    => $teacherBySubject->get($name, 'TBA'),
                'units'      => $subject->units ?? 1,
                // Quarterly grades are already transmuted (DepEd-correct integers)
                'q1'         => $quarterTransmutedGrades['q1_grade'],
                'q2'         => $quarterTransmutedGrades['q2_grade'],
                'q3'         => $quarterTransmutedGrades['q3_grade'],
                'q4'         => $quarterTransmutedGrades['q4_grade'],
                'final'      => $final,
                'descriptor' => $this->descriptor($final),
                'remarks'    => $final !== null ? ($final >= 75 ? 'Passed' : 'Failed') : '—',
            ];
        });

        // ─────────────────────────────────────────────────────────────────
        // Stats — respect the selected quarter filter
        // ─────────────────────────────────────────────────────────────────
        $qtrKey = match ($selectedQuarter) {
            'q1_grade' => 'q1',
            'q2_grade' => 'q2',
            'q3_grade' => 'q3',
            'q4_grade' => 'q4',
            default    => null,
        };

        $gradesForStats = $qtrKey
            ? $subjectRows->pluck($qtrKey)->filter(fn ($v) => $v !== null && $v > 0)
            : $subjectRows->pluck('final')->filter(fn ($v) => $v !== null && $v > 0);

        $overallAverage  = $gradesForStats->isNotEmpty() ? round($gradesForStats->avg(), 2) : null;
        $subjectsTaken   = $subjectRows->count();
        $completedUnits  = $subjectRows->where('final', '!=', null)->sum('units');
        $awardsCount     = ($overallAverage !== null && $overallAverage >= 90) ? 1 : 0;

        $gradeDistribution = $subjectRows
            ->filter(fn ($r) => $r['descriptor'] !== 'N/A')
            ->groupBy('descriptor')
            ->map(fn ($g) => $g->count())
            ->sortByDesc(fn ($v) => $v);

        return view('student.grades.index', compact(
            'overallAverage', 'subjectsTaken', 'completedUnits',
            'awardsCount', 'subjectRows', 'gradeDistribution',
            'selectedQuarter'
        ) + ['quarters' => self::QUARTERS]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GRADE COMPUTATION
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Compute the weighted percentage (Initial Grade, 0–100) for one subject+quarter.
     *
     * This mirrors the teacher controller's formula exactly.
     * Transmutation is applied by the caller.
     */
    private function computeQuarterGrade(
        $components,
        $qtrItems,
        $studentGradesByItemId
    ): float {
        if ($components->isEmpty() || $qtrItems->isEmpty()) {
            return 0.0;
        }

        $weightedSum = 0.0;
        $weightUsed  = 0.0;

        foreach ($components as $component) {
            $weight    = (float) $component->percentage;
            $compItems = $qtrItems->where('component_id', $component->id);

            if ($compItems->isEmpty()) {
                continue; // exclude from weight pool — same as teacher logic
            }

            $max    = (float) $compItems->sum('max_score');
            $earned = 0.0;

            foreach ($compItems->pluck('id') as $itemId) {
                $sg = $studentGradesByItemId->get($itemId);
                if ($sg) {
                    $earned += (float) $sg->raw_score;
                }
            }

            $ratio        = $max > 0 ? min($earned / $max, 1.0) : 0.0;
            $weightedSum += $ratio * $weight;
            $weightUsed  += $weight;
        }

        if ($weightUsed <= 0) {
            return 0.0;
        }

        $normalised = ($weightedSum / $weightUsed) * 100;

        return round(min(max($normalised, 0.0), 100.0), 2);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * DepEd K-12 grade descriptor scale.
     * Accepts already-transmuted integer grades.
     */
    private function descriptor(?float $grade): string
    {
        if ($grade === null || $grade <= 0) {
            return 'N/A';
        }

        return match (true) {
            $grade >= 90 => 'Outstanding',
            $grade >= 85 => 'Very Satisfactory',
            $grade >= 80 => 'Satisfactory',
            $grade >= 75 => 'Fairly Satisfactory',
            default      => 'Did Not Meet Expectations',
        };
    }
}