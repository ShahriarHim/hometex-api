<?php

namespace App\Http\Controllers;

use App\Models\Attribute;
use App\Http\Requests\StoreAttributeRequest;
use App\Http\Requests\UpdateAttributeRequest;
use App\Http\Resources\AttributeListResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AttributeController extends Controller
{
    final public function index(Request $request): AnonymousResourceCollection
    {
        $attributes = Attribute::with(['user', 'value', 'value.user:id,first_name,last_name'])
            ->when($request->search, fn ($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->orderBy($request->order_by ?? 'updated_at', $request->direction ?? 'desc')
            ->paginate($request->per_page ?? 15);

        return AttributeListResource::collection($attributes);
    }

    final public function store(StoreAttributeRequest $request): JsonResponse
    {
        Attribute::create($request->validated());
        return response()->json(['message' => 'Attribute created successfully', 'status' => 'success']);
    }

    final public function update(UpdateAttributeRequest $request, Attribute $attribute): JsonResponse
    {
        $attribute->update($request->validated());
        return response()->json(['message' => 'Attribute updated successfully', 'status' => 'success']);
    }

    public function destroy(Attribute $attribute): JsonResponse
    {
        if ($attribute->productAttributes()->exists()) {
            return response()->json([
                'message' => 'Cannot delete: this attribute is assigned to products in inventory.',
                'status'  => 'error',
            ], 422);
        }

        $attribute->delete();
        return response()->json(['message' => 'Attribute deleted successfully', 'status' => 'success']);
    }

    final public function get_attribute_list(): JsonResponse
    {
        $attributes = (new Attribute())->getAttributeIdAndName();
        return response()->json($attributes);
    }
}
