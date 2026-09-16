<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Union;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicUnionController extends Controller
{
    /**
     * Display a listing of unions with only name and code.
     */
    public function index(Request $request)
    {
        $unions = Union::query()->get(['id', 'name', 'code', 'organization_id']);

        return JsonResource::collection($unions);
    }
}
