<?php

namespace Drupal\utexas_migrate\Plugin\migrate\process;

use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Row;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\node\Entity\Node;
use Drupal\utexas_migrate\MigrateHelper;
use Drupal\utexas_migrate\CustomWidgets\FeaturedHighlight;
use Drupal\utexas_migrate\CustomWidgets\Hero;
use Drupal\utexas_migrate\CustomWidgets\ImageLink;
use Drupal\utexas_migrate\CustomWidgets\PromoLists;
use Drupal\utexas_migrate\CustomWidgets\PromoUnits;
use Drupal\utexas_migrate\CustomWidgets\SocialLinks;

/**
 * Layouts Processor.
 *
 * This plugin takes care of processing a D7 "Page Layout"
 * into something consumable buy D8 "Layout Builder".
 *
 * @MigrateProcessPlugin(
 *   id = "utexas_process_layout"
 * )
 */
class Layouts extends ProcessPluginBase {

  /**
   * The main function.
   *
   * Given a row that contains a destination node ID and
   * the source context for all of the D7 fields, build an array of inline data,
   * organized by Drupal 8 section components, then save that to the node's
   * layout (node__layout_builder__layout).
   */
  public function transform($layout, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    // 1. Get the template name (e.g., "Featured Highlight")
    // and the destination ID (e.g., "1")
    $template = $row->getSourceProperty('template');
    $nid = $row->getDestinationProperty('temp_nid');

    // This contains all inline field data as well as layout structure,
    // in a single array.
    $section_data = self::buildSectionsArray($layout, $template, $nid, $row);
    // @breakpoint recommendation.
    // print_r($section_data);

    // 2. Put those array elements into D8 section objects.
    $sections = [];
    foreach ($section_data as $section) {
      $d8_components = [];
      if (!empty($section['components'])) {
        foreach ($section['components'] as $component) {
          $d8_component = self::createD8SectionComponent($component);
          if ($d8_component) {
            $d8_components[] = $d8_component;
          }
        }
        if (!empty($d8_components)) {
          $section = self::createD8Section($section['layout'], $section['layoutSettings'], $d8_components);
          $sections[] = $section;
        }

      }
    }
    return $sections;
  }

  /**
   * Get layout data into a traversable format.
   *
   * @param string $layout
   *   A serialized array of layout data from the "context" table.
   * @param string $template
   *   The D7 template associated with this page.
   * @param int $nid
   *   The destination NID.
   * @param Drupal\migrate\Row $row
   *   Other entity source data related to this specific entity migration.
   */
  protected static function buildSectionsArray($layout, $template, $nid, Row $row) {
    $layout_data = unserialize($layout);
    // Extract the blocks in the layout from the 'context' table.
    $blocks = $layout_data['block']['blocks'];
    if (!$blocks) {
      return [];
    }

    // Look up presence of "locked" fields & add them programmatically
    // as blocks, potentially adjusting weight of other blocks.
    $blocks = self::addLockedFieldsAsBlocks($blocks, $template, $nid, $row);

    // Build up the D8 sections based on known information about the D7 layout:
    $sections = self::getD8SectionsfromD7Layout($template);

    // Loop through all known blocks, building the D8 section components.
    foreach ($blocks as $id => $settings) {
      $found = FALSE;
      if (in_array($id, array_keys(MigrateHelper::$excludedFieldblocks))) {
        // Skip "excluded" fieldblocks, like Twitter Widget, Contact Info,
        // since UTDK8 doesn't currently have a location for these.
        continue;
      }
      elseif (in_array($id, array_keys(MigrateHelper::$includedFieldBlocks))) {
        $field_name = MigrateHelper::$includedFieldBlocks[$id];
        $found = TRUE;
      }
      elseif ($settings['region'] == 'social_links') {
        // The above eliminates fieldblocks not yet converted to UUIDs.
        // @todo: look up standard blocks' block UUIDs in FlexPageLayoutsSource.php
        // This code may need to be refactored to further disambiguate.
        // This is not a fieldblock (e.g., Social Links). Use the block ID.
        $field_name = 'social_links';
        $found = TRUE;
      }

      if ($found) {
        // @todo: Revise the placeFieldinSection() method to use inline blocks.
        // Now that we know we have a field, check for a D7 display setting,
        // and if so, pass an equivalent view_mode to the D8 field formatter.
        $field_data = self::retrieveFieldData($field_name, $row);
        $sections = self::placeFieldinSection($sections, $field_data, $settings, $template);
      }
    }
    return $sections;
  }

