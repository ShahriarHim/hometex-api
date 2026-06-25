<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStaffRequest;
use App\Http\Requests\UpdateStaffRequest;
use App\Http\Resources\StaffResource;
use App\Manager\ImageUploadManager;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

/**
 * Manages IMS staff users (admin, manager, product_manager, sales_staff, warehouse).
 * Replaces the legacy SalesManagerController + SalesManager model.
 */
class StaffController extends Controller
{
    private const IMS_ROLES = ['admin', 'manager', 'product_manager', 'sales_staff', 'warehouse'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = User::query()
            ->where('user_type', 'staff')
            ->orWhere('user_type', 'admin')
            ->with(['roles', 'staffShop:id,name'])
            ->latest();

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($role = $request->get('role')) {
            $query->role($role);
        }

        if (in_array($request->get('status'), ['active', 'inactive'], true)) {
            $query->where('status', $request->get('status'));
        }

        $staff = $query->paginate($request->get('per_page', 15));

        return StaffResource::collection($staff);
    }

    public function store(StoreStaffRequest $request): JsonResponse
    {
        $data = $request->validated();
        $uuid = (string) Str::uuid();

        // Phase 1: upload to R2 before opening the transaction.
        // If R2 fails here, no DB row is written — nothing to roll back.
        $uploadedKeys = [];
        try {
            if (! empty($data['avatar'])) {
                $uploadedKeys['avatar'] = ImageUploadManager::uploadFromBase64(
                    $data['avatar'], 'staff/' . $uuid . '/avatar'
                );
            }
            if (! empty($data['nid_photo'])) {
                $uploadedKeys['nid_photo'] = ImageUploadManager::uploadFromBase64(
                    $data['nid_photo'], 'staff/' . $uuid . '/nid'
                );
            }
        } catch (Throwable $e) {
            foreach ($uploadedKeys as $key) {
                ImageUploadManager::deleteKey($key);
            }
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Failed to upload image'], 500);
        }

        // Phase 2: commit everything to DB. If this fails, clean up R2 keys.
        try {
            DB::beginTransaction();

            $user = User::create([
                'uuid'               => $uuid,
                'first_name'         => $data['first_name'],
                'last_name'          => $data['last_name'] ?? null,
                'email'              => $data['email'],
                'phone'              => $data['phone'] ?? null,
                'phone_country_code' => $data['phone_country_code'] ?? '+880',
                'date_of_birth'      => $data['date_of_birth'] ?? null,
                'gender'             => $data['gender'] ?? null,
                'password'           => Hash::make($data['password']),
                'user_type'          => $data['role'] === 'admin' ? 'admin' : 'staff',
                'employee_type'      => $data['employee_type'] ?? null,
                'staff_shop_id'      => $data['staff_shop_id'] ?? null,
                'bio'                => $data['bio'] ?? null,
                'nid'                => $data['nid'] ?? null,
                'avatar'             => $uploadedKeys['avatar'] ?? null,
                'nid_photo'          => $uploadedKeys['nid_photo'] ?? null,
                'status'             => 'active',
            ]);

            $role = $data['role'] ?? 'sales_staff';
            if (in_array($role, self::IMS_ROLES)) {
                $user->syncRoles([$role]);
            }

            DB::commit();

            ActivityLogService::staffCreated($user->id, $user->name);

            return response()->json(['status' => 'success', 'message' => 'Staff member created successfully'], 201);
        } catch (Throwable $e) {
            DB::rollBack();
            foreach ($uploadedKeys as $key) {
                ImageUploadManager::deleteKey($key);
            }
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Failed to create staff member'], 500);
        }
    }

    public function show(User $staff): StaffResource
    {
        abort_if(! in_array($staff->user_type, ['staff', 'admin']), 404);
        $staff->load(['roles', 'staffShop:id,name']);
        return new StaffResource($staff);
    }

