<?php

namespace App\Http\Controllers;

use App\Modules\OfficialDocuments\Http\OfficialDocumentController;
use App\Modules\OfficialDocuments\Services\OfficialDocumentAccess;
use App\Support\DashboardStats;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('Dashboard', [
            'stats' => app(DashboardStats::class)->for($request->user()),
            'officialDocuments' => app(OfficialDocumentAccess::class)->listable($request->user())
                ->whereHas('instance', fn ($q) => $q->where('initiator_id', $request->user()->id))
                ->with(['instance.definition:id,name', 'output.template:id,name', 'templateVersion:id,version'])->latest('id')->limit(5)->get()
                ->map(fn ($d) => OfficialDocumentController::row($d, app(OfficialDocumentAccess::class)->canDownload($request->user(), $d), true)),
        ]);
    }
}
