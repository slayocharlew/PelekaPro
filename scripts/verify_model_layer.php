<?php

use App\Models\Business;
use App\Models\BusinessBranch;
use App\Models\CashReconciliation;
use App\Models\CashReconciliationItem;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Delivery;
use App\Models\DeliveryFailure;
use App\Models\DeliveryItem;
use App\Models\DeliveryPayment;
use App\Models\DeliveryProof;
use App\Models\DeliveryStatusLog;
use App\Models\DeliveryTrackingLocation;
use App\Models\DeliveryTrackingSession;
use App\Models\DriverProfile;
use App\Models\FailedDeliveryReason;
use App\Models\NotificationLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$models = [
    Role::class,
    Permission::class,
    Business::class,
    BusinessBranch::class,
    User::class,
    Customer::class,
    CustomerAddress::class,
    DriverProfile::class,
    Delivery::class,
    DeliveryItem::class,
    DeliveryStatusLog::class,
    DeliveryTrackingSession::class,
    DeliveryTrackingLocation::class,
    DeliveryProof::class,
    FailedDeliveryReason::class,
    DeliveryFailure::class,
    DeliveryPayment::class,
    CashReconciliation::class,
    CashReconciliationItem::class,
    NotificationLog::class,
];

$ignoreFillable = ['id', 'created_at', 'updated_at', 'deleted_at', 'remember_token'];
$issues = [];

foreach ($models as $class) {
    $model = new $class;
    $table = $model->getTable();
    $columns = Schema::getColumnListing($table);
    $fillable = $model->getFillable();
    $casts = $model->getCasts();

    $expectedFillable = array_values(array_diff($columns, $ignoreFillable));
    $missingFillable = array_values(array_diff($expectedFillable, $fillable));
    $extraFillable = array_values(array_diff($fillable, $columns));

    if ($missingFillable !== []) {
        $issues[] = $class.' missing fillable: '.implode(', ', $missingFillable);
    }

    if ($extraFillable !== []) {
        $issues[] = $class.' has non-column fillable: '.implode(', ', $extraFillable);
    }

    $hasDeletedAt = in_array('deleted_at', $columns, true);
    $usesSoftDeletes = in_array(SoftDeletes::class, class_uses_recursive($class), true);

    if ($hasDeletedAt !== $usesSoftDeletes) {
        $issues[] = $class.' SoftDeletes mismatch. table deleted_at='
            .($hasDeletedAt ? 'yes' : 'no').', trait='.($usesSoftDeletes ? 'yes' : 'no');
    }

    foreach (DB::select('SHOW COLUMNS FROM `'.$table.'`') as $column) {
        $name = $column->Field;
        $type = strtolower($column->Type);

        if (in_array($name, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
            continue;
        }

        $cast = $casts[$name] ?? null;

        if (str_starts_with($type, 'decimal') && ! str_starts_with((string) $cast, 'decimal:')) {
            $issues[] = $class.' decimal column missing decimal cast: '.$name;
        }

        if ($type === 'tinyint(1)' && $cast !== 'boolean') {
            $issues[] = $class.' boolean column missing boolean cast: '.$name;
        }

        if (str_starts_with($type, 'timestamp') && $cast !== 'datetime') {
            $issues[] = $class.' timestamp column missing datetime cast: '.$name;
        }

        if ($type === 'date' && $cast !== 'date') {
            $issues[] = $class.' date column missing date cast: '.$name;
        }

        if (str_starts_with($type, 'enum') && $cast !== 'string') {
            $issues[] = $class.' enum column missing string cast: '.$name;
        }
    }

    $reflection = new ReflectionClass($class);

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== $class || $method->getNumberOfRequiredParameters() > 0) {
            continue;
        }

        $returnType = $method->getReturnType();

        if (! $returnType instanceof ReflectionNamedType || ! is_a($returnType->getName(), Relation::class, true)) {
            continue;
        }

        $relation = $method->invoke($model);
        $relationName = $class.'::'.$method->getName().'()';

        if ($relation instanceof BelongsTo) {
            if (! Schema::hasColumn($table, $relation->getForeignKeyName())) {
                $issues[] = $relationName.' foreign key missing on '.$table.': '.$relation->getForeignKeyName();
            }

            if (! Schema::hasColumn($relation->getRelated()->getTable(), $relation->getOwnerKeyName())) {
                $issues[] = $relationName.' owner key missing on '.$relation->getRelated()->getTable().': '.$relation->getOwnerKeyName();
            }
        } elseif ($relation instanceof HasMany || $relation instanceof HasOne) {
            $relatedTable = $relation->getRelated()->getTable();

            if (! Schema::hasColumn($relatedTable, $relation->getForeignKeyName())) {
                $issues[] = $relationName.' foreign key missing on '.$relatedTable.': '.$relation->getForeignKeyName();
            }

            if (! Schema::hasColumn($table, $relation->getLocalKeyName())) {
                $issues[] = $relationName.' local key missing on '.$table.': '.$relation->getLocalKeyName();
            }
        } elseif ($relation instanceof BelongsToMany) {
            $pivotTable = $relation->getTable();

            if (! Schema::hasTable($pivotTable)) {
                $issues[] = $relationName.' pivot table missing: '.$pivotTable;

                continue;
            }

            if (! Schema::hasColumn($pivotTable, $relation->getForeignPivotKeyName())) {
                $issues[] = $relationName.' pivot foreign key missing: '.$relation->getForeignPivotKeyName();
            }

            if (! Schema::hasColumn($pivotTable, $relation->getRelatedPivotKeyName())) {
                $issues[] = $relationName.' pivot related key missing: '.$relation->getRelatedPivotKeyName();
            }
        }
    }
}

if ($issues === []) {
    echo "MODEL VERIFICATION PASSED\n";
    echo "Checked fillables, casts, SoftDeletes, and declared relationship keys.\n";
    exit(0);
}

foreach ($issues as $issue) {
    echo "ISSUE: {$issue}\n";
}

exit(1);
