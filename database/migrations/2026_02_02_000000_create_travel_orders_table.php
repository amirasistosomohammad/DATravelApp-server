<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('travel_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personnel_id')->constrained('personnel')->cascadeOnDelete();
            $table->string('travel_purpose', 500);
            $table->string('destination', 255);
            $table->date('start_date');
            $table->date('end_date');
            $table->text('objectives')->nullable();
            $table->decimal('per_diems_expenses', 12, 2)->nullable();
            $table->string('appropriation', 255)->nullable();
            $table->text('remarks')->nullable();
            $table->string('status', 50)->default('draft'); // draft | pending | approved | rejected (Phase 5+)
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('travel_orders');
    }
};
