<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Document;

class DocumentController extends Controller
{
    // Show all documents with optional type filter and stats
    public function index(Request $request)
    {
        $query = Document::query();

        // Filter by type if selected
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        // Paginate documents
        $documents = $query->orderBy('id', 'desc')->paginate(10)->withQueryString();

        // Get unique types for dropdown
        $types = Document::select('type')->distinct()->orderBy('type')->pluck('type');

        // Stats
        $statsQuery = Document::query();
        if ($request->filled('type')) {
            $statsQuery->where('type', $request->type);
        }

       // app/Http/Controllers/Admin/DocumentController.php

        $totalPending = (clone $statsQuery)->where('status', 'pending')->count();
        $totalApproved = (clone $statsQuery)->where('status', 'approved')->count();
        $totalRejected = (clone $statsQuery)->where('status', 'rejected')->count();
        $totalDocuments = (clone $statsQuery)->count();

        return view('admin.documents.index', compact(
            'documents',
            'types',
            'totalPending',
            'totalApproved',
            'totalRejected',
            'totalDocuments'
        ));
    }

    // Show form to create document
    public function create()
    {
        return view('admin.documents.create');
    }

    // Store new document
    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'required|string',
            'status' => 'nullable|string|in:pending,approved,rejected',
        ]);

        Document::create($request->all());

        return redirect()->route('admin.documents.index')->with('success', 'Document created successfully!');
    }

    // Show form to edit document
    public function edit(Document $document)
    {
        return view('admin.documents.edit', compact('document'));
    }

    // Update document
    public function update(Request $request, Document $document)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'required|string',
            'status' => 'nullable|string|in:pending,approved,rejected',
        ]);

        $document->update($request->all());

        return redirect()->route('admin.documents.index')->with('success', 'Document updated successfully!');
    }

    // Approve document
    public function approve(Document $document)
    {
        $document->update(['approval_status' => 'approved']);
        return redirect()->route('admin.documents.index')->with('success', 'Document approved!');
    }

    // Reject document
    public function reject(Document $document)
    {
        $document->update(['approval_status' => 'rejected']);
        return redirect()->route('admin.documents.index')->with('success', 'Document rejected!');
    }

    // Delete document
    public function destroy(Document $document)
    {
        $document->delete();
        return redirect()->route('admin.documents.index')->with('success', 'Document deleted successfully!');
    }
}
