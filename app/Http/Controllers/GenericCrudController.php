<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Services\ActivityLogService;
use App\Support\ApiResponse;
use App\Support\Paginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Generic CRUD controller untuk master data sederhana.
 *
 * Subclass set: model, module name, kolom searchable, eager-load, dan
 * validation rules. Hilangkan ~12x boilerplate controller untuk currencies,
 * items, companies, dst.
 */
abstract class GenericCrudController extends Controller
{
    /** @var class-string<Model> */
    protected string $modelClass;

    /**
     * Module name for activity log entries.
     */
    protected string $moduleName;

    /**
     * Columns participating in the `?search=` LIKE filter.
     */
    protected array $searchable = [];

    /**
     * Relations to eager-load on listing endpoints.
     */
    protected array $with = [];

    /**
     * Whitelisted columns clients can write via Create.
     */
    abstract protected function createRules(): array;

    /**
     * Whitelisted columns clients can write via Update.
     */
    abstract protected function updateRules(): array;

    public function __construct(
        protected ActivityLogService $logSvc
    ) {}

    public function findAll(Request $request): JsonResponse
    {
        $query = ($this->modelClass)::query();
        if ($this->with) {
            $query->with($this->with);
        }
        $rows = $query->orderBy('id', 'desc')->get();

        return ApiResponse::success($rows);
    }

    public function findAllPagination(Request $request): JsonResponse
    {
        $query = ($this->modelClass)::query();
        if ($this->with) {
            $query->with($this->with);
        }

        $paginator = Paginator::apply($query, $request, $this->searchable);

        return ApiResponse::paginated($paginator);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $query = ($this->modelClass)::query();
        if ($this->with) {
            $query->with($this->with);
        }

        $model = $query->findOrFail($id);

        return ApiResponse::success($model);
    }

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate($this->createRules());

        $data = $this->beforeCreate($data, $request);
        $model = ($this->modelClass)::create($data);
        $this->afterCreate($model, $request);

        $this->logSvc->log(
            $request,
            ActivityLog::TYPE_CREATE,
            $this->moduleName,
            "Created {$this->moduleName} #{$model->getKey()}",
        );

        return ApiResponse::created($model);
    }

    public function update(Request $request, ?int $id = null): JsonResponse
    {
        // Support PUT /resource (id di body) dan PUT /resource/{id}.
        $id = $id ?? (int) $request->input('id');
        if ($id <= 0) {
            return ApiResponse::error('id is required', 422);
        }

        $data = $request->validate($this->updateRules());
        $model = ($this->modelClass)::findOrFail($id);
        $data = $this->beforeUpdate($data, $model, $request);
        $model->fill($data);
        $model->save();
        $this->afterUpdate($model, $request);

        $this->logSvc->log(
            $request,
            ActivityLog::TYPE_UPDATE,
            $this->moduleName,
            "Updated {$this->moduleName} #{$model->getKey()}",
        );

        return ApiResponse::success($model);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $model = ($this->modelClass)::findOrFail($id);
        $model->delete();

        $this->logSvc->log(
            $request,
            ActivityLog::TYPE_DELETE,
            $this->moduleName,
            "Deleted {$this->moduleName} #{$id}",
        );

        return ApiResponse::success(null, 'Deleted');
    }

    /**
     * Override to inject derived/audit fields before insert.
     */
    protected function beforeCreate(array $data, Request $request): array
    {
        if (in_array('created_by', $this->createRules() ? array_keys($this->createRules()) : [], true) === false &&
                $this->modelHasColumn('created_by')) {
            $data['created_by'] = $request->user()?->email;
        }

        return $data;
    }

    /**
     * Override to inject derived/audit fields before save.
     */
    protected function beforeUpdate(array $data, Model $model, Request $request): array
    {
        if ($this->modelHasColumn('updated_by')) {
            $data['updated_by'] = $request->user()?->email;
        }

        return $data;
    }

    protected function afterCreate(Model $model, Request $request): void
    {
        // hook
    }

    protected function afterUpdate(Model $model, Request $request): void
    {
        // hook
    }

    protected function modelHasColumn(string $column): bool
    {
        $instance = new $this->modelClass;

        return in_array($column, $instance->getFillable(), true);
    }
}
