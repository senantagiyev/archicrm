<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Question bank: sections. room_type is set for per-room questionnaires —
        // those sections are instantiated once per brief_rooms row.
        Schema::create('brief_sections', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->json('name');       // translatable
            $table->json('intro')->nullable(); // translatable helper text
            $table->string('icon', 64)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('room_type', 64)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('brief_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brief_section_id')->constrained()->cascadeOnDelete();
            $table->string('key', 96);
            $table->json('label');           // translatable
            $table->json('help')->nullable(); // translatable "educational" note
            $table->string('type', 32);      // text/textarea/select/multiselect/number/boolean/scale
            $table->json('options')->nullable(); // [{value, label:{az,ru,en}}]
            $table->boolean('is_required')->default(false);
            $table->boolean('allows_designer_choice')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['brief_section_id', 'key']);
        });

        Schema::create('briefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 32)->default('draft'); // draft/in_progress/completed
            $table->unsignedTinyInteger('progress')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        // A client may describe several rooms of the same type ("Yataq otağı 2").
        Schema::create('brief_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brief_id')->constrained()->cascadeOnDelete();
            $table->string('room_type', 64);
            $table->string('label');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('brief_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brief_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brief_question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brief_room_id')->nullable()->constrained()->cascadeOnDelete();
            $table->json('value')->nullable();
            $table->boolean('delegated_to_designer')->default(false);
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->unique(['brief_id', 'brief_question_id', 'brief_room_id'], 'brief_answers_unique');
        });

        Schema::create('brief_section_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brief_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brief_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brief_room_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('status', 32)->default('empty'); // empty/in_progress/submitted
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['brief_id', 'brief_section_id', 'brief_room_id'], 'brief_section_states_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brief_section_states');
        Schema::dropIfExists('brief_answers');
        Schema::dropIfExists('brief_rooms');
        Schema::dropIfExists('briefs');
        Schema::dropIfExists('brief_questions');
        Schema::dropIfExists('brief_sections');
    }
};
