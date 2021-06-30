<?php

namespace Drupal\utexas_migrate;

use Drupal\Core\Database\Database;

/**
 * Helper functions for migrating elements within WYSIWYG fields.
 */
class WysiwygHelper {

  /**
   * Main method for processing all content.
   *
   * @param string $text
   *   The entire text of a WYSIWYG field.
   *
   * @return string
   *   The processed text
   */
  public static function process($text) {
    $text = self::transformMediaLibrary($text);
    $text = self::transformVideoFilter($text);
    $text = self::transformInnerRail($text);
    return $text;
  }

  /**
   * Find v2 [inner_rail] content & transform it to HTML.
   *
   * @param string $text
   *   The entire text of a WYSIWYG field.
   *
   * @return string
   *   The processed text.
   */
  public static function transformInnerRail($text) {
    // Source: [inner_rail title:"Inner rail title" float:"right"]Lorem ipsum[/inner_rail]
    $destination_token = '<aside class="inner-railFLOAT_TOKEN">TITLE_TOKENCONTENT_TOKEN</aside>';
    $pattern = '/\[inner_rail(.*)\](.*)\[\/inner_rail\]/';
    preg_match_all($pattern, $text, $matches, PREG_SET_ORDER);
    // Expected result:
    // [0] => [inner_rail title:"Inner rail title" float:"right"]Lorem ipsum[/inner_rail]
    // [1] => title:"Inner rail title" float:"right"
    // [2] => Lorem ipsum
    if (isset($matches)) {
      foreach ($matches as $match) {
        // Strip out metadata like width/height that is not used in
        // v3. $parts[0] should be a plain URL.
        preg_match('/title:"([^"]*)"/', $match[1], $title_match);
        preg_match('/float:"([^"]*)"/', $match[1], $float_match);
        $title = '';
        $float = '';
        if (isset($title_match[1])) {
          $title = '<h3>' . $title_match[1] . '</h3>';
        }
        if (isset($float_match[1])) {
          $float = ' ' . $float_match[1];
        }
        if ($match[2]) {
          $replace = str_replace('CONTENT_TOKEN', $match[2], $destination_token);
          $replace = str_replace('TITLE_TOKEN', $title, $replace);
          $replace = str_replace('FLOAT_TOKEN', $float, $replace);
          $text = str_replace($match[0], $replace, $text);
        }
      }
    }
    return $text;
  }

  /**
   * Find v2 video_filter markup & render it as v3 url_embed.
   *
   * @param string $text
   *   The entire text of a WYSIWYG field.
   *
   * @return string
   *   The processed text
   */
  public static function transformVideoFilter($text) {
    // Source: [video:https://www.youtube.com/watch?v=U-0YB6pRArA width:300]
    $destination_token = '<drupal-url data-embed-button="url" data-embed-url="URL_TOKEN" data-entity-label="URL"></drupal-url>';
    $pattern = '/\[video:(.*)\]/';
    preg_match_all($pattern, $text, $matches, PREG_SET_ORDER);
    if (isset($matches)) {
      foreach ($matches as $match) {
        // Strip out metadata like width/height that is not used in
        // v3. $parts[0] should be a plain URL.
        $parts = explode(' ', $match[1]);
        if ($parts[0]) {
          $replace = str_replace('URL_TOKEN', $parts[0], $destination_token);
          $text = str_replace($match[0], $replace, $text);
        }
      }
    }
    return $text;
  }

  /**
   * Find v2 media markup & render it as v3 media tags.
   *
   * @param string $text
   *   The entire text of a WYSIWYG field.
   *
   * @return string
   *   The processed text
   */
  public static function transformMediaLibrary($text) {
    // Source: [[{"fid":"1","view_mode":"preview","fields":{"format":"preview","alignment":"","field_file_image_alt_text[und][0][value]":"placeholder image","field_file_image_title_text[und][0][value]":"placeholder image","external_url":""},"type":"media","field_deltas":{"1":{"format":"preview","alignment":"","field_file_image_alt_text[und][0][value]":"placeholder image","field_file_image_title_text[und][0][value]":"placeholder image","external_url":""}},"attributes":{"alt":"placeholder image","title":"placeholder image","class":"media-element file-preview","data-delta":"1"}}]]
    $destination_token = '<drupal-media data-align="ALIGN_TOKEN" data-entity-type="media" data-entity-uuid="UUID_TOKEN" data-view-mode="VIEWMODE_TOKEN"></drupal-media>';
    $pattern = '/\[\[{(.*)"fid":"(\d*)",(.*)}\]\]/';
    //$pattern = '/\[\[{(.*)"fid":"(\d*)"(.*)"view_mode":"([^\"]*)"(.*)"alignment":"([^\"]*)"(.*)\]\]/';
    preg_match_all($pattern, $text, $matches, PREG_SET_ORDER);
    if (isset($matches)) {
      foreach ($matches as $match) {
        $json = json_decode($match[0]);
        $uuid = self::getMediaUuid($json[0][0]->fid);
        $view_mode = self::getViewMode($json[0][0]->view_mode);
        $alignment = self::getAlignment($json[0][0]->fields->alignment);
        if ($uuid) {
          $replace = str_replace('UUID_TOKEN', $uuid, $destination_token);
          $replace = str_replace('ALIGN_TOKEN', $alignment, $replace);
          $replace = str_replace('VIEWMODE_TOKEN', $view_mode, $replace);
          $text = str_replace($match[0], $replace, $text);
        }
      }
    }
    return $text;
  }

  /**
   * Get a v3 media UUID from a source site FID.
   *
   * @param int $source_fid
   *   The FID of the source site media item.
   *
   * @return string
   *   The processed text
   */
  public static function getMediaUuid($source_fid) {
    $destination_mid = MigrateHelper::getDestinationMid($source_fid);
    if ($destination_mid) {
      $connection = Database::getConnection('default', 'default');
      $uuid = $connection->select('media')
        ->fields('media', ['uuid'])
        ->condition('mid', $destination_mid, '=')
        ->execute()
        ->fetchField();
      if ($uuid) {
        return $uuid;
      }
    }
    return FALSE;
  }

  /**
   * Get a v3 view mode based on v2 value.
   *
   * @param string $source_view_mode
   *   The view mode of the source site media item.
   *
   * @return string
   *   The v2 view mode
   */
  public static function getViewMode($source_view_mode) {
    $view_mode_map = [
      'teaser' => 'utexas_medium',
      'preview' => 'utexas_thumbnail',
    ];
    if (in_array($source_view_mode, array_keys($view_mode_map))) {
      return $view_mode_map[$source_view_mode];
    }
    return 'default';
  }

  /**
   * Get a v3 view mode based on v2 value.
   *
   * @param string $source_alignment
   *   The alignment of the source site media item.
   *
   * @return string
   *   The v2 alignment
   */
  public static function getAlignment($source_alignment) {
    if (in_array($source_alignment, ['left', 'center', 'right'])) {
      return $source_alignment;
    }
    return '';
  }

}