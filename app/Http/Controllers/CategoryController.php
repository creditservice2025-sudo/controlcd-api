<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Http\Requests\Category\CategoryStoreRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class CategoryController extends Controller
{
    public function index()
    {
        return Category::all();
    }

    public function store(CategoryStoreRequest $request)
    {
        $user = Auth::user();
        $category = Category::create([
            'name' => $request->name,
            'user_id' => $user->id
        ]);
        return response()->json($category, Response::HTTP_CREATED);
    }
}
