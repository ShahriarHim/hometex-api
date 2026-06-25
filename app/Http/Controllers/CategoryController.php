<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\ActivityLogService;
use App\Services\CategoryService;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Manager\ImageUploadManager;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Http\Resources\CategoryEditResource;
use App\Http\Resources\CategoryListResource;
use App\Http\Resources\SubCategoryEditResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoryController extends Controller
{
    protected CategoryService $categoryService;

    public function __construct(CategoryService $categoryService)
    {
        $this->categoryService = $categoryService;
    }

    /**
     * Display a listing of the resource.
     * @return AnonymousResourceCollection
     */
    final public function index(Request $request): AnonymousResourceCollection
    {
        // Use CategoryService for unified Category model (level 1)
        $categories = $this->categoryService->getAllCategoriesForAdmin($request->all());
        return CategoryListResource::collection($categories);
    }

    /**
     * @param StoreCategoryRequest $request
     * @return JsonResponse
     */
    final public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category              = $request->except('photo');
        $category['slug']      = Str::slug($request->input('slug'));
        $category['level']     = Category::LEVEL_CATEGORY;
        $category['parent_id'] = null;
        $uploadedKeys          = [];

        if ($request->has('photo')) {
            $keys = ImageUploadManager::upload($request->input('photo'), 'categories/' . $category['slug']);
            $category['photo']      = $uploadedKeys['thumb'] = $keys['thumb'];
            $category['photo_full'] = $uploadedKeys['full']  = $keys['full'];
        }

        try {
            $created = $this->categoryService->createCategory($category, Category::LEVEL_CATEGORY);
        } catch (\Throwable $e) {
            foreach ($uploadedKeys as $key) ImageUploadManager::deleteKey($key);
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Failed to create category'], 500);
        }

        ActivityLogService::categoryCreated($created->id ?? 0, $category['name'] ?? '', 'category');
        return response()->json(['status' => 'success', 'message' => 'Category created successfully']);
    }

    /**
     * @param Category $category
     * @return CategoryEditResource
     */
    final public function show(Category $category): CategoryEditResource
    {
        return new CategoryEditResource($category);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Category $category)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $category_data         = $request->except('photo');
        $category_data['slug'] = Str::slug($request->input('slug'));
        $uploadedKeys          = [];
        $oldKeys               = [];

        if ($request->has('photo')) {
            $oldKeys = ['full' => $category->photo_full, 'thumb' => $category->photo];
            $keys    = ImageUploadManager::upload($request->input('photo'), 'categories/' . $category_data['slug']);
            $category_data['photo']      = $uploadedKeys['thumb'] = $keys['thumb'];
            $category_data['photo_full'] = $uploadedKeys['full']  = $keys['full'];
        }

        try {
            $this->categoryService->updateCategory($category, $category_data);
        } catch (\Throwable $e) {
            foreach ($uploadedKeys as $key) ImageUploadManager::deleteKey($key);
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Failed to update category'], 500);
        }

        foreach ($oldKeys as $key) if ($key) ImageUploadManager::deleteKey($key);

        ActivityLogService::categoryUpdated($category->id, $category->name, 'category');
        return response()->json(['status' => 'success', 'message' => 'Category updated successfully']);
    }

    /**
     * Remove the specified resource from storage.
     * @param Category $category
     * @return JsonResponse
     */
    final public function destroy(Category $category): JsonResponse
    {
        $photoFull    = $category->photo_full;
        $photo        = $category->photo;
        $categoryId   = $category->id;
        $categoryName = $category->name;

        $this->categoryService->deleteCategory($category);

        if ($photoFull) ImageUploadManager::deleteKey($photoFull);
        if ($photo)     ImageUploadManager::deleteKey($photo);

        ActivityLogService::categoryDeleted($categoryId, $categoryName, 'category');
        return response()->json(['status' => 'success', 'message' => 'Category deleted successfully']);
    }

    /**
     * @return JsonResponse
     */
    final public function get_category_list(): JsonResponse
    {
        // Use unified Category model via CategoryService for consistency with ecom
        $categories = $this->categoryService->getCategoriesForDropdown();
        return response()->json($categories);
    }

}

