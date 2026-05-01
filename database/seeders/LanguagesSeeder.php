<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LanguagesSeeder extends Seeder
{
    public function run()
    {
        $languages = [
            ['code' => 'en', 'name' => 'English', 'locale' => 'en_GB', 'is_rtl' => false, 'is_active' => true],
            ['code' => 'fr', 'name' => 'French', 'locale' => 'fr_FR', 'is_rtl' => false, 'is_active' => true],
            ['code' => 'de', 'name' => 'German', 'locale' => 'de_DE', 'is_rtl' => false, 'is_active' => true],
            ['code' => 'es', 'name' => 'Spanish', 'locale' => 'es_ES', 'is_rtl' => false, 'is_active' => true],
            ['code' => 'it', 'name' => 'Italian', 'locale' => 'it_IT', 'is_rtl' => false, 'is_active' => true],
            ['code' => 'pt', 'name' => 'Portuguese', 'locale' => 'pt_PT', 'is_rtl' => false, 'is_active' => true],
            ['code' => 'nl', 'name' => 'Dutch', 'locale' => 'nl_NL', 'is_rtl' => false, 'is_active' => true],
            ['code' => 'sv', 'name' => 'Swedish', 'locale' => 'sv_SE', 'is_rtl' => false, 'is_active' => true],
            ['code' => 'da', 'name' => 'Danish', 'locale' => 'da_DK', 'is_rtl' => false, 'is_active' => true],
            ['code' => 'no', 'name' => 'Norwegian', 'locale' => 'nb_NO', 'is_rtl' => false, 'is_active' => true],
            ['code' => 'fi', 'name' => 'Finnish', 'locale' => 'fi_FI', 'is_rtl' => false, 'is_active' => true],
            ['code' => 'pl', 'name' => 'Polish', 'locale' => 'pl_PL', 'is_rtl' => false, 'is_active' => true],
            ['code' => 'cs', 'name' => 'Czech', 'locale' => 'cs_CZ', 'is_rtl' => false, 'is_active' => true],
            ['code' => 'hu', 'name' => 'Hungarian', 'locale' => 'hu_HU', 'is_rtl' => false, 'is_active' => true],
            ['code' => 'ro', 'name' => 'Romanian', 'locale' => 'ro_RO', 'is_rtl' => false, 'is_active' => true],
            ['code' => 'bg', 'name' => 'Bulgarian', 'locale' => 'bg_BG', 'is_rtl' => false, 'is_active' => true],
            ['code' => 'el', 'name' => 'Greek', 'locale' => 'el_GR', 'is_rtl' => false, 'is_active' => true],
            ['code' => 'hr', 'name' => 'Croatian', 'locale' => 'hr_HR', 'is_rtl' => false, 'is_active' => true],
            ['code' => 'sr', 'name' => 'Serbian', 'locale' => 'sr_RS', 'is_rtl' => false, 'is_active' => true],
            ['code' => 'sl', 'name' => 'Slovenian', 'locale' => 'sl_SI', 'is_rtl' => false, 'is_active' => true],
            ['code' => 'sk', 'name' => 'Slovak', 'locale' => 'sk_SK', 'is_rtl' => false, 'is_active' => true],
            ['code' => 'et', 'name' => 'Estonian', 'locale' => 'et_EE', 'is_rtl' => false, 'is_active' => true],
            ['code' => 'lv', 'name' => 'Latvian', 'locale' => 'lv_LV', 'is_rtl' => false, 'is_active' => true],
            ['code' => 'lt', 'name' => 'Lithuanian', 'locale' => 'lt_LT', 'is_rtl' => false, 'is_active' => true],
            ['code' => 'mt', 'name' => 'Maltese', 'locale' => 'mt_MT', 'is_rtl' => false, 'is_active' => true],
            ['code' => 'is', 'name' => 'Icelandic', 'locale' => 'is_IS', 'is_rtl' => false, 'is_active' => true],
            ['code' => 'ga', 'name' => 'Irish', 'locale' => 'ga_IE', 'is_rtl' => false, 'is_active' => true],
            ['code' => 'ar', 'name' => 'Arabic', 'locale' => 'ar_SA', 'is_rtl' => true, 'is_active' => true],
        ];

        foreach ($languages as $language) {
            DB::table('languages')->updateOrInsert(
                ['code' => $language['code']],
                $language
            );
        }
    }
}