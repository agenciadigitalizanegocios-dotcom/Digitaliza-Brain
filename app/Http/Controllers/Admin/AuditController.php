<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

/** Registro de ações sensíveis (só admin). */
class AuditController extends Controller
{
    public function __invoke(Request $request)
    {
        $logs = AuditLog::with(['user', 'tenant'])
            ->when($request->query('acao'), fn ($q, $a) => $q->where('action', $a))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.audit', ['logs' => $logs]);
    }
}
