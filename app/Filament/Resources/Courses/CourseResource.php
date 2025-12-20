<?php

namespace App\Filament\Resources\Courses;

use BackedEnum;
use App\Models\Course;
use App\Services\ElevenLabsService;

use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Notifications\Notification;

use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;



use App\Filament\Resources\Courses\Pages\CreateCourse;
use App\Filament\Resources\Courses\Pages\EditCourse;
use App\Filament\Resources\Courses\Pages\ListCourses;
use App\Filament\Resources\Courses\Pages\ViewCourse;
use App\Filament\Resources\Courses\Tables\CoursesTable;

use App\Filament\Resources\Courses\Schemas\CourseInfolist;
use App\Filament\Resources\Courses\Schemas\CourseForm;

class CourseResource extends Resource
{
    protected static ?  string $model = Course::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?  string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return CourseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CoursesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function infolist(Schema $schema): Schema
    {
        return CourseInfolist::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListCourses::route('/'),
            'create' => CreateCourse::route('/create'),
            'view'   => ViewCourse::route('/{record}'),
            'edit'   => EditCourse::route('/{record}/edit'),
        ];
    }
}