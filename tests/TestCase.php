<?php

namespace Tests;

use App\Modules\Identity\Directory\DirectoryService;
use App\Modules\Identity\Models\User;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeDirectory;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected FakeDirectory $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = new FakeDirectory;
        $this->app->instance(DirectoryService::class, $this->directory);
        $this->seed([PermissionSeeder::class, NotificationTemplateSeeder::class]);
        $this->withoutVite();
    }

    protected function userWithRole(string $role, array $attributes = []): User
    {
        return tap(User::factory()->create($attributes))->assignRole($role)->fresh();
    }

    protected function userWithPermissions(string ...$permissions): User
    {
        return tap(User::factory()->create())->givePermissionTo($permissions)->fresh();
    }
}
