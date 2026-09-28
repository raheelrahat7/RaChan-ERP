<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offplan_developers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('reference')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
        });
        Schema::create('offplan_projects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('developer_id')->constrained('offplan_developers')->restrictOnDelete();
            $table->foreignId('cost_centre_id')->nullable()->constrained('accounting_cost_centres')->restrictOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->string('emirate', 30);
            $table->string('location')->nullable();
            $table->date('completion_on')->nullable();
            $table->decimal('commission_rate', 6, 2)->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });
        Schema::create('offplan_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('offplan_projects')->restrictOnDelete();
            $table->string('number', 50);
            $table->string('type', 40)->nullable();
            $table->decimal('area_sqft', 12, 2)->nullable();
            $table->decimal('price_aed', 16, 2);
            $table->string('status', 20)->default('available');
            $table->timestamps();
            $table->unique(['project_id', 'number']);
        });
        Schema::create('offplan_payment_milestones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('offplan_projects')->restrictOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('label');
            $table->decimal('percentage', 5, 2);
            $table->date('due_on')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'sequence']);
        });
        Schema::create('offplan_deals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('offplan_projects')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('offplan_units')->restrictOnDelete();
            $table->foreignId('lead_id')->constrained('crm_leads')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('reference', 50);
            $table->decimal('price_aed', 16, 2);
            $table->string('status', 20)->default('enquiry');
            $table->date('contracted_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'reference']);
            $table->index(['organization_id', 'unit_id', 'status'], 'offplan_deal_unit_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offplan_deals');
        Schema::dropIfExists('offplan_payment_milestones');
        Schema::dropIfExists('offplan_units');
        Schema::dropIfExists('offplan_projects');
        Schema::dropIfExists('offplan_developers');
    }
};
