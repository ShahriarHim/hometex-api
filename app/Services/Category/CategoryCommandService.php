<?php

namespace App\Services\Category;

use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CategoryCommandService
 * 
 * Handles all write operations for categories following CQRS pattern.
 * This service is responsible for creating, updating, and deleting categories.
 * 
 * @package App\Services\Category
 */
class CategoryCommandService
{
    protected CategoryQueryService $queryService;

    public function __construct(CategoryQueryService $queryService)
    {
        $this->queryService = $queryService;
    }

    /**
     * Create a new category at any level
     *
     * @param array $data
     * @param int $level
     * @return Category
     * @throws \Exception
     */
    public function create(array $data, int $level = Category::LEVEL_CATEGORY): Category
    {
        return DB::transaction(function () use ($data, $level) {
            $data = $this->normalizeData($data, $level);
            
            $category = Category::create($data);
            
            $this->clearCaches();
            
            Log::info('Category created', [
                'id' => $category->id,
                'name' => $category->name,
                'level' => $level
            ]);
            
            return $category;
        });
    }

    /**
     * Update an existing category
     *
     * @param Category $category
     * @param array $data
     * @return Category
     * @throws \Exception
     */
    public function update(Category $category, array $data): Category
    {
        return DB::transaction(function () use ($category, $data) {
            $data = $this->normalizeData($data, $category->level);
            
            // Don't allow changing the level
            unset($data['level']);
            
            $category->update($data);
            
            $this->clearCaches();
            
            Log::info('Category updated', [
                'id' => $category->id,
                'name' => $category->name
            ]);
            
            return $category->fresh();
        });
    }

    /**
     * Delete a category
     *
     * @param Category $category
     * @return bool
     * @throws \Exception
     */
    public function delete(Category $category): bool
    {
        return DB::transaction(function () use ($category) {
            $id = $category->id;
            $name = $category->name;
            
            // Check for children before deleting
            if ($category->children()->exists()) {
                throw new \Exception("Cannot delete category '{$name}' because it has child categories.");
            }
            
            $result = $category->delete();
            
            $this->clearCaches();
            
            Log::info('Category deleted', [
                'id' => $id,
                'name' => $name
            ]);
            
            return $result;
        });
    }

    /**
     * Force delete a category and all its children
     *
     * @param Category $category
     * @return bool
     * @throws \Exception
     */
    public function forceDeleteWithChildren(Category $category): bool
    {
        return DB::transaction(function () use ($category) {
            $id = $category->id;
            $name = $category->name;
            
            // Recursively delete all children
            $this->deleteChildrenRecursively($category);
            
            $result = $category->forceDelete();
            
            $this->clearCaches();
            
            Log::info('Category force deleted with children', [
                'id' => $id,
                'name' => $name
            ]);
            
            return $result;
        });
    }

    /**
     * Toggle category active status
     *
     * @param Category $category
     * @return Category
     */
    public function toggleActive(Category $category): Category
    {
        $category->update([
            'is_active' => !$category->is_active,
            'status' => $category->is_active ? 0 : 1 // Legacy field
        ]);
        
        $this->clearCaches();
        
        return $category->fresh();
    }

    /**
     * Reorder categories
     *
     * @param array $orderedIds Array of category IDs in desired order
     * @return void
     */
    public function reorder(array $orderedIds): void
    {
        DB::transaction(function () use ($orderedIds) {
            foreach ($orderedIds as $index => $id) {
                Category::where('id', $id)->update([
                    'sort_order' => $index + 1,
                    'serial' => $index + 1 // Legacy field
                ]);
            }
            
            $this->clearCaches();
        });
    }

    /**
     * Move category to a new parent
     *
     * @param Category $category
     * @param int|null $newParentId
     * @return Category
     * @throws \Exception
     */
    public function moveToParent(Category $category, ?int $newParentId): Category
    {
        return DB::transaction(function () use ($category, $newParentId) {
            if ($newParentId !== null) {
                $newParent = Category::findOrFail($newParentId);
                
                // Validate the move is logical (can't move to deeper level than allowed)
                if ($newParent->level >= 2) {
                    throw new \Exception("Cannot move category under level 3 category.");
                }
                
                // Update level based on new parent
                $category->update([
                    'parent_id' => $newParentId,
                    'level' => $newParent->level + 1
                ]);
            } else {
                // Moving to root
                $category->update([
                    'parent_id' => null,
                    'level' => Category::LEVEL_CATEGORY
                ]);
            }
            
            $this->clearCaches();
            
            return $category->fresh();
        });
    }

    // ==================== PRIVATE HELPERS ====================

    /**
     * Normalize input data for backward compatibility
     *
     * @param array $data
     * @param int $level
     * @return array
     */
    private function normalizeData(array $data, int $level): array
    {
        $data['level'] = $level;
        
        // Map legacy 'status' to 'is_active'
        if (isset($data['status'])) {
            $data['is_active'] = $data['status'] == 1;
        }
        
        // Map legacy 'serial' to 'sort_order'
        if (isset($data['serial'])) {
            $data['sort_order'] = $data['serial'];
        }
        
        // Map 'category_id' to 'parent_id' for subcategories
        if (isset($data['category_id']) && $level === Category::LEVEL_SUBCATEGORY) {
            $data['parent_id'] = $data['category_id'];
            unset($data['category_id']);
        }
        
        // Map 'sub_category_id' to 'parent_id' for child categories
        if (isset($data['sub_category_id']) && $level === Category::LEVEL_CHILD_CATEGORY) {
            $data['parent_id'] = $data['sub_category_id'];
            unset($data['sub_category_id']);
        }
        
        return $data;
    }

    /**
     * Recursively delete all children of a category
     *
     * @param Category $category
     * @return void
     */
    private function deleteChildrenRecursively(Category $category): void
    {
        foreach ($category->children as $child) {
            $this->deleteChildrenRecursively($child);
            $child->forceDelete();
        }
    }

    /**
     * Clear all category caches
     *
     * @return void
     */
    private function clearCaches(): void
    {
        $this->queryService->clearAllCaches();
    }
}
