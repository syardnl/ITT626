<?php

namespace Database\Seeders;

use App\Enums\Priority;
use App\Enums\Role;
use App\Models\Category;
use App\Models\Property;
use App\Models\Role as RoleModel;
use App\Models\Skill;
use App\Models\SlaRule;
use Illuminate\Database\Seeder;

/**
 * Reference data every environment needs. Safe to re-run; creates no accounts
 * and therefore no passwords.
 */
class ReferenceSeeder extends Seeder
{
    public function run(): void
    {
        if (! Property::query()->exists()) {
            Property::create(['name' => 'Apartment Development', 'timezone' => 'Asia/Kuala_Lumpur']);
        }

        foreach (Role::cases() as $role) {
            RoleModel::firstOrCreate(['name' => $role->value]);
        }

        // Only missing rows are created, so a re-run never undoes an Administrator's edits.
        foreach (self::CATEGORIES as $key => [$name, $priority]) {
            $category = Category::firstOrCreate(['key' => $key], ['name' => $name, 'default_priority' => $priority]);
            Skill::firstOrCreate(['category_id' => $category->id], ['name' => $category->name, 'active' => $category->active]);
        }

        foreach (self::SLA_TARGETS as [$priority, $response, $resolution]) {
            SlaRule::firstOrCreate(['priority' => $priority], ['response_minutes' => $response, 'resolution_minutes' => $resolution]);
        }
    }

    /** Design document §6.1: the ten Categories and their default priorities. */
    private const CATEGORIES = [
        'lift' => ['Lift', Priority::High],
        'plumbing' => ['Plumbing & Water Supply', Priority::High],
        'fire_safety' => ['Fire & Safety', Priority::Critical],
        'electrical' => ['Electrical', Priority::High],
        'security' => ['Security & Access', Priority::High],
        'structural' => ['Structural & Building', Priority::Medium],
        'sanitation' => ['Sanitation & Waste', Priority::Medium],
        'facilities' => ['Common Facilities', Priority::Low],
        'lighting' => ['Lighting', Priority::Medium],
        'landscaping' => ['Landscaping & Cleaning', Priority::Low],
    ];

    /** Design document §6.3: response and resolution minutes per Priority. */
    private const SLA_TARGETS = [
        [Priority::Critical, 60, 480],
        [Priority::High, 240, 1440],
        [Priority::Medium, 1440, 4320],
        [Priority::Low, 4320, 10080],
    ];
}
