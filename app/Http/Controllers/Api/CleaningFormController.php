<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class CleaningFormController extends Controller
{
    /**
     * Render Cleaning Report local application form.
     */
    public function index(): View
    {
        return view('cleaning.form', [
            'submitUrl' => url('/api/cleaning/submit'),
            'staff' => config('services.cleaning.staff', []),
            'apartmentEntityTypeId' => (int) config('services.cleaning.apartment_entity_type_id', 165),
            'unitEntityTypeId' => (int) config('services.cleaning.unit_entity_type_id', 144),
            'apartmentUnitsField' => (string) config('services.cleaning.apartment_units_field', 'ufCrm5_1697012153'),
        ]);
    }
}
