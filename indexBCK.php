<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Eduframe\Client;
use Eduframe\Connection;

Dotenv::createImmutable(__DIR__)->safeLoad();

header('Content-Type: application/json; charset=utf-8');

error_reporting(E_ALL);
ini_set('display_errors', '1');
@set_time_limit(420);
function envx(string $key, $default = null) {
    $v = getenv($key);
    if ($v !== false && $v !== '') return $v;
    if (isset($_ENV[$key])    && $_ENV[$key]    !== '') return $_ENV[$key];
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return $_SERVER[$key];
    return $default;
}

$token = envx('EDUFRAME_TOKEN');
print("hoi2");
$sinceDate = (new DateTimeImmutable('-7 days'))->format('Y-m-d');

$connection = new Connection();
$connection->setAccessToken($token);
$client = new Client($connection);

// kleine helper
function prop($obj, $path, $default=null) {
  $cur = $obj;
  foreach (explode('.', $path) as $seg) {
    if (is_object($cur) && isset($cur->{$seg})) $cur = $cur->{$seg}; else return $default;
  }
  return $cur;
}

/** 1) Alle courses (ZONDER sort) */
$coursesMap = [];
$page = 1; $perPage = 100;
do {
  $chunk = $client->courses()->all([
    'include'  => 'course_tab_contents.course_tab,labels,custom',
    'per_page' => $perPage,
    'page'     => $page,
    // 'sort'   => 'id:asc', // ← VERWIJDERD (403)
  ]);
  foreach ($chunk as $course) {
    $coursesMap[$course->id] = $course;
  }
  $page++;
} while (count($chunk) === $perPage);

/** 2) Alle planned_courses (met fallback als sort niet mag) */
$plannedByCourse = [];
$page = 1; $perPage = 100;
$plannedParams = [
  'include'         => 'meetings,teachers,course_location,course_variant,course',
  'per_page'        => $perPage,
  'page'            => $page,
  'start_date_from' => $sinceDate,
  'sort'            => 'start_date:asc', // als dit 403 geeft, vangen we het op
];

// functie om pagina te halen met optionele retry zonder sort
$fetchPlannedPage = function($p) use ($client, $plannedParams) {
  $params = $plannedParams;
  $params['page'] = $p;
  try {
    return $client->planned_courses()->all($params);
  } catch (Throwable $e) {
    // als sort niet is toegestaan, probeer zonder sort
    if (strpos($e->getMessage(), 'Sorting on') !== false) {
      unset($params['sort']);
      return $client->planned_courses()->all($params);
    }
    throw $e;
  }
};

do {
  $chunk = $fetchPlannedPage($page);
  foreach ($chunk as $pc) {
    $cid = prop($pc, 'course_id', prop($pc, 'course.id'));
    if (!$cid) continue;
    $plannedByCourse[$cid][] = $pc;
  }
  $page++;
} while (count($chunk) === $perPage);

/** 3) Samenvoegen naar gewenste structuur */
$result = [];

ksort($coursesMap, SORT_NUMERIC); // lokaal sorteren op id (optioneel)

foreach ($coursesMap as $cid => $course) {
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

  $pcs = $plannedByCourse[$cid] ?? [];
  $pcMap = [];
  foreach ($pcs as $pc) {
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
  $result[(string)$cid] = $coursePayload;
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