    public function update(UpdateStaffRequest $request, User $staff): JsonResponse
    {
        abort_if(! in_array($staff->user_type, ['staff', 'admin']), 404);

        $data = $request->validated();

        // Phase 1: upload new images to R2 before touching the DB.
        // Old keys are NOT deleted yet — we keep them as fallback until DB commit succeeds.
        $uploadedKeys = [];
        $oldKeys      = [];
        try {
            if (! empty($data['avatar'])) {
                $oldKeys['avatar']      = $staff->avatar;
                $uploadedKeys['avatar'] = ImageUploadManager::uploadFromBase64(
                    $data['avatar'], 'staff/' . $staff->uuid . '/avatar'
                );
            }
            if (! empty($data['nid_photo'])) {
                $oldKeys['nid_photo']      = $staff->nid_photo;
                $uploadedKeys['nid_photo'] = ImageUploadManager::uploadFromBase64(
                    $data['nid_photo'], 'staff/' . $staff->uuid . '/nid'
                );
            }
        } catch (Throwable $e) {
            foreach ($uploadedKeys as $key) {
                ImageUploadManager::deleteKey($key);
            }
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Failed to upload image'], 500);
        }

        // Phase 2: commit to DB. If it fails, clean up newly uploaded R2 keys (old ones untouched).
        try {
            DB::beginTransaction();

            $updates = [
                'first_name'         => $data['first_name'] ?? $staff->first_name,
                'last_name'          => $data['last_name'] ?? $staff->last_name,
                'email'              => $data['email'] ?? $staff->email,
                'phone'              => $data['phone'] ?? $staff->phone,
                'phone_country_code' => $data['phone_country_code'] ?? $staff->phone_country_code,
                'date_of_birth'      => array_key_exists('date_of_birth', $data) ? ($data['date_of_birth'] ?: null) : $staff->date_of_birth,
                'gender'             => array_key_exists('gender', $data) ? ($data['gender'] ?: null) : $staff->gender,
                'employee_type'      => $data['employee_type'] ?? $staff->employee_type,
                'staff_shop_id'      => array_key_exists('staff_shop_id', $data) ? ($data['staff_shop_id'] ?: null) : $staff->staff_shop_id,
                'bio'                => $data['bio'] ?? $staff->bio,
                'nid'                => array_key_exists('nid', $data) ? ($data['nid'] ?: null) : $staff->nid,
                'status'             => $data['status'] ?? $staff->status,
            ];

            if (! empty($data['password'])) {
                $updates['password'] = Hash::make($data['password']);
            }

            if (isset($uploadedKeys['avatar']))   $updates['avatar']    = $uploadedKeys['avatar'];
            if (isset($uploadedKeys['nid_photo'])) $updates['nid_photo'] = $uploadedKeys['nid_photo'];

            if (! empty($data['role']) && in_array($data['role'], self::IMS_ROLES)) {
                $updates['user_type'] = $data['role'] === 'admin' ? 'admin' : 'staff';
                $staff->syncRoles([$data['role']]);
            }

            $staff->update($updates);

            DB::commit();

            // Phase 3: DB committed — now safe to delete the old R2 keys.
            foreach ($oldKeys as $oldKey) {
                if ($oldKey) ImageUploadManager::deleteKey($oldKey);
            }

            ActivityLogService::staffUpdated($staff->id, $staff->name);

            return response()->json(['status' => 'success', 'message' => 'Staff member updated successfully']);
        } catch (Throwable $e) {
            DB::rollBack();
            foreach ($uploadedKeys as $key) {
                ImageUploadManager::deleteKey($key);
            }
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Failed to update staff member'], 500);
        }
    }

    public function destroy(User $staff): JsonResponse
    {
        abort_if(! in_array($staff->user_type, ['staff', 'admin']), 404);
        abort_if($staff->hasRole('admin') && User::role('admin')->count() <= 1, 422,
            'Cannot delete the last admin account.');

        $avatar   = $staff->avatar;
        $nidPhoto = $staff->nid_photo;

        $name = $staff->name;
        $id   = $staff->id;

        $staff->syncRoles([]);
        $staff->delete();

        if ($avatar)   ImageUploadManager::deleteKey($avatar);
        if ($nidPhoto) ImageUploadManager::deleteKey($nidPhoto);

        ActivityLogService::staffDeleted($id, $name);

        return response()->json(['status' => 'success', 'message' => 'Staff member deleted']);
    }
}
