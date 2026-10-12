<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Models\Department;
use App\Modules\Identity\Models\User;
use Tests\TestCase;

/** AUTH-003, AUTH-007 */
class DirectorySyncTest extends TestCase
{
    public function test_sync_creates_and_updates_users_and_maps_known_departments(): void
    {
        $cse = Department::create(['code' => 'CSE', 'name' => 'Computer Science', 'type' => 'academic']);
        $this->directory->add('asharma', overrides: ['departmentCode' => 'CSE', 'category' => 'faculty']);
        $this->directory->add('ghost', overrides: ['departmentCode' => 'NOPE']);

        $this->artisan('directory:sync')->assertSuccessful();

        $this->assertSame($cse->id, User::query()->where('username', 'asharma')->value('department_id'));
        $this->assertNull(User::query()->where('username', 'ghost')->value('department_id'), 'unknown codes are not invented');

        $this->directory->add('asharma', overrides: ['name' => 'Anita S. Sharma', 'departmentCode' => 'CSE']);
        $this->artisan('directory:sync')->assertSuccessful();
        $this->assertSame('Anita S. Sharma', User::query()->where('username', 'asharma')->value('name'));
    }

    public function test_users_missing_from_the_directory_are_disabled_not_deleted(): void
    {
        foreach (range(1, 20) as $i) {
            $this->directory->add("user{$i}");
        }
        $this->artisan('directory:sync')->assertSuccessful();

        unset($this->directory->entries['user7']);
        $this->artisan('directory:sync')->assertSuccessful();

        $gone = User::query()->where('username', 'user7')->sole();
        $this->assertTrue($gone->directory_disabled);
        $this->assertFalse($gone->canSignIn());
        $this->assertSame(19, User::query()->where('directory_disabled', false)->count());
    }

    public function test_sync_refuses_to_disable_a_large_share_of_users(): void
    {
        foreach (range(1, 10) as $i) {
            $this->directory->add("user{$i}");
        }
        $this->artisan('directory:sync')->assertSuccessful();
        $this->directory->entries = array_slice($this->directory->entries, 0, 2, true);

        $this->artisan('directory:sync')->assertFailed();
        $this->assertSame(0, User::query()->where('directory_disabled', true)->count());
    }

    public function test_sync_fails_cleanly_when_the_directory_is_down(): void
    {
        $this->directory->down = true;
        $this->artisan('directory:sync')->assertFailed();
        $this->assertDatabaseHas('audit_events', ['action' => 'identity.directory_sync', 'outcome' => 'failure']);
    }
}
