<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Tuition;

class TuitionController extends Controller
{
    // Display all tuition records (paginated)
    public function index()
    {
        $tuitions = Tuition::latest()->paginate(10);
    
        $statuses = Tuition::$statusOptions;
        $paymentMethods = Tuition::$paymentMethodOptions;
        $approvalStatuses = Tuition::$approvalStatusOptions;
    
        return view('admin.tuitions.index', compact(
            'tuitions',
            'statuses',
            'paymentMethods',
            'approvalStatuses'
        ));
    }
    
    // Show form to create new tuition
    public function create()
    {
        
        return view('admin.tuitions.create');
    }

    // Store new tuition record
    public function store(Request $request)
    {
        $request->validate([
            'student_name'    => 'required|string|max:255',
            'amount'          => 'required|numeric',
            'status'          => 'required|string|in:paid,partial,unpaid',
            'payment_method'  => 'required|string|in:gcash,bank_transfer,cash',
            'approval_status' => 'required|string|in:pending,approved,rejected',
        ]);

        Tuition::create($request->only([
            'student_name', 'amount', 'status', 'payment_method', 'approval_status'
        ]));

        return redirect()->route('admin.tuitions.index')
                         ->with('success', 'Tuition record saved successfully.');
    }

    // Show form to edit tuition
    public function edit($id)
    {
        $tuition = Tuition::findOrFail($id);
        return view('admin.tuitions.edit', compact('tuition'));
    }

    // Update tuition record
    public function update(Request $request, $id)
    {
        $tuition = Tuition::findOrFail($id);

        $request->validate([
            'student_name'    => 'required|string|max:255',
            'amount'          => 'required|numeric',
            'status'          => 'required|string|in:paid,partial,unpaid',
            'payment_method'  => 'required|string|in:gcash,bank_transfer,cash',
            'approval_status' => 'required|string|in:pending,approved,rejected',
        ]);

        $tuition->update($request->only([
            'student_name', 'amount', 'status', 'payment_method', 'approval_status'
        ]));

        return redirect()->route('admin.tuitions.index')
                         ->with('success', 'Tuition record updated successfully.');
    }

    // Delete tuition record
    public function destroy($id)
    {
        $tuition = Tuition::findOrFail($id);
        $tuition->delete();

        return redirect()->route('admin.tuitions.index')
                         ->with('success', 'Tuition record deleted successfully.');
    }

    // Approve tuition
    public function approve(Tuition $tuition)
    {
        $tuition->update(['approval_status' => 'approved']);

        return redirect()->back()->with('success', 'Tuition approved.');
    }

    // Reject tuition
    public function reject(Tuition $tuition)
    {
        $tuition->update(['approval_status' => 'rejected']);

        return redirect()->back()->with('success', 'Tuition rejected.');
    }
}
