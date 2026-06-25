<?php

namespace App\Http\Controllers;

use App\Http\Resources\ChildSubCategoryEditResource;
use App\Http\Resources\ChildSubCategoryListResource;
use App\Manager\ImageUploadManager;
use App\Models\Category;
use App\Services\ActivityLogService;
use App\Services\CategoryService;
use App\Http\Requests\StoreChildSubCategoryRequest;
use App\Http\Requests\UpdateChildSubCategoryRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ChildSubCategoryController extends Controller
{
    protected CategoryService $categoryService;

    public function __construct(CategoryService $categoryService)
    {
        $this->categoryService = $categoryService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        // Use CategoryService for unified Category model (level 3 - child categories)
        $childCategories = $this->categoryService->getAllChildCategoriesForAdmin($request->all());
        return ChildSubCategoryListResource::collection($childCategories);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * @param StoreChildSubCategoryRequest $request
     * @return JsonResponse
     */
    public function store(StoreChildSubCategoryRequest $request): JsonResponse
    {
        $child_sub_category            = $request->except('photo');
        $child_sub_category['slug']    = Str::slug($request->input('slug'));
        $child_sub_category['level']   = Category::LEVEL_CHILD_CATEGORY;
        $child_sub_category['user_id'] = 1;
        $uploadedKeys                  = [];

        if (isset($child_sub_category['sub_category_id'])) {
            $child_sub_category['parent_id'] = $child_sub_category['sub_category_id'];
            unset($child_sub_category['sub_category_id']);
        }

        if ($request->has('photo')) {
            $keys = ImageUploadManager::upload($request->input('photo'), 'categories/' . $child_sub_category['slug']);
            $child_sub_category['photo']      = $uploadedKeys['thumb'] = $keys['thumb'];
            $child_sub_category['photo_full'] = $uploadedKeys['full']  = $keys['full'];
        }

        try {
            $created = $this->categoryService->createCategory($child_sub_category, Category::LEVEL_CHILD_CATEGORY);
        } catch (\Throwable $e) {
            foreach ($uploadedKeys as $key) ImageUploadManager::deleteKey($key);
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Failed to create child sub-category'], 500);
        }

        ActivityLogService::categoryCreated($created->id ?? 0, $child_sub_category['name'] ?? '', 'child_sub_category');
        return response()->json(['status' => 'success', 'message' => 'Child sub-category created successfully']);
    }

    /**
     * @param Category $childSubCategory
     * @return ChildSubCategoryEditResource
     */
    public function show(Category $childSubCategory): ChildSubCategoryEditResource
    {
        return new ChildSubCategoryEditResource($childSubCategory);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Category $childSubCategory)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateChildSubCategoryRequest $request, Category $childSubCategory): JsonResponse
    {
        $child_sub_category_data         = $request->except('photo');
        $child_sub_category_data['slug'] = Str::slug($request->input('slug'));
        $uploadedKeys                    = [];
        $oldKeys                         = [];

        if (isset($child_sub_category_data['sub_category_id'])) {
            $child_sub_category_data['parent_id'] = $child_sub_category_data['sub_category_id'];
            unset($child_sub_category_data['sub_category_id']);
        }

        if ($request->has('photo')) {
            $oldKeys = ['full' => $childSubCategory->photo_full, 'thumb' => $childSubCategory->photo];
            $keys    = ImageUploadManager::upload($request->input('photo'), 'categories/' . $child_sub_category_data['slug']);
            $child_sub_category_data['photo']      = $uploadedKeys['thumb'] = $keys['thumb'];
            $child_sub_category_data['photo_full'] = $uploadedKeys['full']  = $keys['full'];
        }

        try {
            $this->categoryService->updateCategory($childSubCategory, $child_sub_category_data);
        } catch (\Throwable $e) {
            foreach ($uploadedKeys as $key) ImageUploadManager::deleteKey($key);
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Failed to update child sub-category'], 500);
        }

        foreach ($oldKeys as $key) if ($key) ImageUploadManager::deleteKey($key);

        ActivityLogService::categoryUpdated($childSubCategory->id, $childSubCategory->name, 'child_sub_category');
        return response()->json(['status' => 'success', 'message' => 'Child sub-category updated successfully']);
    }

    /**
     * @param Category $childSubCategory
     * @return JsonResponse
     */
    public function destroy(Category $childSubCategory): JsonResponse
    {
        $photoFull    = $childSubCategory->photo_full;
        $photo        = $childSubCategory->photo;
        $childCatId   = $childSubCategory->id;
        $childCatName = $childSubCategory->name;

        $this->categoryService->deleteCategory($childSubCategory);

        if ($photoFull) ImageUploadManager::deleteKey($photoFull);
        if ($photo)     ImageUploadManager::deleteKey($photo);

        ActivityLogService::categoryDeleted($childCatId, $childCatName, 'child_sub_category');
        return response()->json(['status' => 'success', 'message' => 'Child sub-category deleted successfully']);
    }

    /**
     * Get child sub categories by subcategory ID (optional)
     * Uses unified Category model via CategoryService for consistency with ecom
     * @param int|null $category_id  Subcategory ID to filter by (renamed for backward compatibility)
     * @return JsonResponse
     */
    final public function get_child_sub_category_list(?int $category_id = null): JsonResponse
    {
        // Use unified Category model via CategoryService for consistency with ecom
        $childSubCategories = $this->categoryService->getChildCategoriesForDropdown($category_id);
        return response()->json($childSubCategories);
    }
}
