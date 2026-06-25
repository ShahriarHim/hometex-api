<?php

namespace App\Http\Controllers;

use App\Models\Address;
use App\Models\Supplier;
use Illuminate\Support\Str;
use App\Manager\ImageUploadManager;
use App\Http\Requests\StoreSupplierRequest;
use App\Http\Requests\UpdateSupplierRequest;
use App\Http\Resources\SupplierEditResource;
use App\Http\Resources\SupplierListResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use App\Services\ActivityLogService;
use Illuminate\Support\Facades\DB;
use Throwable;

class SupplierController extends Controller
{
    /**
     * @param Request $request
     * @return AnonymousResourceCollection
     */
    final public function index(Request $request):AnonymousResourceCollection
    {
        $suppliers =(new Supplier())->getSupplierList($request->all());
        return SupplierListResource::collection($suppliers);
    }


    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreSupplierRequest $request)
    {
        $supplier     = (new Supplier())->prepareData($request->all(), auth());
        $address      = (new Address())->prepareData($request->all());
        $uploadedKeys = [];

        if ($request->has('logo')) {
            $keys = ImageUploadManager::upload(
                $request->input('logo'),
                'suppliers/' . Str::slug($supplier['name'] . now()),
            );
            $supplier['logo']      = $uploadedKeys['thumb'] = $keys['thumb'];
            $supplier['logo_full'] = $uploadedKeys['full']  = $keys['full'];
        }

        try {
            DB::beginTransaction();
            $supplier = Supplier::create($supplier);
            $supplier->address()->create($address);
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            foreach ($uploadedKeys as $key) ImageUploadManager::deleteKey($key);
            info('SUPPLIER_STORE_FAIL', ['address' => $address, $e]);
            return response()->json(['message' => 'Failed to save supplier', 'status' => 'error'], 500);
        }

        ActivityLogService::supplierCreated($supplier->id, $supplier->name);
        return response()->json(['message' => 'Supplier Added Successfully', 'status' => 'success']);
    }

    /**
     * @param Supplier $supplier
     * @return SupplierEditResource
     */
    final public function show(Supplier $supplier):SupplierEditResource
    {
        $supplier->load('address.division', 'address.district', 'address.area');
        return new SupplierEditResource($supplier);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateSupplierRequest $request, Supplier $supplier)
    {
        $supplier_data = (new Supplier())->prepareData($request->all(), auth());
        $address_data  = (new Address())->prepareData($request->all());
        $uploadedKeys  = [];
        $oldKeys       = [];

        if ($request->has('logo')) {
            $oldKeys = ['full' => $supplier->logo_full, 'thumb' => $supplier->logo];
            $keys    = ImageUploadManager::upload(
                $request->input('logo'),
                'suppliers/' . Str::slug($supplier_data['name'] . now()),
            );
            $supplier_data['logo']      = $uploadedKeys['thumb'] = $keys['thumb'];
            $supplier_data['logo_full'] = $uploadedKeys['full']  = $keys['full'];
        }

        try {
            DB::beginTransaction();
            $supplier->update($supplier_data);
            $supplier->address()->update($address_data);
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            foreach ($uploadedKeys as $key) ImageUploadManager::deleteKey($key);
            info('SUPPLIER_UPDATE_FAIL', ['address' => $address_data, $e]);
            return response()->json(['message' => 'Failed to update supplier', 'status' => 'error'], 500);
        }

        foreach ($oldKeys as $key) if ($key) ImageUploadManager::deleteKey($key);

        ActivityLogService::supplierUpdated($supplier->id, $supplier->name);
        return response()->json(['message' => 'Supplier Updated Successfully', 'status' => 'success']);
    }

    /**
     * @param Supplier $supplier
     * @return JsonResponse
     */
    public function destroy(Supplier $supplier): JsonResponse
    {
        $logoFull     = $supplier->logo_full;
        $logo         = $supplier->logo;
        $supplierId   = $supplier->id;
        $supplierName = $supplier->name;

        (new Address())->deleteAddressBySupplierId($supplier);
        $supplier->delete();

        if ($logoFull) ImageUploadManager::deleteKey($logoFull);
        if ($logo)     ImageUploadManager::deleteKey($logo);

        ActivityLogService::supplierDeleted($supplierId, $supplierName);
        return response()->json(['message' => 'Supplier deleted successfully', 'status' => 'success']);
    }
     /**
     * @return JsonResponse
     */
    final public function get_provider_list():JsonResponse
        {
            $providers = (new Supplier())->getProviderIdAndName();
            return response()->json($providers);
        }
}
