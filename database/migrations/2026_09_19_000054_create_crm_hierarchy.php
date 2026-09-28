<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
        });
        Schema::create('crm_subdepartments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('crm_departments')->restrictOnDelete();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['department_id', 'name']);
        });
        Schema::create('crm_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subdepartment_id')->constrained('crm_subdepartments')->restrictOnDelete();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['subdepartment_id', 'name']);
        });
        Schema::create('crm_team_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('crm_teams')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
        });
        Schema::create('crm_visibility_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope_type', 20);
            $table->unsignedBigInteger('scope_id')->default(0);
            $table->timestamps();
            $table->unique(['organization_id', 'user_id', 'scope_type', 'scope_id'], 'crm_visibility_grants_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_visibility_grants');
        Schema::dropIfExists('crm_team_memberships');
        Schema::dropIfExists('crm_teams');
        Schema::dropIfExists('crm_subdepartments');
        Schema::dropIfExists('crm_departments');
    }
};
