<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\Reference\CategoryRegistry;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** The ten seeded Categories: list and edit only; there is no create or delete. */
class CategoryController extends Controller
{
    public function __construct(private readonly CategoryRegistry $categories) {}

    /** Every Category, inactive ones included, with its Skill; a fixed list of ten, so not paginated. */
    public function index(): AnonymousResourceCollection
    {
        return CategoryResource::collection(Category::with('skill')->orderBy('name')->get());
    }

    public function update(UpdateCategoryRequest $request, Category $category): CategoryResource
    {
        return new CategoryResource($this->categories->update($category, $request->validated()));
    }
}
