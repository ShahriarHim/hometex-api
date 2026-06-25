<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Http\Requests\StoreShopRequest;
use Illuminate\Support\Str;
use App\Http\Requests\UpdateShopRequest;
use App\Http\Resources\ShopEditResource;
use App\Http\Resources\ShopListResource;
use App\Http\Resources\ProductListResource;
use App\Manager\ImageUploadManager;
use App\Models\Address;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\ActivityLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Throwable;

class ShopController extends Controller
{
    /**
     * @param Request $request
     * @return AnonymousResourceCollection
     */
    public function index(Request $request):AnonymousResourceCollection
    {
        $shop =(new Shop())->getShopList($request->all());
        return ShopListResource::collection($shop);
    }


    /**
 * Store a newly created resource in storage.
 */
public function store(StoreShopRequest $request)
{
    $shopData    = $request->all();
    $addressData = $request->only(['address', 'landmark', 'area_id', 'district_id', 'division_id']);
    $uploadedKeys = [];

    if ($request->has('logo')) {
        $keys = ImageUploadManager::upload(
            $request->input('logo'),
            'shops/' . Str::slug($shopData['name'] . now()),
        );
        $shopData['logo']      = $uploadedKeys['thumb'] = $keys['thumb'];
        $shopData['logo_full'] = $uploadedKeys['full']  = $keys['full'];
    }

    try {
        DB::beginTransaction();
        $shop = Shop::create($shopData);
        $shop->address()->create($addressData);
        DB::commit();
    } catch (Throwable $e) {
        DB::rollBack();
        foreach ($uploadedKeys as $key) ImageUploadManager::deleteKey($key);
        info('Shop', ['Shop' => $shopData, 'address' => $addressData, 'error' => $e]);
        return response()->json(['message' => 'Failed to save shop', 'status' => 'error'], 500);
    }

    ActivityLogService::shopCreated($shop->id, $shop->name);
    return response()->json(['message' => 'Shop Added Successfully', 'status' => 'success']);
}


     /**
     * @param Shop $shop
     * @return ShopEditResource
     */
    public function show(Shop $shop):ShopEditResource
    {
        $shop->load('address.division', 'address.district', 'address.area');
        return new ShopEditResource($shop);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Shop $shop)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateShopRequest $request, Shop $shop)
    {
        $shop_data    = (new Shop())->prepareData($request->all(), auth());
        $address_data = (new Address())->prepareData($request->all());
        $uploadedKeys = [];
        $oldKeys      = [];

        if ($request->has('logo')) {
            $oldKeys      = ['full' => $shop->logo_full, 'thumb' => $shop->logo];
            $keys         = ImageUploadManager::upload(
                $request->input('logo'),
                'shops/' . Str::slug($shop_data['name'] . now()),
            );
            $shop_data['logo']      = $uploadedKeys['thumb'] = $keys['thumb'];
            $shop_data['logo_full'] = $uploadedKeys['full']  = $keys['full'];
        }

        try {
            DB::beginTransaction();
            $shop->update($shop_data);
            $shop->address()->update($address_data);
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            foreach ($uploadedKeys as $key) ImageUploadManager::deleteKey($key);
            info('SHOP_UPDATE_FAIL', ['Shop' => $shop_data, 'address' => $address_data, $e]);
            return response()->json(['message' => 'Failed to update shop', 'status' => 'error'], 500);
        }

        foreach ($oldKeys as $key) if ($key) ImageUploadManager::deleteKey($key);

        ActivityLogService::shopUpdated($shop->id, $shop->name);
        return response()->json(['message' => 'Shop Updated Successfully', 'status' => 'success']);
    }

     /**
 * @param Shop $shop
 * @return JsonResponse
 */
