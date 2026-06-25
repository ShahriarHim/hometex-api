<?php

namespace App\Services\Category;

use App\Models\Category;
use App\Services\CacheService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * CategoryQueryService
 * 
 * Handles all read operations for categories following CQRS pattern.
 * This service is responsible for querying, caching, and returning category data.
 * 
 * @package App\Services\Category
 */
class CategoryQueryService
{
    /**
     * Cache configuration
     */
    private const CACHE_PREFIX = 'categories';
    private const TREE_CACHE_KEY = self::CACHE_PREFIX . ':tree';
    private const ROOT_CACHE_KEY = self::CACHE_PREFIX . ':root';
    private const MENU_CACHE_KEY = self::CACHE_PREFIX . ':menu';
    private const CACHE_TTL = 86400; // 24 hours

    // ==================== PUBLIC API (ECOM) ====================

    /**
     * Get complete menu tree with all levels (for ecom navigation)
     *
     * @param bool $forceRefresh
     * @return array
     */
    public function getTree(bool $forceRefresh = false): array
    {
        $cacheKey = CacheService::categoryKey(self::TREE_CACHE_KEY);
        
        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, self::CACHE_TTL, function () {
            return $this->buildTree();
        });
    }

    /**
     * Get root categories only (level 1)
     *
     * @param bool $forceRefresh
     * @return array
     */
    public function getRootCategories(bool $forceRefresh = false): array
    {
        $cacheKey = CacheService::categoryKey(self::ROOT_CACHE_KEY);
        
        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, self::CACHE_TTL, function () {
            return Category::query()
                ->active()
                ->root()
                ->with(['images' => fn($q) => $q->primary()])
                ->ordered()
                ->get()
                ->map(fn($category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'image' => $category->image_url,
                    'has_children' => $category->hasChildren(),
                    'is_active' => $category->is_active,
                    'sort_order' => $category->sort_order
                ])
                ->toArray();
        });
    }

    /**
     * Get children of a specific category
     *
     * @param int $id
     * @return array|null
     */
    public function getCategoryChildren(int $id): ?array
    {
        $category = Category::query()
            ->active()
            ->with(['images' => fn($q) => $q->primary()])
            ->find($id);

        if (!$category) {
            return null;
        }

        $children = Category::query()
            ->active()
            ->where('parent_id', $id)
            ->with(['images' => fn($q) => $q->primary()])
            ->ordered()
            ->get()
            ->map(fn($child) => [
                'id' => $child->id,
                'name' => $child->name,
                'slug' => $child->slug,
                'parent_id' => $child->parent_id,
                'has_children' => $child->hasChildren(),
                'is_active' => $child->is_active,
                'sort_order' => $child->sort_order
            ])
            ->toArray();

        return [
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'level' => $category->level
            ],
            'children' => $children
        ];
    }

    /**
     * Get category by slug
     *
     * @param string $slug
     * @return array|null
     */
    public function getCategoryBySlug(string $slug): ?array
    {
        $category = Category::query()
            ->active()
            ->where('slug', $slug)
            ->with(['images' => fn($q) => $q->primary()])
            ->first();

        if (!$category) {
            return null;
        }

        $breadcrumb = $category->getBreadcrumb()
            ->map(fn($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'slug' => $item->slug,
                'level' => $item->level
            ])
            ->toArray();

        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'parent_id' => $category->parent_id,
            'level' => $category->level,
            'breadcrumb' => $breadcrumb,
            'is_active' => $category->is_active,
            'meta_title' => $category->meta_title,
            'meta_description' => $category->meta_description,
            'image' => $category->image_url
        ];
    }

    /**
     * Get breadcrumb path for a category
     *
     * @param int $id
     * @return array|null
     */
    public function getBreadcrumb(int $id): ?array
    {
        $category = Category::query()->active()->find($id);

        if (!$category) {
            return null;
        }

        return $category->getBreadcrumb()
            ->map(fn($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'slug' => $item->slug,
                'level' => $item->level
            ])
            ->toArray();
    }

    /**
     * Get optimized category menu (legacy method)
     *
     * @param bool $forceRefresh
     * @return Collection
     */
    public function getMenu(bool $forceRefresh = false): Collection
    {
        $cacheKey = CacheService::categoryKey(self::MENU_CACHE_KEY);
        
        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, self::CACHE_TTL, function () {
            return Category::query()
                ->active()
                ->root()
                ->with([
                    'children' => fn($q) => $q->active()->ordered()
                        ->with(['children' => fn($q) => $q->active()->ordered()]),
                    'images' => fn($q) => $q->primary()
                ])
                ->ordered()
                ->get();
        });
    }

    // ==================== ADMIN DROPDOWN QUERIES ====================

    /**
     * Get categories for admin dropdown (level 1)
     *
     * @return Collection
     */
    public function getCategoriesForDropdown(): Collection
    {
        return Category::query()
            ->active()
            ->byLevel(Category::LEVEL_CATEGORY)
            ->select('id', 'name')
            ->ordered()
            ->get();
    }

    /**
     * Get subcategories for admin dropdown (level 2)
     *
     * @param int|null $categoryId
     * @return Collection
     */
    public function getSubCategoriesForDropdown(?int $categoryId = null): Collection
    {
        $query = Category::query()
            ->active()
            ->byLevel(Category::LEVEL_SUBCATEGORY)
            ->select('id', 'name', 'parent_id as category_id')
            ->ordered();

        if ($categoryId !== null) {
            $query->where('parent_id', $categoryId);
        }

        return $query->get();
    }

    /**
     * Get child categories for admin dropdown (level 3)
     *
     * @param int|null $subCategoryId
     * @return Collection
     */
    public function getChildCategoriesForDropdown(?int $subCategoryId = null): Collection
    {
        $query = Category::query()
            ->active()
            ->byLevel(Category::LEVEL_CHILD_CATEGORY)
            ->select('id', 'name', 'parent_id as sub_category_id')
            ->ordered();

        if ($subCategoryId !== null) {
            $query->where('parent_id', $subCategoryId);
        }

        return $query->get();
    }

    // ==================== ADMIN LIST QUERIES ====================

    /**
     * Get all categories (level 1) for admin listing
     *
     * @param array $input
     * @return LengthAwarePaginator
     */
    public function getAllCategoriesForAdmin(array $input): LengthAwarePaginator
    {
        return $this->buildAdminListQuery(Category::LEVEL_CATEGORY, $input)
            ->with('user:id,first_name,last_name')
            ->paginate($input['per_page'] ?? 10);
    }

    /**
     * Get all subcategories (level 2) for admin listing
     *
     * @param array $input
     * @return LengthAwarePaginator
     */
    public function getAllSubCategoriesForAdmin(array $input): LengthAwarePaginator
    {
        return $this->buildAdminListQuery(Category::LEVEL_SUBCATEGORY, $input)
            ->with(['user:id,first_name,last_name', 'parent:id,name'])
            ->paginate($input['per_page'] ?? 10);
    }

    /**
     * Get all child categories (level 3) for admin listing
     *
     * @param array $input
     * @return LengthAwarePaginator
     */
    public function getAllChildCategoriesForAdmin(array $input): LengthAwarePaginator
    {
        return $this->buildAdminListQuery(Category::LEVEL_CHILD_CATEGORY, $input)
            ->with(['user:id,first_name,last_name', 'parent:id,name'])
            ->paginate($input['per_page'] ?? 10);
    }

    /**
     * Find category by ID with optional level validation
     *
     * @param int $id
     * @param int|null $level
     * @return Category|null
     */
    public function findCategory(int $id, ?int $level = null): ?Category
    {
        $query = Category::query();
        
        if ($level !== null) {
            $query->byLevel($level);
        }
        
        return $query->find($id);
    }

    // ==================== CACHE MANAGEMENT ====================

    /**
     * Clear all category caches
     *
     * @return void
     */
    public function clearAllCaches(): void
    {
        Cache::forget(CacheService::categoryKey(self::TREE_CACHE_KEY));
        Cache::forget(CacheService::categoryKey(self::ROOT_CACHE_KEY));
        Cache::forget(CacheService::categoryKey(self::MENU_CACHE_KEY));
    }

    /**
     * Clear menu cache (legacy alias)
     *
     * @return void
     */
    public function clearMenuCache(): void
    {
        $this->clearAllCaches();
    }

    // ==================== PRIVATE HELPERS ====================

    /**
     * Build complete tree structure
     *
     * @return array
     */
    private function buildTree(): array
    {
        $categories = Category::query()
            ->active()
            ->root()
            ->with([
                'children' => fn($q) => $q->active()->ordered()
                    ->with(['children' => fn($q) => $q->active()->ordered()]),
                'images' => fn($q) => $q->primary()->ordered()
            ])
            ->ordered()
            ->get();

        return $categories->map(fn($category) => $this->formatCategoryForTree($category))->toArray();
    }

    /**
     * Format category for tree structure
     *
     * @param Category $category
     * @return array
     */
    private function formatCategoryForTree(Category $category): array
    {
        $primaryImage = $category->images->first();
        
        $data = [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'image' => $primaryImage ? $primaryImage->url : ($category->image_url ?? null),
            'description' => $category->description,
            'is_active' => $category->is_active,
            'sort_order' => $category->sort_order,
            'subcategories' => []
        ];

        foreach ($category->children as $child) {
            $childData = [
                'id' => $child->id,
                'name' => $child->name,
                'slug' => $child->slug,
                'parent_id' => $child->parent_id,
                'image' => $child->image_url,
                'is_active' => $child->is_active,
                'sort_order' => $child->sort_order,
                'child_categories' => []
            ];

            foreach ($child->children as $grandchild) {
                $childData['child_categories'][] = [
                    'id' => $grandchild->id,
                    'name' => $grandchild->name,
                    'slug' => $grandchild->slug,
                    'parent_id' => $grandchild->parent_id,
                    'image' => $grandchild->image_url,
                    'is_active' => $grandchild->is_active,
                    'sort_order' => $grandchild->sort_order
                ];
            }

            $data['subcategories'][] = $childData;
        }

        return $data;
    }

    /**
     * Build admin list query with common filters
     *
     * @param int $level
     * @param array $input
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function buildAdminListQuery(int $level, array $input): \Illuminate\Database\Eloquent\Builder
    {
        $query = Category::query()->byLevel($level);
        
        if (!empty($input['search'])) {
            $query->where('name', 'like', '%' . $input['search'] . '%');
        }
        
        if (!empty($input['order_by'])) {
            $query->orderBy($input['order_by'], $input['direction'] ?? 'asc');
        } else {
            $query->ordered();
        }
        
        return $query;
    }
}
