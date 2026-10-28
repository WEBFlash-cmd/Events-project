<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\IndexAdminCatalogsRequest;
use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Models\Category;
use App\Services\CategoryService;
use App\Http\Requests\ConfirmWebActionRequest;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AdminCategoryPageController extends Controller
{
    public function index(IndexAdminCatalogsRequest $request)
    {
        $filters = $request->validated();
        $search = $filters['search'] ?? '';
        $categories = Category::query()->withCount('events')
            ->when($search !== '', fn ($query) => $query->whereLike('name', '%'.$search.'%'))
            ->orderBy('name')->orderBy('id')->paginate(12)->withQueryString();

        return response()->view('admin.categories.index', compact('categories', 'filters'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function create()
    {
        return $this->form(new Category());
    }

    public function store(StoreCategoryRequest $request, CategoryService $categories)
    {
        $categories->createCategory($request->validated());

        return redirect()->route('admin.categories.index')->with('admin_success', 'Категория создана.');
    }

    public function edit(Category $category)
    {
        return $this->form($category);
    }

    public function update(UpdateCategoryRequest $request, Category $category, CategoryService $categories)
    {
        $categories->updateCategory($category, $request->validated());

        return redirect()->route('admin.categories.index')->with('admin_success', 'Категория обновлена.');
    }

    public function destroy(ConfirmWebActionRequest $request, Category $category, CategoryService $categories)
    {
        try {
            $categories->deleteCategory($category);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }

            return redirect()->route('admin.categories.index')->with('admin_error',
                'Категорию нельзя удалить: она используется в мероприятиях.');
        }

        return redirect()->route('admin.categories.index')->with('admin_success', 'Категория удалена.');
    }

    private function form(Category $category)
    {
        return response()->view('admin.categories.form', compact('category'))->header('Cache-Control', 'private, no-store');
    }
}
