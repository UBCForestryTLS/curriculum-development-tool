<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\LearningOutcome;
use App\Models\MappingScale;
use App\Models\Program;
use App\Models\ProgramLearningOutcome;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Sample programs and courses for testing the gap and redundancy report locally.
 */
class GapCoverageDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new \RuntimeException('This manual-testing dataset is for the local environment only.');
        }

        $email = 'coverage.tester@example.test';
        if (User::where('email', $email)->exists()) {
            $this->command?->info('Coverage demo account already exists; its password and edited data were preserved.');

            return;
        }

        $password = Str::random(20);
        $programs = DB::transaction(function () use ($email, $password) {
            $user = (new User)->forceFill([
                'name' => 'Coverage Report Tester',
                'email' => $email,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
                'has_temp' => false,
            ]);
            $user->save();
            $user->roles()->attach(DB::table('roles')->where('role', 'user')->sole()->id);

            // Add levels backwards to check that the report uses the program's level order.
            $scales = [];
            foreach (['A' => ['Advanced', '#0065bd'], 'D' => ['Developing', '#1aa7ff'], 'I' => ['Introduced', '#80bdff']] as $key => [$title, $colour]) {
                $scales[$key] = MappingScale::create([
                    'title' => $title, 'abbreviation' => $key, 'colour' => $colour,
                    'description' => 'Local coverage report demo level.',
                ])->map_scale_id;
            }
            MappingScale::firstOrCreate(['map_scale_id' => 0], [
                'title' => 'Not Applicable', 'abbreviation' => 'N/A',
                'description' => 'Not Applicable', 'colour' => '#ffffff',
            ]);

            $program = $this->program($user, 'Environmental Data Science (Coverage QA)', $scales);
            $plos = [];
            foreach ([
                ['PLO 1: Data analysis', 'Analyze environmental datasets using reproducible quantitative methods.'],
                ['PLO 2: Research design', 'Design and evaluate investigations of environmental questions.'],
                ['PLO 3: Communication', 'Communicate environmental evidence to specialist and public audiences.'],
                ['PLO 4: Ethics', 'Evaluate ethical responsibilities in collecting and using environmental data.'],
                ['PLO 5: Community partnership', 'Develop environmental projects in partnership with local communities.'],
            ] as $index => [$short, $text]) {
                $plos[$index + 1] = ProgramLearningOutcome::create([
                    'program_id' => $program->program_id, 'plo_shortphrase' => $short, 'pl_outcome' => $text,
                ])->pl_outcome_id;
            }

            foreach ($this->courses() as [$number, $title, $required, $outcomes]) {
                $course = (new Course)->forceFill([
                    'course_code' => 'ENVD', 'course_num' => $number,
                    'course_title' => $title.' (Coverage QA)',
                    'delivery_modality' => 'I', 'year' => 2026, 'semester' => 'W1', 'section' => 'QA',
                    'status' => 1, 'assigned' => 1, 'type' => 'unassigned',
                    'campus' => 'Okanagan', 'faculty' => 'Faculty of Science', 'department' => 'Earth and Environmental Sciences',
                    'standard_category_id' => DB::table('standard_categories')->value('standard_category_id'),
                    'scale_category_id' => DB::table('standards_scale_categories')->value('scale_category_id'),
                ]);
                $course->save();
                $user->courses()->attach($course->course_id, ['permission' => 1]);
                DB::table('course_programs')->insert([
                    'program_id' => $program->program_id, 'course_id' => $course->course_id,
                    'course_required' => $required, 'created_at' => now(), 'updated_at' => now(),
                ]);

                foreach ($outcomes as $position => [$text, $mappings]) {
                    $clo = (new LearningOutcome)->forceFill([
                        'course_id' => $course->course_id, 'l_outcome' => $text,
                        'clo_shortphrase' => 'CLO '.($position + 1), 'pos_in_alignment' => $position,
                    ]);
                    $clo->save();
                    foreach ($plos as $ploNumber => $ploId) {
                        // Leave these mappings unfinished to test the incomplete-mapping warning.
                        if ($ploNumber === 5 && $number === 399) {
                            continue;
                        }
                        foreach (str_split($mappings[$ploNumber] ?? 'N') as $level) {
                            DB::table('outcome_maps')->insert([
                                'l_outcome_id' => $clo->l_outcome_id, 'pl_outcome_id' => $ploId,
                                'map_scale_id' => $level === 'N' ? 0 : $scales[$level],
                                'created_at' => now(), 'updated_at' => now(),
                            ]);
                        }
                    }
                }
            }

            // Also test a program with no courses.
            $empty = $this->program($user, 'Empty Program (Coverage QA)', $scales);
            ProgramLearningOutcome::create([
                'program_id' => $empty->program_id, 'plo_shortphrase' => 'PLO 1: No data yet',
                'pl_outcome' => 'Analyze evidence once courses have been added to the program.',
            ]);

            return [$program, $empty];
        });

        $this->command?->info("Email: {$email}\nPassword: {$password}");
        foreach ($programs as $program) {
            $this->command?->info($program->program.': '.url("programWizard/{$program->program_id}/step4"));
        }
    }

    private function program(User $user, string $name, array $scales): Program
    {
        $program = (new Program)->forceFill([
            'program' => $name, 'level' => 'Undergraduate', 'status' => 1,
            'campus' => 'Okanagan', 'faculty' => 'Faculty of Science', 'department' => 'Earth and Environmental Sciences',
        ]);
        $program->save();
        $user->programs()->attach($program->program_id, ['permission' => 1]);
        foreach (['I', 'D', 'A'] as $position => $level) {
            DB::table('mapping_scale_programs')->insert([
                'program_id' => $program->program_id, 'map_scale_id' => $scales[$level],
                'position' => $position + 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $program;
    }

    private function courses(): array
    {
        // Each course has a number, title, required status (1/0/null), and CLOs.
        // Mappings use PLO number => levels, so 'ID' maps the same CLO at both levels.
        return [
            [101, 'Environmental Systems', 1, [
                ['Interpret environmental observations and formulate a testable question.', [1 => 'I', 2 => 'I']],
                ['Summarize patterns in a simple ecosystem dataset.', [1 => 'I']],
                ['Explain an environmental process to a public audience.', [3 => 'I']],
            ]],
            [102, 'Introduction to Environmental Statistics', 1, [
                ['Calculate descriptive statistics and identify sampling limitations.', [1 => 'I', 2 => 'I']],
                ['Interpret an environmental dataset and identify ethical data handling practices.', [1 => 'I', 4 => 'I']],
                ['Identify appropriate variables and controls for a field investigation.', [2 => 'I']],
            ]],
            [201, 'Data Management and Visualization', 1, [
                ['Clean a dataset and justify a reproducible analysis workflow.', [1 => 'ID', 2 => 'D']],
                ['Compare analytical approaches for an environmental research question.', [1 => 'D', 2 => 'D']],
                ['Describe distributions using tabular and graphical summaries.', [1 => 'I']],
            ]],
            [202, 'Field Research Methods', 1, [
                ['Collect, interpret and evaluate data from a field investigation.', [1 => 'ID', 2 => 'D']],
                ['Apply uncertainty estimates to environmental measurements.', [1 => 'D']],
                ['Prepare a field report that communicates evidence and limitations.', [3 => 'D']],
            ]],
            [301, 'Applied Environmental Modelling', 0, [
                ['Explain, apply and critically evaluate a model using an independently designed investigation.', [1 => 'IDA', 2 => 'A']],
                ['Defend a modelling study and communicate conclusions to a decision maker.', [2 => 'A', 3 => 'A']],
                ['Evaluate ethical trade-offs in the use of predictive environmental models.', [4 => 'D']],
            ]],
            [302, 'Climate Communication Studio', 0, [
                ['Evaluate climate datasets and design an audience research study.', [1 => 'D', 2 => 'A']],
                ['Analyze uncertainty in regional climate projections.', [1 => 'D']],
                ['Create an evidence-based public climate communication campaign.', [3 => 'A']],
            ]],
            [399, 'Independent Environmental Study', null, [
                ['Explain and apply analytical methods to an independent dataset.', [1 => 'ID']],
                ['Present an independent investigation with clear supporting evidence.', [3 => 'D']],
                ['Develop a personal learning plan and reflect on study habits.', []],
            ]],
            [499, 'Community Partnership Practicum — Draft', 0, []],
        ];
    }
}
