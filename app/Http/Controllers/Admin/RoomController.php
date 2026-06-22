<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Room;

class RoomController extends Controller
{
    public function index()
    {
        $rooms = Room::orderBy('name')->get();
        return view('admin.rooms.index', compact('rooms'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'     => 'required|string|max:255|unique:rooms,name',
            'capacity' => 'required|integer|min:1',
        ]);

        Room::create([
            'name'      => $request->name,
            'capacity'  => $request->capacity,
            'is_active' => true,
        ]);

        return redirect()->back()->with('success', 'Room added successfully.');
    }

    public function update(Request $request, Room $room)
    {
        $request->validate([
            'name'      => 'required|string|max:255|unique:rooms,name,' . $room->id,
            'capacity'  => 'required|integer|min:1',
            'is_active' => 'boolean',
        ]);

        $room->update([
            'name'      => $request->name,
            'capacity'  => $request->capacity,
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->back()->with('success', 'Room updated successfully.');
    }

    public function destroy(Room $room)
    {
        $room->delete();
        return redirect()->back()->with('success', 'Room deleted.');
    }
}