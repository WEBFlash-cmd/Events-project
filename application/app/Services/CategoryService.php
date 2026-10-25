<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Event;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CategoryService
{
    public function getAllCategories(): Collection
    {
        return Category::all();
    }

    public function createCategory(array $data): Category
    {
        return Category::create($data);
    }

    public function updateCategory(Category $category, array $data): Category
    {
        $category->update($data);

        return $category;
    }

    public function deleteCategory(Category $category): void
    {
        DB::transaction(function () use ($category) {
            $category = Category::query()->lockForUpdate()->findOrFail($category->id);

            abort_if(
                Event::query()->where('category_id', $category->id)->exists(),
                409,
                'Cannot delete a category that is used by events.',
            );

            $category->delete();
        });
    }

    public function showCategory(Category $category): Category
    {
        return $category;
    }
}
