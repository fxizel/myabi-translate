<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Http\Request;

class AdminAuditController extends Controller
{
    public function index(Request $request)
    {
        $events = $this->query($request)->latest('id')->paginate(40)->withQueryString();
        $users = User::orderBy('name')->get(['id', 'name']);
        $actions = AuditEvent::select('action')->distinct()->orderBy('action')->pluck('action');

        return view('audit.index', compact('events', 'users', 'actions'));
    }

    public function export(Request $request)
    {
        $query = $this->query($request);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['UTC', 'User', 'Organization', 'Action', 'Entity', 'ID', 'Before', 'After', 'IP', 'Request ID'], ';', '"', '');
            foreach ($query->orderBy('id')->lazyById(500) as $event) {
                $values = [$event->created_at->toIso8601String(), $event->user?->name, $event->organization?->name,
                    $event->action, $event->entity_type, $event->entity_id,
                    json_encode($event->before_values, JSON_UNESCAPED_UNICODE), json_encode($event->after_values, JSON_UNESCAPED_UNICODE),
                    $event->ip_address, $event->request_id];
                // Spreadsheet formula neutralization applies to CSV reports, never to DEVCONF exports.
                $values = array_map(fn ($v) => preg_match('/^[=+@\-\t\r]/', (string) $v) ? "'".$v : $v, $values);
                fputcsv($out, $values, ';', '"', '');
            }
            fclose($out);
        }, 'audit-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function query(Request $request)
    {
        $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'user_id' => ['nullable', 'integer'], 'action' => ['nullable', 'string', 'max:100'],
            'entity' => ['nullable', 'string', 'max:255'],
        ]);
        $query = AuditEvent::with(['user', 'organization']);
        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->date('from', 'Y-m-d', 'Europe/Zurich')->startOfDay()->utc());
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->date('to', 'Y-m-d', 'Europe/Zurich')->endOfDay()->utc());
        }
        foreach (['user_id', 'action'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }
        if ($request->filled('entity')) {
            $query->where('entity_type', $request->string('entity')->toString());
        }

        return $query;
    }
}
