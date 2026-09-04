<?php

namespace Tests\Feature;

use App\Models\EducationalContent;
use App\Models\User;
use App\Services\EducationalWorkflowNotificationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EducationalWorkflowNotificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('full_name')->nullable();
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });

        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
        });

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('notifiable_type');
            $table->unsignedBigInteger('notifiable_id');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('educational_content', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('slug');
            $table->foreignId('author_id');
            $table->string('status')->default('draft');
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_editors_and_authors_receive_their_workflow_notifications(): void
    {
        $author = $this->user('educador@ecois.es');
        $editor = $this->user('editor@ecois.es');
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'editor',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('model_has_roles')->insert([
            'role_id' => $roleId,
            'model_type' => User::class,
            'model_id' => $editor->id,
        ]);
        $content = EducationalContent::create([
            'title' => 'Restauración ecológica',
            'slug' => 'restauracion-ecologica',
            'author_id' => $author->id,
        ]);

        $service = app(EducationalWorkflowNotificationService::class);
        $service->submittedForReview($content->load('author'));
        $service->reviewed($content);

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $editor->id]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $author->id]);
        $this->assertSame(2, DB::table('notifications')->count());
    }

    private function user(string $email): User
    {
        return User::create([
            'full_name' => 'Persona de prueba',
            'email' => $email,
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
    }
}
