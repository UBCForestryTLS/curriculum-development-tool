<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CampusFacultyDepartmentSeeder::class,
            FacultyCourseCodeSeeder::class,
            MappingScaleCategoriesSeeder::class,
            MappingScaleSeeder::class,
            OkanaganSyllabusResourceSeeder::class,
            RoleSeeder::class,
            StandardCategorySeeder::class,
            StandardScaleCategorySeeder::class,
            StandardScaleSeeder::class,
            StandardSeeder::class,
        ]);
    }
}