  /**
   * Get Drupal 7 layout data into a traversable format.
   *
   * @param string $field_name
   *   The Drupal 8 field name (e.g., field_flex_page_fh).
   * @param Drupal\migrate\Row $row
   *   Other entity source data related to this specific entity migration.
   */
  protected static function retrieveFieldData($field_name, Row $row) {
    $nid = $row->getSourceProperty('nid');
    $formatter = [
      'label' => 'hidden',
    ];
    switch ($field_name) {
      case 'social_links':
        $source = SocialLinks::getFromNid($nid);
        $block_type = 'social_links';
        break;

      case 'image_link_a':
      case 'image_link_b':
        $block_type = 'utexas_image_link';
        $source = ImageLink::getFromNid($field_name, $nid);
        break;

      case 'field_flex_page_hi':
        $style_map = [
          'default-center' => 'utexas_hero',
          'hero-style-1-left' => 'utexas_hero_1_left',
          'hero-style-1-center' => 'utexas_hero_1',
          'hero-style-1-right' => 'utexas_hero_1_right',
          'hero-style-2-left' => 'utexas_hero_2_left',
          'hero-style-2-center' => 'utexas_hero_2',
          'hero-style-2-right' => 'utexas_hero_2_right',
          'hero-style-3-left' => 'utexas_hero_3_left',
          'hero-style-3-center' => 'utexas_hero_3',
          'hero-style-3-right' => 'utexas_hero_3_right',
          'hero-style-4-left' => 'utexas_hero_4',
          'hero-style-4-center' => 'utexas_hero_4',
          'hero-style-4-right' => 'utexas_hero_4',
          'hero-style-5-left' => 'utexas_hero_5_left',
          'hero-style-5-center' => 'utexas_hero_5',
          'hero-style-5-right' => 'utexas_hero_5_right',
        ];
        $source = Hero::getSourceData('placeholder', $nid);
        $style = $source[0]['display_style'] ?? 'default';
        $position = $source[0]['position'] ?? 'center';
        $d7_formatter_name = $style . '-' . $position;
        $formatter = [
          'label' => 'hidden',
          'type' => $style_map[$d7_formatter_name],
        ];
        break;

      case 'field_flex_page_fh':
        $style_map = [
          'light' => 'default',
          'navy' => 'utexas_featured_highlight_2',
          'dark' => 'utexas_featured_highlight_3',
        ];
        $source = FeaturedHighlight::getSourceData($nid);
        if (!empty($source[0]['style'])) {
          $style = $source[0]['style'];
          $formatter = [
            'label' => 'hidden',
            'type' => $style_map[$style],
          ];
        }
        break;

      case 'field_flex_page_pu':
        $style_map = [
          'utexas_promo_unit_landscape_image' => 'default',
          'utexas_promo_unit_portrait_image' => 'utexas_promo_unit_2',
          'utexas_promo_unit_square_image' => 'utexas_promo_unit_3',
          'utexas_promo_unit_no_image' => 'default',
        ];
        $source = PromoUnits::getSourceData($nid);
        if (!empty($source[0]['size_option'])) {
          $style = $source[0]['size_option'];
          $formatter = [
            'label' => 'hidden',
            'type' => $style_map[$style],
          ];
        }
        break;

      case 'field_flex_page_pl':
        $style_map = [
          'Single list full (1 item per row)' => 'default',
          'Single list responsive (2 items per row)' => 'utexas_promo_list_2',
          'Two lists, side-by-side' => 'utexas_promo_list_3',
        ];
        $source = PromoLists::getSourceData($nid);
        if (!empty($source[0]['style'])) {
          $style = $source[0]['style'];
          $formatter = [
            'label' => 'hidden',
            'type' => $style_map[$style],
          ];
        }
        break;

    }
    return [
      'field_name' => $field_name,
      'block_type' => $block_type,
      'data' => $source,
      'format' => $formatter,
    ];
  }

