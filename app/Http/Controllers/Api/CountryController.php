<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Country;
use Illuminate\Http\Request;

class CountryController extends Controller
{
    /**
     * Get list of active countries for registration form
     */
    public function index(Request $request)
    {
        $countries = Country::active()
            ->select('iso2 as code', 'name', 'phone_code', 'flag')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $countries
        ]);
    }

    /**
     * Get country by ISO2 code
     */
    public function show($code)
    {
        $country = Country::where('iso2', $code)
            ->where('is_active', true)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => [
                'code' => $country->iso2,
                'name' => $country->name,
                'phone_code' => $country->phone_code,
                'flag' => $country->flag,
                'currency_code' => $country->currency_code,
                'region' => $country->region,
            ]
        ]);
    }
}