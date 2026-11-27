<?php

namespace App\Http\Requests\Complaints;

use Illuminate\Foundation\Http\FormRequest;

/** A Manager asking which Technicians suit a reviewed, classified Complaint. */
class RecommendTechniciansRequest extends FormRequest
{
    use AssignableComplaint;

    public function rules(): array
    {
        return [];
    }
}
