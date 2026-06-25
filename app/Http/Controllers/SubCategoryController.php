<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\ActivityLogService;
use App\Services\CategoryService;
use App\Http\Requests\StoreSubCategoryRequest;
use App\Http\Requests\UpdateSubCategoryRequest;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Manager\ImageUploadManager;
use App\Http\Resources\SubCategoryEditResource;
use App\Http\Resources\SubCategoryListResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SubCategoryController extends Controller
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
    public function index(Request $request): AnonymousResourceCollection
    {
        // Use CategoryService for unified Category model (level 2 - subcategories)
        $categories = $this->categoryService->getAllSubCategoriesForAdmin($request->all());
        return SubCategoryListResource::collection($categories);
    }

    /**
     * @param StoreSubCategoryRequest $request
     * @return JsonResponse
     */
    final public function store(StoreSubCategoryRequest $request): JsonResponse
    {
        $sub_category          = $request->except('photo');
        $sub_category['slug']  = Str::slug($request->input('slug'));
        $sub_category['level'] = Category::LEVEL_SUBCATEGORY;
        $uploadedKeys          = [];

        if (isset($sub_category['category_id'])) {
            $sub_category['parent_id'] = $sub_category['category_id'];
            unset($sub_category['category_id']);
        }

        if ($request->has('photo')) {
            $keys = ImageUploadManager::upload($request->input('photo'), 'categories/' . $sub_category['slug']);
            $sub_category['photo']      = $uploadedKeys['thumb'] = $keys['thumb'];
            $sub_category['photo_full'] = $uploadedKeys['full']  = $keys['full'];
        }

        try {
            $created = $this->categoryService->createCategory($sub_category, Category::LEVEL_SUBCATEGORY);
        } catch (\Throwable $e) {
            foreach ($uploadedKeys as $key) ImageUploadManager::deleteKey($key);
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Failed to create sub-category'], 500);
        }

        ActivityLogService::categoryCreated($created->id ?? 0, $sub_category['name'] ?? '', 'sub_category');
        return response()->json(['status' => 'success', 'message' => 'Sub-category created successfully']);
    }

    /**
     * @param Category $subCategory
     * @return SubCategoryEditResource
     */
    final public function show(Category $subCategory): SubCategoryEditResource
    {
        return new SubCategoryEditResource($subCategory);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Category $subCategory)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     * @param UpdateSubCategoryRequest $request
     * @param Category $subCategory
     * @return JsonResponse
     */
    final public function update(UpdateSubCategoryRequest $request, Category $subCategory): JsonResponse
    {
        $sub_category_data         = $request->except('photo');
        $sub_category_data['slug'] = Str::slug($request->input('slug'));
        $uploadedKeys              = [];
        $oldKeys                   = [];

        if (isset($sub_category_data['category_id'])) {
            $sub_category_data['parent_id'] = $sub_category_data['category_id'];
            unset($sub_category_data['category_id']);
        }

        if ($request->has('photo')) {
            $oldKeys = ['full' => $subCategory->photo_full, 'thumb' => $subCategory->photo];
            $keys    = ImageUploadManager::upload($request->input('photo'), 'categories/' . $sub_category_data['slug']);
            $sub_category_data['photo']      = $uploadedKeys['thumb'] = $keys['thumb'];
            $sub_category_data['photo_full'] = $uploadedKeys['full']  = $keys['full'];
        }

        try {
            $this->categoryService->updateCategory($subCategory, $sub_category_data);
        } catch (\Throwable $e) {
            foreach ($uploadedKeys as $key) ImageUploadManager::deleteKey($key);
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Failed to update sub-category'], 500);
        }

        foreach ($oldKeys as $key) if ($key) ImageUploadManager::deleteKey($key);

        ActivityLogService::categoryUpdated($subCategory->id, $subCategory->name, 'sub_category');
        return response()->json(['status' => 'success', 'message' => 'Sub-category updated successfully']);
    }

    /**
     * Remove the specified resource from storage.
     * @param Category $subCategory
     * @return JsonResponse
     */
    public function destroy(Category $subCategory): JsonResponse
    {
        $photoFull   = $subCategory->photo_full;
        $photo       = $subCategory->photo;
        $subCatId    = $subCategory->id;
        $subCatName  = $subCategory->name;

        $this->categoryService->deleteCategory($subCategory);

        if ($photoFull) ImageUploadManager::deleteKey($photoFull);
        if ($photo)     ImageUploadManager::deleteKey($photo);

        ActivityLogService::categoryDeleted($subCatId, $subCatName, 'sub_category');
        return response()->json(['status' => 'success', 'message' => 'Sub-category deleted successfully']);
    }

    /**
     * @return JsonResponse
     */
    final public function get_sub_category_list_fc(): JsonResponse
    {
        // Use unified Category model via CategoryService for consistency with ecom
        $subCategories = $this->categoryService->getSubCategoriesForDropdown();
        return response()->json($subCategories);
    }

    /**
     * @param int $category_id
     * @return JsonResponse
     */
    final public function get_sub_category_list(int $category_id): JsonResponse
    {
        // Use unified Category model via CategoryService for consistency with ecom
        $subCategories = $this->categoryService->getSubCategoriesForDropdown($category_id);
        return response()->json($subCategories);
    }
}
