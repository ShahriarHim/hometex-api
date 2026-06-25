<?php

namespace App\Http\Controllers;

use App\Models\Formula;
use App\Http\Requests\StoreFormulaRequest;
use App\Http\Requests\UpdateFormulaRequest;
use App\Http\Resources\FormulaResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FormulaController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $formulas = Formula::with('user')
            ->when($request->search, fn ($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->orderBy($request->order_by ?? 'updated_at', $request->direction ?? 'desc')
            ->paginate($request->per_page ?? 15);

        return FormulaResource::collection($formulas);
    }

    public function store(StoreFormulaRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['user_id'] = auth()->id();
        Formula::create($data);
        return response()->json(['message' => 'Formula created successfully', 'status' => 'success']);
    }

    public function show(Formula $formula): FormulaResource
    {
        $formula->load('user');
        return new FormulaResource($formula);
    }

    public function update(UpdateFormulaRequest $request, Formula $formula): JsonResponse
    {
        $formula->update($request->validated());
        return response()->json(['message' => 'Formula updated successfully', 'status' => 'success']);
    }

    public function destroy(Formula $formula): JsonResponse
    {
        $formula->delete();
        return response()->json(['message' => 'Formula deleted successfully', 'status' => 'success']);
    }
}