  /**
   * Add Drupal 7 "locked" fields to D7 data.
   *
   * @param array $blocks
   *   The D7 block data for this given node.
   * @param string $template
   *   The D7 template associated with this page.
   * @param int $nid
   *   The destination NID.
   * @param Drupal\migrate\Row $row
   *   Other entity source data related to this specific entity migration.
   */
  protected static function addLockedFieldsAsBlocks(array $blocks, $template, $nid, Row $row) {
    $node = Node::load($nid);

    // Check if a social link exists on the source node.
    if ($social_link = SocialLinks::getRawSourceData($row->getSourceProperty('nid'))) {
      // Make a fake D7 block ID that can be identified later on.
      $blocks['inline_social_links'] = [
        'type' => 'social_links',
        'region' => 'social_links',
        'weight' => '-1',
      ];
    }
    return $blocks;
    if ($hi = $node->field_flex_page_hi->getValue()) {
      $region = FALSE;
      switch ($template) {
        case 'Hero Image & Sidebars':
          $region = 'content_top_left';
          $id = 'fieldblock-f4361d99a73eca8a4329c07d0724a554';
          break;

        case 'Promotional Page & Sidebar':
          $region = 'content';
          $id = 'fieldblock-f4361d99a73eca8a4329c07d0724a554';
          break;

        case 'Landing Page Template 1':
        case 'Landing Page Template 2':
        case 'Landing Page Template 3':
          $region = 'hero_image';
          $id = 'fieldblock-8af3bd2d3cab537c77dbfbb55146ab7b';
          break;
      }
      if ($region) {
        // Enforce that hero image is above other content.
        $blocks[$id] = [
          'region' => $region,
          'weight' => '-1',
        ];
      }
    }
    if ($fh = $node->field_flex_page_fh->getValue()) {
      $region = FALSE;
      switch ($template) {
        case 'Featured Highlight':
          $region = 'featured_highlight';
          $id = 'fieldblock-553096d7ea242fc7edcddc53f719d074';
          break;

        case 'Landing Page Template 1':
        case 'Landing Page Template 2':
        case 'Landing Page Template 3':
          $region = 'featured_highlight';
          $id = 'fieldblock-205723da13bdadd816a716421b436a92';
          break;
      }
      if ($region) {
        // Enforce that this locked field is above other content,
        // and is above Quick Links, if present.
        $blocks[$id] = [
          'region' => $region,
          'weight' => '-2',
        ];
      }
    }
    if ($ql = $node->field_flex_page_ql->getValue()) {
      $region = FALSE;
      switch ($template) {
        case 'Landing Page Template 2':
          $region = 'quick_links';
          $id = 'fieldblock-669a6a1f32566fa73ea7974696027184';
          break;
      }
      if ($region) {
        // Enforce that this locked field is above other content.
        $blocks[$id] = [
          'region' => $region,
          'weight' => '-1',
        ];
      }
    }
    if ($w = $node->field_flex_page_wysiwyg_a->getValue()) {
      $region = FALSE;
      switch ($template) {
        case 'Open Text Page':
          $region = 'content';
          break;
      }
      if ($region) {
        // Enforce that this locked field is above other content.
        $blocks['fieldblock-fda604d130a57f15015895c8268f20d2'] = [
          'region' => $region,
          'weight' => '-1',
        ];
      }
    }
    return $blocks;
  }