public function destroy(Shop $shop): JsonResponse
{
    if (in_array($shop->id, [1, 3, 4])) {
        return response()->json(['message' => 'This branch cannot be deleted', 'status' => 'error'], 422);
    }

    $logoFull = $shop->logo_full;
    $logo     = $shop->logo;
    $shopId   = $shop->id;
    $shopName = $shop->name;

    try {
        DB::beginTransaction();
        $shop->address()->delete();
        $shop->delete();
        DB::commit();
    } catch (Throwable $e) {
        DB::rollBack();
        return response()->json(['message' => 'Error deleting shop', 'status' => 'error'], 500);
    }

    if ($logoFull) ImageUploadManager::deleteKey($logoFull);
    if ($logo)     ImageUploadManager::deleteKey($logo);

    ActivityLogService::shopDeleted($shopId, $shopName);
    return response()->json(['message' => 'Shop deleted successfully', 'status' => 'success']);
}
    /**
     * @return JsonResponse
     */
    final public function get_shop_list():JsonResponse
    {
        $shops = (new Shop())->getShopListIdName();
        return response()->json($shops);
    }

    /**
     * Get list of shops with total product count
     *
     * @return JsonResponse
     */
    public function getShops(): JsonResponse
    {
        try {
            $shops = Shop::query()
                ->select('shops.id as shop_id', 'shops.name as shop_name')
                ->leftJoin('shop_product', 'shops.id', '=', 'shop_product.shop_id')
                ->selectRaw('COUNT(DISTINCT shop_product.product_id) as total')
                ->groupBy('shops.id', 'shops.name')
                ->orderBy('shops.id')
                ->get()
                ->map(function ($shop) {
                    return [
                        'total' => (int) $shop->total,
                        'shop_id' => $shop->shop_id,
                        'shop_name' => $shop->shop_name,
                    ];
                });

            return $this->success($shops, 'Shops retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve shops', $e->getMessage(), 500);
        }
    }

    /**
     * Get products for a specific shop
     *
     * @param Request $request
     * @param int $shop_id
     * @return JsonResponse
     */
    public function getShopProducts(Request $request, int $shop_id): JsonResponse
    {
        try {
            $shop = Shop::findOrFail($shop_id);

            $perPage = $request->input('per_page', 20);
            $perPage = min(max((int) $perPage, 1), 100);

            $search = $request->input('search');
            $orderBy = $request->input('order_by', 'id');
            $direction = $request->input('direction', 'desc');

            $productsQuery = Product::query()
                ->select('products.*', 'shop_product.quantity as shop_quantity')
                ->join('shop_product', function ($join) use ($shop_id) {
                    $join->on('products.id', '=', 'shop_product.product_id')
                         ->where('shop_product.shop_id', '=', $shop_id);
                });

            if ($search) {
                $productsQuery->where(function ($q) use ($search) {
                    $q->where('products.name', 'LIKE', '%' . $search . '%')
                      ->orWhere('products.sku', 'LIKE', '%' . $search . '%')
                      ->orWhere('products.id', $search);
                });
            }

            $products = $productsQuery->with([
                    'category:id,name',
                    'sub_category:id,name',
                    'child_sub_category:id,name',
                    'brand:id,name',
                    'country:id,name',
                    'supplier:id,name,phone',
                    'created_by' => function($q) {
                        $q->select('id', 'first_name', 'last_name')->withoutGlobalScopes();
                    },
                    'updated_by' => function($q) {
                        $q->select('id', 'first_name', 'last_name')->withoutGlobalScopes();
                    },
                    'primary_photo',
                    'product_attributes.attributes',
                    'product_attributes.attribute_value',
                    'product_specifications.specifications',
                    'shops' => function($q) {
                        $q->select('shops.id', 'shops.name');
                    }
                ])
                ->orderBy("products.{$orderBy}", $direction)
                ->paginate($perPage);

            // Override global stock with shop_quantity
            $products->getCollection()->transform(function ($product) {
                $product->stock = $product->shop_quantity;
                return $product;
            });

            return $this->success([
                'products' => ProductListResource::collection($products),
                'pagination' => [
                    'current_page' => $products->currentPage(),
                    'last_page' => $products->lastPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                    'from' => $products->firstItem(),
                    'to' => $products->lastItem(),
                    'has_more' => $products->hasMorePages(),
                ]
            ], 'Products retrieved successfully');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->error('Shop not found', null, 404);
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve products', $e->getMessage(), 500);
        }
    }
}
