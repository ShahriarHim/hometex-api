<?php

namespace App\Http\Controllers;

use App\Models\AttributeValue;
use App\Http\Requests\StoreAttributeValueRequest;
use App\Http\Requests\UpdateAttributeValueRequest;
use Illuminate\Http\JsonResponse;

class AttributeValueController extends Controller
{
    final public function index(): JsonResponse
    {
        $values = AttributeValue::with('attribute:id,name')->orderBy('updated_at', 'desc')->get();
        return response()->json($values);
    }

    final public function store(StoreAttributeValueRequest $request): JsonResponse
    {
        AttributeValue::create($request->validated());
        return response()->json(['message' => 'Attribute value created successfully', 'status' => 'success']);
    }

    final public function update(UpdateAttributeValueRequest $request, AttributeValue $attributeValue): JsonResponse
    {
        $attributeValue->update($request->validated());
        return response()->json(['message' => 'Attribute value updated successfully', 'status' => 'success']);
    }

    final public function destroy(AttributeValue $attributeValue): JsonResponse
    {
        if ($attributeValue->productAttributes()->exists()) {
            return response()->json([
                'message' => 'Cannot delete: this value is assigned to products in inventory.',
                'status'  => 'error',
            ], 422);
        }

        $attributeValue->delete();
        return response()->json(['message' => 'Attribute value deleted successfully', 'status' => 'success']);
    }
}
