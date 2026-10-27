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

namespace mod_aiinteractivevideo\local;

/**
 * Where an activity's video comes from: a YouTube link or an uploaded MP4 file.
 *
 * @package    mod_aiinteractivevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class video {
    /** @var string The video is a YouTube link. */
    public const SOURCE_YOUTUBE = 'youtube';

    /** @var string The video is an MP4 file uploaded to Moodle. */
    public const SOURCE_UPLOAD = 'upload';

    /** @var string File area that holds the uploaded video. */
    public const FILEAREA = 'video';

    /** @var string[] Video file types teachers can upload. */
    public const VIDEO_TYPES = ['.mp4'];

    /** @var string[] Transcript file types teachers can upload. */
    public const TRANSCRIPT_TYPES = ['.vtt', '.srt', '.txt'];

    /** @var int Largest transcript file that is read (bytes). */
    public const TRANSCRIPT_MAXBYTES = 2097152;

    /**
     * The activity's video source, defaulting to YouTube for activities made before uploads existed.
     *
     * @param \stdClass $instance
     * @return string
     */
    public static function source(\stdClass $instance): string {
        return ($instance->videosource ?? '') === self::SOURCE_UPLOAD ? self::SOURCE_UPLOAD : self::SOURCE_YOUTUBE;
    }

    /**
     * File manager options for the video upload.
     *
     * @param \stdClass|null $course
     * @return array
     */
    public static function filemanager_options(?\stdClass $course = null): array {
        global $CFG;
        $maxbytes = (int)$CFG->maxbytes;
        if ($course) {
            $maxbytes = get_user_max_upload_file_size(
                \context_course::instance($course->id),
                $CFG->maxbytes,
                $course->maxbytes
            );
        }
        return [
            'subdirs' => 0,
            'maxfiles' => 1,
            'maxbytes' => $maxbytes,
            'accepted_types' => self::VIDEO_TYPES,
            'return_types' => FILE_INTERNAL,
        ];
    }

    /**
     * The uploaded video file, if there is one.
     *
     * @param \context $context module context
     * @return \stored_file|null
     */
    public static function file(\context $context): ?\stored_file {
        $files = get_file_storage()->get_area_files(
            $context->id,
            'mod_aiinteractivevideo',
            self::FILEAREA,
            0,
            'sortorder DESC, id ASC',
            false
        );
        return $files ? reset($files) : null;
    }

    /**
     * Address the browser plays the uploaded video from.
     *
     * @param \context $context module context
     * @return string empty when no file is uploaded
     */
    public static function file_url(\context $context): string {
        $file = self::file($context);
        if (!$file) {
            return '';
        }
        return \moodle_url::make_pluginfile_url(
            $context->id,
            'mod_aiinteractivevideo',
            self::FILEAREA,
            0,
            $file->get_filepath(),
            $file->get_filename()
        )->out(false);
    }

    /**
     * Whether the activity has a playable video.
     *
     * @param \stdClass $instance
     * @param \context $context module context
     * @return bool
     */
    public static function has_video(\stdClass $instance, \context $context): bool {
        if (self::source($instance) === self::SOURCE_UPLOAD) {
            return self::file($context) !== null;
        }
        return (string)$instance->videoid !== '';
    }

    /**
     * Identifies the current video, so a change of video can be noticed.
     *
     * @param \stdClass $instance
     * @param \context|null $context module context
     * @return string
     */
    public static function signature(\stdClass $instance, ?\context $context): string {
        if (self::source($instance) === self::SOURCE_UPLOAD) {
            $file = $context ? self::file($context) : null;
            return 'upload:' . ($file ? $file->get_contenthash() : '');
        }
        return 'youtube:' . (string)($instance->videoid ?? '');
    }

    /**
     * Whether a draft area holds a file.
     *
     * @param int $draftitemid
     * @return bool
     */
    public static function draft_has_file(int $draftitemid): bool {
        return self::draft_file($draftitemid) !== null;
    }

    /**
     * The first file in one of the current user's draft areas.
     *
     * @param int $draftitemid
     * @return \stored_file|null
     */
    public static function draft_file(int $draftitemid): ?\stored_file {
        global $USER;
        if ($draftitemid <= 0) {
            return null;
        }
        $usercontext = \context_user::instance($USER->id);
        $files = get_file_storage()->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'id ASC', false);
        return $files ? reset($files) : null;
    }

    /**
     * Reads an uploaded transcript file (VTT, SRT or plain text) as UTF-8 text.
     *
     * @param int $draftitemid
     * @return string|null null when no file was uploaded
     */
    public static function draft_transcript(int $draftitemid): ?string {
        $file = self::draft_file($draftitemid);
        if (!$file) {
            return null;
        }
        if ($file->get_filesize() > self::TRANSCRIPT_MAXBYTES) {
            return '';
        }
        return self::to_utf8($file->get_content());
    }

    /**
     * Converts transcript text to UTF-8 (files saved by Windows tools are often UTF-16 or Windows-1252).
     *
     * @param string $raw
     * @return string
     */
    public static function to_utf8(string $raw): string {
        if (str_starts_with($raw, "\xFF\xFE")) {
            return \core_text::convert(substr($raw, 2), 'UTF-16LE', 'UTF-8');
        }
        if (str_starts_with($raw, "\xFE\xFF")) {
            return \core_text::convert(substr($raw, 2), 'UTF-16BE', 'UTF-8');
        }
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = \core_text::convert($raw, 'windows-1252', 'UTF-8');
        }
        return $raw;
    }
}
