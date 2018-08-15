<?php

namespace Drupal\utexas_migrate\Plugin\migrate\destination;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\media\Entity\Media;
use Drupal\migrate\Plugin\migrate\destination\DestinationBase;
use Drupal\migrate\Plugin\MigrateDestinationInterface;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;
use Drupal\Core\Site\Settings;

/**
 * Provides a 'utexas_media_destination' destination plugin.
 *
 * This is a base class for Media migrations.
 */
abstract class MediaDestination extends DestinationBase implements MigrateDestinationInterface {

  public $migrationSourceBasePath;
  public $migrationSourceBaseUrl;
  public $migrationSourcePrivateFilePath;
  public $migrationSourcePublicFilePath;
  public $mediaElements = [];
  public $importedFile;

  /**
   * Constructor method.
   */
  public function __construct(array $configuration, $plugin_id, array $plugin_definition, MigrationInterface $migration) {
    $this->migrationSourceBasePath = '/' . trim(Settings::get('migration_source_base_path'), '/') . '/';
    $this->migrationSourceBaseUrl = trim(Settings::get('migration_source_base_url'), '/') . '/';
    $this->migrationSourcePublicFilePath = trim(Settings::get('migration_source_public_file_path'), '/') . '/';
    $this->migrationSourcePrivateFilePath = trim(Settings::get('migration_source_private_file_path'), '/') . '/';
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration);
  }

  /**
   * Import function that runs on each row.
   *
   * This import method will return a managed file entity.
   * It is up to implementations to use the file in whatever
   * form of Media entity is needed.
   */
  public function import(Row $row, array $old_destination_id_values = []) {
    // The managed file needs to be saved, first,
    // before the media entity can be created.
    $file_uri = $row->getSourceProperty('uri');
    if (strpos($file_uri, 'public://') !== FALSE) {
      // Public files.
      $path_to_file = $this->migrationSourceBaseUrl . str_replace('public://', $this->migrationSourcePublicFilePath, $file_uri);
    }
    else {
      // Private files.
      $path_to_file = $this->migrationSourceBasePath . str_replace('private://', $this->migrationSourcePrivateFilePath, $file_uri);
    }

    try {
      // This saves a new Managed File.
      $file_data = file_get_contents($path_to_file);
      $dirname = dirname($file_uri);
      // Prepare subdirectories of the filesystem.
      if (!in_array($dirname, ['public:', 'private:'])) {
        file_prepare_directory($dirname, FILE_CREATE_DIRECTORY);
      }
      $this->importedFile = file_save_data($file_data, $file_uri, FILE_EXISTS_REPLACE);
      if ($this->importedFile) {
        $this->mediaElements['name'] = $this->importedFile->getFilename();
        $this->mediaElements['uid'] = $row->getDestinationProperty('uid');
        // File "status" in Drupal 7 is present, but non-functional.
        // Nevertheless, migrate the value ("1") from the source system.
        $this->mediaElements['status'] = $row->getSourceProperty('status');
        // Drupal 7 file entities only have the "timestamp" timestamp, so
        // migrate that as both 'created' & 'changed' into Drupal 8.
        $this->mediaElements['created'] = $row->getSourceProperty('timestamp');
        $this->mediaElements['changed'] = $row->getSourceProperty('timestamp');
      }
    }
    catch (EntityStorageException $e) {
      \Drupal::logger('utexas_migrate')->warning("Import of file failed: :code, :error", [
        ':error' => $e->getMessage(),
        ':code' => $e->getCode(),
      ]);
    }
  }

  /**
   * Helper function that actually saves the media entity.
   *
   * This MUST be called in classes that extend MediaDestination
   * as the last element in those extending classes' import() method.
   */
  protected function saveImportData() {
    // Before trying to save the media entity, check if the file was saved.
    if ($this->importedFile->id() != '0') {
      try {
        $imported_media = Media::create($this->mediaElements);
        $imported_media->save();
        return [$imported_media->id()];
      }
      catch (EntityStorageException $e) {
        \Drupal::logger('utexas_migrate')->warning("Import of node failed: :error - Code: :code", [
          ':error' => $e->getMessage(),
          ':code' => $e->getCode(),
        ]);
      }
    }

  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'id' => [
        'type' => 'integer',
        'unsigned' => FALSE,
        'size' => 'big',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    // TODO: Implement calculateDependencies() method.
  }

  /**
   * {@inheritdoc}
   */
  public function rollback(array $destination_identifier) {
    try {
      $entity = Media::load($destination_identifier['id']);
      if ($entity != NULL) {
        $entity->delete();
      }
    }
    catch (EntityStorageException $e) {
      \Drupal::logger('utexas_migrate')->warning("Rollback of node with nid of :nid failed: :error - Code: :code", [
        ':nid' => $destination_identifier['id'],
        ':error' => $e->getMessage(),
        ':code' => $e->getCode(),
      ]);
    }
    // Delete the actual managed files, as well.
    $query = \Drupal::database()->select('file_managed')
      ->fields('file_managed', ['fid']);
    $result = array_keys($query->execute()->fetchAllAssoc('fid'));
    if (!empty($result)) {
      foreach ($result as $fid) {
        file_delete($fid);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function supportsRollback() {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function rollbackAction() {
    return MigrateIdMapInterface::ROLLBACK_DELETE;
  }

  /**
   * {@inheritdoc}
   */
  public function fields(MigrationInterface $migration = NULL) {
    // Not needed; must be implemented to respect MigrateDestinationInterface.
  }

}
