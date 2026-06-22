<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\User;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;

class DashboardController extends Controller
{
public function index()
{
    $totalAdmissions = Admission::count();
    $totalUsers = User::count();
    $activeYear = AcademicYear::where('is_current', true)->first();
    
    // Replace tuition placeholder with actual enrollment count
    $totalEnrollments = Enrollment::count(); 

    return view('admin.index', compact(
        'totalAdmissions',
        'totalUsers',
        'activeYear',
        'totalEnrollments'
    ));
}

    public function economics()
    {
        // 1. Restored your exact raw queries for the dashboard charts
        $incomeStats = Admission::selectRaw('household_income, count(*) as count')
            ->whereNotNull('household_income')
            ->groupBy('household_income')
            ->pluck('count', 'household_income');

        $employmentStats = Admission::selectRaw('employment_status, count(*) as count')
            ->whereNotNull('employment_status')
            ->groupBy('employment_status')
            ->pluck('count', 'employment_status');

        $avgHouseholdSize = Admission::avg('household_size');

        // 2. Machine Learning: K-Means Clustering
        $admissions = Admission::all();
        
        // Prepare data payload for Python
        $features = $admissions->map(function($s) {
            return [
                'id' => $s->id,
                'household_income' => $s->household_income,
                'household_size' => $s->household_size,
                'employment_status' => $s->employment_status
            ];
        });

        // Try executing the Python script first
        $scriptPath = base_path('scripts/socioeconomic_clustering.py');
        $process = Process::run(['python', $scriptPath, json_encode($features)]);
        
        $outputData = json_decode($process->output(), true);

        // Check if Python succeeded
        if ($process->successful() && !empty($outputData) && !isset($outputData['error'])) {
            
            $mlPredictions = collect($outputData);
            
            $highNeedIds = $mlPredictions->where('risk_tier', 'high_need')->pluck('id');
            $highNeedStudents = $admissions->whereIn('id', $highNeedIds)->values();
            $moderateNeedCount = $mlPredictions->where('risk_tier', 'moderate_need')->count();
            $lowNeedCount = $mlPredictions->where('risk_tier', 'low_need')->count();
            
        } else {
            // FALLBACK: Use your original PHP implementation if Python fails
            $dataset = [];
            $incomeMap = ['below_25k' => 1, '25k_to_50k' => 2, '50k_to_100k' => 3, 'above_100k' => 4];
            $empMap = ['unemployed' => 1, 'employed_part' => 2, 'self_employed' => 3, 'retired' => 3, 'employed_full' => 4];

            foreach ($admissions as $admission) {
                $incomeVal = $incomeMap[$admission->household_income] ?? 2.5;
                $empVal = $empMap[$admission->employment_status] ?? 2.5;
                $sizeVal = (int) $admission->household_size;
                
                if ($sizeVal > 0) {
                    $dataset[] = [
                        'model' => $admission,
                        'vector' => [$incomeVal, $empVal, $sizeVal]
                    ];
                }
            }

            list($highNeedStudents, $moderateNeedStudents, $lowNeedStudents) = $this->performKMeans($dataset, 3);
            
            $moderateNeedCount = count($moderateNeedStudents);
            $lowNeedCount = count($lowNeedStudents);
        }

        $highNeedCount = count($highNeedStudents);

        // Restored your exact view path and variables
        return view('admin.socioeconomics', compact(
            'incomeStats', 
            'employmentStats', 
            'avgHouseholdSize',
            'highNeedCount',
            'moderateNeedCount',
            'lowNeedCount',
            'highNeedStudents'
        ));
    }

    // Restored your original PHP method as a reliable fallback
    private function performKMeans($data, $k = 3, $maxIterations = 15)
    {
        if (count($data) < $k) return [[], [], []];

        $vectors = array_column($data, 'vector');
        $centroids = array_slice($vectors, 0, $k);
        $clusters = [];

        for ($i = 0; $i < $maxIterations; $i++) {
            $clusters = array_fill(0, $k, []);

            foreach ($data as $item) {
                $point = $item['vector'];
                $minDist = PHP_INT_MAX;
                $clusterIndex = 0;
                
                foreach ($centroids as $index => $centroid) {
                    $dist = sqrt(pow($point[0] - $centroid[0], 2) + pow($point[1] - $centroid[1], 2) + pow($point[2] - $centroid[2], 2));
                    if ($dist < $minDist) {
                        $minDist = $dist;
                        $clusterIndex = $index;
                    }
                }
                $clusters[$clusterIndex][] = $item;
            }

            $newCentroids = [];
            foreach ($clusters as $index => $clusterItems) {
                if (count($clusterItems) > 0) {
                    $clusterVectors = array_column($clusterItems, 'vector');
                    $newCentroids[$index] = [
                        array_sum(array_column($clusterVectors, 0)) / count($clusterItems),
                        array_sum(array_column($clusterVectors, 1)) / count($clusterItems),
                        array_sum(array_column($clusterVectors, 2)) / count($clusterItems),
                    ];
                } else {
                    $newCentroids[$index] = $centroids[$index];
                }
            }

            if ($centroids === $newCentroids) break;
            $centroids = $newCentroids;
        }

        $riskScores = [];
        foreach ($centroids as $idx => $c) {
            $riskScores[$idx] = $c[0] + $c[1] - ($c[2] * 0.5); 
        }
        
        asort($riskScores);
        $sortedIndices = array_keys($riskScores);

        $high = array_column($clusters[$sortedIndices[0]] ?? [], 'model');
        $mod = array_column($clusters[$sortedIndices[1]] ?? [], 'model');
        $low = array_column($clusters[$sortedIndices[2]] ?? [], 'model');

        return [$high, $mod, $low];
    }
}