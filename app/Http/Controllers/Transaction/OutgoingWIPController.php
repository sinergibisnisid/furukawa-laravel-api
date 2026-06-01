<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Services\OutgoingWIPService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OutgoingWIPController extends Controller
{
    public function __construct(private OutgoingWIPService $service) {}

    public function findAll(Request $request): JsonResponse
    {
        return ApiResponse::success($this->service->findAll());
    }

    public function findAllPagination(Request $request): JsonResponse
    {
        return ApiResponse::paginated($this->service->findAllPagination($request));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'no' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'type' => ['required', 'string', 'max:255'],
        ]);

        return ApiResponse::created($this->service->create($request, $data));
    }

    public function update(Request $request, ?int $id = null): JsonResponse
    {
        $resolvedId = $id ?? (int) $request->input('id');
        if (! $resolvedId) {
            return ApiResponse::error('id is required', 422);
        }
        $request->merge(['id' => $resolvedId]);

        $data = $request->validate([
            'id' => ['required', 'integer', 'exists:outgoings_wip,id'],
            'no' => ['sometimes', 'required', 'string', 'max:255'],
            'date' => ['sometimes', 'required', 'date'],
            'type' => ['sometimes', 'required', 'string', 'max:255'],
        ]);

        return ApiResponse::success($this->service->update($request, $data));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->service->delete($request, $id);

        return ApiResponse::success(null, 'Deleted');
    }
}
