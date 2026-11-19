<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CleaningStaff;
use App\Support\EntityMap;
use Illuminate\Http\Response;

class CleaningFormController extends Controller
{
    /**
     * Render Cleaning Report local application form.
     */
    public function index(): Response
    {
        $apartmentTypeId = (int) config('services.cleaning.apartment_entity_type_id');
        if ($apartmentTypeId <= 0) {
            $apartmentTypeId = EntityMap::typeId('tr', 'apartments');
        }

        $unitTypeId = (int) config('services.cleaning.unit_entity_type_id');
        if ($unitTypeId <= 0) {
            $unitTypeId = EntityMap::typeId('tr', 'units');
        }

        return response()
            ->view('cleaning.form', [
                'submitUrl' => url('/api/cleaning/submit'),
                'staffSyncUrl' => url('/api/cleaning/staff-sync'),
                'staff' => CleaningStaff::namesForForm(),
                'staffListIblockId' => (int) config('services.cleaning.staff_list_iblock_id', 87),
                'apartmentEntityTypeId' => $apartmentTypeId,
                'unitEntityTypeId' => $unitTypeId,
                'apartmentUnitsField' => (string) config('services.cleaning.apartment_units_field'),
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }
}
