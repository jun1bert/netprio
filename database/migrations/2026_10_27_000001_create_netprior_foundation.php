<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->constrained()->restrictOnDelete();
        });
        foreach (['locations', 'asset_types'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name) {
                $table->id();
                $table->string('name');
                if ($name === 'locations') {
                    $table->string('building')->nullable();
                    $table->string('floor', 50)->nullable();
                } else {
                    $table->unique('name');
                }
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
            });
        }
        Schema::create('network_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->string('asset_code', 100)->unique();
            $table->string('name')->index();
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable()->index();
            $table->ipAddress('ip_address')->nullable();
            $table->macAddress('mac_address')->nullable();
            $table->date('installation_date')->nullable();
            $table->date('warranty_expiry')->nullable();
            $table->decimal('acquisition_cost', 15, 2)->nullable();
            $table->enum('status', ['active', 'inactive', 'under_maintenance', 'retired'])->default('active')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('criteria', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('direction', ['benefit', 'cost']);
            // Null until a complete, consistent AHP calculation is accepted.
            $table->double('weight')->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('network_asset_id')->constrained()->restrictOnDelete();
            $table->foreignId('assessed_by')->constrained('users')->restrictOnDelete();
            $table->date('assessment_date');
            $table->enum('status', ['draft', 'completed'])->default('draft');
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->index(['network_asset_id', 'status', 'assessment_date']);
            $table->index(['status', 'assessment_date']);
        });
        Schema::create('assessment_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->restrictOnDelete();
            $table->foreignId('criterion_id')->constrained('criteria')->restrictOnDelete();
            $table->double('raw_value')->nullable();
            $table->string('raw_unit', 100)->nullable();
            $table->double('score');
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->unique(['assessment_id', 'criterion_id']);
        });
        Schema::create('pairwise_comparisons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('criterion_a_id')->constrained('criteria')->restrictOnDelete();
            $table->foreignId('criterion_b_id')->constrained('criteria')->restrictOnDelete();
            $table->double('comparison_value');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            // Canonical ordering (a < b) also prevents reverse duplicates.
            $table->unique(['criterion_a_id', 'criterion_b_id'], 'pairwise_criteria_unique');
        });
        Schema::create('prioritization_runs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->double('consistency_index')->nullable();
            $table->double('consistency_ratio')->nullable();
            $table->enum('status', ['draft', 'completed'])->default('draft')->index();
            $table->timestamp('completed_at')->nullable()->index();
            // Snapshot names, directions, weights, matrix, thresholds and algorithm version.
            $table->json('decision_snapshot')->nullable();
            $table->timestamps();
        });
        Schema::create('prioritization_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prioritization_run_id')->constrained()->restrictOnDelete();
            $table->foreignId('network_asset_id')->constrained()->restrictOnDelete();
            $table->foreignId('assessment_id')->constrained()->restrictOnDelete();
            $table->double('final_score');
            $table->unsignedInteger('rank');
            $table->enum('priority_level', ['critical', 'high', 'medium', 'low'])->index();
            $table->text('recommendation');
            $table->json('asset_snapshot');
            $table->json('score_breakdown');
            $table->timestamps();
            $table->unique(['prioritization_run_id', 'network_asset_id'], 'run_asset_unique');
            // Ties may share a rank; rank is deliberately not unique.
            $table->index(['prioritization_run_id', 'rank']);
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 100)->index();
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->text('description');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['entity_type', 'entity_id']);
        });

        $this->constraint('network_assets', 'asset_cost_nonnegative', 'acquisition_cost IS NULL OR acquisition_cost >= 0');
        $this->constraint('criteria', 'criterion_weight_range', 'weight IS NULL OR (weight >= 0 AND weight <= 1)');
        $this->constraint('assessment_scores', 'assessment_score_range', 'score >= 1 AND score <= 5');
        $this->constraint('pairwise_comparisons', 'pairwise_canonical_order', 'criterion_a_id < criterion_b_id');
        $this->constraint('pairwise_comparisons', 'pairwise_value_range', 'comparison_value >= 0.1111111111111111 AND comparison_value <= 9');
        $this->constraint('prioritization_results', 'result_score_range', 'final_score >= 0 AND final_score <= 100');
        $this->constraint('prioritization_results', 'result_rank_positive', 'rank >= 1');
    }

    /** SQLite cannot add CHECK constraints after CREATE TABLE; use equivalent triggers in tests. */
    private function constraint(string $table, string $name, string $expression): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $qualified = preg_replace_callback('/\b[a-z_]+\b/', function ($match) {
                return in_array(strtoupper($match[0]), ['IS', 'NULL', 'OR', 'AND']) ? $match[0] : 'NEW.'.$match[0];
            }, $expression);
            foreach (['INSERT', 'UPDATE'] as $event) {
                DB::unprepared("CREATE TRIGGER {$name}_{$event} BEFORE {$event} ON {$table} WHEN NOT ({$qualified}) BEGIN SELECT RAISE(ABORT, '{$name}'); END");
            }
        } else {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }

    public function down(): void
    {
        foreach (['audit_logs', 'prioritization_results', 'prioritization_runs', 'pairwise_comparisons', 'assessment_scores', 'assessments', 'criteria', 'network_assets', 'asset_types', 'locations'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
        });
        Schema::dropIfExists('roles');
    }
};
