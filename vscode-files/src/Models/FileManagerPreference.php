<?php

namespace VsCodeFiles\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $user_id
 * @property string $mode
 */
class FileManagerPreference extends Model
{
    public const MODE_LIST = 'list';

    public const MODE_VSCODE = 'vscode';

    protected $table = 'vscode_files_preferences';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = ['user_id', 'mode'];

    public static function modeFor(?User $user): string
    {
        if (!$user) {
            return self::MODE_LIST;
        }

        return static::query()->whereKey($user->id)->value('mode') ?? self::MODE_LIST;
    }

    public static function setModeFor(User $user, string $mode): void
    {
        static::query()->updateOrCreate(['user_id' => $user->id], ['mode' => $mode]);
    }
}
