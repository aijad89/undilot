<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * The participant's inbox: draw reminders and in-draw notices (AddedToDraw,
 * RemovedFromDraw).
 */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $notifications = $request->user()->notifications()->latest()->paginate(15);

        return view('notifications.index', compact('notifications'));
    }

    public function read(Request $request, string $id)
    {
        $request->user()->notifications()->findOrFail($id)->markAsRead();

        return back();
    }

    /**
     * One statement. `unreadNotifications()` WITH the parentheses is a relation:
     * one UPDATE carrying the notifiable constraint and updated_at. Without them
     * it loads every row and saves each.
     */
    public function readAll(Request $request)
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }

}
