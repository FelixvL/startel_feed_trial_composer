<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Eduframe\Client;
use Eduframe\Connection;

header('Content-Type: application/json; charset=utf-8');

// in prod: geen deprecations naar output
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');

Dotenv::createImmutable(__DIR__)->safeLoad();

function envx(string $key, $default = null) {
    $v = getenv($key);
    if ($v !== false && $v !== '') return $v;
    if (!empty($_ENV[$key]))    return $_ENV[$key];
    if (!empty($_SERVER[$key])) return $_SERVER[$key];
    return $default;
}
function prop($obj, $path, $default=null) {
    $cur = $obj;
    foreach (explode('.', $path) as $seg) {
        if (is_object($cur) && isset($cur->{$seg})) $cur = $cur->{$seg}; else return $default;
    }
    return $cur;
}

$token = envx('EDUFRAME_TOKEN');
if (!$token) {
    http_response_code(500);
    echo json_encode(['error' => 'EDUFRAME_TOKEN ontbreekt of is leeg']);
    exit;
}

$sinceDate = (new DateTimeImmutable('-7 days'))->format('Y-m-d');

$connection = new Connection();
$connection->setAccessToken($token);
$client = new Client($connection);

/**
 * Strategy:
 * - 1) Haal ALLE planned_courses op (met course + relaties).
 * - 2) Bouw een course-map vanuit de embedded course objects (geen /courses-list!).
 * - 3) Als de embedded course GEEN tabs/labels/custom bevat, haal per uniek course_id een /courses/{id} (met includes) op.
 */

// 1) planned_courses ophalen met zoveel mogelijk nested includes
$perPage = 100;
$page = 1;
$planned = [];
do {
    try {
        $chunk = $client->planned_courses()->all([
            // probeer course + nested relaties meteen mee te krijgen
            'include'         => 'course,course.course_tab_contents.course_tab,course.labels,course.custom,meetings,teachers,course_location,course_variant',
            'start_date_from' => $sinceDate,
            'per_page'        => $perPage,
            'page'            => $page,
            // 'sort'          => 'start_date:asc', // aanzetten als jouw tenant dat toestaat
            // 'is_published'  => true,            // optioneel als ondersteund
        ]);
    } catch (\Throwable $e) {
        // fallback zonder nested course-relaties als include-streng is
        $chunk = $client->planned_courses()->all([
            'include'         => 'course,meetings,teachers,course_location,course_variant',
            'start_date_from' => $sinceDate,
            'per_page'        => $perPage,
            'page'            => $page,
        ]);
    }
    $planned = array_merge($planned, $chunk);
    $page++;
} while (count($chunk) === $perPage);

// 2) Course-map bouwen uit embedded course-objects
$coursesById = [];                 // id => courseObject (mogelijk nog “mager”)
$plannedByCourse = [];             // course_id => [ planned_course objects ]
$needEnrichCourseIds = [];         // ids die we later verrijken via find()

foreach ($planned as $pc) {
    $course = $pc->course ?? null;
    $cid    = $pc->course_id ?? ($course->id ?? null);
    if (!$cid) continue;

    // planned per course groeperen
    $plannedByCourse[$cid][] = $pc;

    if ($course) {
        $coursesById[$cid] = $course;

        // check of tabs/labels/custom ontbreken → later verrijken
        $hasTabs   = !empty($course->course_tab_contents);
        $hasLabels = !empty($course->labels);
        $hasCustom = isset($course->custom);

        if (!($hasTabs && $hasLabels && $hasCustom)) {
            $needEnrichCourseIds[$cid] = true;
        }
    } else {
        // geen embedded course → sowieso verrijken
        $needEnrichCourseIds[$cid] = true;
    }
}

// 3) Verrijk per uniek course_id via /courses/{id} (GEEN list!)
foreach (array_keys($needEnrichCourseIds) as $cid) {
    try {
        $full = $client->courses()->find($cid, [
            'include' => 'course_tab_contents.course_tab,labels,custom'
        ]);
        if ($full) {
            $coursesById[$cid] = $full;
        }
    } catch (\Throwable $e) {
        // als zelfs find faalt, laten we de “magerdere” versie staan
        // je kunt hier evt. loggen
    }
}

// 4) Samenvoegen naar jouw gewenste structuur
$result = [];  // "<course_id>" => {..., planned_courses: { "<pc_id>": {...} } }

