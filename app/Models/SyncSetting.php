<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncSetting extends Model
{
    protected $fillable = ['key', 'value'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $row = static::where('key', $key)->first();
        return $row ? $row->value : $default;
    }

    public static function put(string $key, mixed $value): self
    {
        return static::updateOrCreate(['key' => $key], ['value' => (string) $value]);
    }
}
