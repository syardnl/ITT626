<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoryController extends Controller
{
    /** Active Categories for any signed-in role; a fixed list of ten, so not paginated. */
    public function __invoke(): AnonymousResourceCollection
    {
        return CategoryResource::collection(Category::active()->orderBy('name')->get());
    }
}
