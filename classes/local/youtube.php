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
 * YouTube URL helpers.
 *
 * @package    mod_aiinteractivevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class youtube {
    /** @var string[] Hosts accepted as YouTube. */
    private const HOSTS = [
        'youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com',
        'youtu.be', 'www.youtu.be', 'youtube-nocookie.com', 'www.youtube-nocookie.com',
    ];

    /**
     * Extracts the 11 character video id from a YouTube URL (or a bare id).
     *
     * @param string $url
     * @return string|null
     */
    public static function video_id(string $url): ?string {
        $url = trim($url);
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $url)) {
            return $url;
        }
        if ($url === '' || strlen($url) > 500) {
            return null;
        }
        if (!preg_match('~^https?://~i', $url)) {
            $url = 'https://' . $url;
        }
        $parts = parse_url($url);
        if (empty($parts['host']) || !in_array(strtolower($parts['host']), self::HOSTS, true)) {
            return null;
        }
        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '';
        $candidate = null;
        if (str_ends_with($host, 'youtu.be')) {
            $candidate = explode('/', trim($path, '/'))[0] ?? null;
        } else if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
            if (!empty($query['v']) && is_string($query['v'])) {
                $candidate = $query['v'];
            }
        }
        if ($candidate === null && preg_match('~^/(?:embed|shorts|live|v|e)/([A-Za-z0-9_-]{11})~', $path, $m)) {
            $candidate = $m[1];
        }
        if ($candidate !== null && preg_match('/^[A-Za-z0-9_-]{11}$/', $candidate)) {
            return $candidate;
        }
        return null;
    }

    /**
     * Thumbnail image for a video id.
     *
     * @param string $videoid
     * @return string
     */
    public static function thumbnail(string $videoid): string {
        return 'https://i.ytimg.com/vi/' . rawurlencode($videoid) . '/hqdefault.jpg';
    }
}
