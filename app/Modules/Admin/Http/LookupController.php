<?php

namespace App\Modules\Admin\Http;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Typeahead for user pickers (employee selector, holder assignment).
 * Returns only directory-public fields of active users.
 */
class LookupController extends Controller
{
    public function users(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'category' => ['nullable', 'string'],
        ]);
        $like = '%'.mb_strtolower($data['q']).'%';

        $users = User::query()->active()
            ->with(['department:id,code', 'designation:id,name'])
            ->where(fn ($q) => $q->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(username) like ?', [$like])->orWhereRaw('lower(employee_id) like ?', [$like]))
            ->when($data['category'] ?? null, fn ($q, $c) => $q->whereIn('category', explode(',', $c)))
            ->orderBy('name')->limit(15)->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'username' => $u->username,
                'department' => $u->department?->code,
                'designation' => $u->designation?->name,
                'category' => $u->category,
            ]);

        return response()->json($users);
    }
}
