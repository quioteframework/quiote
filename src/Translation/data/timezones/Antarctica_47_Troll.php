<?php

/**
 * Data file for timezone "Antarctica/Troll".
 * Compiled from olson file "(unknown)", version (unknown).
 *
 * @package    agavi
 * @subpackage translation
 *
 * @copyright  Authors
 * @copyright  The Agavi Project
 *
 * @since      0.11.0
 *
 * @version    $Id$
 */

return  [
  'types' => 
   [
    0 => 
     [
      'rawOffset' => 0,
      'dstOffset' => 0,
      'name' => '+00',
    ],
    1 => 
     [
      'rawOffset' => 0,
      'dstOffset' => 7200,
      'name' => '+02',
    ],
  ],
  'rules' => 
   [
    0 => 
     [
      'time' => 1108166400.0,
      'type' => 0,
    ],
    1 => 
     [
      'time' => 1111885200.0,
      'type' => 1,
    ],
  ],
  'finalRule' => 
   [
    'type' => 'dynamic',
    'offset' => 0,
    'name' => '%s',
    'save' => 7200,
    'start' => 
     [
      'month' => 2,
      'date' => -1,
      'day_of_week' => 1,
      'time' => 3600000.0,
      'type' => 2.0,
    ],
    'end' => 
     [
      'month' => 9,
      'date' => -1,
      'day_of_week' => 1,
      'time' => 3600000.0,
      'type' => 2.0,
    ],
    'startYear' => 2006,
  ],
  'source' => '(unknown)',
  'version' => '(unknown)',
  'name' => 'Antarctica/Troll',
];

?>