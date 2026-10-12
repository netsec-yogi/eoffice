<?php

namespace App\Http\Middleware;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Permissions;
use App\Support\NavigationCounts;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();

        return [
            ...parent::share($request),
            'appName' => config('app.name'),
            'auth' => [
                'user' => $user ? fn () => $this->userPayload($user) : null,
            ],
            'counts' => $user ? fn () => app(NavigationCounts::class)->for($user) : null,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'warning' => fn () => $request->session()->get('warning'),
                'error' => fn () => $request->session()->get('error'),
            ],
            // Shared once per full page load; the client keeps it.
            'ziggy' => Inertia::once(fn () => (new Ziggy)->toArray()),
        ];
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user): array
    {
        $user->loadMissing('department:id,code,name', 'designation:id,name');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'category' => $user->category,
            'department' => $user->department?->only('id', 'code', 'name'),
            'designation' => $user->designation?->name,
            'roles' => $user->getRoleNames()->values(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
            'can_admin' => $user->can(Permissions::ADMIN_ACCESS),
        ];
    }
}
