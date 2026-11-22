<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountPhoto;
use App\Services\LoginAppearanceService;
use App\Support\Account\AccountPresenter;
use App\Support\Audit\AuditLogger;
use App\Support\Storage\StorageException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class AccountPhotoController extends Controller
{
    public function handle(Request $request)
    {
        $user = $request->user();
        abort_if(tenant() && $user->hasRole('estudiante') && ! $request->isMethod('GET'), 403);
        abort_if($user->must_change_password, 423);
        $key = AccountPhoto::scopeKey($user);
        $images = new LoginAppearanceService($key, AccountPhoto::class, 'account-photos/'.$key);
        if ($request->isMethod('GET')) return $images->image($request, tenant());
        $request->validate(['image' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:5120']]);
        try {
            $images->change(tenant(), $images->read(tenant())?->version ?? 'default', $request->file('image'), $user->email);
        } catch (StorageException $error) {
            throw ValidationException::withMessages(['image' => [$error->getMessage()]]);
        }
        if (tenant()) AuditLogger::tenant($user, 'UPDATE', 'account.photo');
        else AuditLogger::platform($user, 'UPDATE', 'account.photo');
        return response()->json(['user' => AccountPresenter::user($user)]);
    }
}
