<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LawNotificationController extends Controller
{
    public function index(Request $request)
    {
        $companyId = (string) $request->attributes->get('active_company_id');
        $notifications = DB::table('law_notifications')->where('company_id', $companyId)->where('user_id', $request->user()->id)
            ->whereNull('resolved_at')->latest('created_at')->limit(20)->get();
        return response()->json([
            'unread_count' => $notifications->whereNull('read_at')->count(),
            'notifications' => $notifications->map(function (object $item): array {
                return [...(array) $item, 'payload' => json_decode((string) $item->payload, true) ?: []];
            })->values(),
        ]);
    }

    public function read(Request $request, string $notification)
    {
        $updated = DB::table('law_notifications')->where('id', $notification)
            ->where('company_id', $request->attributes->get('active_company_id'))->where('user_id', $request->user()->id)
            ->whereNull('resolved_at')->update(['read_at' => now(), 'updated_at' => now()]);
        abort_unless($updated, 404, 'Notificação não encontrada.');
        return response()->noContent();
    }
}
