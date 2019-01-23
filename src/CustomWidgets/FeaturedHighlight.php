<?php

namespace Drupal\utexas_migrate\CustomWidgets;

use Drupal\Core\Database\Database;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\utexas_migrate\MigrateHelper;

/**
 * Convert D7 custom compound field to D8.
 */
class FeaturedHighlight {

  /**
   * Convert D7 data to D8 structure.
   *
   * @param int $source_nid
   *   The node ID from the source data.
   *
   * @return array
   *   Returns an array of field data for the widget.
   */
  public static function convert($source_nid) {
    $source_data = self::getSourceData($source_nid);
    $field_data = self::massageFieldData($source_data);
    return $field_data;
  }

  /**
   * Query the source database for data.
   *
   * @param int $source_nid
   *   The node ID from the source data.
   *
   * @return array
   *   Returns an array of Paragraph ID(s) of the widget
   */
  public static function getSourceData($source_nid) {
    // Get all instances from the legacy DB.
    Database::setActiveConnection('utexas_migrate');
    $source_data = Database::getConnection()->select('field_data_field_utexas_featured_highlight', 'f')
      ->fields('f')
      ->condition('entity_id', $source_nid)
      ->execute()
      ->fetchAll();
    $prepared = [];
    foreach ($source_data as $delta => $item) {
      $prepared[$delta] = [
        'image_fid' => $item->field_utexas_featured_highlight_image_fid,
        'date' => $item->field_utexas_featured_highlight_date,
        'headline' => $item->field_utexas_featured_highlight_headline,
        'copy' => $item->field_utexas_featured_highlight_copy_value,
        'link_href' => $item->field_utexas_featured_highlight_link,
        'link_title' => $item->field_utexas_featured_highlight_cta,
        'style' => $item->field_utexas_featured_highlight_highlight_style,
      ];
    }
    return $prepared;
  }

  /**
   * Save data as paragraph(s) & return the paragraph ID(s)
   *
   * @param array $source
   *   A simple key-value array of subfield & value.
   *
   * @return array
   *   A simple key-value array returned the metadata about the paragraph.
   */
  protected static function massageFieldData(array $source) {
    $destination = [];
    foreach ($source as $delta => $instance) {
      // @todo: support Video file entity migration.
      // This may not require much/any change here -- 
      // video entities are still just entity IDs.
      if ($instance['image_fid'] != 0) {
        if ($destination_mid = MigrateHelper::getMediaIdFromFid($instance['image_fid'])) {
          $destination[$delta]['media'] = $destination_mid;
        }
      }
      if (!empty($instance['link_href'])) {
        $destination[$delta]['link_uri'] = MigrateHelper::prepareLink($instance['link_href']);
        $destination[$delta]['link_text'] = $instance['link_title'];
      }
      if (!empty($instance['copy'])) {
        $destination[$delta]['copy_value'] = $instance['copy'];
        $destination[$delta]['copy_format'] = 'flex_html';
      }
      if (!empty($instance['headline'])) {
        $destination[$delta]['headline'] = $instance['headline'];
      }
      if ($instance['date'] != 0) {
        $destination[$delta]['date'] = $instance['date'];
      }
    }
    return $destination;
  }

}
