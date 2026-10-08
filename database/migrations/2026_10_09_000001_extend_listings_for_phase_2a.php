<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_workflow_statuses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 120);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });

        Schema::table('listings', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1);
            $table->string('workflow_status', 40)->nullable();
            $table->foreignId('cost_centre_id')->nullable()->constrained('accounting_cost_centres')->restrictOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('owners')->restrictOnDelete();
            $table->string('listing_category', 80)->nullable();
            $table->string('unit_category', 80)->nullable();
            $table->string('emirate', 100)->nullable();
            $table->string('community', 160)->nullable();
            $table->string('sub_community', 160)->nullable();
            $table->string('trakheesi_permit', 100)->nullable();
            $table->string('dld_permit', 100)->nullable();
            $table->string('bedroom_type', 60)->nullable();
            $table->unsignedSmallInteger('bedrooms')->nullable();
            $table->unsignedSmallInteger('bathrooms')->nullable();
            $table->unsignedSmallInteger('balconies')->nullable();
            $table->unsignedSmallInteger('parking_spaces')->nullable();
            $table->decimal('size_sqft', 14, 2)->nullable();
            $table->decimal('plot_size_sqft', 14, 2)->nullable();
            $table->string('furnishing', 60)->nullable();
            $table->string('completion_status', 60)->nullable();
            $table->date('handover_date')->nullable();
            $table->string('grade', 40)->nullable();
            $table->boolean('loading_bay')->nullable();
            $table->string('fit_out', 80)->nullable();
            $table->string('price_type', 40)->nullable();
            $table->decimal('price_min', 16, 2)->nullable();
            $table->decimal('price_max', 16, 2)->nullable();
            $table->string('price_label', 100)->nullable();
            $table->string('developer_name', 160)->nullable();
            $table->json('portals')->nullable();
            $table->index(['organization_id', 'workflow_status']);
            $table->index(['organization_id', 'emirate', 'community']);
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cost_centre_id');
            $table->dropConstrainedForeignId('owner_id');
            $table->dropIndex(['organization_id', 'workflow_status']);
            $table->dropIndex(['organization_id', 'emirate', 'community']);
            $table->dropColumn(['version', 'workflow_status', 'listing_category', 'unit_category', 'emirate', 'community', 'sub_community', 'trakheesi_permit', 'dld_permit', 'bedroom_type', 'bedrooms', 'bathrooms', 'balconies', 'parking_spaces', 'size_sqft', 'plot_size_sqft', 'furnishing', 'completion_status', 'handover_date', 'grade', 'loading_bay', 'fit_out', 'price_type', 'price_min', 'price_max', 'price_label', 'developer_name', 'portals']);
        });
        Schema::dropIfExists('listing_workflow_statuses');
    }
};
