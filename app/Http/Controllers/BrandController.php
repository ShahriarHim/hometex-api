<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Manager\ImageUploadManager;
use App\Http\Requests\StoreBrandRequest;
use App\Http\Requests\UpdateBrandRequest;
use App\Http\Resources\BrandEditResource;
use App\Http\Resources\BrandListResource;
use App\Services\ActivityLogService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BrandController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $brands = (new Brand())->getAllBrands($request->all());
        return BrandListResource::collection($brands);
    }

    final public function store(StoreBrandRequest $request): JsonResponse
    {
        $brand         = $request->except('logo');
        $brand['slug'] = Str::slug($request->input('slug'));
        $uploadedKeys  = [];

        if ($request->has('logo')) {
            $keys                 = ImageUploadManager::upload($request->input('logo'), 'brands/' . $brand['slug']);
            $brand['logo']        = $uploadedKeys['thumb'] = $keys['thumb'];
            $brand['logo_full']   = $uploadedKeys['full']  = $keys['full'];
        }

        try {
            $created = (new Brand())->storeBrand($brand);
        } catch (\Throwable $e) {
            foreach ($uploadedKeys as $key) ImageUploadManager::deleteKey($key);
            report($e);
            return response()->json(['message' => 'Failed to create brand', 'status' => 'error'], 500);
        }

        ActivityLogService::brandCreated($created->id ?? 0, $brand['name'] ?? '');
        return response()->json(['message' => 'Brand Created Successfully', 'status' => 'success']);
    }

    final public function show(Brand $brand): BrandEditResource
    {
        return new BrandEditResource($brand);
    }

    public function update(UpdateBrandRequest $request, Brand $brand): JsonResponse
    {
        $brand_data         = $request->except('logo');
        $brand_data['slug'] = Str::slug($request->input('slug'));
        $uploadedKeys       = [];
        $oldKeys            = [];

        if ($request->has('logo')) {
            $oldKeys              = ['full' => $brand->logo_full, 'thumb' => $brand->logo];
            $keys                 = ImageUploadManager::upload($request->input('logo'), 'brands/' . $brand_data['slug']);
            $brand_data['logo']       = $uploadedKeys['thumb'] = $keys['thumb'];
            $brand_data['logo_full']  = $uploadedKeys['full']  = $keys['full'];
        }

        try {
            $brand->update($brand_data);
        } catch (\Throwable $e) {
            foreach ($uploadedKeys as $key) ImageUploadManager::deleteKey($key);
            report($e);
            return response()->json(['message' => 'Failed to update brand', 'status' => 'error'], 500);
        }

        foreach ($oldKeys as $key) if ($key) ImageUploadManager::deleteKey($key);

        ActivityLogService::brandUpdated($brand->id, $brand->name);
        return response()->json(['message' => 'Brand Updated Successfully', 'status' => 'success']);
    }

    final public function destroy(Brand $brand): JsonResponse
    {
        $logoFull  = $brand->logo_full;
        $logo      = $brand->logo;
        $brandId   = $brand->id;
        $brandName = $brand->name;

        $brand->delete();

        if ($logoFull) ImageUploadManager::deleteKey($logoFull);
        if ($logo)     ImageUploadManager::deleteKey($logo);

        ActivityLogService::brandDeleted($brandId, $brandName);
        return response()->json(['message' => 'Brand Deleted Successfully', 'status' => 'success']);
    }

    final public function get_brand_list(): JsonResponse
    {
        $brands = (new Brand())->getBrandIdAndName();
        return response()->json($brands);
    }
}