  /**
   * Build the sections that will comprise this page's layout.
   *
   * @param string $template
   *   The D7 template associated with this page.
   */
  protected static function getD8SectionsfromD7Layout($template) {
    $sections = [];
    $onecol = [
      'layout' => 'layout_utexas_onecol',
    ];
    $onecol_full_width = [
      'layout' => 'layout_utexas_onecol',
      'layoutSettings' => [
        'layout_builder_styles_style' => [
          'full_width_of_page',
        ],
      ],
    ];
    $fifty_fifty = [
      'layout' => 'layout_utexas_twocol',
      'layoutSettings' => [
        'column_widths' => '50-50',
      ],
    ];
    $sixty_six_thirty_three = [
      'layout' => 'layout_utexas_twocol',
      'layoutSettings' => [
        'column_widths' => '67-33',
      ],
    ];
    switch ($template) {
      case 'Featured Highlight':
        $sections[0] = $fifty_fifty;
        $sections[1] = $onecol_full_width;
        $sections[2] = $sixty_six_thirty_three;
        break;

      case 'Hero Image & Sidebars':
      case 'Header with Content & Sidebars':
        $sections[0] = $sixty_six_thirty_three;
        $sections[1] = $sixty_six_thirty_three;
        break;

      case 'Full Content Page & Sidebar':
      case 'Promotional Page & Sidebar':
        $sections[0] = $sixty_six_thirty_three;
        break;

      case 'Full Width Content Page & Title':
      case 'Full Width Content Page':
      case 'Open Text Page':
        $sections[0] = $onecol;
        $sections[1] = $onecol;
        break;

      case 'Landing Page Template 1':
        $sections[0] = $onecol;
        $sections[1] = $sixty_six_thirty_three;
        $sections[2] = $onecol;
        $sections[3] = $sixty_six_thirty_three;
        break;

      case 'Landing Page Template 2':
        $sections[0] = $onecol;
        $sections[1] = $onecol;
        $sections[2] = $onecol;
        $sections[3] = $onecol;
        break;

      case 'Landing Page Template 3':
        $sections[0] = $onecol;
        $sections[1] = $onecol;
        $sections[2] = $onecol;
        $sections[3] = $sixty_six_thirty_three;
        break;
    }
    return $sections;
  }

