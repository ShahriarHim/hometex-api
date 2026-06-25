<?php

namespace App\Services;

use App\Models\Category;
use App\Services\Category\CategoryCommandService;
use App\Services\Category\CategoryQueryService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * CategoryService
 * 
 * Facade service that delegates to specialized query and command services.
 * Maintains backward compatibility while following CQRS pattern internally.
 * 
 * For new code, consider using CategoryQueryService or CategoryCommandService directly.
 * 
 * @package App\Services
 */
class CategoryService
{
    protected CategoryQueryService $queryService;
    protected CategoryCommandService $commandService;

    public function __construct(
        CategoryQueryService $queryService,
        CategoryCommandService $commandService
    ) {
        $this->queryService = $queryService;
        $this->commandService = $commandService;
    }

    // ==================== QUERY OPERATIONS (delegated to CategoryQueryService) ====================

    /**
     * Get complete menu tree with all levels
     */
    public function getTree(bool $forceRefresh = false): array
    {
        return $this->queryService->getTree($forceRefresh);
    }

    /**
     * Get root categories only
     */
    public function getRootCategories(bool $forceRefresh = false): array
    {
        return $this->queryService->getRootCategories($forceRefresh);
    }

    /**
     * Get children of a specific category
     */
    public function getCategoryChildren(int $id): ?array
    {
        return $this->queryService->getCategoryChildren($id);
    }

    /**
     * Get category by slug
     */
    public function getCategoryBySlug(string $slug): ?array
    {
        return $this->queryService->getCategoryBySlug($slug);
    }

    /**
     * Get breadcrumb path for a category
     */
    public function getBreadcrumb(int $id): ?array
    {
        return $this->queryService->getBreadcrumb($id);
    }

    /**
     * Get optimized category menu (legacy method)
     */
    public function getMenu(bool $forceRefresh = false): Collection
    {
        return $this->queryService->getMenu($forceRefresh);
    }

    /**
     * Get categories for admin dropdown (level 1)
     */
    public function getCategoriesForDropdown(): Collection
    {
        return $this->queryService->getCategoriesForDropdown();
    }

    /**
     * Get subcategories for admin dropdown (level 2)
     */
    public function getSubCategoriesForDropdown(?int $categoryId = null): Collection
    {
        return $this->queryService->getSubCategoriesForDropdown($categoryId);
    }

    /**
     * Get child categories for admin dropdown (level 3)
     */
    public function getChildCategoriesForDropdown(?int $subCategoryId = null): Collection
    {
        return $this->queryService->getChildCategoriesForDropdown($subCategoryId);
    }

    /**
     * Get all categories (level 1) for admin listing
     */
    public function getAllCategoriesForAdmin(array $input): LengthAwarePaginator
    {
        return $this->queryService->getAllCategoriesForAdmin($input);
    }

    /**
     * Get all subcategories (level 2) for admin listing
     */
    public function getAllSubCategoriesForAdmin(array $input): LengthAwarePaginator
    {
        return $this->queryService->getAllSubCategoriesForAdmin($input);
    }

    /**
     * Get all child categories (level 3) for admin listing
     */
    public function getAllChildCategoriesForAdmin(array $input): LengthAwarePaginator
    {
        return $this->queryService->getAllChildCategoriesForAdmin($input);
    }

    /**
     * Find category by ID with optional level validation
     */
    public function findCategory(int $id, ?int $level = null): ?Category
    {
        return $this->queryService->findCategory($id, $level);
    }

    // ==================== COMMAND OPERATIONS (delegated to CategoryCommandService) ====================

    /**
     * Create a new category at any level
     */
    public function createCategory(array $data, int $level = Category::LEVEL_CATEGORY): Category
    {
        return $this->commandService->create($data, $level);
    }

    /**
     * Update an existing category
     */
    public function updateCategory(Category $category, array $data): Category
    {
        return $this->commandService->update($category, $data);
    }

    /**
     * Delete a category
     */
    public function deleteCategory(Category $category): bool
    {
        return $this->commandService->delete($category);
    }

    // ==================== CACHE MANAGEMENT ====================

    /**
     * Clear all category caches
     */
    public function clearAllCaches(): void
    {
        $this->queryService->clearAllCaches();
    }

    /**
     * Clear menu cache (legacy alias)
     */
    public function clearMenuCache(): void
    {
        $this->queryService->clearMenuCache();
    }
}
