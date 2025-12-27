<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Crear tabla `educational_content`
        Schema::create('educational_content', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement(); // Definirlo explícito para consistencia
            
            $table->enum('content_type', ['course', 'article']);
            
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('thumbnail_url')->nullable();
            
            $table->foreignId('author_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('category_id')->nullable()->constrained('course_categories')->onDelete('set null');
            $table->json('tags')->nullable();
            
            $table->enum('difficulty_level', ['principiante', 'intermedio', 'avanzado'])->default('principiante'); // Corregido default en enum
            $table->integer('estimated_duration')->default(0);
            
            $table->boolean('is_published')->default(0);
            $table->boolean('is_featured')->default(0);
            $table->enum('status', ['draft', 'pending', 'reviewed', 'published'])->default('draft');
            
            // Note: 'references' is listed in revised plan for educational_content?
            // "references" json is in the provided SQL in revised plan section 2.1 [NUEVO] educational_content
            // Wait, previous prompt said references shared. Revised plan 2.1 includes it.
            // But Revised Plan 2.1 also shows `references` in `article_details`.
            // Let's check user's SQL: 
            // educational_content has: `references` json (optional, not explicitly in the list description but visible in the insert/select in 2.2)
            // Actually in 2.2 Step 1 insert list: ..., `references`, ...
            // So it IS in educational_content.
            $table->json('references')->nullable();

            $table->integer('view_count')->default(0);
            $table->decimal('rating_average', 3, 2)->default(0.00);
            $table->integer('rating_count')->default(0);
            
            $table->timestamps();
            
            // Indexes
            $table->index('content_type');
            $table->index(['status', 'is_published']);
            $table->fullText(['title', 'description']); 
            // User requested: FULLTEXT idx_title_description (title, description)
            // Laravel fullText supports single or array.
        });

        // 2. `course_details`
        Schema::create('course_details', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            
            $table->integer('completion_points')->default(100);
            $table->foreignId('achievement_id')->nullable(); 
            
            $table->json('related_taxa')->nullable();
            $table->json('target_location_ids')->nullable();
            
            $table->integer('enrollment_count')->default(0);
            $table->decimal('completion_rate', 5, 2)->default(0.00);
            
            $table->boolean('has_certificate')->default(0);
            $table->json('prerequisite_content_ids')->nullable();
            
            $table->foreign('id')->references('id')->on('educational_content')->onDelete('cascade');
        });

        // 3. `article_details`
        Schema::create('article_details', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            
            $table->longText('content_text');
            
            $table->string('audio_url')->nullable();
            $table->json('audio_timestamps')->nullable();
            $table->string('voice_id')->nullable();
            
            // In 2.1 article_details, `references` IS listed.
            // But if it's in educational_content, should it be here too?
            // The prompt "Las tablas courses y articles comparten aproximadamente 15 campos idénticos (..., references, ...)"
            // So it should be in base.
            // However, 2.1 `article_details` listing shows `references json`.
            // I will put it in base as per "duplicate removal" goal, but if User specifically listed it in article_details in 2.1, maybe they want it specific?
            // RE-READING 2.2 Step 2: "INSERT INTO educational_content ... `references`"
            // So it goes to base. 
            // But wait, 2.1 Article Details list: "references json".
            // I'll assume it's in base to follow CTI "Eliminación total de duplicación". 
            // If I put it in base, I won't put it in details unless specific need.
            
            $table->integer('read_time')->default(0);
            $table->integer('word_count')->default(0);
            
            $table->json('related_taxa')->nullable();
            
            $table->foreign('id')->references('id')->on('educational_content')->onDelete('cascade');
            $table->fullText('content_text');
        });

        // 4. `activities` (Correct polymorphic names)
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            
            // activitable_id, activitable_type
            $table->unsignedBigInteger('activitable_id');
            $table->string('activitable_type', 50);
            
            $table->string('title');
            $table->integer('activity_order');
            $table->enum('activity_type', [
                'quiz_multiple', 'quiz_true_false', 'drag_drop', 'matching', 
                'fill_blanks', 'image_hotspot', 'classification', 'sequencing', 
                'memory_game', 'word_search', 'crossword'
            ]);
            
            $table->text('instructions')->nullable();
            $table->json('content_data');
            $table->json('correct_answers')->nullable();
            $table->json('hints')->nullable();
            
            $table->integer('max_points')->default(10);
            $table->integer('passing_score')->default(7);
            $table->integer('time_limit')->nullable();
            $table->integer('attempts_allowed')->default(3);
            
            $table->text('success_message')->nullable();
            $table->text('failure_message')->nullable();
            $table->text('explanation')->nullable();
            
            $table->boolean('is_mandatory')->default(1);
            
            $table->timestamps();
            
            $table->index(['activitable_type', 'activitable_id'], 'idx_polymorphic');
            $table->index(['activitable_id', 'activity_order'], 'idx_activity_order');
        });

        // 5. `user_content_enrollments`
        Schema::create('user_content_enrollments', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('content_id')->constrained('educational_content')->onDelete('cascade');
            
            $table->timestamp('enrolled_at')->useCurrent();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_accessed_at')->nullable();
            
            $table->unsignedBigInteger('current_lesson_id')->nullable();
            $table->integer('lessons_completed')->default(0);
            $table->integer('total_lessons')->default(0);
            $table->decimal('progress_percentage', 5, 2)->default(0.00);
            
            $table->integer('total_points_earned')->default(0);
            $table->integer('total_points_possible')->default(0);
            $table->decimal('final_score', 5, 2)->nullable();
            
            $table->integer('total_time_spent')->default(0);
            
            $table->integer('user_rating')->nullable();
            $table->text('user_feedback')->nullable();
            
            $table->timestamps();
            
            $table->unique(['user_id', 'content_id'], 'unique_user_content');
            $table->index(['user_id', 'completed_at'], 'idx_user_enrollments');
        });

        // 6. `user_article_progress`
        Schema::create('user_article_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('article_id')->constrained('educational_content')->onDelete('cascade');
            $table->foreignId('enrollment_id')->constrained('user_content_enrollments')->onDelete('cascade');
            
            $table->enum('status', ['no_iniciada', 'en_progreso', 'completada'])->default('no_iniciada');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_accessed_at')->nullable();
            
            $table->decimal('reading_progress', 5, 2)->default(0.00);
            $table->integer('last_position')->nullable();
            
            $table->integer('activities_completed')->default(0);
            $table->integer('total_activities')->default(0);
            $table->integer('points_earned')->default(0);
            $table->integer('points_possible')->default(0);
            
            $table->integer('time_spent')->default(0);
            $table->timestamps();
            
            $table->unique(['user_id', 'article_id'], 'unique_user_article');
        });
        
        // 7. Update Lessons Schema (Renaming course_id -> content_id)
        Schema::table('lessons', function (Blueprint $table) {
             if (Schema::hasColumn('lessons', 'course_id')) {
                 try {
                     $table->dropForeign(['course_id']);
                 } catch (\Exception $e) {}

                 $table->renameColumn('course_id', 'content_id');
                 $table->foreign('content_id')->references('id')->on('educational_content')->onDelete('cascade');
             }
        });

        // 8. Update user_lesson_progress Schema
        Schema::table('user_lesson_progress', function (Blueprint $table) {
             if (Schema::hasColumn('user_lesson_progress', 'enrollment_id')) {
                 try {
                    $table->dropForeign(['enrollment_id']);
                 } catch (\Exception $e) {}
                 
                 $table->foreign('enrollment_id')->references('id')->on('user_content_enrollments')->onDelete('cascade');
             }
        });

        // 9. Triggers
        DB::unprepared('DROP TRIGGER IF EXISTS `validate_course_has_lessons`');
        DB::unprepared('
            CREATE TRIGGER `validate_course_has_lessons`
            BEFORE INSERT ON `lessons`
            FOR EACH ROW
            BEGIN
                DECLARE content_type_val VARCHAR(50);
                SELECT content_type INTO content_type_val FROM educational_content WHERE id = NEW.content_id;
                IF content_type_val != "course" THEN
                    SIGNAL SQLSTATE "45000" SET MESSAGE_TEXT = "Solo los cursos pueden tener lecciones";
                END IF;
            END
        ');
        
        DB::unprepared('DROP TRIGGER IF EXISTS `update_enrollment_count_insert`');
        DB::unprepared('
            CREATE TRIGGER `update_enrollment_count_insert`
            AFTER INSERT ON `user_content_enrollments`
            FOR EACH ROW
            BEGIN
                DECLARE content_type_val VARCHAR(50);
                SELECT content_type INTO content_type_val FROM educational_content WHERE id = NEW.content_id;
                IF content_type_val = "course" THEN
                    UPDATE course_details SET enrollment_count = enrollment_count + 1 WHERE id = NEW.content_id;
                END IF;
            END
        ');

        DB::unprepared('DROP TRIGGER IF EXISTS `update_enrollment_count_delete`');
        DB::unprepared('
            CREATE TRIGGER `update_enrollment_count_delete`
            AFTER DELETE ON `user_content_enrollments`
            FOR EACH ROW
            BEGIN
              UPDATE course_details 
              SET enrollment_count = enrollment_count - 1
              WHERE id = OLD.content_id AND enrollment_count > 0;
            END
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `validate_course_has_lessons`');
        DB::unprepared('DROP TRIGGER IF EXISTS `update_enrollment_count_insert`');
        DB::unprepared('DROP TRIGGER IF EXISTS `update_enrollment_count_delete`');

        Schema::table('lessons', function (Blueprint $table) {
            $table->dropForeign(['content_id']);
            $table->renameColumn('content_id', 'course_id');
        });
        
        Schema::table('user_lesson_progress', function (Blueprint $table) {
            $table->dropForeign(['enrollment_id']);
            // Impossible to restore exact previous FK without the table it pointed to (user_course_enrollments) which is also dropped below?
            // Actually new tables are dropped below. Old tables are renamed in backup phase (manual/command).
            // So if we reverse, we expect old tables to be there?
        });

        Schema::dropIfExists('user_article_progress');
        Schema::dropIfExists('user_content_enrollments');
        Schema::dropIfExists('activities');
        Schema::dropIfExists('article_details');
        Schema::dropIfExists('course_details');
        Schema::dropIfExists('educational_content');
    }
};
