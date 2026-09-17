<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use App\Models\Category;

class CategoryManagementTest extends TestCase
{
    use RefreshDatabase;
    /**
     * A basic feature test example.
     */
    public function testAdminCanCreateCategory(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/categories', [
            'name' => 'Conferences',
            'description' => 'Professional conferences',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'Conferences')
            ->assertJsonPath('data.description', 'Professional conferences');

        $this->assertDatabaseHas('categories', [
            'name' => 'Conferences',
            'description' => 'Professional conferences',
        ]);
    }

    public function testParticipantCannotCreateCategory(): void
    {
        $participant = User::factory()->create([
            'role' => UserRole::PARTICIPANT,
        ]);
        Sanctum::actingAs($participant);
        $response = $this->postJson('/api/admin/categories', [
            'name' => 'Conferences',
            'description' => 'Professional conferences',
        ]);
        $response->assertForbidden();
        $this->assertDatabaseMissing('categories', [
            'name' => 'Conferences',
            'description' => 'Professional conferences',
        ]);
    }
    public function testAdminCannotCreateCategoryWithoutName(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/categories', [
            'description' => 'Professional conferences',
        ]);
        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['name']);

        $this->assertDatabaseCount('categories', 0);
    }

    public function testAdminCannotCreateCategoryWithDuplicateName(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
        ]);

        Sanctum::actingAs($admin);

        Category::factory()->create([
            'name' => 'Conferences',
        ]);
        $response = $this->postJson('/api/admin/categories', [
            'name' => 'Conferences',
            'description' => 'Professional conferences',
        ]);
        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['name']);

        $this->assertDatabaseCount('categories', 1);
    }

    public function testAdminCanViewCategoriesList(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
        ]);

        Sanctum::actingAs($admin);

        Category::factory()->count(3)->create();

        $response = $this->getJson('/api/admin/categories');
        $response->assertOk();
        $response->assertJsonCount(3, 'data');
    }

    public function testAdminCanUpdateCategory(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
        ]);

        Sanctum::actingAs($admin);

        $category = Category::factory()->create([
            'name' => 'Conferences',
            'description' => 'Professional conferences',
        ]);

        $response = $this->patchJson("/api/admin/categories/{$category->id}", [
            'name' => 'Events',
            'description' => 'Professional events',
        ]);
        $response->assertOk();
        $response->assertJsonPath('data.name', 'Events');
        $response->assertJsonPath('data.description', 'Professional events');
        $this->assertDatabaseHas('categories', [
            'name' => 'Events',
            'description' => 'Professional events',
        ]);
    }

    public function testAdminCanDeleteCategory(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
        ]);

        Sanctum::actingAs($admin);

        $category = Category::factory()->create([
            'name' => 'Conferences',
            'description' => 'Professional conferences',
        ]);

        $response = $this->deleteJson("/api/admin/categories/{$category->id}");
        $response->assertNoContent();

        $this->assertDatabaseMissing('categories', [
            'id' => $category->id,
        ]);
    }

    public function testParticipantCannotDeleteCategory(): void
    {
        $participant = User::factory()->create([
            'role' => UserRole::PARTICIPANT,
        ]);

        Sanctum::actingAs($participant);

        $category = Category::factory()->create([
            'name' => 'Conferences',
            'description' => 'Professional conferences',
        ]);
        $response = $this->deleteJson("/api/admin/categories/{$category->id}");
        $response->assertForbidden();
        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
        ]);
    }

    public function testParticipantCannotUpdateCategory(): void
    {
        $participant = User::factory()->create([
            'role' => UserRole::PARTICIPANT,
        ]);
        Sanctum::actingAs($participant);

        $category = Category::factory()->create([
            'name' => 'Conferences',
            'description' => 'Professional conferences',
        ]);

        $response = $this->patchJson("/api/admin/categories/{$category->id}", [
            'name' => 'Events',
            'description' => 'Professional events',
        ]);
        $response->assertForbidden();
        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'name' => $category->name,
            'description' => $category->description,
        ]);
    }

    public function testAdminCannotUpdateCategoryWithDuplicateName(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
        ]);
        Sanctum::actingAs($admin);

        $category1 = Category::factory()->create([
            'name' => 'Conferences',
            'description' => 'Professional conferences',
        ]);

        $category2 = Category::factory()->create([
            'name' => 'Concerts',
            'description' => 'Professional concerts',
        ]);

        $response = $this->patchJson("/api/admin/categories/{$category1->id}", [
            'name' => $category2->name,
            'description' => $category2->description,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['name']);
        $this->assertDatabaseHas('categories', [
            'id' => $category1->id,
            'name' => $category1->name,
            'description' => $category1->description,
        ]);
    }

    public function testAdminCanUpdateCategoryWithoutChangingName(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
        ]);
        Sanctum::actingAs($admin);
        $category = Category::factory()->create([
            'name' => 'Conferences',
            'description' => 'Professional conferences',
        ]);
        $response = $this->patchJson("/api/admin/categories/{$category->id}", [
            'name' => $category->name,
            'description' => 'Professional events',
        ]);
        $response->assertOk();
        $response->assertJsonPath('data.name', 'Conferences');
        $response->assertJsonPath('data.description', 'Professional events');
        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'name' => $category->name,
            'description' => 'Professional events',
        ]);
    }

    public function testAdminCanViewSingleCategory(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
        ]);
        Sanctum::actingAs($admin);
        $category = Category::factory()->create([
            'name' => 'Conferences',
            'description' => 'Professional conferences',
        ]);
        $response = $this->getJson("/api/admin/categories/{$category->id}");
        $response->assertOk();
        $response->assertJsonPath('data.name', 'Conferences');
        $response->assertJsonPath('data.description', 'Professional conferences');
    }

    public function testParticipantCannotViewSingleCategory(): void
    {
        $participant = User::factory()->create([
            'role' => UserRole::PARTICIPANT,
        ]);
        Sanctum::actingAs($participant);
        $category = Category::factory()->create([
            'name' => 'Conferences',
            'description' => 'Professional conferences',
        ]);
        $response = $this->getJson("/api/admin/categories/{$category->id}");
        $response->assertForbidden();
    }
}
