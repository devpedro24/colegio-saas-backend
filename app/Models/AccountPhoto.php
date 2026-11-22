<?php
namespace App\Models;

use App\Models\Rbac\CentralModel;

final class AccountPhoto extends CentralModel
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];

    public static function scopeKey(User $user): string
    {
        $owner = $user->school_user_id ? tenant()?->parent_id : tenant()?->getKey();
        return hash('sha256', ($owner ?? 'platform').':'.($user->school_user_id ?: $user->getKey()));
    }

    public static function url(User $user): ?string
    {
        $photo = self::find(self::scopeKey($user));
        return $photo?->path ? '/api/account/photo?v='.$photo->version : null;
    }
}
