<?php 
namespace App\Http\Controllers\Registrar;

use App\Http\Controllers\Controller;
use App\Models\Section;
use Illuminate\Http\Request;

class ClassListController extends Controller
{
   public function index(Request $request)
{
    $selectedYear = $request->get('academic_year');
    $academicYears = \App\Models\AcademicYear::orderBy('year_range', 'desc')->get();
    $activeYear = \App\Models\AcademicYear::where('is_current', true)->first();
    
    // Fallback to current year if none selected
    $filterId = $selectedYear ?: ($activeYear->id ?? null);

    $customOrder = ['kinder1', 'kinder2', 'kinder3', 'grade1', 'grade2', 'grade3', 'grade4', 'grade5', 'grade6', 'grade7', 'grade8', 'grade9', 'grade10'];

    $sections = Section::with(['enrollments' => function($query) use ($filterId) {
            $query->whereHas('admission', function($q) use ($filterId) {
                $q->where('academic_year_id', $filterId);
            })->with('admission');
        }])
        ->get()
        ->sortBy(fn($section) => array_search($section->year_level, $customOrder) ?? 999)
        ->groupBy('year_level');

    $yearLevels = $sections->keys();

    return view('registrar.classlist.index', compact('sections', 'yearLevels', 'academicYears', 'activeYear'));
}
    
}