  /**
   * Given a D7 field setting & template, place it in the equivalent D8 section.
   *
   * @param array $sections
   *   The sections as defined in the D8 equivalent layout from D7..
   * @param string $field_data
   *   The field data.
   * @param array $settings
   *   Field settings, namely region & weight.
   * @param string $template
   *   The D7 template name.
   */
  protected static function placeFieldinSection(array $sections, $field_data, array $settings, $template) {
    $d8_field = $field_data['field_name'];
    switch ($template) {
      case 'Featured Highlight':
        switch ($settings['region']) {
          case 'main_content_top_left':
            $delta = 0;
            $region = 'first';
            break;

          case 'featured_highlight':
            $delta = 1;
            $region = 'main';
            break;

          case 'main_content_top_right':
            $delta = 0;
            $region = 'second';
            break;

          case 'content_bottom':
            $delta = 2;
            $region = 'first';
            break;

          case 'social_links':
            $delta = 2;
            $region = 'second';
            break;

          case 'sidebar_second':
            $delta = 2;
            $region = 'second';
            break;
        }
        break;

      case 'Full Content Page & Sidebar':
      case 'Promotional Page & Sidebar':
        switch ($settings['region']) {
          case 'content':
            $delta = 0;
            $region = 'main';
            break;

          case 'sidebar_second':
            $delta = 0;
            $region = 'second';
            break;
        }
        break;

      case 'Hero Image & Sidebars':
      case 'Header with Content & Sidebars':
        switch ($settings['region']) {
          case 'content_top_left':
            $delta = 0;
            $region = 'first';
            break;

          case 'content_top_right':
            $delta = 0;
            $region = 'second';
            break;

          case 'content_bottom':
            $delta = 1;
            $region = 'main';
            break;

          case 'content':
            $delta = 1;
            $region = 'main';
            break;

          case 'sidebar_second':
            $delta = 1;
            $region = 'second';
            break;
        }
        break;

      case 'Full Width Content Page & Title':
      case 'Full Width Content Page':
        switch ($settings['region']) {
          case 'content_top':
            $delta = 0;
            $region = 'main';
            break;

          case 'content_bottom':
            $delta = 1;
            $region = 'main';
            break;
        }
        break;

      case 'Open Text Page';
        switch ($settings['region']) {
          case 'content':
            $delta = 0;
            $region = 'main';
            break;
        }
        break;

      case 'Landing Page Template 1':
        switch ($settings['region']) {
          case 'hero_image':
            $delta = 0;
            $region = 'main';
            break;

          case 'content_top_left':
            $delta = 1;
            $region = 'first';
            break;

          case 'content_top_right':
            $delta = 1;
            $region = 'second';
            break;

          case 'featured_highlight':
            $delta = 2;
            $region = 'main';
            break;

          case 'content_bottom':
            $delta = 3;
            $region = 'main';
            break;

          case 'sidebar_second':
            $delta = 3;
            $region = 'second';
            break;
        }
        break;

      case 'Landing Page Template 2':
      case 'Landing Page Template 3':
        switch ($settings['region']) {
          case 'hero_image':
            $delta = 0;
            $region = 'main';
            break;

          case 'content_top_three_pillars':
            if (in_array($d8_field, ['field_flex_page_fca_a', 'field_flex_page_fca_b'])) {
              // Special case: FCA in content_top_three_pillars is 3-columns.
              $formatter['type'] = 'utexas_flex_content_area_3';
            }
            $delta = 1;
            $region = 'main';
            break;

          case 'content_top_four_pillars':
            if (in_array($d8_field, ['field_flex_page_fca_a', 'field_flex_page_fca_b'])) {
              // Special case: FCA in content_top_four_pillars 4-columns.
              $formatter['type'] = 'utexas_flex_content_area_4';
            }
            $delta = 1;
            $region = 'main';
            break;

          case 'featured_highlight':
            $delta = 2;
            $region = 'main';
            break;

          case 'quick_links':
            $delta = 2;
            $region = 'main';
            $formatter['type'] = 'utexas_quick_links_4';
            break;

          case 'content_bottom':
            $delta = 3;
            $region = 'main';
            break;

          case 'sidebar_second':
            $delta = 3;
            $region = 'second';
            break;
        }
        break;

      default:
        break;
    }

    $sections[$delta]['components'][$d8_field] = [
      'field_identifier' => $d8_field,
      'block_data' => $field_data['data'],
      'block_type' => $field_data['block_type'],
      'block_format' => $field_data['format'],
      'region' => $region,
      'weight' => $settings['weight'],
      'formatter' => $formatter,
    ];
    return $sections;
  }

  /**
   * Helper function to create a section.
   *
   * @param string $layout
   *   The D8 machine name of the layout to be used.
   * @param array $layout_settings
   *   Any layout-level settings (full width, percentages, etc.).
   * @param array $components
   *   An array of sectionComponents (i.e., fields)
   */
  protected static function createD8Section($layout, array $layout_settings, array $components) {
    // Each section is stored in its own array.
    $section = new Section($layout, $layout_settings, $components);
    return $section;
  }

  /**
   * Helper method to take field data & create a SectionComponent object.
   *
   * @param array $component_data
   *   The data/context of the component (e.g., region, weight, view_mode)
   *
   * @return mixed
   *   The component object or FALSE.
   */
  protected function createD8SectionComponent(array $component_data) {
    if ($block = MigrateHelper::createInlineBlock($component_data)) {
      // Important: the 'id' value must be "inline_block:" + a valid block type.
      $component = new SectionComponent(md5($component_data['field_identifier']), $component_data['region'], [
        'id' => 'inline_block:' . $component_data['block_type'],
        'label' => $component_data['field_identifier'],
        'provider' => 'layout_builder',
        'label_display' => 0,
        'view_mode' => 'full',
        'block_revision_id' => $block->id(),
      ]);
      $component->setWeight($component_data['weight']);
    }

    if (isset($component)) {
      return $component;
    }
    return FALSE;
  }

}
