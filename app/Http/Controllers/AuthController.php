<?php

namespace App\Http\Controllers;

use App\Manager\ImageUploadManager;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\AuthRequest;
use App\Http\Resources\ShopListResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Throwable;

class AuthController extends Controller
{
    final public function login(AuthRequest $request): JsonResponse
    {
        $user = User::query()
            ->where('email', $request->input('email'))
            ->orWhere('phone', $request->input('email'))
            ->first();
        if (!$user) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Block customer-type accounts from IMS login
        if ($user->user_type === 'customer') {
            throw ValidationException::withMessages([
                'email' => ['Access denied.'],
            ]);
        }

        if ($user->isLocked()) {
            throw ValidationException::withMessages([
                'email' => ['Account temporarily locked due to multiple failed login attempts. Try again later.'],
            ]);
        }

        if (!$user->isActive()) {
            throw ValidationException::withMessages([
                'email' => ['Your account is inactive. Please contact support.'],
            ]);
        }

        if (!Hash::check($request->input('password'), $user->password)) {
            $user->recordFailedLogin();

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $user->recordLogin();

        $branch = null;
        $staffShop = $user->staffShop;
        if ($staffShop) {
            $branch = new ShopListResource((new Shop())->getShopDetailsById($staffShop->id));
        } elseif ($primaryShop = $user->primaryShop()) {
            $branch = new ShopListResource((new Shop())->getShopDetailsById($primaryShop->id));
        }

        return response()->json([
            'token'         => $user->createToken($user->email)->plainTextToken,
            'name'          => $user->name,
            'first_name'    => $user->first_name,
            'last_name'     => $user->last_name,
            'email'         => $user->email,
            'phone'         => $user->phone,
            'photo'         => ImageUploadManager::url($user->avatar),
            'user_type'     => $user->user_type,
            'employee_type' => $user->employee_type ?? null,
            'roles'         => $user->getRoleNames(),
            'permissions'   => $user->getAllPermissions()->pluck('name'),
            'branch'        => $branch,
        ]);
    }

    public function me(): JsonResponse
    {
        $user = request()->user('sanctum');

        if (!$user instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $branch = null;
        $staffShop = $user->staffShop;
        if ($staffShop) {
            $branch = new ShopListResource((new Shop())->getShopDetailsById($staffShop->id));
        } elseif ($primaryShop = $user->primaryShop()) {
            $branch = new ShopListResource((new Shop())->getShopDetailsById($primaryShop->id));
        }

        return response()->json([
            'id'                 => $user->id,
            'name'               => $user->name,
            'first_name'         => $user->first_name,
            'last_name'          => $user->last_name,
            'email'              => $user->email,
            'phone'              => $user->phone,
            'phone_country_code' => $user->phone_country_code ?? '+880',
            'date_of_birth'      => $user->date_of_birth?->format('Y-m-d'),
            'gender'             => $user->gender,
            'nid'                => $user->nid,
            'photo'              => ImageUploadManager::url($user->avatar),
            'user_type'          => $user->user_type,
            'employee_type'      => $user->employee_type ?? null,
            'roles'              => $user->getRoleNames(),
            'permissions'        => $user->getAllPermissions()->pluck('name'),
            'branch'             => $branch,
        ]);
    }

    /**
     * PUT /me/profile
     * Update own profile — name, email, phone, password, avatar.
     * All fields optional — only provided fields are changed.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user('sanctum');

        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $request->validate([
            'first_name'         => 'nullable|string|max:100',
            'last_name'          => 'nullable|string|max:100',
            'phone'              => 'nullable|string|max:20|unique:users,phone,' . $user->id,
            'phone_country_code' => 'nullable|string|max:5',
            'date_of_birth'      => 'nullable|date|before:today',
            'gender'             => 'nullable|string|in:male,female,other,prefer_not_to_say',
            'nid'                => ['nullable', 'digits_between:10,17', 'regex:/^\d+$/'],
            'current_password'   => 'nullable|string|required_with:new_password',
            'new_password'       => 'nullable|string|min:8|confirmed',
            'avatar'             => 'nullable|string|max:14000000',
        ]);

        // Password change — verify current before accepting new
        if ($request->filled('new_password')) {
            if (! Hash::check($request->current_password, $user->password)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Current password is incorrect.',
                    'errors'  => ['current_password' => ['Current password is incorrect.']],
                ], 422);
            }
        }

        $updates = [];

        if ($request->filled('first_name'))         $updates['first_name']         = $request->first_name;
        if ($request->filled('last_name'))          $updates['last_name']          = $request->last_name;
        if ($request->filled('phone'))              $updates['phone']              = $request->phone;
        if ($request->filled('phone_country_code')) $updates['phone_country_code'] = $request->phone_country_code;
        if ($request->has('date_of_birth'))         $updates['date_of_birth']      = $request->date_of_birth ?: null;
        if ($request->has('gender'))                $updates['gender']             = $request->gender ?: null;
        if ($request->has('nid'))                   $updates['nid']                = $request->nid ?: null;
        if ($request->filled('new_password')) {
            $updates['password'] = Hash::make($request->new_password);
        }

        // Avatar — two-phase R2 upload
        $oldAvatarKey = null;
        if ($request->filled('avatar')) {
            $oldAvatarKey = $user->getRawOriginal('avatar'); // R2 key or null
            try {
                $newKey = ImageUploadManager::uploadFromBase64(
                    $request->avatar,
                    'avatars/' . $user->id . '_' . time()
                );
                $updates['avatar'] = $newKey;
            } catch (Throwable $e) {
                report($e);
                return response()->json(['status' => 'error', 'message' => 'Failed to upload avatar.'], 500);
            }
        }

        $user->update($updates);

        // Delete old avatar from R2 only after DB update succeeds
        if ($oldAvatarKey) {
            ImageUploadManager::deleteKey($oldAvatarKey);
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Profile updated successfully.',
            'data'    => [
                'id'         => $user->id,
                'name'       => $user->name,
                'first_name' => $user->first_name,
                'last_name'  => $user->last_name,
                'email'      => $user->email,
                'phone'      => $user->phone,
                'photo'      => ImageUploadManager::url($user->avatar),
            ],
        ]);
    }

    public function logout(): JsonResponse
    {
        $user = Auth::guard('sanctum')->user();

        if ($user instanceof User) {
            $user->activityLogs()->create([
                'action'     => 'logout',
                'description' => 'User logged out',
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
            $user->tokens()->delete();
        }

        return response()->json(['status' => 'success', 'message' => 'You have successfully logged out.']);
    }
}
