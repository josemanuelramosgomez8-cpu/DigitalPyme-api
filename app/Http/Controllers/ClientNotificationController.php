<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ClientNotificationController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            $request->user()->notifications()->latest()->limit(50)->get(),
        );
    }

    public function markRead(Request $request, string $notification)
    {
        $record = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $record->markAsRead();

        return response()->json(['mensaje' => 'Notificación marcada como leída.']);
    }
}