foreach ($coursesById as $cid => $course) {
    // basis course payload
    $coursePayload = [
        'id'                => $course->id ?? null,
        'category_id'       => $course->category_id ?? null,
        'name'              => $course->name ?? null,
        'code'              => $course->code ?? null,
        'duration'          => $course->duration ?? null,
        'level'             => $course->level ?? null,
        'meta_title'        => $course->meta_title ?? null,
        'meta_description'  => $course->meta_description ?? null,
        'result'            => $course->result ?? null,
        'is_published'      => $course->is_published ?? null,
        'position'          => $course->position ?? null,
        'slug'              => $course->slug ?? null,
        'slug_history'      => $course->slug_history ?? [],
        'updated_at'        => $course->updated_at ?? null,
        'created_at'        => $course->created_at ?? null,
        'starting_price'    => $course->starting_price ?? null,
        'signup_url'        => $course->signup_url ?? null,
        'conditions_or_default' => $course->conditions_or_default ?? null,
        'avatar'            => $course->avatar ?? null,
        'course_tab_contents' => array_map(function ($ctc) {
            return [
                'content'    => $ctc->content ?? null,
                'course_tab' => [
                    'name'     => prop($ctc, 'course_tab.name'),
                    'position' => prop($ctc, 'course_tab.position'),
                ],
            ];
        }, is_iterable($course->course_tab_contents ?? null) ? $course->course_tab_contents : []),
        'labels' => array_map(function ($lbl) {
            return [
                'id'         => $lbl->id ?? null,
                'name'       => $lbl->name ?? null,
                'color'      => $lbl->color ?? null,
                'model_type' => $lbl->model_type ?? null,
                'updated_at' => $lbl->updated_at ?? null,
                'created_at' => $lbl->created_at ?? null,
            ];
        }, is_iterable($course->labels ?? null) ? $course->labels : []),
        'custom' => is_object($course->custom ?? null) ? (array)$course->custom : (is_array($course->custom ?? null) ? $course->custom : []),
        'planned_courses' => [],
    ];

    // planned onder course hangen (alleen voor courses die echt geplande items hebben)
    $pcs = $plannedByCourse[$cid] ?? [];
    $pcMap = [];
    foreach ($pcs as $pc) {
        // meetings normaliseren
        $meetings = [];
        if (is_iterable($pc->meetings ?? null)) {
            foreach ($pc->meetings as $m) {
                $meetings[] = [
                    'id'                   => $m->id ?? null,
                    'name'                 => $m->name ?? null,
                    'planned_course_id'    => $m->planned_course_id ?? null,
                    'start_date_time'      => $m->start_date_time ?? ($m->starts_at ?? null),
                    'end_date_time'        => $m->end_date_time ?? ($m->ends_at ?? null),
                    'meeting_location_id'  => $m->meeting_location_id ?? null,
                    'description'          => $m->description ?? null,
                    'description_dashboard'=> $m->description_dashboard ?? null,
                    'created_at'           => $m->created_at ?? null,
                    'updated_at'           => $m->updated_at ?? null,
                ];
            }
        }
        // teachers
        $teachers = [];
        if (is_iterable($pc->teachers ?? null)) {
            foreach ($pc->teachers as $t) {
                $teachers[] = [
                    'id'         => $t->id ?? null,
                    'first_name' => $t->first_name ?? null,
                    'middle_name'=> $t->middle_name ?? null,
                    'last_name'  => $t->last_name ?? null,
                ];
            }
        }
        $pcMap[$pc->id] = [
            'id'                 => $pc->id ?? null,
            'course_location_id' => $pc->course_location_id ?? null,
            'course_variant_id'  => $pc->course_variant_id ?? null,
            'status'             => $pc->status ?? null,
            'availability_state' => $pc->availability_state ?? null,
            'course_id'          => $pc->course_id ?? null,
            'type'               => $pc->type ?? null,
            'start_date'         => $pc->start_date ?? null,
            'end_date'           => $pc->end_date ?? null,
            'min_participants'   => $pc->min_participants ?? null,
            'max_participants'   => $pc->max_participants ?? null,
            'cost_scheme'        => $pc->cost_scheme ?? null,
            'is_published'       => $pc->is_published ?? null,
            'updated_at'         => $pc->updated_at ?? null,
            'created_at'         => $pc->created_at ?? null,
            'cost'               => $pc->cost ?? null,
            'current_participants'=> $pc->current_participants ?? null,
            'available_places'   => $pc->available_places ?? null,
            'currency'           => $pc->currency ?? null,
            'course_location'    => is_object($pc->course_location ?? null) ? [
                'id'         => prop($pc, 'course_location.id'),
                'name'       => prop($pc, 'course_location.name'),
                'created_at' => prop($pc, 'course_location.created_at'),
                'updated_at' => prop($pc, 'course_location.updated_at'),
            ] : null,
            'course_variant'     => is_object($pc->course_variant ?? null) ? [
                'id'         => prop($pc, 'course_variant.id'),
                'name'       => prop($pc, 'course_variant.name'),
                'created_at' => prop($pc, 'course_variant.created_at'),
                'updated_at' => prop($pc, 'course_variant.updated_at'),
            ] : null,
            'meetings'           => $meetings,
            'teachers'           => $teachers,
        ];
    }
    $coursePayload['planned_courses'] = $pcMap;

    // alleen opnemen als er geplande items zijn (past bij jouw doel)
    if (!empty($pcMap)) {
        $result[(string)$cid] = $coursePayload;
    }
}

// 5) Response
echo json_encode([
    '_meta' => [
        'since'               => $sinceDate,
        'unique_courses'      => count($result),
        'planned_records'     => count($planned),
        'built_from'          => 'planned_courses (no /courses list)',
    ],
    'data' => $result,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
