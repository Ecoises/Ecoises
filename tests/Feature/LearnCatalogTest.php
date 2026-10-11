<?php

namespace Tests\Feature;

use App\Models\ContentCategory;
use App\Models\EducationalContent;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LearnCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('full_name')->nullable();
            $table->text('avatar')->nullable();
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });

        Schema::create('educational_content', function (Blueprint $table): void {
            $table->id();
            $table->string('content_type')->nullable();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->foreignId('author_id');
            $table->json('tags')->nullable();
            $table->string('difficulty_level')->default('principiante');
            $table->integer('estimated_duration')->default(0);
            $table->boolean('is_published')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->string('status')->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->integer('view_count')->default(0);
            $table->decimal('rating_average', 3, 2)->default(0);
            $table->integer('rating_count')->default(0);
            $table->timestamps();
        });

        Schema::create('lessons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_id');
            $table->string('title');
            $table->integer('lesson_order')->default(1);
            $table->boolean('is_published')->default(false);
            $table->timestamps();
        });

        Schema::create('content_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->timestamps();
        });

        Schema::create('category_content', function (Blueprint $table): void {
            $table->foreignId('category_id');
            $table->foreignId('content_id');
        });
    }

    private function content(User $author, string $title, string $type, string $status = 'published'): EducationalContent
    {
        return EducationalContent::create([
            'content_type' => $type,
            'title' => $title,
            'slug' => str($title)->slug(),
            'description' => "Sobre {$title}",
            'author_id' => $author->id,
            'status' => $status,
            'is_published' => $status === 'published',
            'published_at' => $status === 'published' ? now()->subDay() : null,
        ]);
    }

    public function test_an_empty_catalog_reports_zero_without_errors(): void
    {
        $this->getJson('/api/educational-contents')
            ->assertOk()
            ->assertJsonPath('meta.facets.total', 0)
            ->assertJsonPath('data', []);
    }

    public function test_the_catalog_is_light_filterable_and_hides_private_author_data(): void
    {
        $author = User::create(['full_name' => 'Ana Bióloga', 'email' => 'ana@example.com', 'password' => Hash::make('secret')]);
        $snakes = $this->content($author, 'Serpientes de Colombia', 'course');
        $this->content($author, 'Murciélagos polinizadores', 'article');
        $this->content($author, 'Borrador sin publicar', 'article', 'draft');

        $category = ContentCategory::create(['name' => 'Ama a los no amados', 'slug' => 'ama-a-los-no-amados']);
        $snakes->categories()->attach($category->id);

        $this->getJson('/api/educational-contents')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.facets.total', 2)
            ->assertJsonPath('meta.facets.types.course', 1)
            ->assertJsonPath('meta.facets.categories.0.slug', 'ama-a-los-no-amados')
            ->assertJsonMissingPath('data.0.author.email');

        $this->getJson('/api/educational-contents?type=course')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Serpientes de Colombia');

        $this->getJson('/api/educational-contents?q=murci')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.content_type', 'article');

        $this->getJson('/api/educational-contents?category=ama-a-los-no-amados')
            ->assertJsonCount(1, 'data');
    }
}
