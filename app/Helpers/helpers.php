<?php

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;

if (!function_exists('setting')) {
    /**
     * Get or set system settings
     * 
     * @param string|array $key
     * @param mixed $default
     * @return mixed
     */
    function setting($key, $default = null)
    {
        // If it's an array, we're setting multiple values
        if (is_array($key)) {
            foreach ($key as $k => $value) {
                set_setting($k, $value);
            }
            return true;
        }
        
        // Otherwise, we're getting a value
        return get_setting($key, $default);
    }
}

if (!function_exists('get_setting')) {
    function get_setting($key, $default = null)
    {
        $cacheKey = "system_setting_{$key}";
        
        return Cache::remember($cacheKey, 3600, function() use ($key, $default) {
            $setting = SystemSetting::where('key_name', $key)->first();
            
            if (!$setting) {
                return $default;
            }
            
            // Cast the value based on its type
            return cast_setting_value($setting->value, $setting->type);
        });
    }
}

if (!function_exists('set_setting')) {
    function set_setting($key, $value)
    {
        // Determine the type
        $type = get_setting_type($value);
        
        // Prepare value for storage
        $storedValue = prepare_setting_value($value, $type);
        
        // Update or create
        $setting = SystemSetting::updateOrCreate(
            ['key_name' => $key],
            [
                'value' => $storedValue,
                'type' => $type,
                'group_name' => 'general' // Default group
            ]
        );
        
        // Clear the cache
        $cacheKey = "system_setting_{$key}";
        Cache::forget($cacheKey);
        
        return $setting;
    }
}

if (!function_exists('cast_setting_value')) {
    function cast_setting_value($value, $type)
    {
        switch ($type) {
            case 'integer':
                return (int) $value;
            case 'boolean':
                return (bool) $value;
            case 'json':
            case 'array':
                return json_decode($value, true);
            case 'string':
            default:
                return (string) $value;
        }
    }
}

if (!function_exists('get_setting_type')) {
    function get_setting_type($value)
    {
        if (is_int($value)) return 'integer';
        if (is_bool($value)) return 'boolean';
        if (is_array($value)) return 'array';
        if (is_object($value)) return 'json';
        return 'string';
    }
}

if (!function_exists('prepare_setting_value')) {
    function prepare_setting_value($value, $type)
    {
        if (in_array($type, ['array', 'json'])) {
            return json_encode($value);
        }
        
        if ($type === 'boolean') {
            return $value ? '1' : '0';
        }
        
        return (string) $value;
    }
}