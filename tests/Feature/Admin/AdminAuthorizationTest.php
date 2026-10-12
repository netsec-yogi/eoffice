<?php

namespace Tests\Feature\Admin;

use App\Modules\Identity\Models\Department;
use App\Modules\Identity\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** IAM-005, ADMIN-001: permissions are enforced on the backend and admin actions are audited. */
class AdminAuthorizationTest extends TestCase
{
    public static function adminPages(): array
    {
        return [['/admin/users'], ['/admin/organisation'], ['/admin/authorities'], ['/admin/committees'], ['/admin/roles'], ['/admin/audit'], ['/admin/calendars'], ['/admin/notifications']];
    }

    #[DataProvider('adminPages')]
    public function test_ordinary_users_cannot_open_admin_pages(string $url): void
    {
        $this->actingAs($this->userWithRole('employee'))->get($url)->assertForbidden();
    }

    #[DataProvider('adminPages')]
    public function test_system_admin_can_open_admin_pages(string $url): void
    {
        $this->actingAs($this->userWithRole('system_admin'))->get($url)->assertOk();
    }

    public function test_admin_access_alone_does_not_grant_each_section(): void
    {
        $auditor = $this->userWithRole('auditor');
        $this->actingAs($auditor)->get('/admin/audit')->assertOk();
        $this->actingAs($auditor)->get('/admin/roles')->assertForbidden();
        $this->actingAs($auditor)->post('/admin/departments', ['code' => 'X', 'name' => 'X', 'type' => 'academic'])->assertForbidden();
    }

    public function test_hidden_endpoints_are_still_protected(): void
    {
        $user = $this->userWithRole('employee');
        $target = User::factory()->create()->fresh();

        $this->actingAs($user)->put("/admin/users/{$target->id}/roles", ['roles' => ['system_admin']])->assertForbidden();
        $this->assertFalse($target->fresh()->hasRole('system_admin'));
    }

    public function test_role_changes_are_audited_and_self_change_is_refused(): void
    {
        $admin = $this->userWithRole('system_admin');
        $target = User::factory()->create()->fresh();

        $this->actingAs($admin)->put("/admin/users/{$target->id}/roles", ['roles' => ['auditor']])->assertSessionHas('success');
        $this->assertTrue($target->fresh()->hasRole('auditor'));
        $this->assertDatabaseHas('audit_events', ['action' => 'admin.user.roles_changed', 'subject_id' => $target->id, 'actor_id' => $admin->id]);

        $this->actingAs($admin)->put("/admin/users/{$admin->id}/roles", ['roles' => []])->assertSessionHas('error');
        $this->assertTrue($admin->fresh()->hasRole('system_admin'));
    }

    public function test_only_system_admin_can_grant_system_admin(): void
    {
        $roleManager = $this->userWithPermissions('admin.access', 'roles.manage');
        $target = User::factory()->create()->fresh();

        $this->actingAs($roleManager)->put("/admin/users/{$target->id}/roles", ['roles' => ['system_admin']])->assertForbidden();
        $this->assertFalse($target->fresh()->hasRole('system_admin'));
    }

    public function test_department_admin_sees_only_their_department(): void
    {
        $cse = Department::create(['code' => 'CSE', 'name' => 'CSE', 'type' => 'academic']);
        $ece = Department::create(['code' => 'ECE', 'name' => 'ECE', 'type' => 'academic']);
        $deptAdmin = $this->userWithRole('department_admin', ['department_id' => $cse->id]);
        $inside = User::factory()->create(['department_id' => $cse->id])->fresh();
        $outside = User::factory()->create(['department_id' => $ece->id]);

        $this->actingAs($deptAdmin)->get('/admin/users')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('users.total', 2));
        $this->actingAs($deptAdmin)->get("/admin/users/{$inside->id}")->assertOk();
        $this->actingAs($deptAdmin)->get("/admin/users/{$outside->id}")->assertForbidden();
        $this->actingAs($deptAdmin)->put("/admin/users/{$inside->id}", ['category' => 'staff', 'is_active' => false])->assertForbidden();
    }

    public function test_organisation_changes_are_audited(): void
    {
        $admin = $this->userWithRole('system_admin');

        $this->actingAs($admin)->post('/admin/departments', ['code' => 'CSE', 'name' => 'Computer Science', 'type' => 'academic'])->assertSessionHas('success');
        $dept = Department::query()->where('code', 'CSE')->sole();
        $this->actingAs($admin)->put("/admin/departments/{$dept->id}", ['code' => 'CSE', 'name' => 'Computer Science & Engg', 'type' => 'academic', 'parent_id' => $dept->id])
            ->assertSessionHasErrors('parent_id');

        $this->assertDatabaseHas('audit_events', ['action' => 'admin.department.created', 'subject_id' => $dept->id]);
    }

    public function test_reporting_cycles_are_refused(): void
    {
        $admin = $this->userWithRole('system_admin');
        $a = User::factory()->create();
        $b = User::factory()->create(['reports_to_id' => $a->id]);

        $this->actingAs($admin)->put("/admin/users/{$a->id}", ['category' => 'staff', 'is_active' => true, 'reports_to_id' => $b->id])
            ->assertSessionHasErrors('reports_to_id');
    }

    public function test_local_accounts_need_a_strong_password_and_are_audited(): void
    {
        $admin = $this->userWithRole('system_admin');

        $this->actingAs($admin)->post('/admin/users/local', ['username' => 'acme', 'name' => 'Acme Ltd', 'email' => 'acme@example.test', 'category' => 'vendor', 'password' => 'short'])
            ->assertSessionHasErrors('password');

        $this->actingAs($admin)->post('/admin/users/local', ['username' => 'acme', 'name' => 'Acme Ltd', 'email' => 'acme@example.test', 'category' => 'vendor', 'password' => 'Vendor-Strong-Pass-92x'])
            ->assertRedirect();
        $vendor = User::query()->where('username', 'acme')->sole();
        $this->assertSame('local', $vendor->auth_source);
        $this->assertTrue($vendor->hasRole('vendor'));
        $this->assertDatabaseHas('audit_events', ['action' => 'admin.user.local_created', 'subject_id' => $vendor->id]);
    }
}
