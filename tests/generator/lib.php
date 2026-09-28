<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Data generator for mod_aiinteractivevideo.
 *
 * @package    mod_aiinteractivevideo
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_aiinteractivevideo_generator extends testing_module_generator {
    /** @var string A short timed transcript used when none is given. */
    public const SAMPLE_TRANSCRIPT = "0:00\nThe heart is a muscular pump that moves blood around the body.\n" .
        "0:08\nIt has four chambers: two atria on top and two ventricles below.\n" .
        "0:16\nValves between the chambers stop blood flowing backwards.\n" .
        "0:24\nThe right ventricle pumps blood to the lungs to collect oxygen.\n" .
        "0:32\nOxygen-rich blood returns to the left atrium through the pulmonary veins.\n" .
        "0:40\nThe left ventricle then pumps blood through the aorta to the body.\n" .
        "0:48\nArteries carry blood away from the heart and veins bring it back.\n" .
        "0:56\nCapillaries connect arteries and veins inside the tissues.\n" .
        "1:04\nA healthy heart beats about seventy times every minute at rest.\n" .
        "1:12\nExercise makes the heart stronger and lowers the resting heart rate.\n";

    /**
     * Creates an instance with sensible defaults and its interactions (made from the transcript, so no LMS Labs call).
     *
     * Pass 'withsections' => false to get an activity that has not been built yet.
     *
     * @param array|stdClass $record
     * @param array|null $options
     * @return stdClass
     */
    public function create_instance($record = null, ?array $options = null) {
        $record = (object)(array)$record;
        $defaults = [
            'videourl' => 'https://www.youtube.com/watch?v=ruM4Xxhx32U',
            'transcript' => self::SAMPLE_TRANSCRIPT,
            'numinteractions' => 3,
            'generator' => 'ai',
            'types' => implode(',', \mod_aiinteractivevideo\local\interaction::TYPES),
            'allowlearn' => 1,
            'allowtest' => 1,
            'preventskip' => 1,
            'sounds' => 1,
            'leaderboard' => 1,
            'accent' => 'indigo',
            'maxtries' => 3,
            'scoremode' => 1,
            'grademethod' => 1,
            'maxattempts' => 0,
            'completionfinish' => 0,
        ];
        foreach ($defaults as $key => $value) {
            if (!isset($record->$key)) {
                $record->$key = $value;
            }
        }
        $withsections = !isset($record->withsections) || !empty($record->withsections);
        unset($record->withsections);
        $instance = parent::create_instance($record, (array)$options);
        if ($withsections) {
            global $DB;
            $full = $DB->get_record('aiinteractivevideo', ['id' => $instance->id], '*', MUST_EXIST);
            $sections = \mod_aiinteractivevideo\local\builtin_generator::generate(
                (string)$full->transcript,
                (int)$full->numinteractions,
                explode(',', (string)$full->types),
                (int)$full->videoduration
            );
            \mod_aiinteractivevideo\local\manager::replace_sections($full, $sections);
            // As if LMS Labs AI had finished the generation.
            $DB->set_field(
                'aiinteractivevideo',
                'genstatus',
                \mod_aiinteractivevideo\local\manager::GEN_READY,
                ['id' => $instance->id]
            );
            \mod_aiinteractivevideo\local\manager::forget_generation((int)$instance->id);
            $instance->grade = $DB->get_field('aiinteractivevideo', 'grade', ['id' => $instance->id]);
        }
        return $instance;
    }
}
