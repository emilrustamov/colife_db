<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\EntityMap;
use Illuminate\View\View;

class CleaningFormController extends Controller
{
    /**
     * Render Cleaning Report local application form.
     */
    public function index(): View
    {
        $apartmentTypeId = (int) config('services.cleaning.apartment_entity_type_id', 0);
        if ($apartmentTypeId <= 0) {
            $apartmentTypeId = EntityMap::typeId('tr', 'apartments');
        }

        $unitTypeId = (int) config('services.cleaning.unit_entity_type_id', 0);
        if ($unitTypeId <= 0) {
            $unitTypeId = EntityMap::typeId('tr', 'units');
        }

        return view('cleaning.form', [
            'submitUrl' => url('/api/cleaning/submit'),
            'staff' => config('services.cleaning.staff', []),
            'apartmentEntityTypeId' => $apartmentTypeId,
            'unitEntityTypeId' => $unitTypeId,
            'apartmentUnitsField' => (string) config('services.cleaning.apartment_units_field', 'ufCrm5_1697012153'),
        ]);
    }
}
